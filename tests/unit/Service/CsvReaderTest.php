<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Service\CsvReader;
use OCA\Territorio\Service\GeoJsonReader;
use PHPUnit\Framework\TestCase;

/**
 * Reading a third-party spreadsheet (#101).
 *
 * The file this is for is the one a clinic sends: a handful of columns in
 * Spanish, saved from Excel in es-CL, which means `;` between the cells and a
 * decimal comma inside the coordinates. Every value here is invented; what is
 * real is the SHAPE — the headings are the ones `CsvWriter` emits, because those
 * are the words the people using this app already see.
 *
 * The rows are not built here. They come out of `GeoJsonReader`, which is what
 * `KmlReader` does too, so the three formats cannot drift into importing
 * differently.
 */
final class CsvReaderTest extends TestCase {
	private CsvReader $reader;

	protected function setUp(): void {
		$this->reader = new CsvReader(new GeoJsonReader());
	}

	/** The header a clinic gets when it exports from this app and edits it. */
	private const HEADINGS = 'Nombre,Categoría,Subcategoría,Latitud,Longitud,Dirección,'
		. 'Teléfono,Correo,Persona de contacto,Cargo,Horario,Notas,Fuente,Ubicación';

	/**
	 * The classification cells carry the LABELS, because that is what a person
	 * copies off the screen: the Capas panel says «Salud» and «CESFAM», not
	 * `salud` and `cesfam`. A fixture that was already slugged only tested the
	 * input the reader happened to handle.
	 */
	private const ONE_ROW = self::HEADINGS . "\n"
		. 'CESFAM de Prueba,Salud,CESFAM,-33.5211,-70.5991,"Avenida Uno 123, local 4",'
		. "229112233,cesfam@ejemplo.cl,Marcela Ríos,Directora,Lunes a viernes,Sin novedad,"
		. "Catastro municipal,exacta\n";

	public function testAMinimalFileBecomesOneRow(): void {
		$rows = $this->reader->read("Nombre\nCESFAM de Prueba\n", 'prueba');

		self::assertCount(1, $rows);
		self::assertSame('CESFAM de Prueba', $rows[0]['name']);
		self::assertNull($rows[0]['geometry'], 'a row with no coordinates is still a record');
	}

	/**
	 * Every column the reader knows, read into the keys the rest of the ingestion
	 * path already expects. Asserted as a whole row rather than field by field:
	 * what matters is that the shape IS `GeoJsonReader`'s, not that one cell
	 * arrived.
	 */
	public function testEveryColumnLandsWhereTheRestOfTheImportLooksForIt(): void {
		$row = $this->reader->read(self::ONE_ROW, 'prueba')[0];

		self::assertSame([
			'externalId' => $row['externalId'],
			'name' => 'CESFAM de Prueba',
			'category' => 'Salud',
			'subcategory' => 'CESFAM',
			'geometry' => ['type' => 'Point', 'coordinates' => [-70.5991, -33.5211]],
			'locationQuality' => 'exacta',
			'address' => 'Avenida Uno 123, local 4',
			'phone' => '229112233',
			'email' => 'cesfam@ejemplo.cl',
			'contactPerson' => 'Marcela Ríos',
			'contactRole' => 'Directora',
			'openingHours' => 'Lunes a viernes',
			'notes' => 'Sin novedad',
			'sourceRef' => 'Catastro municipal',
			'comuna' => '',
		], $row);
	}

	/**
	 * The two classification cells keep their raw labels — one matching home,
	 * `ImportService::classify()`, which tries the label the Capas panel shows
	 * before any slug (L1-06). A clinic that types «Educación», «Jardín
	 * infantil» now classifies through that label path.
	 */
	public function testTheClassificationCellsKeepTheirRawLabels(): void {
		$row = $this->reader->read(
			"Nombre,Categoría,Subcategoría\nJardín de Prueba,Educación,Jardín infantil\n",
			'prueba',
		)[0];

		self::assertSame('Educación', $row['category']);
		self::assertSame('Jardín infantil', $row['subcategory']);
	}

