<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Service\Basemap;
use PHPUnit\Framework\TestCase;

/**
 * The basemap is the one thing an administrator repoints without a code change
 * (ADR-0004), so the value that reaches the Content-Security-Policy is derived
 * from whatever they typed. These cases are the ones that decide whether a
 * clinic can move to a self-hosted tile server, and whether a bad setting can
 * blank the map.
 */
final class BasemapTest extends TestCase {
	public function testUnsetFallsBackToTheOpenStreetMapTileService(): void {
		$basemap = Basemap::resolve(null, null);

		self::assertSame(Basemap::DEFAULT_URL, $basemap->url());
		self::assertStringContainsString('OpenStreetMap', $basemap->attribution());
	}

	public function testBlankStringsAreTreatedAsUnset(): void {
		$basemap = Basemap::resolve('', '   ');

		self::assertSame(Basemap::DEFAULT_URL, $basemap->url());
		self::assertStringContainsString('OpenStreetMap', $basemap->attribution());
	}

	public function testAConfiguredTileServerIsUsedAsGiven(): void {
		$basemap = Basemap::resolve(
			'https://maps.cesfam.local/tiles/{z}/{x}/{y}.png',
			'Cartografía municipal',
		);

		self::assertSame('https://maps.cesfam.local/tiles/{z}/{x}/{y}.png', $basemap->url());
		self::assertSame('Cartografía municipal', $basemap->attribution());
	}

	public function testCspHostIsTheOriginOfTheDefaultTileService(): void {
		self::assertSame(
			'https://tile.openstreetmap.org',
			Basemap::resolve(null, null)->cspHost(),
		);
	}

	public function testCspHostKeepsANonDefaultPort(): void {
		$basemap = Basemap::resolve('https://maps.cesfam.local:8443/{z}/{x}/{y}.png', 'Local');

		self::assertSame('https://maps.cesfam.local:8443', $basemap->cspHost());
	}

	/**
	 * Many tile services spread load across a.b.c subdomains via Leaflet's {s}
	 * placeholder. A CSP naming the literal "{s}.tile.example.org" matches
	 * nothing, so the map would silently go blank — the label becomes a wildcard.
	 */
	public function testSubdomainPlaceholderBecomesAWildcardHost(): void {
		$basemap = Basemap::resolve('https://{s}.tile.example.org/{z}/{x}/{y}.png', 'Example');

		self::assertSame('https://*.tile.example.org', $basemap->cspHost());
	}

	public function testPlainHttpIsAllowedForAnInternalTileServer(): void {
		$basemap = Basemap::resolve('http://tiles.intranet/{z}/{x}/{y}.png', 'Intranet');

		self::assertSame('http://tiles.intranet/{z}/{x}/{y}.png', $basemap->url());
		self::assertSame('http://tiles.intranet', $basemap->cspHost());
	}

	/**
	 * A rejected URL falls back to OpenStreetMap, so the attribution has to fall
	 * back with it. Showing a clinic's own credit over OSM tiles would be a lie,
	 * and OSM's licence requires its own.
	 */
	public function testARejectedUrlAlsoDiscardsItsAttribution(): void {
		$basemap = Basemap::resolve('javascript:alert(1)', 'Cartografía municipal');

		self::assertSame(Basemap::DEFAULT_URL, $basemap->url());
		self::assertStringContainsString('OpenStreetMap', $basemap->attribution());
	}

	public function testAUrlWithoutTileCoordinatesIsRejected(): void {
		$basemap = Basemap::resolve('https://maps.cesfam.local/static.png', 'Local');

		self::assertSame(Basemap::DEFAULT_URL, $basemap->url());
	}

	public function testAUrlMissingOneTileCoordinateIsRejected(): void {
		$basemap = Basemap::resolve('https://maps.cesfam.local/{z}/{x}.png', 'Local');

		self::assertSame(Basemap::DEFAULT_URL, $basemap->url());
	}

