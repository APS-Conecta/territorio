<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\CategoryMapper;
use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\ImportBatch;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Service\ImportService;
use OCA\Territorio\Domain\LocationQuality;
use OCA\Territorio\Service\RevertService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Server;

/**
 * A third-party spreadsheet, through the same pipeline GeoJSON uses (#101).
 *
 * The point of running this against the real database rather than against
 * `CsvReader` alone is the criterion it exists for: the rows a CSV produces have
 * to be the rows a GeoJSON produces. That claim is only testable where the rows
 * are written — so the same records are imported twice, once from each format,
 * and the second run has to report them **unchanged**. Inspecting the reader
 * would compare two arrays this test wrote itself.
 *
 * Every value is invented; the headings are the Spanish ones `CsvWriter` emits,
 * because those are the words a clinic editing a spreadsheet already sees.
 */
final class CsvImportTest extends IntegrationTestCase {
	private const DATASET = 'csv-prueba';

	/**
	 * What a clinic sends: the app's own headings, comma-delimited, dot decimals
	 * — and the classification written the way the Capas panel shows it,
	 * «Salud» · «CESFAM», because that is what a person copies off the screen.
	 */
	private const CSV = "Nombre,Categoría,Subcategoría,Latitud,Longitud,Dirección,Teléfono,"
		. "Correo,Persona de contacto,Cargo,Horario,Notas,Ubicación\n"
		. 'CESFAM de Prueba,Salud,CESFAM,-33.5211,-70.5991,"Avenida Uno 123, local 4",'
		. "229112233,cesfam@ejemplo.cl,Marcela Ríos,Directora,Lunes a viernes,Sin novedad,exacta\n";

	/**
	 * The same record, said in GeoJSON. Not a different record with the same
	 * name: every field is the one the CSV row carries — including the raw
	 * classification labels, which both readers now pass through unchanged
	 * so `differs()` sees the same official blob — so a re-import has
	 * nothing to write, which is the assertion.
	 */
	private const GEOJSON = <<<'JSON'
	{"type":"FeatureCollection","features":[
	  {"type":"Feature","geometry":{"type":"Point","coordinates":[-70.5991,-33.5211]},
	   "properties":{"name":"CESFAM de Prueba","category":"Salud","subcategory":"CESFAM",
	     "ubicacion":"exacta","address":"Avenida Uno 123, local 4","phone":"229112233",
	     "email":"cesfam@ejemplo.cl","contact_person":"Marcela Ríos","contact_role":"Directora",
	     "opening_hours":"Lunes a viernes","notes":"Sin novedad"}}
	]}
	JSON;

	/**
	 * What Excel in es-CL saves for the same file: `;` between the cells, because
	 * the comma is this locale's decimal separator.
	 */
	private const EXCEL_ES_CL = "Nombre;Categoría;Subcategoría;Latitud;Longitud;Dirección;Teléfono;"
		. "Correo;Persona de contacto;Cargo;Horario;Notas;Ubicación\n"
		. "CESFAM de Prueba;Salud;CESFAM;-33,5211;-70,5991;Avenida Uno 123, local 4;"
		. "229112233;cesfam@ejemplo.cl;Marcela Ríos;Directora;Lunes a viernes;Sin novedad;exacta\n";

	protected function setUp(): void {
		$this->forgetDataset(self::DATASET);
	}

	protected function tearDown(): void {
		$this->forgetDataset(self::DATASET);
		parent::tearDown();
	}

	public function testAMinimalCsvCreatesAFeatureADatasetAndABatch(): void {
		$batch = $this->import("Nombre,Latitud,Longitud\nJardín de Prueba,-33.52,-70.58\n");

		self::assertSame(1, $batch->getCreated());
		self::assertSame('tester', $batch->getActor());

		$dataset = Server::get(DatasetMapper::class)->findBySlug(self::DATASET);
		self::assertSame($batch->getDatasetId(), (int)$dataset->getId());

		$stored = array_values($this->stored());
		self::assertCount(1, $stored);
		self::assertSame('Jardín de Prueba', $stored[0]->getName());
	}

	/** ADR-0009: a CSV import is a batch like any other, so the undo works on it. */
	public function testItIsRevertibleLikeAnyOtherImport(): void {
		$batch = $this->import(self::CSV);
		self::assertSame(1, $batch->getCreated());

		$revert = Server::get(RevertService::class)->revert((int)$batch->getId(), null);

		self::assertSame(1, $revert->getUpdated());
		$stored = array_values($this->stored());
		self::assertCount(1, $stored);
		self::assertTrue((bool)$stored[0]->getRetired(), 'a reverted CSV batch leaves the map');
	}

