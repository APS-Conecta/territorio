<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

/**
 * Whether a coordinate falls inside a polygon.
 *
 * Ray casting, and nothing else: no PostGIS (it is not in the database image),
 * no GEOS, no composer geometry library. This is the whole of what ADR-0008
 * needs — "which Sector is this school in" — and it is forty lines.
 *
 * **A point on the boundary counts as inside.** That is a decision. Ray casting
 * alone answers a boundary point differently depending on which way the ray
 * happens to leave, which is no answer at all; and a record sitting on the line
 * between two sectores belongs to one of them rather than to neither. "Neither"
 * is the answer that looks like a bug to whoever finds it.
 *
 * Depends on no OCP class, so it tests in bare PHP.
 */
final class PointInPolygon {
	/**
	 * How close to an edge still counts as on it.
	 *
	 * Coordinates here are degrees, so 1e-9 is about a tenth of a millimetre —
	 * far below any cadastre's accuracy, and far above the rounding error of
	 * comparing two doubles that ought to be equal.
	 */
	private const ON_THE_LINE = 1e-9;

	/**
	 * @param array<int, array<int, array{float, float}>> $rings GeoJSON Polygon
	 *   coordinates: the outer ring first, then any holes
	 */
	public static function contains(array $rings, float $lon, float $lat): bool {
		$outer = $rings[0] ?? null;
		if (!is_array($outer) || count($outer) < 4) {
			// Fewer than four positions cannot close a ring, so there is no inside.
			return false;
		}

		if (!self::inRing($outer, $lon, $lat)) {
			return false;
		}

		// A hole is a ring the point must NOT be in — except on its edge, which is
		// still the boundary of the polygon and so still inside it.
		foreach (array_slice($rings, 1) as $hole) {
			if (is_array($hole) && count($hole) >= 4
				&& self::inRing($hole, $lon, $lat)
				&& !self::onBoundary($hole, $lon, $lat)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<int, array{float, float}> $ring
	 */
	private static function inRing(array $ring, float $lon, float $lat): bool {
		if (self::onBoundary($ring, $lon, $lat)) {
			return true;
		}

		$inside = false;
		$count = count($ring);

		for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
			[$xi, $yi] = $ring[$i];
			[$xj, $yj] = $ring[$j];

			// Each edge is counted as spanning its lower end and not its upper one.
			// That asymmetry is the point: a ray leaving exactly through a vertex
			// touches two edges, and counting both would flip the answer.
			$spans = ($yi > $lat) !== ($yj > $lat);
			if ($spans && $lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
				$inside = !$inside;
			}
		}

		return $inside;
	}

	/**
	 * @param array<int, array{float, float}> $ring
	 */
	private static function onBoundary(array $ring, float $lon, float $lat): bool {
		$count = count($ring);

		for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
			if (self::onSegment($ring[$j], $ring[$i], $lon, $lat)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array{float, float} $from
	 * @param array{float, float} $to
	 */
	private static function onSegment(array $from, array $to, float $lon, float $lat): bool {
		[$x1, $y1] = $from;
		[$x2, $y2] = $to;

		// Outside the segment's own box, so not on it — and this is what keeps the
		// cross-product test below from matching the whole infinite line.
		if ($lon < min($x1, $x2) - self::ON_THE_LINE || $lon > max($x1, $x2) + self::ON_THE_LINE
			|| $lat < min($y1, $y2) - self::ON_THE_LINE || $lat > max($y1, $y2) + self::ON_THE_LINE) {
			return false;
		}

		// Zero cross product means the three points are collinear.
		$cross = ($lon - $x1) * ($y2 - $y1) - ($lat - $y1) * ($x2 - $x1);

		return abs($cross) <= self::ON_THE_LINE;
	}
}
