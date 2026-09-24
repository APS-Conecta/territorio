<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Domain\Basemap;
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
				'kind' => Basemap::KIND_RASTER,
			],
			Basemap::resolve(null, null)->jsonSerialize(),
		);
	}

	/**
	 * A PMTiles archive is one file the BROWSER reads with Range requests, not a
	 * directory of images. It therefore carries none of Leaflet's `{z}/{x}/{y}`
	 * placeholders — and the placeholder test is exactly what used to decide
	 * whether a URL was a tile URL at all, so without this a `.pmtiles` setting
	 * was rejected and the map quietly stayed on OpenStreetMap.
	 */
	public function testAPmtilesArchiveIsAcceptedWithoutTilePlaceholders(): void {
		$basemap = Basemap::resolve('https://tiles.cesfam.local/chile.pmtiles', 'Protomaps');

		self::assertSame('https://tiles.cesfam.local/chile.pmtiles', $basemap->url());
		self::assertSame(Basemap::KIND_PMTILES, $basemap->kind());
		self::assertFalse($basemap->wasRejected());
	}

	public function testAPmtilesArchiveKeepsItsOriginForThePolicy(): void {
		$basemap = Basemap::resolve('https://tiles.cesfam.local:10009/chile.pmtiles', '');

		self::assertSame('https://tiles.cesfam.local:10009', $basemap->cspHost());
	}

	/**
	 * The distinction the Content-Security-Policy turns on. Raster tiles are
	 * `<img>` loads and belong in `img-src`; a PMTiles archive is `fetch`ed, so
	 * naming it in `img-src` leaves the map blank with a policy that reads as
	 * though it allowed the thing it just blocked.
	 */
	public function testKindSaysHowTheBasemapIsFetched(): void {
		self::assertSame(
			Basemap::KIND_RASTER,
			Basemap::resolve('https://tiles.intranet/{z}/{x}/{y}.png', '')->kind(),
		);
		self::assertSame(
			Basemap::KIND_PMTILES,
			Basemap::resolve('https://tiles.intranet/chile.pmtiles', '')->kind(),
		);
	}

	public function testAPmtilesArchiveIsNotOpenStreetMapsOwnTileService(): void {
		// `usesOpenStreetMap` gates OpenStreetMap's own copyright LINK markup, which
		// belongs to their tile service. A Protomaps archive is still OSM DATA and
		// still owes ODbL credit — that is the attribution string's job, not this
		// flag's.
		self::assertFalse(
			Basemap::resolve('https://tiles.intranet/chile.pmtiles', '')->usesOpenStreetMap(),
		);
	}

	public function testAPmtilesUrlWithAQueryStringIsStillAnArchive(): void {
		$basemap = Basemap::resolve('https://tiles.local/chile.pmtiles?v=20260915', '');

		self::assertSame(Basemap::KIND_PMTILES, $basemap->kind());
		self::assertFalse($basemap->wasRejected());
	}

	public function testSomethingThatIsNeitherFormIsStillRejected(): void {
		// The guard this change could most easily have opened: a bare host, a
		// static image, or a path that merely mentions the word.
		foreach ([
			'https://maps.local/static.png',
			'https://maps.local/',
			'https://maps.local/pmtiles/readme.txt',
		] as $url) {
			self::assertTrue(
				Basemap::resolve($url, '')->wasRejected(),
				"{$url} should not be accepted",
			);
			self::assertSame(Basemap::DEFAULT_URL, Basemap::resolve($url, '')->url());
		}
	}

	public function testAPmtilesArchiveMustStillBeAnHttpUrl(): void {
		// Not file:// and not a bare path: the browser fetches this cross-origin,
		// and whatever lands here is also what goes into a response header.
		self::assertTrue(Basemap::resolve('file:///srv/tiles/chile.pmtiles', '')->wasRejected());
		self::assertTrue(Basemap::resolve('/srv/tiles/chile.pmtiles', '')->wasRejected());
	}

	public function testAPmtilesArchiveCannotSmuggleAHeaderBreak(): void {
		self::assertTrue(
			Basemap::resolve("https://tiles.local;script-src *\n/chile.pmtiles", '')->wasRejected(),
		);
	}
}
