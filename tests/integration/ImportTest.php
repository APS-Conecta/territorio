<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\ImportService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Importing a Dataset, twice, and over the top of somebody's edit.
 *
 * These run against a real database because the guarantees are database
 * guarantees: matching on `(dataset, external id)` is a unique index, and
 * "running it twice changes nothing" is a claim about what was written, which a
 * mock cannot answer.
 */
final class ImportTest extends IntegrationTestCase {
	private const DATASET = 'test-import';

	private ImportService $import;
	private FeatureMapper $features;
	private RevisionLog $revisions;

	private const ONE = <<<'JSON'
	{"type":"FeatureCollection","features":[
	  {"type":"Feature","geometry":{"type":"Point","coordinates":[-70.58,-33.52]},
	   "properties":{"uid":"t:uno","name":"Plaza Uno","category":"deporte_recreacion",
	     "subcategory":"plaza","address":"Calle Uno 1","phone":"961111111","source":"prueba"}}
	]}
	JSON;

	protected function setUp(): void {
		$this->import = Server::get(ImportService::class);
		$this->features = Server::get(FeatureMapper::class);
		$this->revisions = Server::get(RevisionLog::class);
		$this->forgetDataset();
	}

	protected function tearDown(): void {
		$this->forgetDataset();
		parent::tearDown();
	}

