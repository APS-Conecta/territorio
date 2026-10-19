<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Controller;

use OCA\Territorio\Controller\PageController;
use OCA\Territorio\Domain\Comuna;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\FeaturePolicy;
use PHPUnit\Framework\TestCase;

/**
 * The page grants geolocation to itself, or «¿Dónde estoy?» is dead.
 *
 * Nextcloud puts a bare FeaturePolicy on every TemplateResponse, and a bare one
 * says `geolocation 'none'`: measured on NC 34, every click on the control
 * failed with code 1 before the browser's own permission was consulted, and
 * three green suites never saw it — nothing here renders a page. This asserts
 * the one object index() sets beside the CSP. That it reaches the header is
 * tools/browser-check.py's to measure: a policy value can read correct and
 * block silently, which is what the CSP directive did once (78a94e3).
 *
 * The same goes for comunaState(), the shape of the `comuna` initial state the
 * map opens on (ADR-0021), and for contentSecurityPolicy(), which names the one
 * host the basemap's images come from (ADR-0021).
 *
 * The factory rather than index(): index() calls Util::addScript, which wants
 * a running Nextcloud (`Class "OC" not found` under `make test`).
 */
final class PageControllerTest extends TestCase {
	public function testThePageGrantsGeolocationToItselfAndNobodyElse(): void {
		$built = PageController::geolocationPolicy()->buildPolicy();

		// The whole directive, terminator included: `geolocation 'self' https://x;`
		// would contain the substring and grant a second origin.
		self::assertStringContainsString("geolocation 'self';", $built);
	}

	/**
	 * FeaturePolicy, not EmptyFeaturePolicy. Core's FeaturePolicyMiddleware
	 * merges the response's policy into its defaults UNLESS the class is
	 * exactly EmptyFeaturePolicy, which it returns untouched — so an empty one
	 * would read as the grant above and grant nothing.
	 */
	public function testThePolicyIsOneTheMiddlewareMerges(): void {
		self::assertInstanceOf(FeaturePolicy::class, PageController::geolocationPolicy());
	}

	/**
	 * The directives of a built policy, by name. Split the way a browser reads it
	 * (`name value value;`), so a case compares whole directives and not substrings.
	 *
	 * @return array<string, string>
	 */
	private static function directives(ContentSecurityPolicy $policy): array {
		$directives = [];
		foreach (explode(';', $policy->buildPolicy()) as $directive) {
			$directive = trim($directive);
			if ($directive === '') {
				continue;
			}
			[$name, $value] = array_pad(explode(' ', $directive, 2), 2, '');
			$directives[$name] = $value;
		}

		return $directives;
	}

	/**
	 * The basemap is `<img>` loads, so the host goes in `img-src`: a host named in
	 * any other directive reads as though it allowed the map and leaves it blank
	 * (the lesson commit 78a94e3 records).
	 */
	public function testTheBasemapHostIsAllowedForImagesAndNothingElse(): void {
		$base = self::directives(new ContentSecurityPolicy());
		$built = self::directives(PageController::contentSecurityPolicy());

		self::assertSame('https://tile.openstreetmap.org', PageController::BASEMAP_HOST);
		self::assertSame($base['img-src'] . ' https://tile.openstreetmap.org', $built['img-src']);

		// Everything else is Nextcloud's default, byte for byte: no connect-src
		// domain (nothing is fetched cross-origin any more), no second image host.
		unset($base['img-src'], $built['img-src']);
		self::assertSame($base, $built);
	}

	public function testNoOtherHostAppearsAnywhereInThePolicy(): void {
		$hosts = [];
		preg_match_all('#https?://[^\s;]+#', PageController::contentSecurityPolicy()->buildPolicy(), $hosts);

		self::assertSame(['https://tile.openstreetmap.org'], array_values(array_unique($hosts[0])));
	}

	/**
	 * The frontend's tile URL is the one place JavaScript names the host. PHP and
	 * JS cannot share a constant, so this is what keeps the policy and the layer
	 * from naming two different hosts.
	 */
	public function testThePolicyHostIsTheHostTheTileLayerLoads(): void {
		$source = (string)file_get_contents(dirname(__DIR__, 3) . '/src/basemap.js');
		self::assertSame(
			1,
			preg_match("#export const OSM_TILE_URL = '(https://[^/']+)/#", $source, $match),
			'src/basemap.js must export OSM_TILE_URL',
		);

		self::assertSame(PageController::BASEMAP_HOST, $match[1]);
	}

	/**
	 * Every number differs from every other and longitude is far from latitude,
	 * so swapping any two corners changes a value this reads back. Leaflet's
	 * fitBounds order: [[south, west], [north, east]].
	 */
	public function testAChosenKnownComunaCarriesItsOwnExtent(): void {
		self::assertSame(
			[
				'cut' => '13110',
				'name' => 'La Florida',
				'chosen' => true,
				'extent' => [[-33.571, -70.615], [-33.491, -70.436]],
			],
			PageController::comunaState(Comuna::of('13110', 'La Florida')),
		);
	}

	/** A valid CUT the table has no box for (Antártica): the page still loads, the map opens on the next source. */
	public function testAChosenComunaMissingFromTheTableHasNoExtent(): void {
		self::assertSame(
			['cut' => '12202', 'name' => 'Antártica', 'chosen' => true, 'extent' => null],
			PageController::comunaState(Comuna::of('12202', 'Antártica')),
		);
	}

	public function testNoComunaChosenMeansNoExtent(): void {
		self::assertSame(
			['cut' => '', 'name' => '', 'chosen' => false, 'extent' => null],
			PageController::comunaState(Comuna::none()),
		);
	}

	/**
	 * Only the selected row reaches the client, never the table. The table has
	 * a few hundred rows, so the payload is checked by how big it is and by which
	 * CUTs it names, not by trusting the shape above.
	 */
	public function testOnlyTheSelectedRowReachesTheClient(): void {
		$json = (string)json_encode(PageController::comunaState(Comuna::of('13110', 'La Florida')));

		self::assertLessThan(200, strlen($json));
		// A CUT as a key or value other than its own would be another row.
		preg_match_all('/\d{5}/', $json, $cuts);
		self::assertSame(['13110'], array_values(array_unique($cuts[0])));
	}
}
