<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Controller;

use OCA\Territorio\Controller\PageController;
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
}
