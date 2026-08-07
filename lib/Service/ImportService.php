<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use DateTime;
use OCA\Territorio\Db\CategoryMapper;
use OCA\Territorio\Db\Dataset;
use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\ImportBatch;
use OCA\Territorio\Db\ImportBatchMapper;
use OCA\Territorio\Db\Origin;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Exception\InvalidGeometryException;
use OCA\Territorio\Exception\InvalidImportException;

/**
 * Brings a GeoJSON file into a Dataset, once or repeatedly.
 *
 * Three rules do most of the work here, and all three are ADRs rather than
 * preferences:
 *
 *  - Matching is on `(Dataset, external id)`, so importing the same file twice
 *    updates rather than duplicates.
 *  - A record a person has edited is never overwritten (ADR-0003). The incoming
 *    official value is stored beside the override so the two stay comparable and
 *    the override can be undone.
 *  - Every Revision a run takes is stamped with its batch (ADR-0009), because
 *    that is what makes the run revertible and it cannot be worked out
 *    afterwards. A range of Revision numbers would not do: writes are serialised
 *    behind one cursor, so a long import is easily long enough for a colleague's
 *    edit to fall inside the window and be reverted with it.
 *
 * The file is read, resolved against the taxonomy and checked in full before a
 * single row is written, so a file that is wrong anywhere imports nothing. Once
 * writing starts each row commits separately, so a failure part-way through does
 * leave rows behind — which is exactly why the batch is recorded first, and why
 * its counts are written even when the run throws.
 */
class ImportService {
	/** Where a row goes when its Category or Subcategory is not one we hold. */
	private const FALLBACK = ['sin_clasificar', 'otro'];

	/**
	 * Import brings in Places only.
	 *
	 * The map draws Zones and Routes now (issue #10), so this is no longer about
	 * rendering: it is that no import in this app produces one yet. The boundary
	 * datasets that will — the Unidad Vecinal cadastre (issue #9) and the
	 * boundary features from Overpass (issue #12) — arrive with the loading and
	 * matching rules those need, and lifting this before them would import a
	 * comuna's worth of lines into the ordinary map with none of it.
	 */
	private const SUPPORTED_GEOMETRY = 'Point';

	public function __construct(
		private GeoJsonReader $reader,
		private DatasetMapper $datasets,
		private FeatureMapper $features,
		private FeatureService $featureService,
		private CategoryMapper $categories,
		private SubcategoryMapper $subcategories,
		private RevisionLog $revisions,
		private ImportBatchMapper $batches,
	) {
	}

	/**
	 * @throws InvalidImportException when the file, or any row in it, is unusable
	 */
	public function importGeoJson(
		string $json,
		string $datasetSlug,
		?string $label = null,
		?string $actor = null,
	): ImportBatch {
		$rows = $this->reader->read($json, $datasetSlug);
		[$rows, $duplicates] = $this->collapseDuplicates($rows);
		[$prepared, $unsupported] = $this->prepare($rows);

		// The Dataset is created only once the whole file has been read and
		// checked. Creating it first left a Dataset behind after a refused
		// import, which made "imports nothing" not quite true.
		$dataset = $this->datasets->findOrCreate(
			$datasetSlug,
			$label ?? $datasetSlug,
			Origin::Cadastral,
		);

		return $this->apply($dataset, $prepared, $duplicates, $unsupported, $actor);
	}

	/**
	 * Rows the file repeated verbatim collapse to one.
	 *
	 * The La Florida catastro contains 18 of these across 865 features —
	 * "Balneario Municipal" three times with the same address and coordinate,
	 * inherited from FONASA. Collapsing them is right; doing it silently is not,
	 * so the count is reported on the batch.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return array{0: array<string, array<string, mixed>>, 1: int}
	 */
	private function collapseDuplicates(array $rows): array {
		$byId = [];
		$duplicates = 0;
		foreach ($rows as $row) {
			if (isset($byId[$row['externalId']])) {
				$duplicates++;
			}
			$byId[$row['externalId']] = $row;
		}

		return [$byId, $duplicates];
	}

