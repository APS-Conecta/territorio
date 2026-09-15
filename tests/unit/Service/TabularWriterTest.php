<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Service\CsvWriter;
use OCA\Territorio\Service\KmlWriter;
use PHPUnit\Framework\TestCase;

/**
 * The two lossy exports (issue #17).
 *
 * CSV is the Datos view as a spreadsheet; KML is geometry and name for anyone
 * who wants to look at it in Google Earth. Neither claims to round-trip, and the
 * criterion for KML is that it SAYS which fields it cannot carry rather than
 * dropping them quietly.
 */
final class TabularWriterTest extends TestCase {
	private const ROW = [
		'externalId' => 'catastro:abc123',
		'name' => 'Junta de Vecinos Los Navegantes',
		'categoryLabel' => 'Organización · Junta de vecinos',
		'geometry' => ['type' => 'Point', 'coordinates' => [-70.5811, -33.5211]],
		'locationQuality' => 'exacta',
		'address' => 'Los Navegantes 1234',
		'phone' => '+56 9 1234 5678',
		'email' => 'contacto@ejemplo.cl',
		'contactPerson' => 'Presidenta',
		'contactRole' => 'Presidenta',
		'openingHours' => 'Lunes a viernes',
		'notes' => 'Entrada por el pasaje',
		'sourceRef' => 'Catastro municipal',
	];

	/**
	 * The same record as it reaches an export from an OSM-derived Dataset.
	 *
	 * `tools/boundary-features.php` stamps `source_es: OpenStreetMap` and a
	 * `source_url` on everything it produces (ADR-0004), and `GeoJsonReader`
	 * joins the two into the `sourceRef` an exported row carries.
	 *
	 * @return array<string, mixed>
	 */
	private static function osmRow(): array {
		return array_merge(self::ROW, [
			'name' => 'Calle Vicuña Mackenna',
			'sourceRef' => 'OpenStreetMap — https://www.openstreetmap.org/way/101',
		]);
	}

	/**
	 * The same imported record after somebody rewrote the Fuente box.
	 *
	 * `sourceRef` IS that box (`src/components/FeatureDetail.vue`) and anybody may
	 * retype it. `official` is the row as the authority published it, written only
	 * by an import — `FeatureService::applyProvenance()` runs for a Provenance and
	 * a person's edit passes none (ADR-0003) — so it still names OpenStreetMap
	 * after the edit, and the geometry in the file is still OpenStreetMap's.
	 *
	 * @return array<string, mixed>
	 */
	private static function editedOsmRow(): array {
		return array_merge(self::ROW, [
			'name' => 'Calle Vicuña Mackenna',
			'sourceRef' => 'Municipalidad',
			'official' => json_encode(
				array_merge(self::ROW, [
					'sourceRef' => 'OpenStreetMap — https://www.openstreetmap.org/way/101',
				]),
				JSON_UNESCAPED_UNICODE,
			),
		]);
	}

	/**
	 * Parse it the way a spreadsheet does, which includes eating the BOM.
	 *
	 * @return list<array<int, string>>
	 */
	private function parse(string $csv): array {
		$rows = [];
		$handle = fopen('php://memory', 'r+');
		fwrite($handle, (string)preg_replace('/^\x{FEFF}/u', '', $csv));
		rewind($handle);
		while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
			$rows[] = $row;
		}
		fclose($handle);

