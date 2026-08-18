<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\ImportBatch;
use OCA\Territorio\Service\ImportService;
use OCA\Territorio\Service\RevertService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;

/**
 * What a revert has to do about the assignments the batch caused (issue #30).
 *
 * ADR-0009 makes a revert the only recovery path there is, and ADR-0008 makes an
 * assignment derived from geometry rather than stored by hand. Put together:
 * importing a boundary reassigns every Place it covers, so undoing that import
 * has to let those Places go again. Nothing tested the two together, and both
 * ways of getting it wrong are silent — the Registry keeps pointing at a
 * boundary nobody can see, and the revert reports success.
 */
final class RevertAssignmentTest extends IntegrationTestCase {
	private const DATASET = 'test-revert-assign';

	private ImportService $import;
	private RevertService $revert;
	private FeatureMapper $features;

	/** A square around (-70.58, -33.52), where IntegrationTestCase::place() puts things. */
	private const SECTOR_RING = '[[[-70.59,-33.53],[-70.57,-33.53],[-70.57,-33.51],[-70.59,-33.51],[-70.59,-33.53]]]';

	protected function setUp(): void {
		$this->import = Server::get(ImportService::class);
		$this->revert = Server::get(RevertService::class);
		$this->features = Server::get(FeatureMapper::class);
		$this->forgetDataset();
	}

	protected function tearDown(): void {
		$this->forgetDataset();
		parent::tearDown();
	}

