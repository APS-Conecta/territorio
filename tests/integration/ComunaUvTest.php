<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Domain\Comuna;
use OCA\Territorio\Service\ComunaConfig;
use OCA\Territorio\Service\ImportService;
use OCA\Territorio\Domain\Kind;
use OCA\Territorio\Service\RegistryService;
use OCP\IDBConnection;
use OCP\Server;

use function Territorio\Tools\Comuna\cutComuna;

require_once __DIR__ . '/../../tools/comuna-cut.php';

/**
 * The Comuna setting and the Unidad Vecinal cadastre, together.
 *
 * The file under test is produced by the cutter itself rather than hand-written
 * to look like its output — the cutter and the import are two halves of one
 * contract (the `t_id_uv_ca` key, the collection-level comuna, the division
 * Subcategory), and a fixture imitating the cutter would keep passing after the
 * cutter changed.
 *
 * Values are invented. The attribute NAMES are the real ones, because matching
 * them is the job.
 */
final class ComunaUvTest extends IntegrationTestCase {
	private const SLUG = 'uv-prueba';
	private const OURS = '13110';
	private const ELSEWHERE = '13101';

	/** @var int[] */
	private array $datasets = [];
	private ?Comuna $before = null;

	protected function setUp(): void {
		$this->before = Server::get(ComunaConfig::class)->get();
		Server::get(ComunaConfig::class)->set(Comuna::of(self::OURS, 'Comuna de Prueba'));
	}

	protected function tearDown(): void {
		parent::tearDown();

		if ($this->before !== null) {
			Server::get(ComunaConfig::class)->set($this->before);
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

	public function testTheSeedLeavesSomewhereToFileAUnidadVecinal(): void {
		$slugs = [];
		foreach (Server::get(SubcategoryMapper::class)->findAll() as $subcategory) {
			$slugs[] = $subcategory->getSlug();
		}

		self::assertContains('unidad_vecinal', $slugs);
	}

	/** They come from the cadastre as areas, and they are read, never drawn. */
	public function testTheCadastreImportsAsZonesUnderTheDivisionCategory(): void {
		$batch = $this->import();

		self::assertSame(2, $batch->getCreated());

		foreach ($this->stored() as $feature) {
			self::assertSame(Kind::Zone->value, $feature->getKind());
			self::assertSame(
				'Unidad Vecinal',
				Server::get(SubcategoryMapper::class)->find($feature->getSubcategoryId())->getLabel(),
			);
		}
	}

	/** `t_id_uv_ca` is the key, so a re-import matches rather than duplicates. */
	public function testItKeysOnTheNationalIdAndReimportsIdempotently(): void {
		$this->import();
		$again = $this->import();

		self::assertSame(0, $again->getCreated());
		self::assertSame(2, $again->getUnchanged());
		self::assertArrayHasKey('13110-0003', $this->stored());
	}

	/**
	 * The criterion, and the reason it matters: a Territorio is a portion of one
	 * Comuna and never reaches into a second (CONTEXT.md). Catching it at the door
	 * beats working out afterwards which 300 boundaries came from the wrong place.
	 */
	public function testAFileForAnotherComunaIsRefusedWithSomethingUsable(): void {
		try {
			Server::get(ImportService::class)->importFile(
				$this->file(self::ELSEWHERE), 'datos.geojson',
				self::SLUG,
				'Ajena',
				'prueba',
			);
			self::fail('a file for another comuna was imported');
		} catch (InvalidImportException $e) {
			self::assertStringContainsString(self::ELSEWHERE, $e->getMessage());
			self::assertStringContainsString(self::OURS, $e->getMessage());
		}

		// And nothing was written, which is the half that actually protects anyone.
		try {
			$dataset = Server::get(DatasetMapper::class)->findBySlug(self::SLUG);
			self::assertCount(0, Server::get(FeatureMapper::class)->findByDataset((int)$dataset->getId()));
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			self::assertTrue(true, 'no Dataset was created either');
		}
	}

	/** A file that says nothing about a comuna is imported as before. */
	public function testAFileThatNamesNoComunaIsNotRefused(): void {
		$plain = json_encode([
			'type' => 'FeatureCollection',
			'features' => [[
				'type' => 'Feature',
				'id' => 'plain/1',
				'properties' => ['name' => 'Sin comuna'],
				'geometry' => ['type' => 'Point', 'coordinates' => [-70.58, -33.52]],
			]],
		], JSON_THROW_ON_ERROR);

		$batch = Server::get(ImportService::class)->importFile($plain, 'datos.geojson', self::SLUG, 'Llana', 'prueba');
		$this->track();

		self::assertSame(1, $batch->getCreated());
	}

	/**
	 * Unidades Vecinales are records, so another app can count them.
	 *
	 * A delta, not a total: this runs against an instance somebody has been using,
	 * and asserting an absolute number would only be measuring how empty the
	 * database happened to be.
	 */
	public function testTheServiceCountsThem(): void {
		$counted = static fn (): int => Server::get(RegistryService::class)
			->counts()['categories']['division_territorial']['subcategories']['unidad_vecinal'];

		$before = $counted();
		$this->import();

		self::assertSame($before + 2, $counted());
	}

	private function import(): \OCA\Territorio\Db\ImportBatch {
		$batch = Server::get(ImportService::class)->importFile(
			$this->file(self::OURS), 'datos.geojson',
			self::SLUG,
			'Unidades Vecinales de prueba',
			'prueba',
		);
		$this->track();

		return $batch;
	}

	private function track(): void {
		$id = (int)Server::get(DatasetMapper::class)->findBySlug(self::SLUG)->getId();
		$this->datasets[] = $id;
		foreach (Server::get(FeatureMapper::class)->findByDataset($id) as $feature) {
			$this->created[] = (int)$feature->getId();
		}
	}

	/** @return array<string, \OCA\Territorio\Db\Feature> */
	private function stored(): array {
		$dataset = Server::get(DatasetMapper::class)->findBySlug(self::SLUG);

		return Server::get(FeatureMapper::class)->findByDataset((int)$dataset->getId());
	}

	/** Cut by the tool itself, from an invented national file. */
	private function file(string $cut): string {
		return json_encode(cutComuna([
			'type' => 'FeatureCollection',
			'features' => [
				self::uv('13110', '13110-0003', '3', 'Villa de Prueba', -70.60),
				self::uv('13110', '13110-0007', '7', '', -70.58),
				self::uv('13101', '13101-0001', '1', 'Ajena', -70.70),
			],
		], $cut), JSON_THROW_ON_ERROR);
	}

	/** @return array<string, mixed> */
	private static function uv(string $comuna, string $id, string $number, string $name, float $west): array {
		return [
			'type' => 'Feature',
			'properties' => [
				't_com' => $comuna, 't_com_nom' => 'Comuna de Prueba',
				't_id_uv_ca' => $id, 'uv_carto' => $number, 't_uv_nom' => $name,
			],
			'geometry' => ['type' => 'Polygon', 'coordinates' => [[
				[$west, -33.53], [$west + 0.02, -33.53], [$west + 0.02, -33.51], [$west, -33.53],
			]]],
		];
	}
}
