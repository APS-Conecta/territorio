<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Domain\Geometry;
use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Service\GeoJsonReader;
use OCA\Territorio\Service\KmlReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Reading a Google MyMaps export.
 *
 * The fixture is shaped like the real `La_Florida_Google_MyMaps.kml` — Folders
 * holding Placemarks, geometry and a name and nothing else — but every value in
 * it is invented. What was checked against the real file is the SHAPE: 865
 * placemarks arrived with no ExtendedData at all, so no phone, no email, no
 * contact and no source.
 */
final class KmlReaderTest extends TestCase {
	private KmlReader $reader;

	protected function setUp(): void {
		$this->reader = new KmlReader(new GeoJsonReader());
	}

	public function testItReadsThePointsLinesAndAreasAMyMapsExportCarries(): void {
		$rows = $this->reader->read(self::KML, 'prueba');

		self::assertCount(3, $rows);
		self::assertSame(
			['Point', 'LineString', 'Polygon'],
			array_column(array_column($rows, 'geometry'), 'type'),
		);
	}

	/** KML says lon,lat[,altitude]; GeoJSON says [lon, lat] and nothing else. */
	public function testACoordinateArrivesAsGeoJsonOrdersIt(): void {
		$point = $this->reader->read(self::KML, 'prueba')[0];

		self::assertSame([-70.58, -33.52], $point['geometry']['coordinates']);
	}

	public function testALineKeepsEveryVertexInOrder(): void {
		$line = $this->reader->read(self::KML, 'prueba')[1];

		self::assertSame(
			[[-70.60, -33.53], [-70.59, -33.53], [-70.58, -33.53]],
			$line['geometry']['coordinates'],
		);
	}

	/** A KML ring is one flat list; GeoJSON wraps it, because a Polygon has rings. */
	public function testAnAreaBecomesAPolygonWithItsRingClosed(): void {
		$area = $this->reader->read(self::KML, 'prueba')[2];

		self::assertSame([[
			[-70.62, -33.55], [-70.60, -33.55], [-70.60, -33.54], [-70.62, -33.55],
		]], $area['geometry']['coordinates']);
	}

	/**
	 * The Folder is the only classification a MyMaps export carries, and it works
	 * because those folders were built from these Categories in the first place.
	 * Raw here rather than matched against the taxonomy: the reader knows
	 * nothing about what Categories exist, which is the import's job.
	 */
	/**
	 * A hole is part of what bounds the area. Dropped, the boundary claims every
	 * record standing in the lagoon it is drawn around — and nothing says so,
	 * because the shape still draws and still assigns, just wrongly.
	 */
	public function testAPolygonKeepsItsHoles(): void {
		$kml = <<<'XML'
		<?xml version="1.0" encoding="UTF-8"?>
		<kml xmlns="http://www.opengis.net/kml/2.2"><Document>
		  <Placemark><name>Parque con laguna</name><Polygon>
		    <outerBoundaryIs><LinearRing><coordinates>
		      -70.62,-33.55 -70.60,-33.55 -70.60,-33.54 -70.62,-33.54 -70.62,-33.55
		    </coordinates></LinearRing></outerBoundaryIs>
		    <innerBoundaryIs><LinearRing><coordinates>
		      -70.615,-33.548 -70.605,-33.548 -70.605,-33.543 -70.615,-33.548
		    </coordinates></LinearRing></innerBoundaryIs>
		  </Polygon></Placemark>
		</Document></kml>
		XML;

		$rings = $this->reader->read($kml, 'prueba')[0]['geometry']['coordinates'];

		self::assertCount(2, $rings, 'the hole was dropped, so the area claims what is inside it');
		self::assertSame([-70.615, -33.548], $rings[1][0]);
	}

	/**
	 * MyMaps wraps a single shape in a MultiGeometry often enough that treating
	 * that as unreadable would throw away ordinary records.
	 */
	public function testAMultiGeometryAroundOneShapeIsUnwrapped(): void {
		$kml = <<<'XML'
		<?xml version="1.0" encoding="UTF-8"?>
		<kml xmlns="http://www.opengis.net/kml/2.2"><Document>
		  <Placemark><name>Plaza envuelta</name><MultiGeometry>
		    <Point><coordinates>-70.58,-33.52</coordinates></Point>
		  </MultiGeometry></Placemark>
		</Document></kml>
		XML;

		$geometry = $this->reader->read($kml, 'prueba')[0]['geometry'];

		self::assertSame('Point', $geometry['type'] ?? null);
		self::assertSame([-70.58, -33.52], $geometry['coordinates']);
	}

