<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Domain\Basemap;
use OCA\Territorio\Service\BasemapConfig;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The write path an administrator takes: type a tile URL, save, and get back
 * what the setting actually resolves to. The judgement lives in {@see Basemap};
 * what matters here is that saving stores the raw string while reading applies
 * that judgement — so a typo stays visible in the field that produced it
 * instead of being silently rewritten.
 */
final class BasemapConfigTest extends TestCase {
	/** In-memory stand-in for Nextcloud's app config. */
	private function appConfig(array $stored = []): IAppConfig {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '', bool $lazy = false): string
				=> $stored[$key] ?? $default,
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$key] = $value;
				return true;
			},
		);

		return $config;
	}

	public function testAnEmptyInstallServesOpenStreetMap(): void {
		$basemap = (new BasemapConfig($this->appConfig()))->get();

		self::assertSame(Basemap::DEFAULT_URL, $basemap->url());
		self::assertTrue($basemap->usesOpenStreetMap());
		self::assertFalse($basemap->wasRejected());
	}

	/**
	 * The settings form reads this, so a rejected URL stays in the box that
	 * produced it instead of vanishing when the page reloads.
	 */
	public function testStoredReturnsTheRawValuesWithoutJudgingThem(): void {
		$config = new BasemapConfig($this->appConfig([
			BasemapConfig::KEY_URL => 'https://maps.local/not-a-tile-url.png',
			BasemapConfig::KEY_ATTRIBUTION => 'Cartografía municipal',
		]));

		self::assertSame([
			'url' => 'https://maps.local/not-a-tile-url.png',
			'attribution' => 'Cartografía municipal',
		], $config->stored());

		// …while what actually gets served falls back, and says it was rejected.
		self::assertSame(Basemap::DEFAULT_URL, $config->get()->url());
		self::assertTrue($config->get()->wasRejected());
	}

	public function testStoredValuesAreResolved(): void {
		$basemap = (new BasemapConfig($this->appConfig([
			BasemapConfig::KEY_URL => 'https://tiles.cesfam.local/{z}/{x}/{y}.png',
			BasemapConfig::KEY_ATTRIBUTION => 'Cartografía municipal',
		])))->get();

		self::assertSame('https://tiles.cesfam.local/{z}/{x}/{y}.png', $basemap->url());
		self::assertSame('Cartografía municipal', $basemap->attribution());
		self::assertSame('https://tiles.cesfam.local', $basemap->cspHost());
		self::assertFalse($basemap->usesOpenStreetMap());
	}

	public function testSavingReturnsWhatTheSettingResolvesTo(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->expects(self::exactly(2))->method('setValueString');

		$basemap = (new BasemapConfig($config))->set(
			'  https://tiles.cesfam.local/{z}/{x}/{y}.png  ',
			'  Cartografía municipal  ',
		);

		self::assertInstanceOf(Basemap::class, $basemap);
	}

	/**
	 * A URL Territorio will not put in a policy still gets stored verbatim, so
	 * the administrator can see and fix their typo — while `get()` keeps serving
	 * OpenStreetMap in the meantime.
	 */
	public function testARejectedUrlIsStillStoredButNeverServed(): void {
		$written = [];
		$config = $this->createStub(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = $value;
				return true;
			},
		);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string
				=> $written[$key] ?? $default,
		);

		$basemap = (new BasemapConfig($config))->set('https://evil.example; script-src *', 'x');

		self::assertSame('https://evil.example; script-src *', $written[BasemapConfig::KEY_URL]);
		self::assertSame(Basemap::DEFAULT_URL, $basemap->url());
		self::assertSame('https://tile.openstreetmap.org', $basemap->cspHost());
	}
}
