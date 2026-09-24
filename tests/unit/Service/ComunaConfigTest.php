<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Domain\Comuna;
use OCA\Territorio\Service\ComunaConfig;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Reading the stored setting applies Comuna's judgement — and a value nobody
 * would have been allowed to save (typed by hand into the database, say) reads
 * as "not chosen" rather than throwing on every page load. That degradation is
 * easy to lose when the exception moves (L7-01): this is the pin.
 */
final class ComunaConfigTest extends TestCase {
	/** In-memory stand-in for Nextcloud's app config. */
	private function appConfig(array $stored = []): IAppConfig {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '', bool $lazy = false) use (&$stored): string {
				return $stored[$key] ?? $default;
			},
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$key] = $value;
				return true;
			},
		);

		return $config;
	}

	public function testAStoredComunaReadsBackChosen(): void {
		$config = new ComunaConfig($this->appConfig());
		$config->set(Comuna::of('13110', 'La Florida'));

		$comuna = $config->get();

		self::assertSame('13110', $comuna->cut);
		self::assertSame('La Florida', $comuna->name);
		self::assertTrue($comuna->isChosen());
	}

	public function testNoStoredComunaIsNotChosen(): void {
		self::assertFalse((new ComunaConfig($this->appConfig()))->get()->isChosen());
	}

	public function testAStoredValueNobodyWouldHaveBeenAllowedToSaveReadsAsNotChosen(): void {
		$config = new ComunaConfig($this->appConfig([ComunaConfig::KEY_CUT => '131']));

		$comuna = $config->get();

		self::assertFalse($comuna->isChosen());
		self::assertSame('', $comuna->cut);
	}
}
