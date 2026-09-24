<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Domain;

use OCA\Territorio\Domain\BoundingBox;
use PHPUnit\Framework\TestCase;

/**
 * `holds()` is the cheap half of resolving an assignment (ADR-0008): four
 * comparisons that stand in front of the point-in-polygon walk. Its contract
 * — "Inclusive at the edges… a box that excluded its own border would throw
 * away points that ARE inside" — had no caller in any test. These coordinates
 * are the La Florida ones the rest of the suite uses.
 */
final class BoundingBoxTest extends TestCase {
	private const LON = -70.5553836;
	private const LAT = -33.5187671;

	private function box(): BoundingBox {
		return BoundingBox::around([
			[self::LON - 0.01, self::LAT - 0.01],
			[self::LON + 0.01, self::LAT + 0.01],
		]);
	}

	public function testInside(): void {
		self::assertTrue($this->box()->holds(self::LON, self::LAT));
	}

	/** All four edges — the contract the docblock states and nothing pinned. */
	public function testInclusiveAtEveryEdge(): void {
		$box = $this->box();

		self::assertTrue($box->holds($box->minLon(), self::LAT));
		self::assertTrue($box->holds($box->maxLon(), self::LAT));
		self::assertTrue($box->holds(self::LON, $box->minLat()));
		self::assertTrue($box->holds(self::LON, $box->maxLat()));
	}

	public function testOutsideBeyondEveryEdge(): void {
		$box = $this->box();

		self::assertFalse($box->holds($box->minLon() - 0.001, self::LAT));
		self::assertFalse($box->holds($box->maxLon() + 0.001, self::LAT));
		self::assertFalse($box->holds(self::LON, $box->minLat() - 0.001));
		self::assertFalse($box->holds(self::LON, $box->maxLat() + 0.001));
	}

	/** A Place's box: min and max are the same point, and it holds itself. */
	public function testADegenerateBoxIsAPlaceAndHoldsItsOwnCoordinate(): void {
		$point = BoundingBox::around([[self::LON, self::LAT]]);

		self::assertTrue($point->holds(self::LON, self::LAT));
		self::assertFalse($point->holds(self::LON + 0.0001, self::LAT));
	}

	/** No positions is a programming error, and min() on [] is the guard that
	 *  says so — pinned so a future rewrite cannot turn it into a silent
	 *  all-zeros box, which would hold the null island and assign by accident. */
	public function testNoPositionsIsAnErrorNotAZeroBox(): void {
		$this->expectException(\ValueError::class);
		BoundingBox::around([]);
	}
}