	/**
	 * More than one shape is a Multi* geometry, which this app does not store.
	 * It must arrive as something `Geometry::supports()` refuses, so the import
	 * COUNTS it as unsupported and says so — the established answer for a
	 * MultiPolygon in a cadastre. Arriving with no geometry at all would import
	 * it shapeless instead, which looks like a record somebody never located.
	 */
	public function testAMultiGeometryAroundSeveralShapesArrivesUnsupportedRatherThanShapeless(): void {
		$kml = <<<'XML'
		<?xml version="1.0" encoding="UTF-8"?>
		<kml xmlns="http://www.opengis.net/kml/2.2"><Document>
		  <Placemark><name>Dos plazas</name><MultiGeometry>
		    <Point><coordinates>-70.58,-33.52</coordinates></Point>
		    <Point><coordinates>-70.57,-33.51</coordinates></Point>
		  </MultiGeometry></Placemark>
		</Document></kml>
		XML;

		$geometry = $this->reader->read($kml, 'prueba')[0]['geometry'];

		self::assertNotNull($geometry, 'it arrived shapeless, so the import will not count it');
		self::assertFalse(
			\OCA\Territorio\Domain\Geometry::supports($geometry['type'] ?? null),
			'the type must be one the import refuses, so the batch reports it',
		);
	}

	public function testTheFolderNameBecomesTheCategory(): void {
		$rows = $this->reader->read(self::KML, 'prueba');

		self::assertSame('Salud', $rows[0]['category']);
		self::assertSame('Movilidad y transporte', $rows[1]['category']);
	}

	/** No Folder yields no Category rather than a guess. */
	public function testAPlacemarkOutsideAnyFolderCarriesNoCategory(): void {
		$rows = $this->reader->read(self::LOOSE, 'prueba');

		self::assertNull($rows[0]['category']);
	}

	/**
	 * KML has no Subcategory at all, and inventing one would file records under a
	 * precision the file does not have.
	 */
	public function testThereIsNeverASubcategory(): void {
		foreach ($this->reader->read(self::KML, 'prueba') as $row) {
			self::assertNull($row['subcategory']);
		}
	}

	/**
	 * The lossiness, asserted rather than described: a MyMaps export carries no
	 * ExtendedData, so every contact field arrives empty. If this ever starts
	 * failing, the reader has begun inventing values.
	 */
	public function testEverythingKmlCannotCarryArrivesEmpty(): void {
		foreach ($this->reader->read(self::KML, 'prueba') as $row) {
			foreach (['phone', 'email', 'contactPerson', 'contactRole', 'openingHours'] as $absent) {
				self::assertNull($row[$absent], $absent . ' cannot come from KML');
			}
		}
	}

	/**
	 * Ids are derived exactly as they are for a GeoJSON file with no ids, because
	 * they are derived by the same code — reading the same export twice has to
	 * update the same records rather than duplicate them (ADR-0003).
	 */
	public function testTheSameFileReadTwiceYieldsTheSameIds(): void {
		$first = array_column($this->reader->read(self::KML, 'prueba'), 'externalId');
		$second = array_column($this->reader->read(self::KML, 'prueba'), 'externalId');

		self::assertSame($first, $second);
		self::assertCount(3, array_unique($first));
	}

	/**
	 * KML files name several different namespaces depending on what wrote them —
	 * Google Earth desktop still emits `earth.google.com/kml/2.1`. Matching on the
	 * OGC one alone found nothing in those and told the person their file was
	 * empty, which blames the file for the reader's assumption.
	 *
	 * @dataProvider namespaces
	 */
	public function testItReadsWhicheverNamespaceWroteTheFile(string $xmlns): void {
		$xml = sprintf(
			'<kml%s><Document><Folder><name>Salud</name><Placemark><name>CESFAM de Prueba</name>'
			. '<Point><coordinates>-70.58,-33.52</coordinates></Point>'
			. '</Placemark></Folder></Document></kml>',
			$xmlns,
		);

		$rows = $this->reader->read($xml, 'prueba');

		self::assertCount(1, $rows, 'no Placemark found for ' . ($xmlns ?: 'no namespace'));
		self::assertSame('Salud', $rows[0]['category']);
	}

	/** @return array<string, array{string}> */
	public static function namespaces(): array {
		return [
			'OGC 2.2' => [' xmlns="http://www.opengis.net/kml/2.2"'],
			'Google Earth 2.2' => [' xmlns="http://earth.google.com/kml/2.2"'],
			'Google Earth 2.1' => [' xmlns="http://earth.google.com/kml/2.1"'],
			'none at all' => [''],
		];
	}

	/**
	 * A Placemark with no name is scaffolding, not a record — and passing it on
	 * would abort the whole file, because the reader downstream refuses a nameless
	 * row. So it is dropped, and a file that holds nothing else is refused as
	 * empty rather than as invalid.
	 */
	public function testANamelessPlacemarkIsDroppedRatherThanAbortingTheFile(): void {
		$rows = $this->reader->read(
			'<kml xmlns="http://www.opengis.net/kml/2.2"><Document>'
			. '<Placemark><Point><coordinates>-70.58,-33.52</coordinates></Point></Placemark>'
			. '<Placemark><name>Con nombre</name>'
			. '<Point><coordinates>-70.59,-33.52</coordinates></Point></Placemark>'
			. '</Document></kml>',
			'prueba',
		);

		self::assertCount(1, $rows);
		self::assertSame('Con nombre', $rows[0]['name']);
	}

