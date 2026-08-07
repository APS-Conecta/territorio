<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\ExportRecordMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\Origin;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Service\ExportService;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\ImportService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Exports against the real Registry (issue #17, ADR-0006, ADR-0012).
 *
 * The writers are unit-tested against rows; what needs a database is everything
 * around them — that the ids a browser sends become the records it was showing,
 * that the taxonomy resolves, that a contact-bearing export is recorded, and
 * that none of it touches the change cursor.
 */
final class ExportTest extends IntegrationTestCase {
	private ExportService $exports;

	/** @var int[] log entries this test made */
	private array $logged = [];

	protected function setUp(): void {
		$this->exports = Server::get(ExportService::class);
	}

	protected function tearDown(): void {
		if ($this->logged !== []) {
			$db = Server::get(IDBConnection::class);
			$qb = $db->getQueryBuilder();
			$qb->delete('territorio_export')
				->where($qb->expr()->in('id', $qb->createNamedParameter(
					$this->logged, IQueryBuilder::PARAM_INT_ARRAY,
				)))
				->executeStatement();
			$this->logged = [];
		}

		parent::tearDown();
	}

	/**
	 * Ids of the log entries that exist now.
	 *
	 * Compared as sets rather than counted: this instance is shared, and a count
	 * measures whatever else has run against it — the trap that has cost several
	 * runs in this repo.
	 *
	 * @return int[]
	 */
	private function logIds(): array {
		return array_map(
			static fn ($entry): int => (int)$entry->getId(),
			Server::get(ExportRecordMapper::class)->findRecent(),
		);
	}

	/**
	 * Run something and hand back the log entries it added, registered for
	 * cleanup.
	 *
	 * @return int[]
	 */
	private function logging(callable $work): array {
		$before = $this->logIds();
		$work();
		$added = array_values(array_diff($this->logIds(), $before));
		$this->logged = [...$this->logged, ...$added];

		return $added;
	}

	public function testItExportsExactlyTheRecordsItWasGiven(): void {
		$wanted = $this->place('Se exporta', ['phone' => '+56 9 1111 1111']);
		$this->place('No se exporta');

		$file = null;
		$this->logging(function () use ($wanted, &$file): void {
			$file = $this->exports->export([$wanted], 'geojson', true);
		});

		$written = json_decode($file['body'], true);
		self::assertCount(1, $written['features']);
		self::assertSame('Se exporta', $written['features'][0]['properties']['name']);
	}

	/**
	 * The whole point of the GeoJSON export: it goes back in.
	 *
	 * Through the real import, not the reader alone — matching is on (Dataset,
	 * external id), so this is also the check that an export re-imported UPDATES
	 * the Registry instead of duplicating it (ADR-0003).
	 */
	public function testAGeoJsonExportGoesBackInAsUpdatesRatherThanCopies(): void {
		$dataset = 'export-round-trip-' . uniqid();
		$original = <<<'JSON'
			{"type":"FeatureCollection","features":[
			  {"type":"Feature","properties":{"uid":"rt-1","name":"Sede Ida y Vuelta",
			    "phone":"+56 9 2222 2222","address":"Los Navegantes 1234"},
			   "geometry":{"type":"Point","coordinates":[-70.5822,-33.5222]}}
			]}
			JSON;

		Server::get(ImportService::class)->importGeoJson($original, $dataset);
		$imported = Server::get(FeatureMapper::class)->findByDataset(
			(int)Server::get(DatasetMapper::class)
				->findOrCreate($dataset, $dataset, Origin::Cadastral)
				->getId(),
		);
		self::assertCount(1, $imported);
		$id = (int)$imported['rt-1']->getId();
		$this->created[] = $id;

		$file = null;
		$this->logging(function () use ($id, &$file): void {
			$file = $this->exports->export([$id], 'geojson', true);
		});

		$before = Server::get(FeatureMapper::class)->find($id)->comparable();

		// Back in through the same door it came out of.
		$batch = Server::get(ImportService::class)->importGeoJson($file['body'], $dataset);

		// Not one new row: the external id came back, so the import matched
		// (Dataset, external id) and updated in place (ADR-0003).
		self::assertSame(0, $batch->getCreated(), 'a re-imported export must not duplicate anything');

		// And the record still says exactly what it said. This is what lossless
		// means — every field a person can see survived the trip.
		self::assertSame($before, Server::get(FeatureMapper::class)->find($id)->comparable());

		// It IS counted as an update, and that is honest rather than a defect: an
		// import rewrites `official`, the record of what the authority published,
		// and an export of ours is not the authority. Nothing a person sees
		// changes; what changes is the provenance ADR-0003 keeps beside it.
		self::assertSame(1, $batch->getUpdated());
	}

	/**
	 * ADR-0012, and the reason the log is not a Revision: an export changes
	 * nothing, so it must not move the cursor every open browser polls.
	 */
	public function testExportingDoesNotMoveTheChangeCursor(): void {
		$id = $this->place('No mueve el cursor', ['phone' => '+56 9 3333 3333']);
		$before = Server::get(RevisionLog::class)->current();

		$this->logging(function () use ($id): void {
			$this->exports->export([$id], 'geojson', true);
			$this->exports->export([$id], 'csv', false);
		});

		self::assertSame($before, Server::get(RevisionLog::class)->current());
	}

	public function testAContactBearingExportIsRecordedWithWhoAndHowMany(): void {
		$id = $this->place('Con contacto', ['phone' => '+56 9 4444 4444']);

		$added = $this->logging(function () use ($id): void {
			$this->exports->export([$id], 'csv', true);
		});

		self::assertCount(1, $added);
		$entry = Server::get(ExportRecordMapper::class)->find($added[0]);
		self::assertSame('csv', $entry->getFormat());
		self::assertSame(1, $entry->getRecords());
		self::assertTrue($entry->getContacts());
		self::assertNotSame('', $entry->getActor());
	}

	/** An export without contact fields is an ordinary download (ADR-0006). */
	public function testAnExportWithoutContactsIsNotRecorded(): void {
		$id = $this->place('Sin contacto');

		$added = $this->logging(function () use ($id): void {
			$this->exports->export([$id], 'geojson', false);
			$this->exports->export([$id], 'csv', false);
		});

		self::assertSame([], $added);
	}

	/**
	 * A KML carries no contact details whatever was asked for, so recording it as
	 * an export of contact data would put a false entry in the one log that is
	 * meant to say who took personal data out of the building.
	 */
	public function testAKmlIsNotRecordedAsCarryingContacts(): void {
		$id = $this->place('KML', ['phone' => '+56 9 5555 5555']);

		$file = null;
		$added = $this->logging(function () use ($id, &$file): void {
			$file = $this->exports->export([$id], 'kml', true);
		});

		self::assertStringNotContainsString('+56 9 5555 5555', $file['body']);
		self::assertSame([], $added);
	}

	public function testARetiredRecordIsNotExportedEvenIfAskedFor(): void {
		$id = $this->place('Retirado antes de exportar');
		Server::get(FeatureService::class)->retire($id);

		$file = $this->exports->export([$id], 'geojson', false);

		self::assertSame([], json_decode($file['body'], true)['features']);
	}

	public function testAnUnknownFormatIsRefused(): void {
		$id = $this->place('Formato raro');

		$this->expectException(InvalidFeatureException::class);
		$this->exports->export([$id], 'shapefile', false);
	}

	/** The CSV carries the classification a person reads, not an id. */
	public function testTheCsvCarriesTheReadableClassification(): void {
		$id = $this->place('Con clasificación');

		$csv = $this->exports->export([$id], 'csv', false)['body'];

		self::assertStringContainsString(' · ', $csv, 'the CSV should carry "Categoría · Subcategoría"');
	}
}
