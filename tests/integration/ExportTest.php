<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\ExportRecordMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Exception\InvalidImportException;
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

	/** One entry, found among the recent ones — the mapper has no find-by-id. */
	private function entry(int $id): \OCA\Territorio\Db\ExportRecord {
		foreach (Server::get(ExportRecordMapper::class)->findRecent() as $entry) {
			if ((int)$entry->getId() === $id) {
				return $entry;
			}
		}

		self::fail("no export log entry $id");
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
	 *
	 * The fixture is stamped the way `tools/boundary-features.php` stamps what it
	 * produces, so this run carries the ODbL notice as well (#28). Extended here
	 * rather than given a test of its own because the criterion is that the notice
	 * does not break a consumer, and the harshest consumer this file has is this
	 * app's own importer — checking it anywhere else would check something easier.
	 */
	public function testAGeoJsonExportGoesBackInAsUpdatesRatherThanCopies(): void {
		$dataset = 'export-round-trip-' . uniqid();
		$original = <<<'JSON'
			{"type":"FeatureCollection","features":[
			  {"type":"Feature","properties":{"uid":"rt-1","name":"Sede Ida y Vuelta",
			    "phone":"+56 9 2222 2222","address":"Los Navegantes 1234",
			    "source_es":"OpenStreetMap","source_url":"https://www.openstreetmap.org/way/101"},
			   "geometry":{"type":"Point","coordinates":[-70.5822,-33.5222]}}
			]}
			JSON;

		Server::get(ImportService::class)->importFile($original, 'datos.geojson', $dataset);
		$imported = Server::get(FeatureMapper::class)->findByDataset(
			(int)Server::get(DatasetMapper::class)
				->findOrCreate($dataset, $dataset)
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

		// The licence is named, because the record came from OpenStreetMap (#28).
		$written = json_decode($file['body'], true);
		self::assertStringContainsString('ODbL', $written['license'] ?? '');

		// Back in through the same door it came out of.
		$batch = Server::get(ImportService::class)->importFile($file['body'], 'datos.geojson', $dataset);

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
	 * The export that leaves the contact fields out must not be re-importable.
	 *
	 * It is the DEFAULT export, and re-importing one would tell the Registry that
	 * every record in it has no telephone number — which is what the file says,
	 * because the fields are absent. `ImportService::differs()` sees a change,
	 * `input()` writes the nulls, and a comuna's contact details are gone in one
	 * quiet "Actualizado" per record.
	 *
	 * So the file says it is partial and the reader refuses it. Loudly: an
	 * accidental wipe is silent, and a refusal is a sentence on the screen.
	 */
	public function testAnExportWithoutContactsCannotBeImportedBackOverTheOriginals(): void {
		$dataset = 'export-partial-' . uniqid();
		$id = $this->place('Conserva su teléfono', ['phone' => '+56 9 6666 6666']);

		$file = $this->exports->export([$id], 'geojson', false);

		$this->expectException(InvalidImportException::class);
		Server::get(ImportService::class)->importFile($file['body'], 'datos.geojson', $dataset);
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
			$this->exports->export([$id], 'csv', true, 'búsqueda: "contacto"');
		});

		self::assertCount(1, $added);
		$entry = $this->entry($added[0]);
		self::assertSame('csv', $entry->getFormat());
		self::assertSame(1, $entry->getRecords());
		self::assertNotSame('', $entry->getActor());
		self::assertNotNull($entry->getExportedAt());
		// ADR-0006 asks for actor, moment and SCOPE. A count is the size of an
		// export; this is what it was of.
		self::assertSame('búsqueda: "contacto"', $entry->getScope());
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
	 * A KML cannot carry contact details, so asking for one that does is refused.
	 *
	 * Not quietly downgraded: it would mean a password prompt for a file with
	 * nothing personal in it, which is the unearned prompt ADR-0006 exists to
	 * avoid, and a log entry claiming contact data left the building when none
	 * did.
	 */
	public function testAKmlCannotBeAskedForWithContacts(): void {
		$id = $this->place('KML', ['phone' => '+56 9 5555 5555']);

		try {
			$this->exports->export([$id], 'kml', true);
			self::fail('a KML with contacts should be refused');
		} catch (InvalidFeatureException) {
			// as expected
		}

		$file = null;
		$added = $this->logging(function () use ($id, &$file): void {
			$file = $this->exports->export([$id], 'kml', false);
		});

		self::assertStringNotContainsString('+56 9 5555 5555', $file['body']);
		self::assertSame([], $added, 'a KML carries nobody, so there is nothing to record');
	}

	/**
	 * A KMZ is the KML it would have exported, in an archive (#102).
	 *
	 * Here as well as in `ExportServiceTest` because what a unit test cannot say
	 * is that the archive holds the KML of the RECORDS — the ids from the browser,
	 * through `rows()`, through the taxonomy, into the file. The comparison is
	 * against the KML export of the same ids in the same run rather than against a
	 * fixture, because the criterion is that the two cannot drift apart.
	 */
	public function testAKmzCarriesTheSameKmlTheKmlExportWouldHaveWritten(): void {
		$id = $this->place('KMZ', ['phone' => '+56 9 7777 7777']);

		$kmz = $this->exports->export([$id], 'kmz', false);
		$kml = $this->exports->export([$id], 'kml', false);

		self::assertSame('application/vnd.google-earth.kmz', $kmz['mime']);
		self::assertStringEndsWith('.kmz', $kmz['filename']);
		self::assertSame($kml['body'], self::docKml($kmz['body']));
		// The KML inside is a KML: it names the record and carries nobody's
		// telephone number, whatever the archive around it is called.
		self::assertStringContainsString('KMZ', (string)self::docKml($kmz['body']));
		self::assertStringNotContainsString('+56 9 7777 7777', $kmz['body']);
	}

	/**
	 * And the refusal that covers a KML covers it, for the same reason (#102).
	 *
	 * The log assertion is the half that matters most: a KMZ let through would
	 * ask for a password over a file with nothing personal in it AND leave an
	 * entry saying contact data left the building when none did — a false line in
	 * the one record that says who took personal data out (ADR-0006, ADR-0012).
	 */
	public function testAKmzCannotBeAskedForWithContactsEither(): void {
		$id = $this->place('KMZ con contactos', ['phone' => '+56 9 8888 8888']);

		$added = $this->logging(function () use ($id): void {
			try {
				$this->exports->export([$id], 'kmz', true);
				self::fail('a KMZ with contacts should be refused');
			} catch (InvalidFeatureException $refusal) {
				// The same sentence a KML gets, because it is the same reason.
				self::assertStringContainsString('no lleva datos de contacto', $refusal->getMessage());
			}
		});

		self::assertSame([], $added, 'a refused export must leave nothing in the log');
	}

	/** What the archive holds, or null if it holds no `doc.kml`. */
	private static function docKml(string $kmz): ?string {
		$path = tempnam(sys_get_temp_dir(), 'territorio-kmz-test-');
		file_put_contents($path, $kmz);

		try {
			$zip = new \ZipArchive();
			self::assertTrue($zip->open($path) === true, 'the KMZ should open as an archive');
			$doc = $zip->getFromName('doc.kml');
			$zip->close();

			return $doc === false ? null : $doc;
		} finally {
			unlink($path);
		}
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

	/**
	 * An export of the clinic's own records claims nothing about OpenStreetMap.
	 *
	 * The other half of #28, and the half a notice printed on every file would
	 * silently fail: an attribution that is always there is read as boilerplate,
	 * and it would also be a false claim about a municipal catastro.
	 */
	public function testAnExportOfLocalRecordsCarriesNoLicenceNotice(): void {
		$id = $this->place('Catastro propio');

		$geojson = $this->exports->export([$id], 'geojson', false)['body'];
		$csv = $this->exports->export([$id], 'csv', false)['body'];
		$kml = $this->exports->export([$id], 'kml', false)['body'];

		self::assertArrayNotHasKey('license', json_decode($geojson, true));
		self::assertStringNotContainsString('Licencia', $csv);
		self::assertStringNotContainsString('ODbL', $kml);
	}

	/**
	 * Rewriting Fuente on an imported street does not take the licence off the
	 * file (#28).
	 *
	 * `sourceRef` is the Fuente box in the form and anybody may retype it, so a
	 * notice keyed on it alone would disappear from exactly the record it is
	 * about, and disappear invisibly — the geometry in the file is still
	 * OpenStreetMap's. `official` is the row as the authority published it and
	 * only an import writes it (ADR-0003), which is why this survives.
	 *
	 * Here rather than in a unit test because the unit tests are handed a row:
	 * what needs the database is that `ExportService::row()` actually carries
	 * `official` out of the Registry, through the real edit path, into all three
	 * writers.
	 */
	public function testRewritingFuenteDoesNotStripTheLicenceFromAnImportedRecord(): void {
		$dataset = 'export-edited-fuente-' . uniqid();
		$file = <<<'JSON'
			{"type":"FeatureCollection","features":[
			  {"type":"Feature","properties":{"uid":"ef-1","name":"Calle Vicuña Mackenna",
			    "source_es":"OpenStreetMap","source_url":"https://www.openstreetmap.org/way/101"},
			   "geometry":{"type":"Point","coordinates":[-70.5833,-33.5233]}}
			]}
			JSON;

		Server::get(ImportService::class)->importFile($file, 'datos.geojson', $dataset);
		$imported = Server::get(FeatureMapper::class)->findByDataset(
			(int)Server::get(DatasetMapper::class)->findOrCreate($dataset, $dataset)->getId(),
		);
		$id = (int)$imported['ef-1']->getId();
		$this->created[] = $id;

		// What a person does in the Fuente box. No Provenance, so this is a local
		// override and `official` is left exactly as the import wrote it.
		// `apply()` reads name and subcategoryId out of the input on every write,
		// not out of the entity, so an edit has to restate them — as every other
		// caller of update() in this suite does. Geometry may stay out: an update
		// that says nothing about the shape leaves it alone.
		$edited = Server::get(FeatureService::class)->update($id, [
			'name' => 'Calle Vicuña Mackenna',
			'subcategoryId' => $imported['ef-1']->getSubcategoryId(),
			'sourceRef' => 'Municipalidad',
		]);
		self::assertSame('Municipalidad', $edited->getSourceRef(), 'the edit must have landed');

		$geojson = $this->exports->export([$id], 'geojson', false)['body'];
		$csv = $this->exports->export([$id], 'csv', false)['body'];
		$kml = $this->exports->export([$id], 'kml', false)['body'];

		self::assertStringContainsString('ODbL', json_decode($geojson, true)['license'] ?? '');
		self::assertStringContainsString('ODbL', $csv);
		self::assertStringContainsString('ODbL', $kml);

		// And the copy the notice was read from stayed out of the files. It is the
		// whole published row, so leaking it would carry fields ADR-0006 withholds.
		self::assertStringNotContainsString('official', $geojson);
		self::assertStringNotContainsString('official', $csv);
		self::assertStringNotContainsString('official', $kml);
	}

	/** The CSV carries the classification a person reads, not an id. */
	public function testTheCsvCarriesTheReadableClassification(): void {
		$id = $this->place('Con clasificación');

		$csv = $this->exports->export([$id], 'csv', false)['body'];

		self::assertStringContainsString(' · ', $csv, 'the CSV should carry "Categoría · Subcategoría"');
	}

	/**
	 * The assignment columns: a Sector's NAME in the cell, never its id.
	 *
	 * The fixtures mirror `RevertAssignmentTest`: a Sector imported through the
	 * import door, its ring around where `place()` puts a record, so the
	 * assignment in the export is the derived one the app actually stores
	 * (ADR-0008) rather than one typed in by a test.
	 */
	public function testTheCsvCarriesTheDivisionNames(): void {
		$dataset = 'export-sector-' . uniqid();
		// Registered so teardown forgets the whole import (the base class's
		// dataset seam) — no row of it survives this test.
		$this->datasets[] = $dataset;
		$sector = $this->importSector($dataset);

		$inside = $this->place('Sede dentro del sector');
		$unassigned = $this->place('Sede sin asignar', ['geometry' => null]);
		// The fixture must hold before anything else is asserted of it: if the
		// ring stops claiming the record, the name assertions below would pass
		// or fail on a cell that was empty for a different reason.
		self::assertSame($sector, Server::get(FeatureMapper::class)->find($inside)->getSectorId(),
			'the place inside the ring was not claimed by the imported Sector');

		$claimed = $this->csvRow($this->exports->export([$inside], 'csv', false)['body']);
		self::assertSame('Sector de exportar', $claimed['Sector']);
		self::assertSame('', $claimed['Unidad Vecinal'], 'only a Sector was imported');

		$plain = $this->csvRow($this->exports->export([$unassigned], 'csv', false)['body']);
		self::assertSame('', $plain['Sector']);
		self::assertSame('', $plain['Unidad Vecinal']);

		// And the two keys ride the row unmapped in the formats that have no
		// column for them — the `official` precedent.
		$geojson = $this->exports->export([$inside], 'geojson', false)['body'];
		$kml = $this->exports->export([$inside], 'kml', false)['body'];
		self::assertStringNotContainsString('sectorName', $geojson);
		self::assertStringNotContainsString('unidadVecinalName', $geojson);
		self::assertStringNotContainsString('sectorName', $kml);
		self::assertStringNotContainsString('unidadVecinalName', $kml);

		// The drift state no app door can produce: every retirement sweeps the
		// assignment away (ADR-0008), so a record still pointing at a retired
		// division is exactly what the export must answer with an empty cell
		// rather than naming — or numbering — something the app will not show.
		// Stamped directly, the way this suite's fixtures arrange what no door
		// can.
		Server::get(FeatureService::class)->retire($sector);
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->update('territorio_feature')
				->set('sector_id', $qb->createNamedParameter($sector, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($inside, IQueryBuilder::PARAM_INT)))
				->executeStatement();

		$dangling = $this->csvRow($this->exports->export([$inside], 'csv', false)['body']);
		self::assertSame('', $dangling['Sector'], 'a retired division must not be named');
		self::assertSame('', $dangling['Unidad Vecinal']);
	}

	/** The imported Sector — `RevertAssignmentTest`'s fixture, name of its own. */
	private function importSector(string $dataset): int {
		$ring = '[[[-70.59,-33.53],[-70.57,-33.53],[-70.57,-33.51],[-70.59,-33.51],[-70.59,-33.53]]]';
		$file = <<<JSON
		{"type":"FeatureCollection","features":[
		  {"type":"Feature","geometry":{"type":"Polygon","coordinates":{$ring}},
		   "properties":{"uid":"ex:sector","name":"Sector de exportar",
		     "category":"division_territorial","subcategory":"sector"}}
		]}
		JSON;

		Server::get(ImportService::class)->importFile($file, 'datos.geojson', $dataset, 'Prueba', 'tester');

		return (int)Server::get(FeatureMapper::class)->findByDataset(
			(int)Server::get(DatasetMapper::class)->findOrCreate($dataset, $dataset)->getId(),
		)['ex:sector']->getId();
	}

	/**
	 * One data row of an export, by its column heading — BOM eaten, the way
	 * `TabularWriterTest` reads the writer's own output.
	 *
	 * @return array<string, string>
	 */
	private static function csvRow(string $csv): array {
		$handle = fopen('php://memory', 'r+');
		fwrite($handle, (string)preg_replace('/^\x{FEFF}/u', '', $csv));
		rewind($handle);
		$header = fgetcsv($handle, 0, ',', '"', '');
		$row = fgetcsv($handle, 0, ',', '"', '');
		fclose($handle);

		return array_combine($header, $row);
	}
}
