<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\ImportBatch;
use OCA\Territorio\Db\ImportBatchMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Service\ImportService;
use OCA\Territorio\Service\RevertService;
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
	private ImportBatchMapper $batches;

	/** A square around (-70.58, -33.52), where IntegrationTestCase::place() puts things. */
	private const SECTOR_RING = '[[[-70.59,-33.53],[-70.57,-33.53],[-70.57,-33.51],[-70.59,-33.51],[-70.59,-33.53]]]';

	/** An L beside that square, around (-70.56, -33.50): its bounding box covers
	 *  (-70.58, -33.52), its polygon does not. */
	private const UV_L_RING = '[[[-70.59,-33.51],[-70.59,-33.49],[-70.55,-33.49],[-70.55,-33.53],[-70.57,-33.53],[-70.57,-33.51],[-70.59,-33.51]]]';

	protected function setUp(): void {
		$this->import = Server::get(ImportService::class);
		$this->revert = Server::get(RevertService::class);
		$this->features = Server::get(FeatureMapper::class);
		$this->batches = Server::get(ImportBatchMapper::class);
		$this->forgetDataset(self::DATASET);
	}

	protected function tearDown(): void {
		$this->forgetDataset(self::DATASET);
		parent::tearDown();
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

	/** A Place and then a Sector that covers it, in one batch. */
	private function importPlaceAndSector(): ImportBatch {
		$json = <<<JSON
		{"type":"FeatureCollection","features":[
		  {"type":"Feature","geometry":{"type":"Point","coordinates":[-70.58,-33.52]},
		   "properties":{"uid":"ra:place","name":"Plaza del lote"}},
		  {"type":"Feature","geometry":{"type":"Polygon","coordinates":{$this->ring()}},
		   "properties":{"uid":"ra:boundary","name":"Sector del lote",
		     "category":"division_territorial","subcategory":"sector"}}
		]}
		JSON;

		return $this->import->importFile($json, 'datos.geojson', self::DATASET, 'Prueba', 'tester');
	}

	/**
	 * Two adjacent Unidades Vecinales in one batch: ra:uv1 is the square, ra:uv2
	 * the L beside it, whose box reaches over ra:uv1's Place. With $place, a Place
	 * inside ra:uv1 comes first, so it is the batch-end sweep that assigns it.
	 */
	private function importUnidadesVecinales(bool $place = false): ImportBatch {
		$first = $place
			? '{"type":"Feature","geometry":{"type":"Point","coordinates":[-70.58,-33.52]},'
				. '"properties":{"uid":"ra:place","name":"Plaza del lote"}},'
			: '';
		$ring = self::UV_L_RING;
		$json = <<<JSON
		{"type":"FeatureCollection","features":[{$first}
		  {"type":"Feature","geometry":{"type":"Polygon","coordinates":{$this->ring()}},
		   "properties":{"uid":"ra:uv1","name":"Unidad Vecinal cuadrada",
		     "category":"division_territorial","subcategory":"unidad_vecinal"}},
		  {"type":"Feature","geometry":{"type":"Polygon","coordinates":{$ring}},
		   "properties":{"uid":"ra:uv2","name":"Unidad Vecinal en L",
		     "category":"division_territorial","subcategory":"unidad_vecinal"}}
		]}
		JSON;

		return $this->import->importFile($json, 'datos.geojson', self::DATASET, 'Prueba', 'tester');
	}

	/** @return list<int> the newest batch ids, newest first */
	private function recentBatchIds(int $limit): array {
		return array_map(static fn (ImportBatch $batch): int => (int)$batch->getId(), $this->batches->findRecent($limit));
	}

	private function stored(string $uid): \OCA\Territorio\Db\Feature {
		$datasetId = (int)Server::get(DatasetMapper::class)->findBySlug(self::DATASET)->getId();

		return $this->features->findByDataset($datasetId)[$uid];
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
		$batch = $this->importPlaceAndSector();
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

	/**
	 * A revert sweeps its boundaries once, at the end of its walk, inside its own
	 * batch (#200).
	 *
	 * Swept per boundary instead, retiring ra:uv2 handed the Place back to ra:uv1,
	 * still live, and retiring ra:uv1 let it go again, each under a «reasignación
	 * de límite» batch of its own. The walk then reached the Place's Created entry,
	 * found that sweep's write on it, took it for a colleague's and skipped it: the
	 * import read «deshecha» with the Place still on the map.
	 */
	public function testUndoingAnImportOfAPlaceAndTwoAdjacentUnidadesVecinalesRetiresThePlace(): void {
		$batch = $this->importUnidadesVecinales(place: true);
		self::assertSame(3, $batch->getCreated());
		self::assertSame(
			(int)$this->stored('ra:uv1')->getId(),
			$this->stored('ra:place')->getUnidadVecinalId(),
			'the batch-end sweep should have given the Place to the square',
		);

		$revert = $this->revert->revert((int)$batch->getId(), 'tester');

		self::assertTrue(
			(bool)$this->stored('ra:place')->getRetired(),
			'the Place the batch created is still on the map after undoing that batch',
		);
		self::assertSame(0, $revert->getSkipped(), 'nothing here belonged to anybody else');
		self::assertSame(
			[(int)$revert->getId(), (int)$batch->getId()],
			$this->recentBatchIds(2),
			'the revert recorded a batch besides its own',
		);
	}

	/**
	 * Undo, redo, undo of the Place-and-Sector batch, each skipping nothing.
	 *
	 * The redo restores the Sector, and swept at once that claimed the Place under
	 * a batch of its own — so the redo then skipped the entry that puts the
	 * Place's assignment back, and the second undo skipped the Place's own.
	 */
	public function testUndoRedoUndoOfABoundaryImportSkipsNothing(): void {
		$batch = $this->importPlaceAndSector();
		$sectorId = (int)$this->stored('ra:boundary')->getId();

		$undo = $this->revert->revert((int)$batch->getId(), 'tester');
		self::assertSame(0, $undo->getSkipped());

		$redo = $this->revert->revert((int)$undo->getId(), 'tester');
		self::assertSame(0, $redo->getSkipped(), 'the redo skipped an entry of its own undo');
		self::assertFalse((bool)$this->stored('ra:place')->getRetired(), 'the redo should have put the Place back');
		self::assertSame(
			$sectorId,
			$this->stored('ra:place')->getSectorId(),
			'the redo should end with the assignment the import produced',
		);

		$again = $this->revert->revert((int)$redo->getId(), 'tester');
		self::assertTrue((bool)$this->stored('ra:place')->getRetired(), 'the Place is still on the map');
		self::assertTrue($this->batches->isUndone((int)$batch->getId()), 'the import should read as undone');
		self::assertSame(0, $again->getSkipped(), 'nothing here belonged to anybody else');
	}

	/**
	 * Undoing a batch of adjacent boundaries leaves its sweep's writes under the
	 * revert's own id and records no batch but the revert (#200).
	 *
	 * Swept per boundary, each one that moved a Place recorded a «reasignación de
	 * límite» batch with its own «Deshacer»; undoing a 40-UV cadastre pushed the
	 * import and its revert out of the dialog's recent list.
	 */
	public function testUndoingAnImportOfAdjacentBoundariesStampsItsSweepAndRecordsOneBatch(): void {
		$batch = $this->importUnidadesVecinales();
		$inSquare = $this->place('Plaza en la unidad cuadrada ' . uniqid());
		$inL = $this->place('Plaza en la unidad en L ' . uniqid(), [
			'geometry' => ['type' => 'Point', 'coordinates' => [-70.56, -33.50]],
		]);
		self::assertSame((int)$this->stored('ra:uv1')->getId(), $this->reload($inSquare)->getUnidadVecinalId());
		self::assertSame((int)$this->stored('ra:uv2')->getId(), $this->reload($inL)->getUnidadVecinalId());
		$newestId = (int)$this->batches->findRecent(1)[0]->getId();

		$revert = $this->revert->revert((int)$batch->getId(), 'tester');

		self::assertSame([(int)$revert->getId(), $newestId], $this->recentBatchIds(2), 'the revert recorded a batch besides its own');
		self::assertSame(0, $revert->getSkipped(), 'nothing here belonged to anybody else');

		foreach ([$inSquare, $inL] as $placeId) {
			$place = $this->reload($placeId);
			// Invariant style, for the reason the first test gives: which boundary
			// holds the Place afterwards is a fact about the shared instance.
			foreach ([$place->getSectorId(), $place->getUnidadVecinalId()] as $boundaryId) {
				if ($boundaryId !== null) {
					self::assertFalse(
						(bool)$this->reload((int)$boundaryId)->getRetired(),
						'the Place points at a retired boundary, which no read will resolve',
					);
				}
			}
			self::assertSame(
				(int)$revert->getId(),
				Server::get(RevisionLog::class)->newestFor($placeId)['importId'],
				'the sweep should have written the Place under the revert\'s own batch',
			);
		}
	}
}
