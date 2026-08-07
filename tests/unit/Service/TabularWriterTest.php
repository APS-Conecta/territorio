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

	/** KML carries the geometry and the name, and that is the whole claim. */
	public function testKmlCarriesTheNameAndTheGeometry(): void {
		$kml = (new KmlWriter())->write([self::ROW]);

		self::assertStringContainsString('<name>Junta de Vecinos Los Navegantes</name>', $kml);
		self::assertStringContainsString('-70.5811,-33.5211', $kml);
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
}