	public function testAFileOfNothingButNamelessPlacemarksIsRefused(): void {
		$this->expectException(InvalidImportException::class);
		$this->reader->read(
			'<kml xmlns="http://www.opengis.net/kml/2.2"><Document><Placemark>'
			. '<Point><coordinates>-70.58,-33.52</coordinates></Point>'
			. '</Placemark></Document></kml>',
			'prueba',
		);
	}

	/**
	 * A named Placemark whose geometry cannot be read is still a record, with no
	 * coordinate — which is a valid state here. Dropping it would lose a row
	 * silently, and the batch's counts exist precisely so nothing does.
	 */
	public function testANamedPlacemarkWithUnreadableGeometryStillArrives(): void {
		$rows = $this->reader->read(
			'<kml xmlns="http://www.opengis.net/kml/2.2"><Document><Placemark>'
			. '<name>Sin ubicación</name><MultiGeometry/>'
			. '</Placemark></Document></kml>',
			'prueba',
		);

		self::assertCount(1, $rows);
		self::assertSame('Sin ubicación', $rows[0]['name']);
		self::assertNull($rows[0]['geometry']);
	}

	public function testSomethingThatIsNotKmlIsRefusedWithSomethingUsable(): void {
		$this->expectException(InvalidImportException::class);
		$this->reader->read('no soy kml', 'prueba');
	}

	/** A description is the one extra thing MyMaps does carry, when someone typed one. */
	public function testADescriptionBecomesTheNotes(): void {
		$rows = $this->reader->read(self::LOOSE, 'prueba');

		self::assertSame('Escrito a mano en el mapa', $rows[0]['notes']);
	}

	/**
	 * A KMZ is a KML in a ZIP, and this reads the one inside it (issue #103).
	 *
	 * Asserted against `read()` on the same bytes rather than against a list of
	 * expected rows: the criterion is that the two arrive IDENTICAL, and a second
	 * list of expectations here would be a second thing to keep in step with the
	 * KML tests above.
	 */
	public function testAKmzIsReadAsTheKmlInsideIt(): void {
		self::assertSame(
			$this->reader->read(self::KML, 'prueba'),
			$this->reader->readArchive(self::kmz(['doc.kml' => self::KML]), 'prueba'),
		);
	}

	/**
	 * `doc.kml` is Google Earth's convention, not the format's rule: the document
	 * is the FIRST `.kml` entry in the archive, and QGIS, ArcGIS and several
	 * exporters write `<capa>.kml`. Asking for `doc.kml` by name would refuse a
	 * perfectly valid file — and blame the file for it.
	 *
	 * The image entry ahead of it is the other half: the first entry of the
	 * archive is very often an icon, so "the first entry" and "the first `.kml`
	 * entry" are different rules and only the second one is the format's.
	 */
	public function testTheDocumentIsTheFirstKmlEntryWhateverItIsCalled(): void {
		self::assertSame(
			$this->reader->read(self::KML, 'prueba'),
			$this->reader->readArchive(
				self::kmz(['images/icono.png' => 'no soy kml', 'La Florida.KML' => self::KML]),
				'prueba',
			),
		);
	}

	/**
	 * A few kilobytes of archive can declare a KML of gigabytes — a zip bomb
	 * (#129). Nine MiB of spaces deflate to almost nothing, and the refusal
	 * must come from the declared size in the central directory, BEFORE the
	 * entry is inflated: an over-cap bomb costs the import a sentence, not
	 * this process's memory.
	 */
	public function testAKmlTooBigToInflateIsRefusedBeforeItIsInflated(): void {
		$this->expectException(InvalidImportException::class);
		$this->expectExceptionMessage('supera los 8 MB al descomprimir');

		$this->reader->readArchive(
			self::kmz(['doc.kml' => str_repeat(' ', 9 * 1024 * 1024)]),
			'prueba',
		);
	}

	/**
	 * The cap refuses nothing legitimate, and the boundary is exclusive: the
	 * KML padded with trailing spaces to exactly 8 MiB imports to the same rows
	 * as the unpadded one (#129) — a `>=` guard would refuse this file.
	 */
	public function testAKmlPaddedToTheCapImportsTheSameRows(): void {
		self::assertSame(
			$this->reader->read(self::KML, 'prueba'),
			$this->reader->readArchive(
				self::kmz(['doc.kml' => str_pad(self::KML, 8 * 1024 * 1024)]),
				'prueba',
			),
		);
	}