	/**
	 * A quoted comma is one cell, not two. A Chilean address has commas in it,
	 * and one unquoted comma shifts every column after it for the rest of the
	 * row — which is why this is parsed rather than exploded.
	 */
	public function testACommaInsideAQuotedCellDoesNotShiftTheColumns(): void {
		$row = $this->reader->read(self::ONE_ROW, 'prueba')[0];

		self::assertSame('Avenida Uno 123, local 4', $row['address']);
		self::assertSame('229112233', $row['phone']);
	}

	/**
	 * A line break inside a quoted cell is part of the cell, not the end of the
	 * row — somebody types one into a Notas column and a spreadsheet saves it
	 * quoted. This is why the file is read as a stream rather than split into
	 * lines first: only the reader that is mid-quote knows the row has not ended.
	 */
	public function testALineBreakInsideAQuotedCellDoesNotEndTheRow(): void {
		$rows = $this->reader->read(
			"Nombre,Notas\nCESFAM de Prueba,\"Atiende de 8 a 14.\nCerrado en enero.\"\n",
			'prueba',
		);

		self::assertCount(1, $rows);
		self::assertSame("Atiende de 8 a 14.\nCerrado en enero.", $rows[0]['notes']);
	}

	/**
	 * Excel writes a BOM in front of a UTF-8 CSV — this app's own writer does
	 * too. Left in, it would be part of the first heading and «Nombre» would stop
	 * being «Nombre», so the whole file would be refused for a reason nobody can
	 * see.
	 *
	 * Nothing strips it. Headings are matched on their slug, and the marker is
	 * neither a letter nor a digit, so the folding takes it off with the spaces
	 * and the accents. This test is what holds that: matching a heading literally
	 * instead would refuse a file every spreadsheet in the country produces.
	 */
	public function testAByteOrderMarkIsNotPartOfTheFirstHeading(): void {
		$plain = $this->reader->read(self::ONE_ROW, 'prueba');
		$marked = $this->reader->read("\u{FEFF}" . self::ONE_ROW, 'prueba');

		self::assertSame($plain, $marked);
	}

	/**
	 * Encoding is decided, not guessed: a file that is not valid UTF-8 is
	 * Windows-1252, which is what Excel writes when it is not asked for UTF-8.
	 * The two halves are one test because each is only meaningful beside the
	 * other — a reader that converted everything would pass the first alone.
	 */
	public function testAFileThatIsNotValidUtf8IsReadAsWindows1252(): void {
		$utf8 = "Nombre\nJardín Ñuñoa\n";
		$latin = (string)mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');

		self::assertFalse(mb_check_encoding($latin, 'UTF-8'), 'the fixture is not the case being tested');
		self::assertSame('Jardín Ñuñoa', $this->reader->read($latin, 'prueba')[0]['name']);
		self::assertSame('Jardín Ñuñoa', $this->reader->read($utf8, 'prueba')[0]['name']);
	}

	/**
	 * What a Chilean clinic's Excel actually saves: `;` between the cells,
	 * because the comma is already the decimal separator. Asserted against its
	 * comma-and-dot twin as WHOLE rows, derived external ids included — two files
	 * saying the same thing have to import to the same records, or re-saving a
	 * spreadsheet in the other locale would duplicate the whole file.
	 */
	public function testAnExcelEsClFileImportsToTheSameRowsAsItsDotDecimalTwin(): void {
		$semicolons = "Nombre;Latitud;Longitud;Dirección\n"
			. "CESFAM de Prueba;-33,5211;-70,5991;Avenida Uno 123\n";
		$commas = "Nombre,Latitud,Longitud,Dirección\n"
			. "CESFAM de Prueba,-33.5211,-70.5991,Avenida Uno 123\n";

		self::assertSame(
			$this->reader->read($commas, 'prueba'),
			$this->reader->read($semicolons, 'prueba'),
		);
	}

	/** A file whose header names no «Nombre» is refused, in the app's language. */
	public function testAFileWithoutANombreColumnIsRefused(): void {
		$this->expectException(InvalidImportException::class);
		$this->expectExceptionMessageMatches('/Nombre/');

		$this->reader->read("Dirección,Teléfono\nAvenida Uno 123,229112233\n", 'prueba');
	}

	public function testAnEmptyFileIsRefused(): void {
		$this->expectException(InvalidImportException::class);

		$this->reader->read('', 'prueba');
	}

