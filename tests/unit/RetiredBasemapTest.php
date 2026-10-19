<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The vector basemap library stays out of the dependencies (ADR-0021).
 *
 * The one removal nothing else pins: a retired class or route fails on its own
 * the moment something uses it, but a dependency that comes back through
 * package.json fails nothing until the bundle is read.
 */
final class RetiredBasemapTest extends TestCase {
	public function testTheVectorBasemapLibraryIsNotADependency(): void {
		$package = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/package.json'), true);

		self::assertArrayNotHasKey('protomaps-leaflet', $package['dependencies']);
	}
}
