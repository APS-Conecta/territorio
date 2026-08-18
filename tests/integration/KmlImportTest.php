<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Service\ImportService;
use OCA\Territorio\Service\Kind;
use OCA\Territorio\Service\RevertService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\Server;

/**
 * A Google MyMaps export, through the same pipeline GeoJSON uses.
 *
 * Shaped like the real `La_Florida_Google_MyMaps.kml` — Folders holding
 * Placemarks, geometry and a name and nothing else — with every value invented.
 * What was checked against the real file is the shape: 865 placemarks and not
 * one `ExtendedData` element, so no phone, email, contact or source anywhere.
 */
final class KmlImportTest extends IntegrationTestCase {
	private const SLUG = 'mymaps-prueba';

	/** @var int[] */
	private array $datasets = [];

	protected function tearDown(): void {
		parent::tearDown();

		if ($this->datasets === []) {
			return;
		}

		$db = Server::get(IDBConnection::class);
		foreach ($this->datasets as $id) {
			foreach (['territorio_import' => 'dataset_id', 'territorio_dataset' => 'id'] as $table => $column) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->eq($column, $qb->createNamedParameter($id)))
					->executeStatement();
			}
		}
		$this->datasets = [];
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
		$this->datasets[] = $id;
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
		$this->datasets[] = $id;

		$labels = [];
		foreach (Server::get(FeatureMapper::class)->findByDataset($id) as $feature) {
			$this->created[] = (int)$feature->getId();
			$labels[$feature->getName()] = $this->labelOf($feature->getSubcategoryId());
		}

		self::assertSame('Movilidad y transporte · Ciclovía', $labels['Ciclovía de Prueba']);
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

	private function import(): \OCA\Territorio\Db\ImportBatch {
		$batch = Server::get(ImportService::class)->importFile(
			self::KML,
			'La_Florida_de_Prueba.kml',
			self::SLUG,
			'MyMaps de prueba',
			'prueba',
		);

		try {
			$id = (int)Server::get(DatasetMapper::class)->findBySlug(self::SLUG)->getId();
			$this->datasets[] = $id;
			foreach (Server::get(FeatureMapper::class)->findByDataset($id) as $feature) {
				$this->created[] = (int)$feature->getId();
			}
		} catch (DoesNotExistException) {
			// Nothing was created, which the assertions will say better than this.
		}

		return $batch;
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