		return $rows;
	}

	/**
	 * The BOM is deliberate and worth a test of its own.
	 *
	 * Excel does not ask about encoding; it guesses, and it guesses Latin-1, so
	 * "Ñuñoa" opens as "Ã'uÃ±oa" on the machines this file is actually opened on.
	 * LibreOffice asks and does not need it. It costs three bytes.
	 */
	public function testTheFileStartsWithAByteOrderMark(): void {
		self::assertStringStartsWith("\u{FEFF}", (new CsvWriter())->write([self::ROW], true));
	}

	public function testTheHeaderNamesTheColumnsInSpanish(): void {
		$rows = $this->parse((new CsvWriter())->write([self::ROW], true));

		self::assertSame('Nombre', $rows[0][0]);
		self::assertContains('Latitud', $rows[0]);
		self::assertContains('Teléfono', $rows[0]);
	}

	public function testWithoutContactsThoseColumnsAreNotThere(): void {
		$header = $this->parse((new CsvWriter())->write([self::ROW], false))[0];

		foreach (['Teléfono', 'Correo', 'Persona de contacto', 'Cargo'] as $column) {
			self::assertNotContains($column, $header);
		}
		self::assertContains('Nombre', $header);
		self::assertContains('Dirección', $header);
	}

	/**
	 * A coordinate goes out as two numbers a spreadsheet can sort.
	 *
	 * Latitude before longitude, which is the order everybody outside GeoJSON
	 * writes them in and the order the app's own form asks for.
	 */
	public function testTheCoordinateBecomesTwoColumns(): void {
		$rows = $this->parse((new CsvWriter())->write([self::ROW], true));
		$row = array_combine($rows[0], $rows[1]);

		self::assertSame('-33.5211', $row['Latitud']);
		self::assertSame('-70.5811', $row['Longitud']);
	}

	/** A record with no coordinate is exported, with the columns left empty. */
	public function testARecordWithoutACoordinateStillHasARow(): void {
		$rows = $this->parse((new CsvWriter())->write(
			[['name' => 'Sin coordenada', 'geometry' => null]],
			true,
		));
		$row = array_combine($rows[0], $rows[1]);

		self::assertSame('Sin coordenada', $row['Nombre']);
		self::assertSame('', $row['Latitud']);
	}

	/**
	 * Only a Point gets latitude and longitude columns.
	 *
	 * A Sector is a polygon; the centre of it is not where the Sector is, and a
	 * spreadsheet column that quietly holds a made-up point is worse than an
	 * empty one.
	 */
	public function testAShapeGetsNoCoordinateColumns(): void {
		$rows = $this->parse((new CsvWriter())->write([[
			'name' => 'Sector Norte',
			'geometry' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0]]]],
		]], true));
		$row = array_combine($rows[0], $rows[1]);

		self::assertSame('', $row['Latitud']);
		self::assertSame('', $row['Longitud']);
	}

	/**
	 * The separator injection a spreadsheet export is famous for.
	 *
	 * A Chilean address has commas in it and a note can have anything in it. If
	 * fields are not quoted, one comma in a name shifts every column after it and
	 * the file is silently wrong from that row down.
	 */
	public function testCommasAndQuotesInAValueDoNotShiftTheColumns(): void {
		$rows = $this->parse((new CsvWriter())->write([[
			'name' => 'Sede "La Amistad", block C',
			'address' => 'Av. La Florida 1234, depto 5',
			'geometry' => null,
		]], true));

		self::assertCount(2, $rows);
		self::assertCount(count($rows[0]), $rows[1]);
		$row = array_combine($rows[0], $rows[1]);
		self::assertSame('Sede "La Amistad", block C', $row['Nombre']);
		self::assertSame('Av. La Florida 1234, depto 5', $row['Dirección']);
	}

	/**
	 * A note that looks like a formula must not become one.
	 *
	 * Excel and LibreOffice execute a cell beginning with =, +, - or @ when the
	 * file is opened. Every user of this instance can edit every record
	 * (ADR-0005), so "somebody typed it" is not a defence: a note reading
	 * `=HYPERLINK(...)` would run on the machine of whoever opened the export,
	 * which is usually not the person who typed it.
	 */
	public function testAValueThatLooksLikeAFormulaIsNotOne(): void {
		$rows = $this->parse((new CsvWriter())->write([[
			'name' => '=HYPERLINK("http://ejemplo.cl","haga clic")',
			'notes' => '@SUM(1+1)',
			'geometry' => null,
		]], true));
		$row = array_combine($rows[0], $rows[1]);

		self::assertStringStartsNotWith('=', $row['Nombre']);
		self::assertStringStartsNotWith('@', $row['Notas']);
		// The text is still all there — quoted, not censored.
		self::assertStringContainsString('HYPERLINK("http://ejemplo.cl","haga clic")', $row['Nombre']);
	}

	/** An ordinary value is untouched, or every cell would carry punctuation. */
	public function testAnOrdinaryValueIsNotQuoted(): void {
		$rows = $this->parse((new CsvWriter())->write([self::ROW], true));
		$row = array_combine($rows[0], $rows[1]);

		self::assertSame('Junta de Vecinos Los Navegantes', $row['Nombre']);
	}

	/**
	 * ODbL asks for the LICENCE to be named, and a CSV can only say it in a cell.
	 *
	 * RFC 4180 has no comment syntax: a line above the header breaks header
	 * detection, and a trailing line of a different width breaks a strict parser
	 * — both of which would be the export "not breaking a consumer" in the only
	 * format most consumers here are a spreadsheet (#28).
	 */
	public function testTheCsvNamesTheLicenceInAColumnOfItsOwn(): void {
		$rows = $this->parse((new CsvWriter())->write([self::osmRow()], false));
		$row = array_combine($rows[0], $rows[1]);

		self::assertArrayHasKey('Licencia', $row);
		self::assertStringContainsString('OpenStreetMap', $row['Licencia']);
		self::assertStringContainsString('ODbL', $row['Licencia']);
		self::assertStringContainsString('https://www.openstreetmap.org/copyright', $row['Licencia']);
	}

	/** Every row the same width as the header, or a strict parser stops reading. */
	public function testTheLicenceColumnDoesNotRaggedTheFile(): void {
		$rows = $this->parse((new CsvWriter())->write([self::ROW, self::osmRow()], true));

		self::assertCount(3, $rows);
		foreach ($rows as $index => $row) {
			self::assertCount(count($rows[0]), $row, "row $index is not as wide as the header");
		}
	}

	/**
	 * Only the rows that ARE OSM's carry the cell.
	 *
	 * A mixed set is the normal case — a comuna's own catastro beside imported
	 * streets — and a notice repeated on every row would say the municipality's
	 * own records belong to OpenStreetMap.
	 */
	public function testOnlyTheOsmRowsCarryTheLicenceCell(): void {
		$rows = $this->parse((new CsvWriter())->write([self::ROW, self::osmRow()], false));
		$local = array_combine($rows[0], $rows[1]);
		$osm = array_combine($rows[0], $rows[2]);

		self::assertSame('', $local['Licencia']);
		self::assertStringContainsString('ODbL', $osm['Licencia']);
	}

	/** And with nothing of OSM's in the set there is no column at all. */
	public function testTheCsvHasNoLicenceColumnWithoutOsmData(): void {
		$header = $this->parse((new CsvWriter())->write([self::ROW], false))[0];

		self::assertNotContains('Licencia', $header);
	}

	/** KML carries the geometry and the name, and that is the whole claim. */
	public function testKmlCarriesTheNameAndTheGeometry(): void {
		$kml = (new KmlWriter())->write([self::ROW]);

		self::assertStringContainsString('<name>Junta de Vecinos Los Navegantes</name>', $kml);
		self::assertStringContainsString('-70.5811,-33.5211', $kml);
	}

	/**
	 * A Polygon's holes survive the export, because they survive the import.
	 *
	 * `KmlReader` keeps `innerBoundaryIs` deliberately — `KmlReaderTest::testAPolygonKeepsItsHoles`
	 * pins it — and `PointInPolygon` counts those rings out when it decides what a boundary
	 * contains. The writer kept only `$rings[0]`, so a Unidad Vecinal imported with a lagoon cut out
	 * of it exported as a solid shape, silently, in the format most likely to be forwarded to a
	 * municipality.
	 *
	 * The second assertion is not redundant with the count: `$rings[1]` must be the HOLE, so it also
	 * pins that `innerBoundaryIs` comes after `outerBoundaryIs`. Emitting them the other way round
	 * is schema-invalid KML with the same LinearRing count, and only this catches it.
	 */
	public function testKmlKeepsAPolygonsHoles(): void {
		$outer = [[-70.62, -33.55], [-70.60, -33.55], [-70.60, -33.54], [-70.62, -33.54], [-70.62, -33.55]];
		$hole = [[-70.615, -33.548], [-70.605, -33.548], [-70.605, -33.543], [-70.615, -33.548]];
		$row = ['nombre' => 'Parque con laguna', 'geometry' => ['type' => 'Polygon', 'coordinates' => [$outer, $hole]]];

		$kml = (new KmlWriter())->write([$row]);

		$doc = simplexml_load_string($kml);
		self::assertNotFalse($doc, 'the export must be parseable XML');
		$rings = $doc->Document->Placemark->Polygon->xpath('.//*[local-name()="LinearRing"]');
		self::assertCount(2, $rings, 'the hole was dropped, so the export claims what is inside it');
		self::assertStringContainsString('-70.615,-33.548', (string)$rings[1]->coordinates);
	}

	/**
	 * The criterion: it states what it cannot carry, IN the file.
	 *
	 * In the file rather than only in the dialog, because the file is what gets
	 * emailed to somebody who never saw the dialog.
	 */
	public function testKmlSaysWhatItCannotCarry(): void {
		$kml = (new KmlWriter())->write([self::ROW]);

		self::assertStringContainsString('teléfono', mb_strtolower($kml));
		self::assertStringContainsString('categoría', mb_strtolower($kml));
	}

	/**
	 * The notice has to keep up with what the app stores.
	 *
	 * It was written when there was no Limit and stayed true by accident until
	 * there was one. A notice listing what a file omits is only useful while it
	 * is complete, and nothing else would notice it going stale.
	 */
	public function testTheKmlNoticeMentionsTheLimit(): void {
		self::assertStringContainsString('límite', mb_strtolower((new KmlWriter())->write([])));
	}

	/**
	 * The KML says the licence in the one place the format lets it (#28).
	 *
	 * Appended to the `<description>` this Document already has, rather than
	 * given an `<atom:author>` of its own: that would need a namespace
	 * declaration for a line nobody opening the file would read any differently.
	 */
	public function testKmlNamesTheLicenceWhenItCarriesOsmData(): void {
		$kml = (new KmlWriter())->write([self::osmRow()]);

		$doc = simplexml_load_string($kml);
		self::assertNotFalse($doc, 'the export must be parseable XML');
		$description = (string)$doc->Document->description;
		self::assertStringContainsString('OpenStreetMap', $description);
		self::assertStringContainsString('ODbL', $description);
	}

	/** And a KML of the clinic's own records does not claim OSM wrote them. */
	public function testKmlCarriesNoLicenceWithoutOsmData(): void {
		self::assertStringNotContainsString('ODbL', (new KmlWriter())->write([self::ROW]));
	}

	/** And it does not carry them, whatever the caller passed in. */
	public function testKmlNeverCarriesContactDetails(): void {
		$kml = (new KmlWriter())->write([self::ROW]);

		self::assertStringNotContainsString('+56 9 1234 5678', $kml);
		self::assertStringNotContainsString('contacto@ejemplo.cl', $kml);
	}

	/** A name with an ampersand in it must not produce a broken document. */
	public function testKmlEscapesWhatXmlCannotHold(): void {
		$kml = (new KmlWriter())->write([[
			'name' => 'Salud & Vida <primera>',
			'geometry' => ['type' => 'Point', 'coordinates' => [-70.5, -33.5]],
		]]);

		self::assertStringNotContainsString('<primera>', $kml);
		self::assertNotFalse(simplexml_load_string($kml), 'the KML should parse');
	}

	/**
	 * Rewriting Fuente must not drop the licence from the spreadsheet (#28).
	 *
	 * Same failure as in the GeoJSON and just as invisible: the OSM rows are still
	 * there and the column saying whose they are has gone.
	 */
	public function testAnEditedFuenteDoesNotStripTheLicenceCell(): void {
		$rows = $this->parse((new CsvWriter())->write([self::editedOsmRow()], false));
		$row = array_combine($rows[0], $rows[1]);

		self::assertArrayHasKey('Licencia', $row);
		self::assertStringContainsString('ODbL', $row['Licencia']);
	}

	/** And the KML says it too, from the same unedited copy. */
	public function testAnEditedFuenteDoesNotStripTheLicenceFromTheKml(): void {
		$kml = (new KmlWriter())->write([self::editedOsmRow()]);

		self::assertStringContainsString('ODbL', $kml);
	}

	/**
	 * And the copy they read that from never reaches either file.
	 *
	 * `official` is the whole published row, contact fields included. It rides in
	 * the export row only so the licence can be decided, and a CSV written without
	 * contact columns that carried the telephone number inside a JSON cell would
	 * break exactly the promise ADR-0006 makes.
	 */
	public function testTheOfficialCopyNeverBecomesAColumnOrACell(): void {
		$row = self::editedOsmRow();
		$csv = (new CsvWriter())->write([$row], false);
		$kml = (new KmlWriter())->write([$row]);

		self::assertNotContains('official', $this->parse($csv)[0]);
		self::assertStringNotContainsString('contacto@ejemplo.cl', $csv);
		self::assertStringNotContainsString('contacto@ejemplo.cl', $kml);
	}
}
