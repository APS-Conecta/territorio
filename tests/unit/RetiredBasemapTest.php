<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * What ADR-0021 removed stays removed.
 *
 * The basemap is OpenStreetMap's tile service as one constant, with no setting
 * and no second form. Each case here fails when a removed piece comes back by
 * the front door: a class, a dependency, an import.
 */
final class RetiredBasemapTest extends TestCase {
	private static function root(): string {
		return dirname(__DIR__, 2);
	}

	public function testTheSettingsAndTheirResolverAreGone(): void {
		self::assertFalse(class_exists('OCA\\Territorio\\Domain\\Basemap'));
		self::assertFalse(class_exists('OCA\\Territorio\\Service\\BasemapConfig'));
	}

	public function testTheAdministratorCannotSaveATileSetting(): void {
		self::assertFalse(
			method_exists(\OCA\Territorio\Controller\AdminSettingsController::class, 'save'),
			'AdminSettingsController::save wrote tile_url and tile_attribution',
		);
	}

	public function testTheVectorBasemapLibraryIsNotADependency(): void {
		$package = json_decode((string)file_get_contents(self::root() . '/package.json'), true);

		self::assertArrayNotHasKey('protomaps-leaflet', $package['dependencies']);
	}

	public function testNothingInSrcImportsIt(): void {
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/src'));
		foreach ($files as $file) {
			if (!$file->isFile() || !preg_match('/\.(js|vue)$/', $file->getFilename())) {
				continue;
			}

			self::assertFalse(
				str_contains((string)file_get_contents($file->getPathname()), 'protomaps-leaflet'),
				$file->getPathname() . ' imports the retired vector basemap library',
			);
		}
	}
}
