<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Tools;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

use function Territorio\Tools\overpassQuery;
use function Territorio\Tools\toGeoJson;

require_once __DIR__ . '/../../../tools/boundary-features.php';

/**
 * The repo-side half of ADR-0004: OSM data arrives as a file a person produced
 * on purpose, never as a call the app makes. Everything here is pure — building
 * the query text and turning Overpass's answer into GeoJSON — so it runs with no
 * network at all. The one thing not covered is whether Overpass agrees, which no
 * test in this repo can tell you.
 */
final class BoundaryFeaturesTest extends TestCase {
	private const BBOX = '-33.55,-70.62,-33.48,-70.52';

	public function testTheQueryAsksForTheThreeKindsALimitCanFollow(): void {
		$query = overpassQuery(self::BBOX);

		self::assertStringContainsString('way["highway"]["name"]', $query);
		self::assertStringContainsString('way["waterway"]["name"]', $query);
		self::assertStringContainsString('way["railway"]["name"]', $query);
	}

	/**
	 * Every kind carries `["name"]`, and not only because unnamed service roads
	 * and driveways trebled the result: the import refuses a nameless row
	 * outright, so an unnamed way would abort the whole file rather than be
	 * skipped.
	 */
	public function testNothingUnnamedIsAskedFor(): void {
		foreach (['highway', 'waterway', 'railway'] as $kind) {
			self::assertStringNotContainsString(
				sprintf('way["%s"](', $kind),
				overpassQuery(self::BBOX),
				sprintf('an unfiltered %s query would pull in unnamed ways', $kind),
			);
		}
	}

	/**
	 * The box reaching the query is rebuilt from the parsed numbers, not the
	 * string that was typed — so what is asked for is what was understood.
	 */
	public function testTheQueryIsBoundedAndAsksForGeometry(): void {
		$query = overpassQuery(self::BBOX);

		self::assertStringContainsString('(-33.5500000,-70.6200000,-33.4800000,-70.5200000)', $query);
		// Without `out geom` Overpass answers with node references and no shape.
		self::assertStringContainsString('out geom;', $query);
	}

