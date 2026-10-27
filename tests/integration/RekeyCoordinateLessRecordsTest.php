<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Migration\RekeyCoordinateLessRecords;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\ImportService;
use OCP\Migration\IOutput;
use OCP\Server;

/**
 * A record imported under the id #202 collapsed onto is carried to the id
 * ADR-0022 derives, once, so the next import of its file matches it instead of
 * creating the whole set beside it.
 *
 * Each seeded record arrives with an explicit uid, which is how a record with a
 * legacy id can be put into a Dataset now that the reader no longer derives
 * one. The ids are literals worked out from the legacy rule
 * (`sha1(name|sin-coordenadas)`) and from ADR-0022's, not computed here.
 */
final class RekeyCoordinateLessRecordsTest extends IntegrationTestCase {
	private const DATASET = 'rekey-prueba';

	/** (a) «Multicancha» at «Pasaje 15», collapsed under the legacy id; it carries a Local override. */
	private const A_LEGACY = 'rekey-prueba:316a1b63cabc9a41';
	private const A_NEW = 'rekey-prueba:0ca2c49bb1a047ad';
	/** (b) coordinate-less, with an explicit uid of its own. */
	private const B_EXPLICIT = 'rekey-prueba:explicita';
	/** (d) legacy id, no address and no notes: ADR-0022 derives the same id. */
	private const D_LEGACY = 'rekey-prueba:4a5599504950631c';
	/** (e) legacy id whose new id another record of the Dataset already holds. */
	private const E_LEGACY = 'rekey-prueba:9e98c1b933472454';
	private const E_TAKEN = 'rekey-prueba:7beb8e38d43efc26';
	/** (f) an explicit uid shaped like a legacy id, on a record that has a coordinate. */
	private const F_LOOKALIKE = 'rekey-prueba:f67d1a5606522267';

	private const SEED = <<<'JSON'
	{"type":"FeatureCollection","features":[
	  {"type":"Feature","geometry":null,
	   "properties":{"uid":"rekey-prueba:316a1b63cabc9a41","name":"Multicancha","address":"Pasaje 15","phone":"229000015"}},
	  {"type":"Feature","geometry":null,
	   "properties":{"uid":"rekey-prueba:explicita","name":"Junta de Vecinos Uno","address":"Calle Tres 3"}},
	  {"type":"Feature","geometry":{"type":"Point","coordinates":[-70.58,-33.52]},
	   "properties":{"name":"Plaza Dos","address":"Calle Dos 2"}},
	  {"type":"Feature","geometry":null,
	   "properties":{"uid":"rekey-prueba:4a5599504950631c","name":"Sede Vecinal"}},
	  {"type":"Feature","geometry":null,
	   "properties":{"uid":"rekey-prueba:9e98c1b933472454","name":"Club Deportivo","address":"Pasaje 9"}},
	  {"type":"Feature","geometry":null,
	   "properties":{"uid":"rekey-prueba:7beb8e38d43efc26","name":"Club Deportivo","address":"Pasaje 9"}},
	  {"type":"Feature","geometry":{"type":"Point","coordinates":[-70.59,-33.53]},
	   "properties":{"uid":"rekey-prueba:f67d1a5606522267","name":"Escuela Cuatro","address":"Calle Cuatro 4"}}
	]}
	JSON;

	/** @var array<string, int> feature ids by the external id they were seeded under */
	private array $seeded = [];

	protected function setUp(): void {
		$this->forgetDataset(self::DATASET);

		$batch = Server::get(ImportService::class)->importFile(
			self::SEED, 'semilla.geojson', self::DATASET, 'Re-key de prueba', 'tester',
		);
		foreach (Server::get(FeatureMapper::class)->findByDataset($batch->getDatasetId()) as $externalId => $feature) {
			$this->seeded[$externalId] = (int)$feature->getId();
		}

		// (a)'s Local override: a person corrected the phone (ADR-0003).
		$a = Server::get(FeatureMapper::class)->find($this->seeded[self::A_LEGACY]);
		Server::get(FeatureService::class)->update((int)$a->getId(), [
			'name' => 'Multicancha',
			'subcategoryId' => $a->getSubcategoryId(),
			'address' => 'Pasaje 15',
			'phone' => '900000000',
			'geometry' => null,
		]);
	}

	protected function tearDown(): void {
		$this->forgetDataset(self::DATASET);
		parent::tearDown();
	}

	public function testALegacyRecordIsReKeyedAndEveryOtherIsLeftAlone(): void {
		$before = $this->externalIds();
		$revision = Server::get(RevisionLog::class)->current();

		$output = $this->repair();

		$after = $this->externalIds();
		self::assertSame(self::A_NEW, $after[$this->seeded[self::A_LEGACY]], '(a) carries the new id');
		unset($before[$this->seeded[self::A_LEGACY]], $after[$this->seeded[self::A_LEGACY]]);
		self::assertSame($before, $after, '(b) to (f) keep the id they had');
		self::assertSame(self::E_LEGACY, $after[$this->seeded[self::E_LEGACY]], '(e) collides and is skipped');
		self::assertSame(
			$revision,
			Server::get(RevisionLog::class)->current(),
			'nothing a person sees changed, so no Revision is spent and the cursor stays',
		);
		self::assertMatchesRegularExpression('/re-keyed 1\b/', $output);
		self::assertMatchesRegularExpression('/skipped 1\b/', $output);
	}

	public function testASecondRunChangesNothing(): void {
		$this->repair();
		$once = $this->externalIds();

		$this->repair();

		self::assertSame($once, $this->externalIds());
	}

	/**
	 * The point of the step: after it, the file #202 reported imports the
	 * fourteen places it never did and matches the fifteenth, override intact.
	 */
	public function testAfterTheReKeyTheFileMatchesTheRecordInsteadOfDuplicatingIt(): void {
		$this->repair();

		$batch = Server::get(ImportService::class)->importFile(
			CsvImportTest::multicanchas(), 'catastro.csv', self::DATASET, 'Re-key de prueba', 'tester',
		);

		self::assertSame(14, $batch->getCreated());
		self::assertSame(1, $batch->getSkipped(), '(a) is matched, and skipped because it is overridden');
		$a = Server::get(FeatureMapper::class)->find($this->seeded[self::A_LEGACY]);
		self::assertSame(self::A_NEW, $a->getExternalId());
		self::assertTrue((bool)$a->getOverridden());
		self::assertSame('900000000', $a->getPhone(), 'the Local override still wins (ADR-0003)');
	}

	/** @return array<int, string> external id by feature id, for every record of the Dataset */
	private function externalIds(): array {
		$dataset = Server::get(DatasetMapper::class)->findBySlug(self::DATASET);
		$ids = [];
		foreach (Server::get(FeatureMapper::class)->findByDataset((int)$dataset->getId()) as $externalId => $feature) {
			$ids[(int)$feature->getId()] = (string)$externalId;
		}
		ksort($ids);

		return $ids;
	}

	/** Runs the step and returns everything it said. */
	private function repair(): string {
		$output = new class implements IOutput {
			public string $said = '';

			public function debug(string $message): void {
				$this->said .= $message . "\n";
			}

			public function info($message) {
				$this->said .= $message . "\n";
			}

			public function warning($message) {
				$this->said .= $message . "\n";
			}

			public function startProgress($max = 0) {
			}

			public function advance($step = 1, $description = '') {
			}

			public function finishProgress() {
			}
		};
		Server::get(RekeyCoordinateLessRecords::class)->run($output);

		return $output->said;
	}
}
