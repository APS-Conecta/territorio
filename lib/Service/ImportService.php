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
 *  - Every run records the Revision range it produced (ADR-0009), because that
 *    is the only thing that makes it revertible and it cannot be worked out
 *    afterwards.
 *
 * Nothing is written until every row has been read, resolved and checked. A file
 * that is wrong anywhere imports nothing at all — a half-imported catastro is
 * far harder to reason about than one that was refused.
 */
class ImportService {
	/** Where a row goes when its Category or Subcategory is not one we hold. */
	private const FALLBACK = ['sin_clasificar', 'otro'];

	/** Only Places are drawn yet; Zones and Routes arrive with issue #10. */
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
		$dataset = $this->datasets->findOrCreate(
			$datasetSlug,
			$label ?? $datasetSlug,
			Origin::Cadastral,
		);

		[$rows, $duplicates] = $this->collapseDuplicates($rows);
		[$prepared, $unsupported] = $this->prepare($rows);

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
				// Read, understood, and not imported: a Zone would land on the map
				// as a pin on an arbitrary vertex until issue #10 draws it properly.
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
		$existing = $this->features->findByDataset((int)$dataset->getId());
		$revisionFrom = $this->revisions->current();
		$startedAt = new DateTime();

		$created = $updated = $skipped = $unchanged = 0;

		foreach ($rows as $externalId => $row) {
			$provenance = new Provenance((int)$dataset->getId(), (string)$externalId, $this->official($row));
			$current = $existing[$externalId] ?? null;

			if ($current === null) {
				$this->featureService->create($this->input($row), $provenance);
				$created++;
				continue;
			}

			// ADR-0003, and retirement counts too: both are local decisions about
			// a record, and an import must not undo either. The official value is
			// still refreshed, so "what does the authority say now" stays current
			// and the override remains undoable.
			if ($current->getOverridden() || $current->getRetired()) {
				$this->refreshOfficial($current, $provenance);
				$skipped++;
				continue;
			}

			if (!$this->differs($current, $row, $provenance)) {
				$unchanged++;
				continue;
			}

			$this->featureService->update((int)$current->getId(), $this->input($row), $provenance);
			$updated++;
		}

		$batch = new ImportBatch();
		$batch->setDatasetId((int)$dataset->getId());
		$batch->setActor($actor);
		$batch->setStartedAt($startedAt);
		$batch->setRevisionFrom($revisionFrom);
		$batch->setRevisionTo($this->revisions->current());
		$batch->setCreated($created);
		$batch->setUpdated($updated);
		$batch->setSkipped($skipped);
		$batch->setDuplicates($duplicates);
		$batch->setUnsupported($unsupported);
		$batch->setUnchanged($unchanged);

		return $this->batches->insert($batch);
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
	 * Written only when it actually changed, so re-importing an unchanged file
	 * against an overridden record still writes nothing.
	 */
	private function refreshOfficial(Feature $current, Provenance $provenance): bool {
		if ($current->getOfficial() === $provenance->officialAsJson()) {
			return false;
		}

		$current->setOfficial($provenance->officialAsJson());
		$this->features->update($current);

		return true;
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
