<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Service\PointInPolygon;
use PHPUnit\Framework\TestCase;

/**
 * Which polygon a coordinate falls in, in about forty lines.
 *
 * No PostGIS — it is not in the database image — no GEOS, and no composer
 * geometry library. Ray casting is old, short and exactly right for this, and
 * the cases worth pinning are the ones textbooks gloss over: a point on an edge,
 * a point on a vertex, and a ray that leaves through a vertex.
 *
 * The boundary rule is a decision, not an accident: **a point on the boundary is
 * inside**. A record on the line between two sectores belongs to one of them
 * rather than to neither, and "neither" is the answer that looks like a bug to
 * whoever finds it.
 */
final class PointInPolygonTest extends TestCase {
	/** A unit square, counter-clockwise, closed. */
	private const SQUARE = [[[0.0, 0.0], [4.0, 0.0], [4.0, 4.0], [0.0, 4.0], [0.0, 0.0]]];

	public function testAPointWellInsideIsInside(): void {
		self::assertTrue(PointInPolygon::contains(self::SQUARE, 2.0, 2.0));
	}

	public function testAPointWellOutsideIsOutside(): void {
		self::assertFalse(PointInPolygon::contains(self::SQUARE, 9.0, 9.0));
		self::assertFalse(PointInPolygon::contains(self::SQUARE, -1.0, 2.0));
	}

	/**
	 * On the edge counts as in. Ray casting on its own answers this differently
	 * depending on which side the ray leaves, which is no answer at all.
	 */
	public function testAPointOnAnEdgeIsInside(): void {
		self::assertTrue(PointInPolygon::contains(self::SQUARE, 0.0, 2.0), 'left edge');
		self::assertTrue(PointInPolygon::contains(self::SQUARE, 4.0, 2.0), 'right edge');
		self::assertTrue(PointInPolygon::contains(self::SQUARE, 2.0, 0.0), 'bottom edge');
		self::assertTrue(PointInPolygon::contains(self::SQUARE, 2.0, 4.0), 'top edge');
	}

	public function testAPointOnAVertexIsInside(): void {
		foreach ([[0.0, 0.0], [4.0, 0.0], [4.0, 4.0], [0.0, 4.0]] as [$lon, $lat]) {
			self::assertTrue(
				PointInPolygon::contains(self::SQUARE, $lon, $lat),
				sprintf('vertex %s,%s', $lon, $lat),
			);
		}
	}

	/**
	 * The classic ray-casting trap: a horizontal ray that leaves exactly through
	 * a vertex counts that crossing twice, and the point flips to "outside".
	 */
	public function testARayLeavingThroughAVertexStillCountsOnce(): void {
		$diamond = [[[2.0, 0.0], [4.0, 2.0], [2.0, 4.0], [0.0, 2.0], [2.0, 0.0]]];

		self::assertTrue(PointInPolygon::contains($diamond, 2.0, 2.0), 'the centre');
		self::assertFalse(PointInPolygon::contains($diamond, 4.5, 2.0), 'past the right vertex');
	}

	/** A hole is not inside, however far inside the outer ring it sits. */
	public function testAPointInAHoleIsOutside(): void {
		$withHole = [
			[[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0], [0.0, 0.0]],
			[[4.0, 4.0], [6.0, 4.0], [6.0, 6.0], [4.0, 6.0], [4.0, 4.0]],
		];

		self::assertTrue(PointInPolygon::contains($withHole, 1.0, 1.0), 'between the rings');
		self::assertFalse(PointInPolygon::contains($withHole, 5.0, 5.0), 'in the hole');
	}

	/** A concave shape is where a naive bounding-box answer goes wrong. */
	public function testAConcaveShapeIsNotItsBoundingBox(): void {
		// An L, missing its top-right quarter.
		$ele = [[[0.0, 0.0], [4.0, 0.0], [4.0, 2.0], [2.0, 2.0], [2.0, 4.0], [0.0, 4.0], [0.0, 0.0]]];

		self::assertTrue(PointInPolygon::contains($ele, 1.0, 3.0), 'in the tall arm');
		self::assertFalse(PointInPolygon::contains($ele, 3.0, 3.0), 'in the missing quarter');
	}

	/**
	 * The tolerance is a distance, and it has to stay one however short the edge.
	 *
	 * The collinearity test compares a cross product, which is an AREA: for a
	 * segment a metre long, a fixed area tolerance of 1e-9 degrees² answers "on
	 * the line" for a point most of a metre away from it. Boundaries at cadastral
	 * vertex density are exactly that short, and the answer flips from outside to
	 * inside.
	 *
	 * This diamond is about a metre across; the point is well outside it.
	 */
	public function testAShortEdgeDoesNotSwallowPointsNearIt(): void {
		$tiny = [[
			[0.0, 1e-5], [1e-5, 0.0], [2e-5, 1e-5], [1e-5, 2e-5], [0.0, 1e-5],
		]];

		self::assertFalse(PointInPolygon::contains($tiny, 0.0, 0.0), 'a corner of its bounding box');
		self::assertTrue(PointInPolygon::contains($tiny, 1e-5, 1e-5), 'and the centre is still inside');
	}

	/** Nonsense in, false out — never a crash, and never a wrong "inside". */
	public function testSomethingThatIsNotAPolygonContainsNothing(): void {
		self::assertFalse(PointInPolygon::contains([], 1.0, 1.0));
		self::assertFalse(PointInPolygon::contains([[]], 1.0, 1.0));
		self::assertFalse(PointInPolygon::contains([[[0.0, 0.0], [1.0, 1.0]]], 0.5, 0.5), 'a line');
	}

	/**
	 * Chilean coordinates are negative in both ordinates, which is where a sign
	 * mistake in the ray test would show up and nowhere else.
	 */
	public function testItWorksInTheSouthernAndWesternHemispheres(): void {
		$comuna = [[
			[-70.62, -33.56], [-70.54, -33.56], [-70.54, -33.48], [-70.62, -33.48], [-70.62, -33.56],
		]];

		self::assertTrue(PointInPolygon::contains($comuna, -70.58, -33.52));
		self::assertFalse(PointInPolygon::contains($comuna, -70.70, -33.52));
	}
}