	public function testAUrlWithNoHostIsRejected(): void {
		$basemap = Basemap::resolve('https:///{z}/{x}/{y}.png', 'Local');

		self::assertSame(Basemap::DEFAULT_URL, $basemap->url());
	}

	public function testGarbageIsRejected(): void {
		$basemap = Basemap::resolve('not a url at all', 'Local');

		self::assertSame(Basemap::DEFAULT_URL, $basemap->url());
	}

	/**
	 * The CSP host is interpolated into a header, so a value that could carry a
	 * space or a semicolon out of the setting and into the policy must never
	 * survive validation.
	 */
	public function testAHostCarryingPolicyBreakingCharactersIsRejected(): void {
		$basemap = Basemap::resolve("https://evil.example; script-src *\n/{z}/{x}/{y}.png", 'x');

		self::assertSame(Basemap::DEFAULT_URL, $basemap->url());
		self::assertSame('https://tile.openstreetmap.org', $basemap->cspHost());
	}

	/**
	 * An administrator may leave their own tile server uncredited — it is theirs
	 * to credit or not.
	 */
	public function testAPrivateTileServerMayCreditNobody(): void {
		$basemap = Basemap::resolve('https://tiles.intranet/{z}/{x}/{y}.png', '');

		self::assertSame('https://tiles.intranet/{z}/{x}/{y}.png', $basemap->url());
		self::assertSame('', $basemap->attribution());
	}

	/**
	 * OpenStreetMap's is not theirs to drop. Typing the default URL — which is
	 * the placeholder text in the settings field, so people will — and clearing
	 * the attribution must not serve OSM tiles with no credit at all.
	 */
	public function testOpenStreetMapIsCreditedEvenWhenTheAttributionIsCleared(): void {
		$basemap = Basemap::resolve(Basemap::DEFAULT_URL, '');

		self::assertSame(Basemap::DEFAULT_ATTRIBUTION, $basemap->attribution());
		self::assertTrue($basemap->usesOpenStreetMap());
	}

	public function testAnExplicitAttributionOverOpenStreetMapTilesIsKept(): void {
		$basemap = Basemap::resolve(Basemap::DEFAULT_URL, 'OSM vía caché local');

		self::assertSame('OSM vía caché local', $basemap->attribution());
	}

	public function testSurroundingWhitespaceIsIgnored(): void {
		$basemap = Basemap::resolve("  https://tiles.intranet/{z}/{x}/{y}.png \n", '  Intranet  ');

		self::assertSame('https://tiles.intranet/{z}/{x}/{y}.png', $basemap->url());
		self::assertSame('Intranet', $basemap->attribution());
	}

	public function testUsesOpenStreetMapTurnsOnTheUrlAloneNotTheAttribution(): void {
		self::assertTrue(Basemap::resolve(null, null)->usesOpenStreetMap());
		// A custom credit does not change whose tiles these are.
		self::assertTrue(Basemap::resolve(Basemap::DEFAULT_URL, 'Mi crédito')->usesOpenStreetMap());
		self::assertFalse(
			Basemap::resolve('https://tiles.intranet/{z}/{x}/{y}.png', 'Intranet')->usesOpenStreetMap(),
		);
	}

	public function testNothingConfiguredIsNotARejection(): void {
		self::assertFalse(Basemap::resolve(null, null)->wasRejected());
		self::assertFalse(Basemap::resolve('', '')->wasRejected());
	}

	public function testASuppliedUrlThatIsNotUsedIsReportedAsRejected(): void {
		self::assertTrue(Basemap::resolve('https://maps.local/static.png', '')->wasRejected());
		self::assertFalse(
			Basemap::resolve('https://tiles.intranet/{z}/{x}/{y}.png', '')->wasRejected(),
		);
	}

	public function testJsonSerializeCarriesWhatTheClientsNeed(): void {
		self::assertSame(
			[
				'url' => Basemap::DEFAULT_URL,
				'attribution' => Basemap::DEFAULT_ATTRIBUTION,
				'usesOpenStreetMap' => true,
			],
			Basemap::resolve(null, null)->jsonSerialize(),
		);
	}
}