	/** Imported rows are not tracked by $created, so clean by Dataset. */
	private function forgetDataset(): void {
		$db = Server::get(IDBConnection::class);
		$datasets = Server::get(DatasetMapper::class);

		try {
			$dataset = $datasets->findBySlug(self::DATASET);
		} catch (\Throwable) {
			return;
		}
		$id = (int)$dataset->getId();

		foreach ($this->features->findByDataset($id) as $feature) {
			$qb = $db->getQueryBuilder();
			$qb->delete('territorio_revision')
				->where($qb->expr()->eq('feature_id', $qb->createNamedParameter(
					(int)$feature->getId(), IQueryBuilder::PARAM_INT,
				)))->executeStatement();
		}
		foreach (['territorio_feature' => 'dataset_id', 'territorio_import' => 'dataset_id'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq($column, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('territorio_dataset')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function import(string $json): \OCA\Territorio\Db\ImportBatch {
		return $this->import->importGeoJson($json, self::DATASET, 'Prueba', 'tester');
	}

	public function testItCreatesRecordsAndRecordsTheBatch(): void {
		$batch = $this->import(self::ONE);

		self::assertSame(1, $batch->getCreated());
		self::assertSame('tester', $batch->getActor());
		self::assertGreaterThan($batch->getRevisionFrom(), $batch->getRevisionTo());

		$imported = $this->features->findByDataset($batch->getDatasetId());
		self::assertArrayHasKey('t:uno', $imported);
		self::assertSame('Plaza Uno', $imported['t:uno']->getName());
		self::assertSame('961111111', $imported['t:uno']->getPhone());
	}

	/**
	 * The criterion this whole slice turns on. Not "does not duplicate" — writes
	 * nothing at all, so the change cursor does not move and every open map is
	 * spared a flood of updates that changed nothing.
	 */
	public function testRunningTheSameImportTwiceChangesNothing(): void {
		$this->import(self::ONE);
		$cursor = $this->revisions->current();

		$second = $this->import(self::ONE);

		self::assertSame(0, $second->getCreated());
		self::assertSame(0, $second->getUpdated());
		self::assertSame(1, $second->getUnchanged());
		self::assertSame($cursor, $this->revisions->current(), 'no Revision may be spent on a no-op import');
	}

	public function testAChangedFileUpdatesTheRecord(): void {
		$this->import(self::ONE);
		$this->import(str_replace('961111111', '962222222', self::ONE));

		$imported = $this->features->findByDataset(
			(int)Server::get(DatasetMapper::class)->findBySlug(self::DATASET)->getId(),
		);
		self::assertSame('962222222', $imported['t:uno']->getPhone());
	}

	/**
	 * ADR-0003: what a person wrote outranks what the authority publishes next,
	 * because they were standing in the territory and the authority was not.
	 */
	public function testALocalEditSurvivesReImportAndKeepsTheOfficialValue(): void {
		$batch = $this->import(self::ONE);
		$feature = $this->features->findByDataset($batch->getDatasetId())['t:uno'];

		Server::get(FeatureService::class)->update((int)$feature->getId(), [
			'name' => 'Plaza Uno',
			'subcategoryId' => $feature->getSubcategoryId(),
			'phone' => '900000000',
			'geometry' => json_decode((string)$feature->getGeometry(), true),
		]);

		$second = $this->import(str_replace('961111111', '963333333', self::ONE));

		self::assertSame(1, $second->getSkipped());
		self::assertSame(0, $second->getUpdated());

		$after = $this->features->find((int)$feature->getId());
		self::assertSame('900000000', $after->getPhone(), 'the local edit must win');
		self::assertTrue((bool)$after->getOverridden());
		self::assertSame(
			'963333333',
			json_decode((string)$after->getOfficial(), true)['phone'],
			'the authority\'s latest value is kept beside the override, or it cannot be undone',
		);
	}

	public function testARetiredRecordIsNotResurrectedByReImport(): void {
		$batch = $this->import(self::ONE);
		$feature = $this->features->findByDataset($batch->getDatasetId())['t:uno'];
		Server::get(FeatureService::class)->retire((int)$feature->getId());

		$second = $this->import(self::ONE);

		self::assertSame(1, $second->getSkipped());
		self::assertTrue((bool)$this->features->find((int)$feature->getId())->getRetired());
	}

	public function testRowsTheFileRepeatsAreCollapsedAndCounted(): void {
		$json = '{"type":"FeatureCollection","features":['
			. '{"type":"Feature","geometry":{"type":"Point","coordinates":[-70.58,-33.52]},'
			. '"properties":{"name":"Repetida","source":"prueba"}},'
			. '{"type":"Feature","geometry":{"type":"Point","coordinates":[-70.58,-33.52]},'
			. '"properties":{"name":"Repetida","source":"prueba"}}]}';

		$batch = $this->import($json);

		self::assertSame(1, $batch->getCreated());
		self::assertSame(1, $batch->getDuplicates());
	}

	public function testGeometryKindsNothingDrawsYetAreReportedNotImported(): void {
		$json = '{"type":"FeatureCollection","features":['
			. '{"type":"Feature","geometry":{"type":"Polygon","coordinates":'
			. '[[[-70.6,-33.5],[-70.5,-33.5],[-70.5,-33.4],[-70.6,-33.5]]]},'
			. '"properties":{"name":"Sector","source":"prueba"}}]}';

		$batch = $this->import($json);

		self::assertSame(0, $batch->getCreated());
		self::assertSame(1, $batch->getUnsupported());
	}

	public function testAnUnknownCategoryLandsInSinClasificarRatherThanFailing(): void {
		$json = str_replace('"deporte_recreacion"', '"no_existe"', self::ONE);

		$batch = $this->import($json);

		self::assertSame(1, $batch->getCreated());
		self::assertArrayHasKey('t:uno', $this->features->findByDataset($batch->getDatasetId()));
	}

	public function testAMalformedFileImportsNothing(): void {
		$before = $this->revisions->current();

		try {
			$this->import('{not json');
			self::fail('a malformed file should have been refused');
		} catch (InvalidImportException) {
			// Expected.
		}

		self::assertSame($before, $this->revisions->current());
	}

	/**
	 * A single unusable row stops the whole file. A half-imported catastro is
	 * much harder to reason about than one that was refused outright.
	 */
	public function testOneBadCoordinateStopsTheWholeImport(): void {
		$json = '{"type":"FeatureCollection","features":['
			. '{"type":"Feature","geometry":{"type":"Point","coordinates":[-70.58,-33.52]},'
			. '"properties":{"uid":"t:bueno","name":"Buena","source":"prueba"}},'
			. '{"type":"Feature","geometry":{"type":"Point","coordinates":[-70.58,-999]},'
			. '"properties":{"uid":"t:malo","name":"Mala","source":"prueba"}}]}';
		$before = $this->revisions->current();

		$this->expectException(InvalidImportException::class);
		try {
			$this->import($json);
		} finally {
			self::assertSame($before, $this->revisions->current(), 'nothing may be written');
		}
	}
}
