<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Service\ImportService;
use OCA\Territorio\Domain\Kind;
use OCA\Territorio\Service\RevertService;
use OCA\Territorio\Service\TaxonomyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ZipArchive;

/**
 * A Google MyMaps export, through the same pipeline GeoJSON uses.
 *
 * Shaped like the real a real comuna's Google My Maps export — Folders holding
 * Placemarks, geometry and a name and nothing else — with every value invented.
 * What was checked against the real file is the shape: 865 placemarks and not
 * one `ExtendedData` element, so no phone, email, contact or source anywhere.
 */
final class KmlImportTest extends IntegrationTestCase {
	private const SLUG = 'mymaps-prueba';

	/** @var int[] */
	private array $datasetIds = [];

	/** @var int[] Subcategories this test added, to be removed after its Features */
	private array $addedSubcategories = [];

	protected function tearDown(): void {
		parent::tearDown();

		$db = Server::get(IDBConnection::class);
		foreach ($this->datasetIds as $id) {
			foreach (['territorio_import' => 'dataset_id', 'territorio_dataset' => 'id'] as $table => $column) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->eq($column, $qb->createNamedParameter($id)))
					->executeStatement();
			}
		}
		$this->datasetIds = [];

		// Not behind the Dataset sweep: a run that throws before its Dataset is
		// recorded has still added the Subcategory. Raw SQL, not
		// `removeSubcategory()`, which refuses while anything is filed under the
		// row (CategoryCrudTest, #134).
		if ($this->addedSubcategories !== []) {
			$qb = $db->getQueryBuilder();
			$qb->delete('territorio_subcategory')
				->where($qb->expr()->in('id', $qb->createNamedParameter(
					$this->addedSubcategories, IQueryBuilder::PARAM_INT_ARRAY,
				)))
				->executeStatement();
			$this->addedSubcategories = [];
		}
	}

	/** The criterion: Points, LineStrings and Polygons all arrive. */
	public function testItImportsEveryGeometryAMyMapsExportHolds(): void {
		$batch = $this->import();

		self::assertSame(3, $batch->getCreated());

		$kinds = array_map(
			static fn ($feature): string => $feature->getKind(),
			array_values($this->stored()),
		);
		sort($kinds);

		self::assertSame([Kind::Place->value, Kind::Route->value, Kind::Zone->value], $kinds);
	}

	/**
	 * The Folder is the only classification the file carries. Without a
	 * category-only match tier every record here would land in Sin clasificar
	 * while the Folder sat there naming the right Category.
	 */
	public function testAFolderNameLandsInThatCategory(): void {
		$this->import();

		$labels = [];
		foreach ($this->stored() as $feature) {
			$labels[$feature->getName()] = $this->labelOf($feature->getSubcategoryId());
		}

		self::assertSame('Salud · Otro', $labels['CESFAM de Prueba']);
		self::assertSame('Deporte y recreación · Otro', $labels['Parque de Prueba']);
	}

	/**
	 * The Category-only tier is for a file that named no Subcategory at all,
	 * which is KML. A GeoJSON that DID name one this install lacks keeps falling
	 * to Sin clasificar: guessing at that Category's Otro would also move records
	 * that already exist, because `differs()` compares the Subcategory — an
	 * unchanged re-import would quietly re-file rows and spend a Revision.
	 */
	public function testNamingASubcategoryThatDoesNotExistStillLandsInSinClasificar(): void {
		$json = json_encode([
			'type' => 'FeatureCollection',
			'features' => [[
				'type' => 'Feature',
				'id' => 'geo/1',
				'properties' => ['name' => 'Con subcategoría inventada', 'category' => 'salud', 'subcategory' => 'no_existe'],
				'geometry' => ['type' => 'Point', 'coordinates' => [-70.58, -33.52]],
			]],
		], JSON_THROW_ON_ERROR);

		Server::get(ImportService::class)->importFile($json, 'inventada.geojson', self::SLUG, null, 'prueba');
		$id = (int)Server::get(DatasetMapper::class)->findBySlug(self::SLUG)->getId();
		$this->datasetIds[] = $id;
		$stored = Server::get(FeatureMapper::class)->findByDataset($id)['geo/1'];
		$this->created[] = (int)$stored->getId();

		self::assertSame('Sin clasificar · Otro', $this->labelOf($stored->getSubcategoryId()));
	}

	/**
	 * Not every MyMaps folder is a Category. Two of the thirteen in the real
	 * export are Subcategory-level — "Ciclovías" holds 39 placemarks and "Plazas
	 * y parques" 270 — so matching Categories alone left 309 of 865 records, 36%
	 * of the file, in Sin clasificar while `ciclovia` and `plaza` sat in the seed.
	 *
	 * The folder names here are the real ones, because that is the whole point of
	 * the test; the placemarks under them are invented.
	 */
	public function testAFolderNamedAfterASubcategoryFindsIt(): void {
		$kml = <<<'XML'
			<?xml version="1.0" encoding="UTF-8"?>
			<kml xmlns="http://www.opengis.net/kml/2.2"><Document>
			  <Folder><name>Ciclovías</name>
			    <Placemark><name>Ciclovía de Prueba</name>
			      <LineString><coordinates>-70.60,-33.53 -70.58,-33.53</coordinates></LineString>
			    </Placemark>
			  </Folder>
			  <Folder><name>Plazas y parques</name>
			    <Placemark><name>Plaza de Prueba</name>
			      <Point><coordinates>-70.59,-33.52</coordinates></Point>
			    </Placemark>
			  </Folder>
			</Document></kml>
			XML;

		Server::get(ImportService::class)->importFile($kml, 'folders.kml', self::SLUG, null, 'prueba');
		$id = (int)Server::get(DatasetMapper::class)->findBySlug(self::SLUG)->getId();
		$this->datasetIds[] = $id;

		$labels = [];
		foreach (Server::get(FeatureMapper::class)->findByDataset($id) as $feature) {
			$this->created[] = (int)$feature->getId();
			$labels[$feature->getName()] = $this->labelOf($feature->getSubcategoryId());
		}

		self::assertSame('Movilidad y transporte · Ciclovía', $labels['Ciclovía de Prueba']);
		self::assertSame('Deporte y recreación · Plaza o parque', $labels['Plaza de Prueba']);
	}

	/**
	 * A MyMaps folder of sector polygons is not a set of Sectors: a Sector is
	 * drawn by the clinic from picked Boundary features (ADR-0007), and a file
	 * reaches a division only by naming «División territorial» and the
	 * Subcategory. «Colegios» still answers, being the first word that names
	 * exactly one Subcategory (#204).
	 */
	public function testAFolderNamingASectorNeverReachesTheDivision(): void {
		$kml = <<<'XML'
			<?xml version="1.0" encoding="UTF-8"?>
			<kml xmlns="http://www.opengis.net/kml/2.2"><Document>
			  <Folder><name>Sector Norte</name>
			    <Placemark><name>Polígono Norte</name>
			      <Polygon><outerBoundaryIs><LinearRing><coordinates>
			        -70.62,-33.50 -70.61,-33.50 -70.61,-33.49 -70.62,-33.50
			      </coordinates></LinearRing></outerBoundaryIs></Polygon>
			    </Placemark>
			  </Folder>
			  <Folder><name>Sector 1</name>
			    <Placemark><name>Polígono Uno</name>
			      <Polygon><outerBoundaryIs><LinearRing><coordinates>
			        -70.60,-33.50 -70.59,-33.50 -70.59,-33.49 -70.60,-33.50
			      </coordinates></LinearRing></outerBoundaryIs></Polygon>
			    </Placemark>
			  </Folder>
			  <Folder><name>Sectores</name>
			    <Placemark><name>Polígono Sectores</name>
			      <Polygon><outerBoundaryIs><LinearRing><coordinates>
			        -70.58,-33.50 -70.57,-33.50 -70.57,-33.49 -70.58,-33.50
			      </coordinates></LinearRing></outerBoundaryIs></Polygon>
			    </Placemark>
			  </Folder>
			  <Folder><name>Unidad vecinal</name>
			    <Placemark><name>Polígono Unidad</name>
			      <Polygon><outerBoundaryIs><LinearRing><coordinates>
			        -70.62,-33.56 -70.61,-33.56 -70.61,-33.55 -70.62,-33.56
			      </coordinates></LinearRing></outerBoundaryIs></Polygon>
			    </Placemark>
			  </Folder>
			  <Folder><name>Sector Sur - Colegios</name>
			    <Placemark><name>Polígono Colegios</name>
			      <Polygon><outerBoundaryIs><LinearRing><coordinates>
			        -70.60,-33.56 -70.59,-33.56 -70.59,-33.55 -70.60,-33.56
			      </coordinates></LinearRing></outerBoundaryIs></Polygon>
			    </Placemark>
			  </Folder>
			</Document></kml>
			XML;

		$labels = $this->importFolders($kml);

		self::assertSame([
			'Polígono Colegios' => 'Educación · Colegio',
			'Polígono Norte' => 'Sin clasificar · Otro',
			'Polígono Sectores' => 'Sin clasificar · Otro',
			'Polígono Unidad' => 'Sin clasificar · Otro',
			'Polígono Uno' => 'Sin clasificar · Otro',
		], $labels);
	}

	/**
	 * A plural in «-es» loses «-es», not only its «s»: «Parques» is `parque`,
	 * not `parqu` (#204).
	 *
	 * In its own process because the taxonomy is written mid-request here and
	 * nowhere in the app: `TaxonomyService::subcategoryIndex()` is memoised, and
	 * the imports earlier in the suite have already filled it without «Parque».
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testAPluralFolderFindsAClinicAddedSubcategory(): void {
		Server::get(TaxonomyService::class)->addSubcategory('deporte_recreacion', 'Parque');
		$this->addedSubcategories[] = $this->subcategoryIdBySlug('parque');

		$kml = <<<'XML'
			<?xml version="1.0" encoding="UTF-8"?>
			<kml xmlns="http://www.opengis.net/kml/2.2"><Document>
			  <Folder><name>Parques</name>
			    <Placemark><name>Parque de Prueba</name>
			      <Point><coordinates>-70.60,-33.52</coordinates></Point>
			    </Placemark>
			  </Folder>
			  <Folder><name>Plazas y parques</name>
			    <Placemark><name>Plaza de Prueba</name>
			      <Point><coordinates>-70.59,-33.52</coordinates></Point>
			    </Placemark>
			  </Folder>
			</Document></kml>
			XML;

		$labels = $this->importFolders($kml);

		self::assertSame('Deporte y recreación · Parque', $labels['Parque de Prueba']);
		self::assertSame('Deporte y recreación · Plaza o parque', $labels['Plaza de Prueba']);
	}

	/** A Folder this install has no Category for still imports, unclassified. */
	public function testAnUnknownFolderLandsInSinClasificar(): void {
		$this->import();

		foreach ($this->stored() as $feature) {
			if ($feature->getName() === 'Rareza de Prueba') {
				self::assertSame('Sin clasificar · Otro', $this->labelOf($feature->getSubcategoryId()));

				return;
			}
		}

		self::fail('the record from the unknown Folder was not imported at all');
	}

	/**
	 * Same file twice updates rather than duplicates, exactly as for GeoJSON —
	 * because the ids are derived by the same code (ADR-0003).
	 */
	public function testReimportingTheSameExportChangesNothing(): void {
		$this->import();
		$again = $this->import();

		self::assertSame(0, $again->getCreated());
		self::assertSame(3, $again->getUnchanged());
		self::assertCount(3, $this->stored());
	}

	/**
	 * It is a batch like any other, so ADR-0009's undo works on it.
	 *
	 * This asserted `revisionTo > revisionFrom` and never called revert — green whether or not
	 * undo worked at all, while being the only test claiming to cover ADR-0009 for KML. The count
	 * is asserted before the loop deliberately: a foreach over an empty collection passes every
	 * assertion inside it, which is the same defect in a newer place.
	 */
	public function testItIsRevertibleLikeAnyOtherImport(): void {
		$batch = $this->import();
		self::assertSame(3, $batch->getCreated());

		$revert = Server::get(RevertService::class)->revert((int)$batch->getId(), null);

		self::assertSame(3, $revert->getUpdated());
		$stored = $this->stored();
		self::assertCount(3, $stored);
		foreach ($stored as $feature) {
			self::assertTrue((bool)$feature->getRetired(), 'a reverted KML batch leaves the map');
		}
	}

	/** @return array<string, \OCA\Territorio\Db\Feature> */
	private function stored(): array {
		$dataset = Server::get(DatasetMapper::class)->findBySlug(self::SLUG);

		return Server::get(FeatureMapper::class)->findByDataset((int)$dataset->getId());
	}

	private function labelOf(int $subcategoryId): string {
		$subcategories = Server::get(SubcategoryMapper::class);
		$subcategory = $subcategories->find($subcategoryId);

		foreach (Server::get(\OCA\Territorio\Db\CategoryMapper::class)->findAll() as $category) {
			if ((int)$category->getId() === (int)$subcategory->getCategoryId()) {
				return $category->getLabel() . ' · ' . $subcategory->getLabel();
			}
		}

		return $subcategory->getLabel();
	}

	/**
	 * A KMZ imports to the same Features as the KML inside it (issue #103).
	 *
	 * Stated as the import itself states it: after the archive has been imported,
	 * the same KML imported DIRECTLY finds every record already there and
	 * unchanged. `differs()` compares the whole official blob, so "unchanged"
	 * here means every field and every derived external id matched — a stronger
	 * claim than any list of expectations this test could carry, and one that
	 * cannot drift away from what the KML tests above assert.
	 *
	 * The archive is deliberately awkward in the two ways real ones are: the name
	 * is `.KMZ` in capitals, like `.kml` has always been accepted in any case, and
	 * the document inside is not called `doc.kml` — QGIS and ArcGIS write
	 * `<capa>.kml`, and an icon sits ahead of it.
	 */
	public function testAKmzImportsTheSameRecordsAsTheKmlInsideIt(): void {
		$batch = $this->importArchive(
			'Catastro de Prueba.KMZ',
			['images/icono.png' => 'no soy kml', 'capa principal.kml' => self::KML],
		);

		self::assertSame(3, $batch->getCreated());

		$kml = $this->import();

		self::assertSame(0, $kml->getCreated(), 'the KMZ created records the KML does not recognise');
		self::assertSame(3, $kml->getUnchanged(), 'the KMZ and the KML inside it did not agree field for field');
	}

	/**
	 * A `.kmz` that is not an archive is refused, and refused BEFORE the Dataset
	 * is created — the same "a file that is wrong anywhere imports nothing" the
	 * rest of the pipeline promises. The unit tests pin the message; what only
	 * the database can answer is that nothing was left behind.
	 */
	public function testAKmzThatIsNotAnArchiveImportsNothingAtAll(): void {
		try {
			Server::get(ImportService::class)->importFile(
				'no soy un zip',
				'roto.kmz',
				self::SLUG,
				null,
				'prueba',
			);
			self::fail('a KMZ that is not an archive should be refused');
		} catch (InvalidImportException $e) {
			self::assertStringContainsString('no es un KMZ', $e->getMessage());
		}

		$this->expectException(DoesNotExistException::class);
		Server::get(DatasetMapper::class)->findBySlug(self::SLUG);
	}

	/**
	 * The archive, built the only way `ZipArchive` can build one — through a
	 * path, because `close()` is what writes it and it writes it to a file.
	 *
	 * @param array<string, string> $entries
	 */
	private function importArchive(string $filename, array $entries): \OCA\Territorio\Db\ImportBatch {
		$path = tempnam(sys_get_temp_dir(), 'territorio-kmz-fixture-');

		try {
			$zip = new ZipArchive();
			self::assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
			foreach ($entries as $name => $contents) {
				self::assertTrue($zip->addFromString($name, $contents));
			}
			self::assertTrue($zip->close());

			$batch = Server::get(ImportService::class)->importFile(
				(string)file_get_contents($path),
				$filename,
				self::SLUG,
				'MyMaps de prueba',
				'prueba',
			);
		} finally {
			unlink($path);
		}

		$this->track();

		return $batch;
	}

	private function import(): \OCA\Territorio\Db\ImportBatch {
		$batch = Server::get(ImportService::class)->importFile(
			self::KML,
			'Catastro_de_Prueba.kml',
			self::SLUG,
			'MyMaps de prueba',
			'prueba',
		);

		$this->track();

		return $batch;
	}

	/**
	 * Import a KML of Folders and answer where each record was filed.
	 *
	 * @return array<string, string> record name => "Category · Subcategory", by name
	 */
	private function importFolders(string $kml): array {
		Server::get(ImportService::class)->importFile($kml, 'folders.kml', self::SLUG, null, 'prueba');
		$this->track();

		$labels = [];
		foreach ($this->stored() as $feature) {
			$labels[$feature->getName()] = $this->labelOf($feature->getSubcategoryId());
		}
		ksort($labels);

		return $labels;
	}

	/** Whatever this run's Dataset now holds, so tearDown can take it away again. */
	private function track(): void {
		try {
			$id = (int)Server::get(DatasetMapper::class)->findBySlug(self::SLUG)->getId();
			$this->datasetIds[] = $id;
			foreach (Server::get(FeatureMapper::class)->findByDataset($id) as $feature) {
				$this->created[] = (int)$feature->getId();
			}
		} catch (DoesNotExistException) {
			// Nothing was created, which the assertions will say better than this.
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
		      <name>Deporte y recreación</name>
		      <Placemark>
		        <name>Parque de Prueba</name>
		        <Polygon><outerBoundaryIs><LinearRing><coordinates>
		          -70.62,-33.55,0 -70.60,-33.55,0 -70.60,-33.54,0 -70.62,-33.55,0
		        </coordinates></LinearRing></outerBoundaryIs></Polygon>
		      </Placemark>
		    </Folder>
		    <Folder>
		      <name>Una carpeta que nadie reconoce</name>
		      <Placemark>
		        <name>Rareza de Prueba</name>
		        <LineString><coordinates>
		          -70.60,-33.53,0 -70.58,-33.53,0
		        </coordinates></LineString>
		      </Placemark>
		    </Folder>
		  </Document>
		</kml>
		XML;
}
