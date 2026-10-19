<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Domain;

use OCA\Territorio\Domain\ComunaExtent;
use OCA\Territorio\Domain\ComunaExtentTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The validator over the committed, generated table. The generator checks its
 * own input; this checks what was actually committed, which is what the app
 * ships.
 */
final class ComunaExtentTableTest extends TestCase {
	private const SOURCE = __DIR__ . '/../../../lib/Domain/ComunaExtentTable.php';
	private const CUTS = __DIR__ . '/../../fixtures/comuna-cuts.txt';

	/**
	 * A tripwire for a part picked wrongly: the union of every part of a
	 * southern comuna is a box of many degrees. Not a statement about any
	 * real comuna.
	 */
	private const MAX_SPAN = 4.0;

	/** PHP turns a numeric string key such as '13101' into an int; '01101' stays a string. */
	private static function cuts(): array {
		return array_map('strval', array_keys(ComunaExtentTable::BY_CUT));
	}

	public function testTheHeaderSaysWhereTheNumbersCameFrom(): void {
		$header = (string)file_get_contents(self::SOURCE);

		foreach ([
			'GENERATED',
			'geoportal-dpa-comunas-2023',
			'https://geoportal.cl/geoportal/catalog/download/912598ad-ac92-35f6-8045-098f214bd9c2',
			'8ac1ce872230b645c3cf04db2d980c1968e78f602144347f4513119dc9b945db',
			'345 records',
			'SIRGAS-Chile',
			'most DEIS establishments',
			'python3 -I tools/comuna-extents.py',
		] as $fact) {
			self::assertStringContainsString($fact, $header);
		}
	}

	/** Exactly the 345 comunas of comunas-deis.csv, minus Antártica (12202) and the 99999 sentinel. */
	public function testItCoversEveryComunaAndNothingElse(): void {
		$expected = file(self::CUTS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

		self::assertCount(345, $expected);
		self::assertSame($expected, self::cuts());
	}

	public function testItIsSortedByCut(): void {
		$cuts = self::cuts();
		$sorted = $cuts;
		sort($sorted, SORT_STRING);

		self::assertSame($sorted, $cuts);
	}

	public function testNubleHasAllTwentyOneOfItsComunas(): void {
		$nuble = array_filter(self::cuts(), static fn (string $cut): bool => str_starts_with($cut, '16'));

		self::assertCount(21, $nuble);
	}

	public function testEveryRowIsAValidExtentNoWiderThanFourDegrees(): void {
		$widest = 0.0;
		foreach (self::cuts() as $cut) {
			$extent = ComunaExtent::forCut($cut);
			self::assertNotNull($extent, $cut);

			$box = $extent->box();
			$widest = max($widest, $box->maxLon() - $box->minLon(), $box->maxLat() - $box->minLat());
		}

		self::assertLessThanOrEqual(self::MAX_SPAN, $widest);
	}

	/**
	 * Places people live. The first three are the comunas whose largest part
	 * is the wrong one, so these fail if the generator takes the largest part,
	 * or the union of all of them, instead of the part with the establishments.
	 *
	 * @return array<string, array{string, float, float}>
	 */
	public static function anchors(): array {
		return [
			'Puerto Natales' => ['12401', -72.4978, -51.7308],
			'Puerto Williams' => ['12201', -67.6167, -54.9333],
			'San Juan Bautista, Robinson Crusoe' => ['05104', -78.8359, -33.6393],
			'Santiago centre' => ['13101', -70.6693, -33.4489],
			'La Florida' => ['13110', -70.5985, -33.5225],
		];
	}

	#[DataProvider('anchors')]
	public function testAPlacePeopleLiveIsInsideItsComunasBox(string $cut, float $lon, float $lat): void {
		$extent = ComunaExtent::forCut($cut);

		self::assertNotNull($extent);
		self::assertTrue($extent->box()->holds($lon, $lat));
	}
}
