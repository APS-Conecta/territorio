<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Service\GeoJsonReader;
use OCA\Territorio\Service\KmlReader;
use PHPUnit\Framework\TestCase;

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
	 * Slugified here rather than matched against the taxonomy: the reader knows
	 * nothing about what Categories exist, which is the import's job.
	 */
	public function testTheFolderNameBecomesTheCategory(): void {
		$rows = $this->reader->read(self::KML, 'prueba');

		self::assertSame('salud', $rows[0]['categorySlug']);
		self::assertSame('movilidad_transporte', $rows[1]['categorySlug']);
	}

	/** No Folder yields no Category rather than a guess. */
	public function testAPlacemarkOutsideAnyFolderCarriesNoCategory(): void {
		$rows = $this->reader->read(self::LOOSE, 'prueba');

		self::assertNull($rows[0]['categorySlug']);
	}

	/**
	 * KML has no Subcategory at all, and inventing one would file records under a
	 * precision the file does not have.
	 */
	public function testThereIsNeverASubcategory(): void {
		foreach ($this->reader->read(self::KML, 'prueba') as $row) {
			self::assertNull($row['subcategorySlug']);
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

	public function testAPlacemarkWithNoNameIsRefusedLikeAnyOtherRow(): void {
		$this->expectException(InvalidImportException::class);
		$this->reader->read(
			'<kml xmlns="http://www.opengis.net/kml/2.2"><Document><Placemark>'
			. '<Point><coordinates>-70.58,-33.52</coordinates></Point>'
			. '</Placemark></Document></kml>',
			'prueba',
		);
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