	/**
	 * A spreadsheet always carries columns nobody here asked for — a code, a
	 * total, a note to the person who filled it in. Refusing the file for them
	 * would make this unusable on a real file.
	 */
	public function testAnUnknownColumnIsIgnoredRatherThanRefused(): void {
		$rows = $this->reader->read(
			"Nombre,Sitio web,Código interno\nCESFAM de Prueba,https://ejemplo.cl,A-17\n",
			'prueba',
		);

		self::assertCount(1, $rows);
		self::assertSame('CESFAM de Prueba', $rows[0]['name']);
	}

	/**
	 * The columns are an allow-list, and that is a guard rather than tidiness: a
	 * heading that slugs to a name the row builder DOES read — `uid` is the one
	 * that costs something — would take over the derived external id, and the
	 * same file saved with one extra column would import as a second set of
	 * records instead of updating the first (ADR-0003).
	 */
	public function testAColumnThisReaderDoesNotKnowCannotReachIntoTheDerivedId(): void {
		$plain = $this->reader->read("Nombre,Latitud,Longitud\nCESFAM de Prueba,-33.52,-70.58\n", 'prueba');
		$withUid = $this->reader->read(
			"Nombre,uid,Latitud,Longitud\nCESFAM de Prueba,hoja-de-calculo-7,-33.52,-70.58\n",
			'prueba',
		);

		self::assertSame($plain[0]['externalId'], $withUid[0]['externalId']);
		self::assertStringStartsWith('prueba:', $withUid[0]['externalId']);
	}

	/**
	 * A row with no coordinates is a record, not an error: the catastro holds
	 * organisations whose address never resolved, and the Registry stores them.
	 */
	public function testARowWithNoCoordinatesArrivesWithNoGeometry(): void {
		$rows = $this->reader->read(
			"Nombre,Latitud,Longitud\nJunta de Vecinos de Prueba,,\n",
			'prueba',
		);

		self::assertNull($rows[0]['geometry']);
	}

	/**
	 * Half a coordinate is not a position. Taken as none rather than as an error,
	 * for the same reason the whole row is kept: the record is real, the cell is
	 * not, and refusing the file would cost the person every other row in it.
	 */
	public function testHalfACoordinateIsNoCoordinate(): void {
		$rows = $this->reader->read("Nombre,Latitud,Longitud\nA medias,-33.52,\n", 'prueba');

		self::assertNull($rows[0]['geometry']);
	}

	/**
	 * The blank line a spreadsheet leaves at the end of the file is not a
	 * nameless record — and a nameless record aborts the whole import, so this is
	 * the difference between importing the file and refusing it.
	 */
	public function testTheTrailingBlankLineASpreadsheetLeavesIsNotARow(): void {
		$rows = $this->reader->read("Nombre\nCESFAM de Prueba\n\n", 'prueba');

		self::assertCount(1, $rows);
	}

	/**
	 * A row that HAS cells but no name is refused, by the same rule that refuses
	 * a nameless GeoJSON feature — it is unfindable in the list and
	 * unidentifiable in the duplicate queue.
	 */
	public function testARowWithCellsButNoNameIsRefused(): void {
		$this->expectException(InvalidImportException::class);

		$this->reader->read("Nombre,Dirección\n,Avenida Uno 123\n", 'prueba');
	}

	/**
	 * Ids are derived by `GeoJsonReader`, so reading the same spreadsheet twice
	 * updates the same records rather than duplicating them (ADR-0003).
	 */
	public function testTheSameFileReadTwiceYieldsTheSameIds(): void {
		self::assertSame(
			array_column($this->reader->read(self::ONE_ROW, 'prueba'), 'externalId'),
			array_column($this->reader->read(self::ONE_ROW, 'prueba'), 'externalId'),
		);
	}

	/** Headings are matched by their words, so a missing accent still matches. */
	public function testAHeadingTypedWithoutItsAccentStillMatches(): void {
		$rows = $this->reader->read("Nombre,Telefono\nCESFAM de Prueba,229112233\n", 'prueba');

		self::assertSame('229112233', $rows[0]['phone']);
	}
}
