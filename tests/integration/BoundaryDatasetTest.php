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

use function Territorio\Tools\Boundary\toGeoJson;

require_once __DIR__ . '/../../tools/boundary-features.php';

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
	 * ADR-0007 warns that "the boundary network goes stale as OSM changes.
	 * Refreshing it is a re-import and must never move a Sector that has already
	 * been drawn."
	 *
	 * What this pins is the structural half of that: an import only ever walks
	 * its own Dataset, and a hand-drawn Sector belongs to none, so a refresh
	 * cannot reach it. It would fail if matching ever went wider than
	 * `findByDataset()` — and it is honest to say it proves no more than that. The
	 * harder half, a Sector whose Limit cites boundary features that have since
	 * moved, cannot exist until a Limit is stored (issue #15) and belongs to that
	 * slice.
	 */
	public function testARefreshCannotReachAHandDrawnSector(): void {
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

	/**
	 * The mark survives an import that does not mention it.
	 *
	 * This is the one that would hurt: a browser upload cannot declare `boundary`
	 * at all, and a refresh from the command line can forget `--boundary`. If
	 * either were read as "declared not a boundary", the comuna's eleven thousand
	 * lines would land on the map, in the Datos list and in every open browser's
	 * next poll — and the un-marking takes no Revision, so ADR-0009's revert could
	 * not undo it either.
	 */
	public function testAnImportThatSaysNothingCannotUnmarkTheDataset(): void {
		$this->importBoundaries();

		// Exactly what ImportController::upload() passes: no opinion on boundary.
		Server::get(ImportService::class)->importFile(
			self::boundaryFile(false), 'datos.geojson',
			self::SLUG,
			'Límites de prueba',
			'alguien',
		);

		self::assertTrue(
			(bool)Server::get(DatasetMapper::class)->findBySlug(self::SLUG)->getBoundary(),
			'an import that did not mention boundaries un-marked the Dataset',
		);

		// And the consequence that would follow, checked rather than assumed.
		$pushed = array_column(Server::get(FeatureService::class)->changesSince(0)['features'], 'id');
		foreach (Server::get(FeatureMapper::class)->findByDataset($this->datasetId()) as $boundary) {
			self::assertNotContains((int)$boundary->getId(), $pushed);
		}
	}

	/**
	 * The one read that asks for Boundary features rather than hiding them: the
	 * Sector editor's (issue #13). Everything else in this file checks they stay
	 * out of sight, so this checks the door they are allowed through.
	 */
	public function testTheEditorCanAskForTheOnesOnScreen(): void {
		$this->importBoundaries();
		$mine = array_map(
			static fn ($feature): int => (int)$feature->getId(),
			array_values(Server::get(FeatureMapper::class)->findByDataset($this->datasetId())),
		);

		// Membership, not totals: this runs against an instance that may already
		// hold a comuna's worth of streets, and a count would only measure those.
		$inView = $this->idsIn(-33.54, -70.61, -33.51, -70.57);
		foreach ($mine as $id) {
			self::assertContains($id, $inView);
		}

		// A box beside them holds neither.
		$elsewhere = $this->idsIn(-33.40, -70.40, -33.39, -70.39);
		foreach ($mine as $id) {
			self::assertNotContains($id, $elsewhere);
		}

		// Overlapping counts: the canal runs from -70.60 to -70.58 and this box
		// holds only the middle of it, but a line running off the edge of the view
		// is still one you can click the visible half of.
		self::assertNotEmpty(
			array_intersect($mine, $this->idsIn(-33.535, -70.59, -33.525, -70.585)),
			'a line crossing the box was not offered',
		);
	}

	/** Retired ones are gone from the editor too (ADR-0005). */
	public function testARetiredBoundaryIsNotOfferedToPick(): void {
		$this->importBoundaries();
		$one = (int)Server::get(FeatureMapper::class)->findByDataset($this->datasetId())['way/101']->getId();
		self::assertContains($one, $this->idsIn(-33.54, -70.61, -33.51, -70.57));

		Server::get(FeatureService::class)->retire($one);

		self::assertNotContains($one, $this->idsIn(-33.54, -70.61, -33.51, -70.57));
	}

	/** @return int[] */
	private function idsIn(float $south, float $west, float $north, float $east): array {
		return array_map(
			static fn ($feature): int => (int)$feature->getId(),
			Server::get(FeatureMapper::class)->findBoundariesIn($south, $west, $north, $east),
		);
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
		$batch = Server::get(ImportService::class)->importFile(
			self::boundaryFile($moved), 'datos.geojson',
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

	/**
	 * Built by the tool itself, from an invented Overpass answer, rather than
	 * hand-written to look like its output.
	 *
	 * That is the point of doing it this way: the tool and the reader are two
	 * halves of one contract — the id, the property names, the [lon, lat] order —
	 * and a fixture that imitates the tool would keep passing after the tool
	 * changed. This is the only place the two meet.
	 */
	private static function boundaryFile(bool $moved): string {
		$street = $moved
			? [['lat' => -33.52, 'lon' => -70.60], ['lat' => -33.52, 'lon' => -70.585]]
			: [['lat' => -33.52, 'lon' => -70.60], ['lat' => -33.52, 'lon' => -70.59]];

		return json_encode(
			toGeoJson([
				'elements' => [
					[
						'type' => 'way',
						'id' => 101,
						'tags' => ['highway' => 'residential', 'name' => 'Calle de Prueba'],
						'geometry' => $street,
					],
					[
						'type' => 'way',
						'id' => 102,
						'tags' => ['waterway' => 'canal', 'name' => 'Canal de Prueba'],
						'geometry' => [
							['lat' => -33.53, 'lon' => -70.60],
							['lat' => -33.53, 'lon' => -70.58],
						],
					],
				],
			], 'prueba'),
			JSON_THROW_ON_ERROR,
		);
	}
}
