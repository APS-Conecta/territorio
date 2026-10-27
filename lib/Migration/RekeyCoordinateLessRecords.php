<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use OCA\Territorio\Service\GeoJsonReader;
use OCP\AppFramework\Db\TTransactional;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Carries each record imported under the id #202 collapsed onto to the id
 * ADR-0022 derives, once, so the next import of its file matches it instead of
 * creating the whole set beside it and stranding its Local override (ADR-0003).
 *
 * Until 0.80.1 a row with no id and no coordinate was identified by its name
 * alone, `<source>:sha1(name|sin-coordenadas)`. A record is re-keyed only when
 * its stored id is exactly that digest of its own official name AND its
 * official value has no coordinate: a legacy-looking explicit uid on a record
 * that has one is the file's own id, and moving it would make that file create
 * a duplicate. The new id comes from `GeoJsonReader::derivedId()`, the import's
 * own derivation, so the two cannot disagree.
 *
 * Only `external_id` changes. Nothing a person sees moves, so no Revision is
 * taken and the cursor stays (the reasoning `FeatureMapper::storeOfficial()`
 * gives). A record whose new id another record of its Dataset already holds (an
 * import that ran before this step, or a pre-upgrade export re-imported after
 * it) is skipped and counted, not allowed to fail the upgrade on the unique
 * index.
 *
 * Idempotent: a re-keyed id no longer matches the legacy digest, so the second
 * run, and every upgrade after it, changes nothing.
 *
 * Registered for both `install` and `post-migration` in `appinfo/info.xml`,
 * beside EnsureSeedData, which explains why a repair step and not a migration.
 */
class RekeyCoordinateLessRecords implements IRepairStep {
	use TTransactional;

	private const TABLE = 'territorio_feature';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function getName(): string {
		return 'Territorio: re-key coordinate-less records to their name and address';
	}

	public function run(IOutput $output): void {
		[$rekeyed, $skipped] = $this->atomic(function (): array {
			$rekeyed = 0;
			$skipped = 0;
			foreach ($this->legacyRecords() as [$id, $datasetId, $newId]) {
				if ($this->taken($datasetId, $newId)) {
					$skipped++;
					continue;
				}
				$this->rekey($id, $newId);
				$rekeyed++;
			}

			return [$rekeyed, $skipped];
		}, $this->db);

		if ($rekeyed > 0) {
			$output->info(sprintf('Territorio: re-keyed %d coordinate-less records (ADR-0022)', $rekeyed));
		}
		if ($skipped > 0) {
			$output->warning(sprintf(
				'Territorio: skipped %d coordinate-less records whose new id another record of their Dataset already holds',
				$skipped,
			));
		}
	}

	/**
	 * Every record still filed under its legacy id, with the id it moves to.
	 *
	 * Collected before the first UPDATE, so no write runs under an open cursor.
	 *
	 * ponytail: reads every imported record's official value on each upgrade;
	 * cheap at one comuna's catastro, filter in SQL if a national one makes it slow.
	 *
	 * @return list<array{0: int, 1: int, 2: string}> feature id, Dataset id, new external id
	 */
	private function legacyRecords(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('id', 'dataset_id', 'external_id', 'official')
			->from(self::TABLE)
			->where($qb->expr()->isNotNull('external_id'))
			->andWhere($qb->expr()->isNotNull('official'))
			->executeQuery();

		$records = [];
		while (($row = $result->fetch()) !== false) {
			$newId = $this->newId((string)$row['external_id'], (string)$row['official']);
			if ($newId !== null) {
				$records[] = [(int)$row['id'], (int)$row['dataset_id'], $newId];
			}
		}
		$result->closeCursor();

		return $records;
	}

	/** The ADR-0022 id of a record filed under its legacy id, or null when it is not one. */
	private function newId(string $stored, string $officialJson): ?string {
		$official = json_decode($officialJson, true);
		if (!is_array($official) || !is_string($official['name'] ?? null)) {
			return null;
		}
		// The test position() makes: a coordinate-bearing id never changed.
		if (is_array($official['geometry']['coordinates'] ?? null)) {
			return null;
		}

		$colon = strrpos($stored, ':');
		if ($colon === false) {
			return null;
		}
		$source = substr($stored, 0, $colon);
		if ($stored !== GeoJsonReader::derivedId($source, $official['name'], null, null, null)) {
			return null;
		}

		$newId = GeoJsonReader::derivedId(
			$source,
			$official['name'],
			null,
			self::stringOrNull($official['address'] ?? null),
			self::stringOrNull($official['notes'] ?? null),
		);

		// No address and no notes: the derivation is unchanged, nothing to move.
		return $newId === $stored ? null : $newId;
	}

	/** The reader stored these already trimmed, or null where blank (`GeoJsonReader::text()`). */
	private static function stringOrNull(mixed $value): ?string {
		return is_string($value) ? $value : null;
	}

	private function taken(int $datasetId, string $externalId): bool {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('id')
			->from(self::TABLE)
			->where($qb->expr()->eq('dataset_id', $qb->createNamedParameter($datasetId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('external_id', $qb->createNamedParameter($externalId)))
			->setMaxResults(1)
			->executeQuery();
		$taken = $result->fetch() !== false;
		$result->closeCursor();

		return $taken;
	}

	private function rekey(int $id, string $externalId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('external_id', $qb->createNamedParameter($externalId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
