<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\ImportBatch;
use OCA\Territorio\Db\ImportBatchMapper;
use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Service\Action;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\ImportService;
use OCA\Territorio\Service\RevertService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Undoing one import (ADR-0009).
 *
 * There is no backup feature, so this IS the recovery path, and the thing it
 * must never do is take somebody else's work with it. Most of what follows is
 * about what a revert leaves alone.
 */
final class RevertTest extends IntegrationTestCase {
	private const DATASET = 'test-revert';

	private ImportService $import;
	private RevertService $revert;
	private FeatureMapper $features;

	private const TWO = <<<'JSON'
	{"type":"FeatureCollection","features":[
	  {"type":"Feature","geometry":{"type":"Point","coordinates":[-70.58,-33.52]},
	   "properties":{"uid":"r:uno","name":"Uno","address":"Calle Uno 1","source":"prueba"}},
	  {"type":"Feature","geometry":{"type":"Point","coordinates":[-70.57,-33.51]},
	   "properties":{"uid":"r:dos","name":"Dos","address":"Calle Dos 2","source":"prueba"}}
	]}
	JSON;

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

	private function importTwo(string $json = self::TWO): ImportBatch {
		return $this->import->importFile($json, 'datos.geojson', self::DATASET, 'Prueba', 'tester');
	}

	/** @return array<string, \OCA\Territorio\Db\Feature> */
	private function imported(): array {
		return $this->features->findByDataset(
			(int)Server::get(DatasetMapper::class)->findBySlug(self::DATASET)->getId(),
		);
	}

	public function testRevertingRetiresWhatTheBatchCreated(): void {
		$batch = $this->importTwo();
		self::assertSame(2, $batch->getCreated());

		$revert = $this->revert->revert((int)$batch->getId(), 'tester');

		self::assertSame(2, $revert->getUpdated());
		foreach ($this->imported() as $feature) {
			self::assertTrue((bool)$feature->getRetired(), 'created records leave the map');
		}
	}

	public function testRevertingRestoresValuesTheBatchOverwrote(): void {
		$this->importTwo();
		$second = $this->importTwo(str_replace('Calle Uno 1', 'Calle Uno 99', self::TWO));
		self::assertSame(1, $second->getUpdated());

		$this->revert->revert((int)$second->getId(), 'tester');

		self::assertSame('Calle Uno 1', $this->imported()['r:uno']->getAddress());
	}

	/**
	 * The criterion the whole slice turns on. A revert must restore what the
	 * batch overwrote and touch nothing else — undoing an import is no reason to
	 * discard what a colleague did afterwards.
	 */
	public function testARecordEditedAfterTheBatchIsLeftAlone(): void {
		$batch = $this->importTwo();
		$dos = $this->imported()['r:dos'];

		Server::get(FeatureService::class)->update((int)$dos->getId(), [
			'name' => 'Dos, corregida a mano',
			'subcategoryId' => $dos->getSubcategoryId(),
			'geometry' => json_decode((string)$dos->getGeometry(), true),
		]);

		$revert = $this->revert->revert((int)$batch->getId(), 'tester');

		$after = $this->imported();
		self::assertTrue((bool)$after['r:uno']->getRetired(), 'the untouched record is undone');
		self::assertFalse((bool)$after['r:dos']->getRetired(), 'the edited one is left exactly as it is');
		self::assertSame('Dos, corregida a mano', $after['r:dos']->getName());
		self::assertSame(1, $revert->getSkipped(), 'and the revert says so rather than being silent');
	}

	/**
	 * A revert is recorded as a batch of its own, which is what makes it
	 * revertible without a second mechanism.
	 */
	public function testARevertCanItselfBeReverted(): void {
		$batch = $this->importTwo();
		$revert = $this->revert->revert((int)$batch->getId(), 'tester');

		self::assertSame((int)$batch->getId(), $revert->getRevertsId());

		$undoTheUndo = $this->revert->revert((int)$revert->getId(), 'tester');

		self::assertSame(2, $undoTheUndo->getUpdated());
		foreach ($this->imported() as $feature) {
			self::assertFalse((bool)$feature->getRetired(), 'the records come back');
		}
	}

	/**
	 * A revert that undid nothing must not spend the batch.
	 *
	 * Import A, then import B overwrites all of it. Undoing A now correctly finds
	 * every record touched since and leaves them alone — but if that counted as
	 * "A has been undone", A could never be undone later, and the only recovery
	 * path ADR-0009 offers would be gone for good.
	 *
	 * What this does NOT claim: that undoing B afterwards makes A undoable again.
	 * The guard is deliberately conservative — it knows another batch's undo
	 * touched those records last, not that it happened to restore exactly A's
	 * values — and when undo is ambiguous, refusing and saying so is the safe
	 * direction to be wrong in.
	 */
	public function testARevertThatUndidNothingDoesNotSpendTheBatch(): void {
		$first = $this->importTwo();
		// Both rows, or undoing the first would still legitimately undo the one
		// the second import left alone.
		$this->importTwo(str_replace(
			['Calle Uno 1', 'Calle Dos 2'],
			['Calle Uno 99', 'Calle Dos 99'],
			self::TWO,
		));

		$empty = $this->revert->revert((int)$first->getId(), 'tester');

		self::assertSame(0, $empty->getUpdated(), 'nothing to undo: the rows moved on');
		self::assertSame(2, $empty->getSkipped(), 'and it says so rather than being silent');
		self::assertFalse(
			Server::get(ImportBatchMapper::class)->isUndone((int)$first->getId()),
			'a revert that undid nothing must leave the batch undoable',
		);
	}

	/** Undo, redo, and the original is available to undo again. */
	public function testUndoingARevertMakesTheOriginalRevertibleAgain(): void {
		$batch = $this->importTwo();
		$revert = $this->revert->revert((int)$batch->getId(), 'tester');
		$this->revert->revert((int)$revert->getId(), 'tester');

		$again = $this->revert->revert((int)$batch->getId(), 'tester');

		self::assertSame(2, $again->getUpdated());
	}

	public function testABatchCannotBeRevertedTwice(): void {
		$batch = $this->importTwo();
		$this->revert->revert((int)$batch->getId(), 'tester');

		$this->expectException(InvalidImportException::class);
		$this->revert->revert((int)$batch->getId(), 'tester');
	}

	public function testTheRevertAppearsInEachRecordsHistory(): void {
		$batch = $this->importTwo();
		$uno = (int)$this->imported()['r:uno']->getId();

		$this->revert->revert((int)$batch->getId(), 'tester');

		$actions = array_column(Server::get(FeatureService::class)->history($uno), 'action');
		self::assertSame(Action::Retired->value, $actions[0]);
		self::assertSame(Action::Created->value, $actions[1]);
	}

	/**
	 * A revert must not make its records look like somebody edited them, or every
	 * future import would skip them for good under ADR-0003.
	 */
	public function testRevertingDoesNotMarkRecordsAsLocallyOverridden(): void {
		$this->importTwo();
		$second = $this->importTwo(str_replace('Calle Uno 1', 'Calle Uno 99', self::TWO));

		$this->revert->revert((int)$second->getId(), 'tester');

		self::assertFalse((bool)$this->imported()['r:uno']->getOverridden());
	}
}
