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

	/** Every field an import carries, so a variant can change exactly one. */
	private const FULL = <<<'JSON'
	{"type":"FeatureCollection","features":[
	  {"type":"Feature","geometry":{"type":"Point","coordinates":[-70.58,-33.52]},
	   "properties":{"uid":"t:full","name":"Plaza Completa","category":"deporte_recreacion",
	     "subcategory":"plaza","ubicacion":"exacta","address":"Calle Uno 1","phone":"961111111",
	     "email":"uno@ejemplo.cl","contact_person":"Marcela Ríos","contact_role":"Presidenta",
	     "opening_hours":"Lunes a viernes","notes":"Sin novedad","source":"prueba"}}
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

	/**
	 * Every field an import carries is one a re-import writes when it changes.
	 *
	 * `differs()` decides whether a re-import writes at all, and it used to list
	 * the fields it compared by hand, beside `input()` which lists them again. A
	 * field added to one and forgotten in the other made a re-import report the
	 * record **unchanged** and never write it — silent, and the whole point of
	 * running an import again. One case per field, so the loss shows up as the
	 * field that stopped being noticed rather than as "an import test broke".
	 *
	 * @dataProvider changedFields
	 */
	public function testAChangeToAnyImportedFieldIsWritten(string $from, string $to): void {
		$this->import(self::FULL);

		$changed = $this->import(str_replace($from, $to, self::FULL));

		self::assertSame(1, $changed->getUpdated(), "changing {$from} left the record unchanged");
		self::assertSame(0, $changed->getUnchanged());
	}

	/**
	 * @return array<string, array{string, string}> the substring to change, and to what
	 */
	public static function changedFields(): array {
		return [
			'name' => ['"name":"Plaza Completa"', '"name":"Plaza Renombrada"'],
			'subcategory' => ['"subcategory":"plaza"', '"subcategory":"multicancha"'],
			'geometry' => ['[-70.58,-33.52]', '[-70.59,-33.53]'],
			'locationQuality' => ['"ubicacion":"exacta"', '"ubicacion":"aproximada"'],
			'address' => ['"address":"Calle Uno 1"', '"address":"Calle Dos 2"'],
			'phone' => ['"phone":"961111111"', '"phone":"962222222"'],
			'email' => ['"email":"uno@ejemplo.cl"', '"email":"dos@ejemplo.cl"'],
			'contactPerson' => ['"contact_person":"Marcela Ríos"', '"contact_person":"Ana Soto"'],
			'contactRole' => ['"contact_role":"Presidenta"', '"contact_role":"Secretaria"'],
			'openingHours' => ['"opening_hours":"Lunes a viernes"', '"opening_hours":"Solo martes"'],
			'notes' => ['"notes":"Sin novedad"', '"notes":"Con novedad"'],
			'sourceRef' => ['"source":"prueba"', '"source":"otra-fuente"'],
		];
	}

	/**
	 * The one path that reaches the field-by-field comparison at all.
	 *
	 * `differs()` returns early when the authority's own blob has changed, and
	 * that blob is the whole row — so on an ordinary re-import it answers before
	 * any field is looked at. A revert is what separates the two: it writes the
	 * old values back **without** marking the record overridden (ADR-0009's
	 * recovery path, and the reason ADR-0003's guard does not fire), so the
	 * record now disagrees with an official blob that is still current. Only then
	 * does what `differs()` compares field by field decide anything.
	 *
	 * This is the case the hand-maintained list could get wrong, and the reason
	 * deriving it from `input()` is worth doing.
	 */
	public function testAReImportAfterARevertWritesTheRecordBackAgain(): void {
		$changed = str_replace('"notes":"Sin novedad"', '"notes":"Con novedad"', self::FULL);

		$this->import(self::FULL);
		$second = $this->import($changed);
		self::assertSame(1, $second->getUpdated(), 'the setup never changed anything');

		Server::get(\OCA\Territorio\Service\RevertService::class)
			->revert((int)$second->getId(), 'tester');

		$third = $this->import($changed);

		self::assertSame(1, $third->getUpdated(), 'a reverted record was not written back');
		self::assertSame('Con novedad', $this->features->findByDataset(
			(int)Server::get(DatasetMapper::class)->findBySlug(self::DATASET)->getId(),
		)['t:full']->getNotes());
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

	/**
	 * A Zone imports now that one can be drawn (issue #10), and a boundary file
	 * is Routes from end to end (issue #12) — the pipeline takes everything the
	 * Registry can store.
	 */
	public function testEveryGeometryTheRegistryCanStoreIsImported(): void {
		$json = '{"type":"FeatureCollection","features":['
			. '{"type":"Feature","geometry":{"type":"Polygon","coordinates":'
			. '[[[-70.6,-33.5],[-70.5,-33.5],[-70.5,-33.4],[-70.6,-33.5]]]},'
			. '"properties":{"name":"Sector","source":"prueba"}},'
			. '{"type":"Feature","geometry":{"type":"LineString","coordinates":'
			. '[[-70.6,-33.5],[-70.5,-33.5]]},'
			. '"properties":{"name":"Calle","source":"prueba"}}]}';

		$batch = $this->import($json);

		self::assertSame(2, $batch->getCreated());
		self::assertSame(0, $batch->getUnsupported());
	}

	/**
	 * What is left over is counted, not refused. One MultiPolygon in a cadastre
	 * of thousands must not make the whole file an all-or-nothing problem — the
	 * batch says how many rows it could not take, and the rest are imported.
	 */
	public function testAGeometryTheRegistryCannotStoreIsCountedAndTheRestImported(): void {
		$json = '{"type":"FeatureCollection","features":['
			. '{"type":"Feature","geometry":{"type":"MultiPolygon","coordinates":'
			. '[[[[-70.6,-33.5],[-70.5,-33.5],[-70.5,-33.4],[-70.6,-33.5]]]]},'
			. '"properties":{"name":"Dos trozos","source":"prueba"}},'
			. '{"type":"Feature","geometry":{"type":"Point","coordinates":[-70.58,-33.52]},'
			. '"properties":{"name":"Una plaza","source":"prueba"}}]}';

		$batch = $this->import($json);

		self::assertSame(1, $batch->getCreated());
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