	/**
	 * Resolve every row against the taxonomy and check its geometry, before
	 * anything is written.
	 *
	 * @param array<string, array<string, mixed>> $rows
	 * @return array{0: array<string, array<string, mixed>>, 1: int}
	 */
	private function prepare(array $rows): array {
		$subcategories = $this->subcategoryIndex();
		$fallback = $subcategories[implode('/', self::FALLBACK)] ?? null;
		if ($fallback === null) {
			throw new InvalidImportException(
				'Falta la subcategoría "Sin clasificar · Otro"; no hay dónde poner lo no reconocido.',
			);
		}

		$prepared = [];
		$unsupported = 0;
		$problems = [];

		foreach ($rows as $externalId => $row) {
			if ($row['geometry'] !== null && ($row['geometry']['type'] ?? null) !== self::SUPPORTED_GEOMETRY) {
				// Read, understood, and counted rather than imported — see
				// SUPPORTED_GEOMETRY for why the boundary datasets bring their own way in.
				$unsupported++;
				continue;
			}

			try {
				// Parsed here so a bad coordinate is caught while nothing has been
				// written, rather than half-way through the file.
				if ($row['geometry'] !== null) {
					Geometry::fromArray($row['geometry']);
				}
			} catch (InvalidGeometryException $e) {
				$problems[] = sprintf('%s: %s', $row['name'], $e->getMessage());
				continue;
			}

			$key = $row['categorySlug'] . '/' . $row['subcategorySlug'];
			$row['subcategoryId'] = $subcategories[$key] ?? $fallback;
			$prepared[$externalId] = $row;
		}

		if ($problems !== []) {
			throw new InvalidImportException(sprintf(
				'%d registro(s) del archivo no se pueden importar y no se importó nada: %s',
				count($problems),
				implode('; ', array_slice($problems, 0, 3)) . (count($problems) > 3 ? '; …' : ''),
			));
		}

		return [$prepared, $unsupported];
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 */
	private function apply(
		Dataset $dataset,
		array $rows,
		int $duplicates,
		int $unsupported,
		?string $actor,
	): ImportBatch {
		// The batch is written BEFORE a single row is, and that ordering is the
		// point. Each row commits its own transaction, so a file that fails at row
		// 400 leaves 399 rows behind — and if the batch were only written at the
		// end, ADR-0009's one recovery path would not exist for precisely the
		// failure ADR-0009 was written about.
		$batch = new ImportBatch();
		$batch->setDatasetId((int)$dataset->getId());
		$batch->setActor($actor);
		$batch->setStartedAt(new DateTime());
		$batch->setRevisionFrom($this->revisions->current());
		$batch->setRevisionTo($this->revisions->current());
		$batch->setDuplicates($duplicates);
		$batch->setUnsupported($unsupported);
		$batch = $this->batches->insert($batch);

		$counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'unchanged' => 0];

		try {
			// Every Revision taken in here is stamped with this batch, so a revert
			// acts on exactly the rows the import touched and leaves a colleague's
			// concurrent edit alone.
			$this->revisions->duringImport(
				(int)$batch->getId(),
				// A long closure, not fn(): an arrow function captures by value, so
				// the counts applyRows() writes by reference would never come back.
				function () use ($dataset, $rows, &$counts): void {
					$this->applyRows($dataset, $rows, $counts);
				},
			);
		} finally {
			// Recorded even when the run threw, or the rows it did write would be
			// unrevertible.
			$batch->setCreated($counts['created']);
			$batch->setUpdated($counts['updated']);
			$batch->setSkipped($counts['skipped']);
			$batch->setUnchanged($counts['unchanged']);
			$batch->setRevisionTo($this->revisions->current());
			$this->batches->update($batch);
		}

		return $batch;
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @param array<string, int> $counts mutated as rows are applied
	 */
	private function applyRows(Dataset $dataset, array $rows, array &$counts): void {
		$existing = $this->features->findByDataset((int)$dataset->getId());

		foreach ($rows as $externalId => $row) {
			$provenance = new Provenance((int)$dataset->getId(), (string)$externalId, $this->official($row));
			$current = $existing[$externalId] ?? null;

			if ($current === null) {
				$this->featureService->create($this->input($row), $provenance);
				$counts['created']++;
				continue;
			}

			// ADR-0003, and retirement counts too: both are local decisions about
			// a record, and an import must not undo either. The official value is
			// still refreshed, so "what does the authority say now" stays current
			// and the override remains undoable.
			if ($current->getOverridden() || $current->getRetired()) {
				$this->refreshOfficial($current, $provenance);
				$counts['skipped']++;
				continue;
			}

			if (!$this->differs($current, $row, $provenance)) {
				$counts['unchanged']++;
				continue;
			}

			$this->featureService->update((int)$current->getId(), $this->input($row), $provenance);
			$counts['updated']++;
		}
	}

	/**
	 * Whether importing this row would change anything a person can see.
	 *
	 * Checked before writing so that re-importing an unchanged file produces no
	 * Revisions at all — otherwise every re-import would flood the change cursor
	 * with hundreds of "updates" that changed nothing, and history with them.
	 *
	 * @param array<string, mixed> $row
	 */
	private function differs(Feature $current, array $row, Provenance $provenance): bool {
		if ($current->getOfficial() !== $provenance->officialAsJson()) {
			return true;
		}

		$geometry = $row['geometry'] === null ? null : Geometry::fromArray($row['geometry'])->toJson();

		return $current->getName() !== $row['name']
			|| $current->getSubcategoryId() !== $row['subcategoryId']
			|| $current->getLocationQuality() !== $this->expectedQuality($row)
			|| $current->getGeometry() !== $geometry
			|| $current->getAddress() !== $row['address']
			|| $current->getPhone() !== $row['phone']
			|| $current->getEmail() !== $row['email']
			|| $current->getContactPerson() !== $row['contactPerson']
			|| $current->getContactRole() !== $row['contactRole']
			|| $current->getOpeningHours() !== $row['openingHours']
			|| $current->getNotes() !== $row['notes']
			|| $current->getSourceRef() !== $row['sourceRef'];
	}

	/**
	 * Keep the authority's latest values beside an override, without touching
	 * what the person wrote.
	 *
	 * Written only when it actually changed, and written WITHOUT taking a
	 * Revision. Nothing a person sees changes — the record still shows the local
	 * edit — so pushing it through the normal write path would spend a Revision,
	 * make every polling client refetch, and leave an "Actualizado" entry in
	 * history whose diff is empty.
	 */
	private function refreshOfficial(Feature $current, Provenance $provenance): void {
		if ($current->getOfficial() === $provenance->officialAsJson()) {
			return;
		}

		$this->features->storeOfficial($current, $provenance->officialAsJson());
	}

	/**
	 * The Location quality this row will end up with, so `differs()` compares
	 * like with like — FeatureService derives it rather than storing what the
	 * file said.
	 *
	 * @param array<string, mixed> $row
	 */
	private function expectedQuality(array $row): string {
		if ($row['geometry'] === null) {
			return LocationQuality::Absent->value;
		}

		return $row['locationQuality'] ?? LocationQuality::Exact->value;
	}

	/**
	 * What the authority published, kept verbatim for comparison against a local
	 * edit (ADR-0003).
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function official(array $row): array {
		$official = $row;
		unset($official['externalId'], $official['subcategoryId']);

		return $official;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function input(array $row): array {
		return [
			'name' => $row['name'],
			'subcategoryId' => $row['subcategoryId'],
			'geometry' => $row['geometry'],
			'locationQuality' => $row['locationQuality'],
			'address' => $row['address'],
			'phone' => $row['phone'],
			'email' => $row['email'],
			'contactPerson' => $row['contactPerson'],
			'contactRole' => $row['contactRole'],
			'openingHours' => $row['openingHours'],
			'notes' => $row['notes'],
			'sourceRef' => $row['sourceRef'],
		];
	}

	/** @return array<string, int> "categorySlug/subcategorySlug" → Subcategory id */
	private function subcategoryIndex(): array {
		$categorySlugs = [];
		foreach ($this->categories->findAll() as $category) {
			$categorySlugs[(int)$category->getId()] = $category->getSlug();
		}

		$index = [];
		foreach ($this->subcategories->findAll() as $subcategory) {
			$categorySlug = $categorySlugs[$subcategory->getCategoryId()] ?? null;
			if ($categorySlug !== null) {
				$index[$categorySlug . '/' . $subcategory->getSlug()] = (int)$subcategory->getId();
			}
		}

		return $index;
	}
}