	/** An archive holding no KML is refused, in the language the person reads. */
	public function testAnArchiveWithNoKmlEntryIsRefusedInSpanish(): void {
		$this->expectException(InvalidImportException::class);
		$this->expectExceptionMessage('no contiene ningún archivo .kml');

		$this->reader->readArchive(self::kmz(['leeme.txt' => 'nada aquí']), 'prueba');
	}

	/**
	 * Something that is not an archive at all is refused the same way, rather
	 * than reaching the XML parser or leaving a `ZipArchive` warning where a
	 * sentence in Spanish belongs.
	 */
	public function testSomethingThatIsNotAnArchiveIsRefusedRatherThanThrown(): void {
		$this->expectException(InvalidImportException::class);
		$this->expectExceptionMessage('no es un KMZ');

		$this->reader->readArchive('no soy un zip', 'prueba');
	}

	/**
	 * `ZipArchive::open()` takes a path and the ingestion path carries a string —
	 * both the browser upload and `occ territorio:import` read the bytes before
	 * calling the service — so the bytes go through a temporary file. Nothing
	 * else removes it, so both paths out of the method have to.
	 *
	 * Compared as sets and never as a count: the temporary directory is shared,
	 * so a sibling worktree's `make test` running at the same time would make an
	 * assertion about how many files are in it red for something this suite did
	 * not do. What is left behind by this call is what it is asked about.
	 */
	public function testTheTemporaryFileIsGoneOnBothPaths(): void {
		$before = self::strays();
		$this->reader->readArchive(self::kmz(['doc.kml' => self::KML]), 'prueba');

		self::assertSame($before, self::strays(), 'a KMZ that imported left its temporary file behind');

		try {
			$this->reader->readArchive('no soy un zip', 'prueba');
		} catch (InvalidImportException) {
			// The point of the call; the assertion is below it.
		}

		self::assertSame($before, self::strays(), 'a KMZ that was refused left its temporary file behind');
	}

	/**
	 * Temporary files matching the name the reader builds under, so a leak shows
	 * up as a NEW one. See `testTheTemporaryFileIsGoneOnBothPaths` for why this
	 * is never turned into a count.
	 *
	 * @return list<string>
	 */
	private static function strays(): array {
		$found = glob(sys_get_temp_dir() . '/territorio-kmz*') ?: [];
		sort($found);

		return $found;
	}

	/**
	 * An archive holding exactly these entries, built the only way `ZipArchive`
	 * can build one — through a path, because `close()` is what writes an archive
	 * and it writes it to a file.
	 *
	 * @param array<string, string> $entries
	 */
	private static function kmz(array $entries): string {
		$path = tempnam(sys_get_temp_dir(), 'territorio-kmz-fixture-');

		try {
			$zip = new ZipArchive();
			self::assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
			foreach ($entries as $name => $contents) {
				self::assertTrue($zip->addFromString($name, $contents));
			}
			self::assertTrue($zip->close());

			return (string)file_get_contents($path);
		} finally {
			unlink($path);
		}
	}

	private const KML = <<<'XML'
		<?xml version="1.0" encoding="UTF-8"?>
		<kml xmlns="http://www.opengis.net/kml/2.2">
		  <Document>
		    <name>Catastro de prueba</name>
		    <Folder>
		      <name>Salud</name>
		      <Placemark>
		        <name>CESFAM de Prueba</name>
		        <Point><coordinates>-70.58,-33.52,0</coordinates></Point>
		      </Placemark>
		    </Folder>
		    <Folder>
		      <name>Movilidad y transporte</name>
		      <Placemark>
		        <name>Ciclovía de Prueba</name>
		        <LineString><coordinates>
		          -70.60,-33.53,0 -70.59,-33.53,0 -70.58,-33.53,0
		        </coordinates></LineString>
		      </Placemark>
		    </Folder>
		    <Folder>
		      <name>Deporte y recreación</name>
		      <Placemark>
		        <name>Parque de Prueba</name>
		        <Polygon><outerBoundaryIs><LinearRing><coordinates>
		          -70.62,-33.55,0 -70.60,-33.55,0 -70.60,-33.54,0 -70.62,-33.55,0
		        </coordinates></LinearRing></outerBoundaryIs></Polygon>
		      </Placemark>
		    </Folder>
		  </Document>
		</kml>
		XML;

	private const LOOSE = <<<'XML'
		<?xml version="1.0" encoding="UTF-8"?>
		<kml xmlns="http://www.opengis.net/kml/2.2">
		  <Document>
		    <Placemark>
		      <name>Suelto de Prueba</name>
		      <description>Escrito a mano en el mapa</description>
		      <Point><coordinates>-70.55,-33.51</coordinates></Point>
		    </Placemark>
		  </Document>
		</kml>
		XML;
}
