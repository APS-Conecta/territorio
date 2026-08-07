<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\ImportService;
use OCA\Territorio\Service\Kind;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Boundary features are in the Registry and out of the way.
 *
 * They import through the same pipeline as any Cadastral Dataset — same batch,
 * same external ids, same revert — and then have to be absent from every read
 * that feeds a screen. A comuna is around eleven thousand of them; one that
 * leaks into the map, the Datos list or the change feed is not a cosmetic
 * problem.
 */
final class BoundaryDatasetTest extends IntegrationTestCase {
	private const SLUG = 'limites-prueba';

	/** @var int[] datasets this test made */
	private array $datasets = [];

	protected function tearDown(): void {
		// The features first, through the shared cleanup, then the Datasets they
		// pointed at — a Dataset row left behind would make the next run's
		// findOrCreate return a boundary Dataset nobody asked for.
		parent::tearDown();

		if ($this->datasets !== []) {
			$db = Server::get(IDBConnection::class);
			foreach ($this->datasets as $id) {
				$qb = $db->getQueryBuilder();
				$qb->delete('territorio_import')
					->where($qb->expr()->eq('dataset_id', $qb->createNamedParameter($id)))
					->executeStatement();

				$qb = $db->getQueryBuilder();
				$qb->delete('territorio_dataset')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
					->executeStatement();
			}
			$this->datasets = [];
		}
	}

	public function testBoundaryFeaturesAreStoredAsRoutesWithTheirOsmIds(): void {
		$this->importBoundaries();

		$stored = Server::get(FeatureMapper::class)->findByDataset($this->datasetId());

		self::assertCount(2, $stored);
		self::assertArrayHasKey('way/101', $stored, 'the id from the file, not a derived digest');
		self::assertSame('Calle de Prueba', $stored['way/101']->getName());
		self::assertSame(Kind::Route->value, $stored['way/101']->getKind());
	}

	/**
	 * The criterion this whole slice turns on: they are in the Registry and on no
	 * screen. `findAll` feeds the map, the Datos list and the Análisis counts
	 * alike, so one check covers all three.
	 */
	public function testTheyAreOnNoOrdinaryScreen(): void {
		$visible = $this->place('Plaza visible');
		$this->importBoundaries();

		$ids = array_map(
			static fn ($feature): int => (int)$feature->getId(),
			Server::get(FeatureService::class)->listAll(),
		);

		self::assertContains($visible, $ids);
		foreach (Server::get(FeatureMapper::class)->findByDataset($this->datasetId()) as $boundary) {
			self::assertNotContains((int)$boundary->getId(), $ids, 'a boundary reached the map');
		}
	}

	/**
	 * And not through the change feed either. This is the read that would hurt
	 * most: importing a comuna moves the cursor by eleven thousand, and every open
	 * map polls it every five seconds.
	 */
	public function testTheyAreNotPushedToOpenMaps(): void {
		$before = Server::get(FeatureService::class)->changesSince(0)['revision'];
		$this->importBoundaries();

		$changes = Server::get(FeatureService::class)->changesSince($before);

		self::assertGreaterThan($before, $changes['revision'], 'the cursor still moved');
		self::assertSame([], $changes['features'], 'nothing for a client to draw');
	}

	/** The recovery screen is for records a person retired, not for streets. */
	public function testARetiredBoundaryDoesNotFillTheRecoveryScreen(): void {
		$this->importBoundaries();
		$boundary = Server::get(FeatureMapper::class)->findByDataset($this->datasetId())['way/101'];
		Server::get(FeatureService::class)->retire((int)$boundary->getId());

		$retired = array_map(
			static fn ($feature): int => (int)$feature->getId(),
			Server::get(FeatureService::class)->listRetired(),
		);

		self::assertNotContains((int)$boundary->getId(), $retired);
	}