	/**
	 * The bbox is interpolated into the query text, so it is checked rather than
	 * trusted — four numbers, south below north and west below east.
	 */
	public function testAnUnusableBoundingBoxIsRefused(): void {
		foreach ([
			'' => 'empty',
			'-33.55,-70.62,-33.48' => 'three numbers',
			'-33.55,-70.62,-33.48,-70.52,1' => 'five numbers',
			'-33.48,-70.62,-33.55,-70.52' => 'south above north',
			'-33.55,-70.52,-33.48,-70.62' => 'west beyond east',
			'a,b,c,d' => 'not numbers',
			'-33.55,-70.62,-33.48,"]);out;//' => 'an injection attempt',
		] as $bbox => $why) {
			try {
				overpassQuery((string)$bbox);
				self::fail(sprintf('a bbox with %s should be refused', $why));
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	public function testAWayBecomesANamedLineStringKeyedByItsOsmId(): void {
		$collection = toGeoJson(self::overpassAnswer(), 'prueba');

		self::assertSame('FeatureCollection', $collection['type']);
		self::assertCount(2, $collection['features']);

		$street = $collection['features'][0];
		self::assertSame('way/101', $street['id']);
		self::assertSame('Calle de Prueba', $street['properties']['name']);
		self::assertSame('LineString', $street['geometry']['type']);
		// GeoJSON orders positions [longitude, latitude]; Overpass answers lat/lon.
		self::assertSame([[-70.60, -33.52], [-70.59, -33.52]], $street['geometry']['coordinates']);
	}

	/**
	 * The id is what a re-import matches on (ADR-0003). It comes from OSM rather
	 * than from a digest of name and position, so a street that is renamed or
	 * redrawn upstream updates the record it already has instead of arriving as a
	 * second copy of the same street.
	 */
	public function testTheIdSurvivesARenameUpstream(): void {
		$answer = self::overpassAnswer();
		$answer['elements'][0]['tags']['name'] = 'Calle Rebautizada';

		self::assertSame('way/101', toGeoJson($answer, 'prueba')['features'][0]['id']);
	}

	public function testEachFeatureKeepsALinkBackToWhatItCameFrom(): void {
		$properties = toGeoJson(self::overpassAnswer(), 'prueba')['features'][0]['properties'];

		self::assertSame('OpenStreetMap', $properties['source_es']);
		self::assertSame('https://www.openstreetmap.org/way/101', $properties['source_url']);
	}

	/**
	 * A way with one point, or none, is not a line anything can follow. Skipped
	 * rather than emitted: the import parses every geometry before it writes
	 * anything, so one unusable row would refuse the entire file.
	 */
	public function testAWayThatIsNotALineIsLeftOut(): void {
		$answer = self::overpassAnswer();
		$answer['elements'][] = [
			'type' => 'way',
			'id' => 103,
			'tags' => ['name' => 'Un solo punto'],
			'geometry' => [['lat' => -33.5, 'lon' => -70.6]],
		];

		self::assertCount(2, toGeoJson($answer, 'prueba')['features']);
	}

	public function testAnythingWithoutANameIsLeftOut(): void {
		$answer = self::overpassAnswer();
		$answer['elements'][] = [
			'type' => 'way',
			'id' => 104,
			'tags' => ['highway' => 'service'],
			'geometry' => [['lat' => -33.5, 'lon' => -70.6], ['lat' => -33.5, 'lon' => -70.59]],
		];

		self::assertCount(2, toGeoJson($answer, 'prueba')['features']);
	}

	/** Overpass answers with nodes and relations too when a way references them. */
	public function testOnlyWaysAreTaken(): void {
		$answer = self::overpassAnswer();
		$answer['elements'][] = [
			'type' => 'node',
			'id' => 900,
			'tags' => ['name' => 'Un nodo con nombre'],
			'lat' => -33.5,
			'lon' => -70.6,
		];

		self::assertCount(2, toGeoJson($answer, 'prueba')['features']);
	}

	public function testAnAnswerWithNoElementsIsAnEmptyCollectionNotAnError(): void {
		$collection = toGeoJson(['elements' => []], 'prueba');

		self::assertSame([], $collection['features']);
	}

	/**
	 * Boundary features are filed under the fallback Subcategory on purpose: they
	 * are never drawn on the ordinary map and never toggled, so what they ARE is
	 * said by the Dataset they belong to, not by a taxonomy row nobody sees.
	 */
	public function testTheyAreFiledWhereNothingHasToBeSeededForThem(): void {
		$properties = toGeoJson(self::overpassAnswer(), 'prueba')['features'][0]['properties'];

		self::assertSame('sin_clasificar', $properties['category']);
		self::assertSame('otro', $properties['subcategory']);
	}

	/**
	 * ADR-0004, enforced rather than described: "the app makes no Overpass calls
	 * of its own". Live Overpass is a free service with rate limits and no SLA,
	 * and a call from `lib/` or `src/` would put that inside a consultation. This
	 * file is the only place the address may appear, and it is neither.
	 */
	public function testNothingInTheAppEverCallsOverpass(): void {
		$root = dirname(__DIR__, 3);

		foreach (['lib', 'src'] as $directory) {
			/** @var \SplFileInfo $file */
			foreach (new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($root . '/' . $directory),
			) as $file) {
				if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'js', 'vue'], true)) {
					continue;
				}

				self::assertStringNotContainsStringIgnoringCase(
					'overpass',
					(string)file_get_contents($file->getPathname()),
					sprintf('%s reaches for Overpass; ADR-0004 keeps it repo-side', $file->getPathname()),
				);
			}
		}
	}

	/** Invented, not copied from any real extract — two ways is enough to pin the shape. */
	private static function overpassAnswer(): array {
		return [
			'version' => 0.6,
			'elements' => [
				[
					'type' => 'way',
					'id' => 101,
					'tags' => ['highway' => 'residential', 'name' => 'Calle de Prueba'],
					'geometry' => [
						['lat' => -33.52, 'lon' => -70.60],
						['lat' => -33.52, 'lon' => -70.59],
					],
				],
				[
					'type' => 'way',
					'id' => 102,
					'tags' => ['waterway' => 'canal', 'name' => 'Canal de Prueba'],
					'geometry' => [
						['lat' => -33.53, 'lon' => -70.60],
						['lat' => -33.53, 'lon' => -70.58],
					],
				],
			],
		];
	}
}