	/**
	 * The criterion this slice turns on: the rows a CSV produces ARE the rows a
	 * GeoJSON produces.
	 *
	 * Proven by importing both into the same Dataset and reading the second
	 * batch's counts. `differs()` compares the whole official blob and then every
	 * field `input()` carries, so **one** field arriving under a different key,
	 * with a different type, or trimmed differently would report an update here.
	 * Nothing changing is the whole assertion — and the external id matching at
	 * all is half of it, since that id is derived from the name and the position.
	 */
	public function testACsvRowIsTheSameRowTheSameRecordInGeoJsonProduces(): void {
		self::assertSame(1, $this->import(self::CSV)->getCreated());

		$again = Server::get(ImportService::class)->importFile(
			self::GEOJSON, 'datos.geojson', self::DATASET, 'Prueba CSV', 'tester',
		);

		self::assertSame(0, $again->getCreated(), 'the GeoJSON derived a different external id');
		self::assertSame(0, $again->getUpdated(), 'the CSV wrote something the GeoJSON says differently');
		self::assertSame(1, $again->getUnchanged());
	}

	/**
	 * The file a Chilean clinic actually sends imports to the same records as its
	 * comma-and-dot twin. Asserted through a second import for the same reason as
	 * above: if the semicolon file produced even slightly different rows, this
	 * would report an update or a second record instead of nothing.
	 */
	public function testAnExcelEsClFileImportsToTheSameRecordsAsItsDotDecimalTwin(): void {
		self::assertSame(1, $this->import(self::CSV)->getCreated());

		$again = $this->import(self::EXCEL_ES_CL);

		self::assertSame(0, $again->getCreated(), 'the decimal comma moved the record, so the id changed');
		self::assertSame(0, $again->getUpdated());
		self::assertSame(1, $again->getUnchanged());
	}

	/**
	 * The pair of columns is what `classify()` needs, and a CSV is the only
	 * import format that can carry both — which is the reason the reader emits
	 * `category` and `subcategory` rather than one label.
	 *
	 * The cells say «Salud» and «CESFAM», not `salud` and `cesfam`: `classify()`
	 * looks the pair up by SLUG, so this only files where the reader folds what a
	 * person typed. A pre-slugged fixture asserted nothing about the file a
	 * clinic actually sends.
	 */
	public function testTheCategoryAndSubcategoryColumnsFileTheRecord(): void {
		$this->import(self::CSV);

		$stored = array_values($this->stored());
		self::assertCount(1, $stored);
		self::assertSame('Salud · CESFAM', $this->labelOf($stored[0]->getSubcategoryId()));
	}

	/**
	 * A row with no coordinates is a record, not an error — and the Registry
	 * derives its Location quality rather than storing what the file said.
	 */
	public function testARowWithNoCoordinatesImportsWithNoGeometry(): void {
		$this->import("Nombre,Latitud,Longitud,Dirección\nJunta de Prueba,,,Avenida Uno 123\n");

		$stored = array_values($this->stored());
		self::assertCount(1, $stored);
		self::assertNull($stored[0]->getGeometry());
		self::assertSame(LocationQuality::Absent->value, $stored[0]->getLocationQuality());
	}

	/**
	 * A file whose header names no «Nombre» is refused before anything is
	 * written — not even the Dataset, which is created only once the whole file
	 * has been read.
	 */
	public function testAFileWithoutANombreColumnImportsNothingAtAll(): void {
		try {
			$this->import("Dirección,Teléfono\nAvenida Uno 123,229112233\n");
			self::fail('a CSV with no Nombre column was accepted');
		} catch (InvalidImportException $e) {
			self::assertStringContainsString('Nombre', $e->getMessage());
		}

		$this->expectException(DoesNotExistException::class);
		Server::get(DatasetMapper::class)->findBySlug(self::DATASET);
	}

	private function import(string $csv): ImportBatch {
		return Server::get(ImportService::class)->importFile(
			$csv, 'catastro.csv', self::DATASET, 'Prueba CSV', 'tester',
		);
	}

	/** @return array<string, Feature> */
	private function stored(): array {
		$dataset = Server::get(DatasetMapper::class)->findBySlug(self::DATASET);

		return Server::get(FeatureMapper::class)->findByDataset((int)$dataset->getId());
	}

	private function labelOf(int $subcategoryId): string {
		$subcategory = Server::get(SubcategoryMapper::class)->find($subcategoryId);

		foreach (Server::get(CategoryMapper::class)->findAll() as $category) {
			if ((int)$category->getId() === (int)$subcategory->getCategoryId()) {
				return $category->getLabel() . ' · ' . $subcategory->getLabel();
			}
		}

		return $subcategory->getLabel();
	}
}