	/**
	 * ADR-0007's warning, made a test: "the boundary network goes stale as OSM
	 * changes. Refreshing it is a re-import and must never move a Sector that has
	 * already been drawn."
	 */
	public function testReimportingLeavesADrawnSectorExactlyWhereItWas(): void {
		$sector = $this->sector();
		$before = Server::get(FeatureMapper::class)->find($sector);

		$this->importBoundaries();
		// Twice, with a street moved upstream in between: the second run is the
		// refresh a clinic would actually do months later.
		$this->importBoundaries(moved: true);

		$after = Server::get(FeatureMapper::class)->find($sector);

		self::assertSame($before->getGeometry(), $after->getGeometry());
		self::assertSame($before->getRevision(), $after->getRevision(), 'the Sector was not even touched');
		self::assertCount(1, Server::get(FeatureService::class)->history($sector), 'created, and nothing since');
	}

	/** Re-importing the same file changes nothing and spends no Revisions. */
	public function testAnUnchangedRefreshIsRecognisedRatherThanRewritten(): void {
		$this->importBoundaries();
		$batch = $this->importBoundaries();

		self::assertSame(0, $batch->getCreated());
		self::assertSame(0, $batch->getUpdated());
		self::assertSame(2, $batch->getUnchanged());
	}

	/** A street redrawn upstream updates the record it already has. */
	public function testAMovedStreetUpdatesRatherThanDuplicates(): void {
		$this->importBoundaries();
		$batch = $this->importBoundaries(moved: true);

		self::assertSame(0, $batch->getCreated());
		self::assertSame(1, $batch->getUpdated());
		self::assertCount(2, Server::get(FeatureMapper::class)->findByDataset($this->datasetId()));
	}

	private function importBoundaries(bool $moved = false): \OCA\Territorio\Db\ImportBatch {
		$batch = Server::get(ImportService::class)->importGeoJson(
			self::boundaryFile($moved),
			self::SLUG,
			'Límites de prueba',
			'prueba',
			boundary: true,
		);

		$this->datasets[] = $this->datasetId();
		foreach (Server::get(FeatureMapper::class)->findByDataset($this->datasetId()) as $feature) {
			$this->created[] = (int)$feature->getId();
		}

		return $batch;
	}

	private function datasetId(): int {
		return (int)Server::get(DatasetMapper::class)->findBySlug(self::SLUG)->getId();
	}

	/** A hand-drawn Sector, as issue #10 saves one. */
	private function sector(): int {
		$subcategoryId = 0;
		foreach (Server::get(SubcategoryMapper::class)->findAll() as $subcategory) {
			if ($subcategory->getSlug() === 'sector') {
				$subcategoryId = (int)$subcategory->getId();
			}
		}

		$id = (int)Server::get(FeatureService::class)->create([
			'name' => 'Sector de prueba',
			'subcategoryId' => $subcategoryId,
			'colour' => '#16a34a',
			'geometry' => ['type' => 'Polygon', 'coordinates' => [[
				[-70.60, -33.53], [-70.57, -33.53], [-70.57, -33.51], [-70.60, -33.53],
			]]],
		])->getId();
		$this->created[] = $id;

		return $id;
	}

	/** Invented, not taken from any real extract — the shape is what matters. */
	private static function boundaryFile(bool $moved): string {
		$street = $moved
			? [[-70.60, -33.52], [-70.585, -33.52]]
			: [[-70.60, -33.52], [-70.59, -33.52]];

		return json_encode([
			'type' => 'FeatureCollection',
			'features' => [
				[
					'type' => 'Feature',
					'id' => 'way/101',
					'properties' => [
						'name' => 'Calle de Prueba',
						'category' => 'sin_clasificar',
						'subcategory' => 'otro',
						'source_es' => 'OpenStreetMap',
						'source_url' => 'https://www.openstreetmap.org/way/101',
					],
					'geometry' => ['type' => 'LineString', 'coordinates' => $street],
				],
				[
					'type' => 'Feature',
					'id' => 'way/102',
					'properties' => [
						'name' => 'Canal de Prueba',
						'category' => 'sin_clasificar',
						'subcategory' => 'otro',
						'source_es' => 'OpenStreetMap',
						'source_url' => 'https://www.openstreetmap.org/way/102',
					],
					'geometry' => [
						'type' => 'LineString',
						'coordinates' => [[-70.60, -33.53], [-70.58, -33.53]],
					],
				],
			],
		], JSON_THROW_ON_ERROR);
	}
}
