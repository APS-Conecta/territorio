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
 * Brings a file into a Dataset, once or repeatedly.
 *
 * GeoJSON or KML — the format decides only which reader runs. Both produce the
 * same rows, so the batch, the matching, the local-override rule and the revert
 * are one implementation and cannot drift apart between the two.
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


	public function __construct(
		private GeoJsonReader $reader,
		private KmlReader $kml,
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
	 * Import a file, reading it according to what it is.
	 *
	 * The name is used only to choose the reader — everything downstream is the
	 * same batch, the same matching and the same revert, whichever format it
	 * arrived in (issue #8).
	 *
	 * @throws InvalidImportException
	 */
	public function importFile(
		string $contents,
		string $filename,
		string $datasetSlug,
		?string $label = null,
		?string $actor = null,
		?bool $boundary = null,
	): ImportBatch {
		return self::isKml($filename)
			? $this->importKml($contents, $datasetSlug, $label, $actor, $boundary)
			: $this->importGeoJson($contents, $datasetSlug, $label, $actor, $boundary);
	}

	/** Whether this filename says KML, whatever case it was typed in. */
	private static function isKml(string $filename): bool {
		return str_ends_with(strtolower(trim($filename)), '.kml');
	}

	/**
	 * @throws InvalidImportException
	 */
	public function importKml(
		string $xml,
		string $datasetSlug,
		?string $label = null,
		?string $actor = null,
		?bool $boundary = null,
	): ImportBatch {
		return $this->importRows(
			$this->kml->read($xml, $datasetSlug),
			$datasetSlug,
			$label,
			$actor,
			$boundary,
		);
	}

	public function importGeoJson(
		string $json,
		string $datasetSlug,
		?string $label = null,
		?string $actor = null,
		?bool $boundary = null,
	): ImportBatch {
		return $this->importRows(
			$this->reader->read($json, $datasetSlug),
			$datasetSlug,
			$label,
			$actor,
			$boundary,
		);
	}

	/**
	 * Everything after the file has been read, which is everything both formats
	 * share.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @throws InvalidImportException
	 */
	private function importRows(
		array $rows,
		string $datasetSlug,
		?string $label,
		?string $actor,
		?bool $boundary,
	): ImportBatch {
		[$rows, $duplicates] = $this->collapseDuplicates($rows);
		[$prepared, $unsupported] = $this->prepare($rows);

		// The Dataset is created only once the whole file has been read and
		// checked. Creating it first left a Dataset behind after a refused
		// import, which made "imports nothing" not quite true.
		$dataset = $this->datasets->findOrCreate(
			$datasetSlug,
			$label ?? $datasetSlug,
			Origin::Cadastral,
			$boundary,
		);

		return $this->apply($dataset, $prepared, $duplicates, $unsupported, $actor);
	}

	/**
	 * The recent runs, as the import dialog lists them.
	 *
	 * The Dataset's name is joined on here rather than shipped by the batch: a
	 * batch knows an id, and "Importación #3 en 7" tells a person nothing.
	 *
	 * This lives in the service rather than the controller so that an OCS
	 * controller later is a new file rather than a second copy of the join
	 * (issue #18).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function recentBatches(): array {
		$datasets = $this->datasets->allById();

		return array_map(
			function (ImportBatch $batch) use ($datasets): array {
				// ?? not ?->: a missing key is an undefined-index warning before
				// the nullsafe operator ever gets a chance.
				$dataset = $datasets[$batch->getDatasetId()] ?? null;

				return $batch->toClient() + [
					'dataset' => $dataset?->getLabel() ?? 'conjunto eliminado',
					'undone' => $this->batches->isUndone((int)$batch->getId()),
				];
			},
			$this->batches->findRecent(),
		);
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
			if ($row['geometry'] !== null && !Geometry::supports($row['geometry']['type'] ?? null)) {
				// Read, understood, and counted rather than imported. Counting is
				// the point: one MultiPolygon in a cadastre of thousands must not
				// make the file an all-or-nothing problem, and the batch says how
				// many rows were left behind.
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

			$row['subcategoryId'] = $this->classify($row, $subcategories, $fallback);
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

	/**
	 * Where a row lands in the taxonomy.
	 *
	 * The named pair first. Then — only when the file named a Category and NO
	 * Subcategory — that Category's own "Otro". That case is KML (issue #8): a
	 * Google MyMaps export names a Folder and nothing finer, and matching on the
	 * pair alone would send every record in the file to Sin clasificar while the
	 * Folder sat there naming the right Category.
	 *
	 * Deliberately not applied when the file DID name a Subcategory and this
	 * install has no such thing. Falling to that Category's Otro would be a guess
	 * about what was meant, and it would also change where existing records live:
	 * `differs()` compares the Subcategory, so re-importing an unchanged GeoJSON
	 * would quietly move rows out of Sin clasificar and spend a Revision doing it.
	 * Sin clasificar · Otro says the honest thing — the file named something this
	 * install does not have.
	 *
	 * @param array<string, mixed> $row
	 * @param array<string, int> $subcategories
	 */
	private function classify(array $row, array $subcategories, int $fallback): int {
		$category = $row['categorySlug'];
		if ($category === null) {
			return $fallback;
		}

		if ($row['subcategorySlug'] === null) {
			return $subcategories[$category . '/otro']
				?? $this->asSubcategory($category, $subcategories)
				?? $fallback;
		}

		return $subcategories[$category . '/' . $row['subcategorySlug']] ?? $fallback;
	}

	/**
	 * The name of a Subcategory, where a file named one and called it a Category.
	 *
	 * Not every MyMaps folder is a Category. Two of the thirteen in the real La
	 * Florida export are Subcategory-level — "Ciclovías" over 39 placemarks and
	 * "Plazas y parques" over 270 — so matching Categories alone left 309 of 865
	 * records, 36% of the file, in Sin clasificar while `ciclovia` and `plaza`
	 * sat in the seed.
	 *
	 * ponytail: exact match first, then each word with a Spanish plural taken off
	 * — enough for "ciclovias" → `ciclovia` and "plazas_parques" → `plaza`, and
	 * checked against those two real folder names. It is a heuristic and it will
	 * miss something eventually; a miss costs Sin clasificar, which is where the
	 * row was going anyway. Match on the Subcategory's label when that is not
	 * good enough.
	 *
	 * @param array<string, int> $subcategories keyed "categorySlug/subcategorySlug"
	 */
	private function asSubcategory(string $named, array $subcategories): ?int {
		$bySlug = [];
		foreach ($subcategories as $key => $id) {
			$bySlug[substr($key, (int)strpos($key, '/') + 1)] ??= $id;
		}

		foreach ([$named, ...explode('_', $named)] as $word) {
			foreach ([$word, preg_replace('/(es|s)$/', '', $word)] as $candidate) {
				if ($candidate !== '' && isset($bySlug[$candidate])) {
					return $bySlug[$candidate];
				}
			}
		}

		return null;
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
