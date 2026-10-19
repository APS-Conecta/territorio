<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Migration\RemoveRetiredTileSettings;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Server;

/**
 * The stale tile settings leave a real app config (ADR-0021).
 *
 * The unit test pins the order and the log against an in-memory config; this is
 * the half it cannot prove: that `hasKey()` of Nextcloud's own IAppConfig sees a
 * key stored through `setValueString()`, the way the retired form stored it, and
 * that the container can build the step with a real logger. An empty string is
 * the case worth running here, because it is the one a falsy check would skip.
 */
final class RemoveRetiredTileSettingsTest extends IntegrationTestCase {
	private const KEYS = ['tile_url', 'tile_attribution'];

	protected function tearDown(): void {
		// The keys are retired, so leaving them behind would only teach the next run
		// to expect them.
		foreach (self::KEYS as $key) {
			Server::get(IAppConfig::class)->deleteKey('territorio', $key);
		}

		parent::tearDown();
	}

	private function repair(): void {
		Server::get(RemoveRetiredTileSettings::class)->run($this->createStub(IOutput::class));
	}

	private function stored(string $key): bool {
		return Server::get(IAppConfig::class)->hasKey('territorio', $key);
	}

	public function testBothStoredSettingsAreRemoved(): void {
		$config = Server::get(IAppConfig::class);
		$config->setValueString('territorio', 'tile_url', 'https://tiles.cesfam.example/chile.pmtiles');
		$config->setValueString('territorio', 'tile_attribution', '© Servicio de Salud');

		$this->repair();

		self::assertFalse($this->stored('tile_url'));
		self::assertFalse($this->stored('tile_attribution'));
	}

	public function testAKeyStoredAsTheEmptyStringIsStillRemoved(): void {
		$config = Server::get(IAppConfig::class);
		$config->setValueString('territorio', 'tile_url', '');
		$config->setValueString('territorio', 'tile_attribution', '');
		self::assertTrue($this->stored('tile_url'), 'fixture: an empty value is still a stored key');

		$this->repair();

		self::assertFalse($this->stored('tile_url'));
		self::assertFalse($this->stored('tile_attribution'));
	}

	public function testRunningItWithNothingStoredIsHarmless(): void {
		$this->repair();
		$this->repair();

		self::assertFalse($this->stored('tile_url'));
	}

	public function testAnUnrelatedSettingOfTheAppIsLeftAlone(): void {
		$config = Server::get(IAppConfig::class);
		$before = $config->getValueString('territorio', 'comuna_cut');

		$this->repair();

		self::assertSame($before, $config->getValueString('territorio', 'comuna_cut'));
	}
}