	private function forgetDataset(): void {
		$db = Server::get(IDBConnection::class);
		try {
			$id = (int)Server::get(DatasetMapper::class)->findBySlug(self::DATASET)->getId();
		} catch (\Throwable) {
			return;
		}

		foreach ($this->features->findByDataset($id) as $feature) {
			$qb = $db->getQueryBuilder();
			$qb->delete('territorio_revision')
				->where($qb->expr()->eq('feature_id', $qb->createNamedParameter(
					(int)$feature->getId(), IQueryBuilder::PARAM_INT,
				)))->executeStatement();
		}
		foreach (['territorio_feature', 'territorio_import'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('dataset_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('territorio_dataset')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function importSector(string $uid = 'ra:sector'): ImportBatch {
		$json = <<<JSON
		{"type":"FeatureCollection","features":[
		  {"type":"Feature","geometry":{"type":"Polygon","coordinates":{$this->ring()}},
		   "properties":{"uid":"{$uid}","name":"Sector de prueba",
		     "category":"division_territorial","subcategory":"sector"}}
		]}
		JSON;

		return $this->import->importFile($json, 'datos.geojson', self::DATASET, 'Prueba', 'tester');
	}

	private function ring(): string {
		return self::SECTOR_RING;
	}

	private function reload(int $id): \OCA\Territorio\Db\Feature {
		return $this->features->find($id);
	}

	/**
	 * The registry-wide one. A Place assigned by an imported boundary must not be
	 * left holding that boundary's id once the import is undone — the boundary is
	 * retired, so every read that resolves the assignment will refuse to show it,
	 * and the sidebar says "sin asignar" about a record the database says is
	 * assigned.
	 */
	public function testRevertingAnImportedBoundaryLetsGoOfWhatItClaimed(): void {
		$placeId = $this->place('Plaza dentro del sector ' . uniqid());

		$batch = $this->importSector();
		$datasetId = (int)Server::get(DatasetMapper::class)->findBySlug(self::DATASET)->getId();
		$sectorId = (int)$this->features->findByDataset($datasetId)['ra:sector']->getId();

		self::assertSame(
			$sectorId,
			$this->reload($placeId)->getSectorId(),
			'importing the boundary should have claimed the Place',
		);

		$this->revert->revert((int)$batch->getId(), 'tester');

		// Asserted as an invariant, not as a restored value. What the Place points
		// at afterwards depends on which other boundaries cover it, and a shared
		// instance gains and loses those constantly — an earlier version of this
		// test captured the previous id and failed when the test that had created
		// THAT Sector tore it down. The property the bug violates does not depend
		// on any of it: a revert must never leave a record pointing at a boundary
		// it has just retired.
		$after = $this->reload($placeId)->getSectorId();
		self::assertNotSame($sectorId, $after, 'the Place still points at the Sector the revert retired');

		if ($after !== null) {
			self::assertFalse(
				(bool)$this->reload((int)$after)->getRetired(),
				'the Place points at a retired boundary, which no read will resolve',
			);
		}
	}

	/**
	 * The same duty on the update path.
	 *
	 * Re-importing a boundary with a different shape is an ordinary Updated
	 * entry, and undoing it puts the old shape back — which moves the line. What
	 * falls inside a boundary is derived from that line (ADR-0008), so restoring
	 * the geometry and leaving the assignments where they were produces exactly
	 * the state ADR-0008 exists to prevent. `revertTo()` wrote the geometry and
	 * skipped the reassignment.
	 */
	public function testRevertingARedrawnBoundaryReassignsWhatItNoLongerCovers(): void {
		$placeId = $this->place('Plaza fuera y luego dentro ' . uniqid());

		// A square nowhere near the Place.
		$elsewhere = '[[[-70.50,-33.45],[-70.49,-33.45],[-70.49,-33.44],[-70.50,-33.44],[-70.50,-33.45]]]';
		$small = <<<JSON
		{"type":"FeatureCollection","features":[
		  {"type":"Feature","geometry":{"type":"Polygon","coordinates":{$elsewhere}},
		   "properties":{"uid":"ra:sector","name":"Sector de prueba",
		     "category":"division_territorial","subcategory":"sector"}}
		]}
		JSON;

		$this->import->importFile($small, 'datos.geojson', self::DATASET, 'Prueba', 'tester');
		$datasetId = (int)Server::get(DatasetMapper::class)->findBySlug(self::DATASET)->getId();
		$sectorId = (int)$this->features->findByDataset($datasetId)['ra:sector']->getId();
		self::assertNotSame($sectorId, $this->reload($placeId)->getSectorId(), 'it is nowhere near yet');

		// Redraw it over the Place. Same uid, so this is an update, not a create.
		$grown = $this->importSector();
		self::assertSame(1, $grown->getUpdated(), 'the boundary should have been redrawn, not recreated');
		self::assertSame(
			$sectorId,
			$this->reload($placeId)->getSectorId(),
			'growing the boundary over the Place should have claimed it',
		);

		$this->revert->revert((int)$grown->getId(), 'tester');

		self::assertNotSame(
			$sectorId,
			$this->reload($placeId)->getSectorId(),
			'the boundary shrank back off the Place, but the Place still says it is inside',
		);
	}

	/**
	 * A batch that touched one record twice has to be undone twice.
	 *
	 * One import holding a Place and then a boundary covering it gives the Place
	 * two Revisions in the same batch: Created, and then Updated when the boundary
	 * claims it. Undoing the newest writes a Revision stamped with the revert's
	 * own batch — so unless the walk knows that batch is its own, the older entry
	 * looks like somebody else's work and is skipped. The record then stays on the
	 * map, reported as "omitido", which reads as deliberately protected.
	 */
	public function testABatchThatTouchedARecordTwiceIsFullyUndone(): void {
		$json = <<<JSON
		{"type":"FeatureCollection","features":[
		  {"type":"Feature","geometry":{"type":"Point","coordinates":[-70.58,-33.52]},
		   "properties":{"uid":"ra:place","name":"Plaza del lote"}},
		  {"type":"Feature","geometry":{"type":"Polygon","coordinates":{$this->ring()}},
		   "properties":{"uid":"ra:boundary","name":"Sector del lote",
		     "category":"division_territorial","subcategory":"sector"}}
		]}
		JSON;

		$batch = $this->import->importFile($json, 'datos.geojson', self::DATASET, 'Prueba', 'tester');
		self::assertSame(2, $batch->getCreated());

		$datasetId = (int)Server::get(DatasetMapper::class)->findBySlug(self::DATASET)->getId();
		$before = $this->features->findByDataset($datasetId);
		self::assertNotNull($before['ra:place']->getSectorId(), 'the boundary should have claimed it');

		$revert = $this->revert->revert((int)$batch->getId(), 'tester');

		$after = $this->features->findByDataset($datasetId);
		self::assertTrue(
			(bool)$after['ra:place']->getRetired(),
			'the Place the batch created is still on the map after undoing that batch',
		);
		self::assertTrue((bool)$after['ra:boundary']->getRetired(), 'the boundary should be gone too');
		self::assertSame(0, $revert->getSkipped(), 'nothing here belonged to anybody else');
	}
}
