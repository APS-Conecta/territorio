<?php

declare(strict_types=1);

namespace OCA\Territorio\Domain;

/**
 * The rectangle a geometry fits inside, in degrees.
 *
 * This exists because the database has no PostGIS and therefore no spatial
 * index. Four plain indexed columns carrying this box are what every "what is
 * in view" query filters on before anything looks at the geometry itself.
 */
final class BoundingBox {
	/**
	 * Metres per degree of latitude, near enough anywhere.
	 *
	 * Public because it is one fact with two readers: `metresTo()` below, which
	 * the street search splits same-name ways with, and DuplicateFinder's
	 * distance between two records. Two private copies of it were one edit away
	 * from disagreeing.
	 */
	public const METRES_PER_DEGREE = 111_320.0;

	private function __construct(
		private readonly float $minLat,
		private readonly float $minLon,
		private readonly float $maxLat,
		private readonly float $maxLon,
	) {
	}

	/**
	 * Direct constructor from min/max coordinates when bounds are already known.
	 *
	 * Bypasses array allocations and min/max scans done by around().
	 */
	public static function fromBounds(float $minLat, float $minLon, float $maxLat, float $maxLon): self {
		return new self($minLat, $minLon, $maxLat, $maxLon);
	}

	/**
	 * @param list<array{float, float}> $positions each as [longitude, latitude]
	 */
	public static function around(array $positions): self {
		$lons = array_column($positions, 0);
		$lats = array_column($positions, 1);

		return new self(min($lats), min($lons), max($lats), max($lons));
	}

	public function minLat(): float {
		return $this->minLat;
	}

	public function minLon(): float {
		return $this->minLon;
	}

	public function maxLat(): float {
		return $this->maxLat;
	}

	/**
	 * Whether a coordinate falls in the box.
	 *
	 * The cheap half of resolving an assignment (ADR-0008): four comparisons that
	 * reject almost every boundary before anything walks a ring. Inclusive at the
	 * edges, like the point-in-polygon test it stands in front of — a box that
	 * excluded its own border would throw away points that ARE inside.
	 */
	public function holds(float $lon, float $lat): bool {
		return $lat >= $this->minLat && $lat <= $this->maxLat
			&& $lon >= $this->minLon && $lon <= $this->maxLon;
	}

	public function maxLon(): float {
		return $this->maxLon;
	}

	/**
	 * Metres between this box and another where they come closest — 0 when they
	 * touch or overlap.
	 *
	 * The street search's measure of "one street or two" (BoundaryService::
	 * names()): the gap BETWEEN two ways rather than between their middles,
	 * because an avenue is held as a hundred ways laid end to end, each touching
	 * the next, while their centres are hundreds of metres apart.
	 *
	 * ponytail: equirectangular, for the reason DuplicateFinder::metresBetween()
	 * gives — over a few hundred metres the difference from haversine is
	 * millimetres. Longitude is scaled at the mean latitude of the four edges,
	 * so it is right at Santiago's -33° and not only at the equator.
	 */
	public function metresTo(self $other): float {
		$dLat = max(0.0, $other->minLat - $this->maxLat, $this->minLat - $other->maxLat);
		$dLon = max(0.0, $other->minLon - $this->maxLon, $this->minLon - $other->maxLon);
		$lat = ($this->minLat + $this->maxLat + $other->minLat + $other->maxLat) / 4;

		return hypot(
			$dLat * self::METRES_PER_DEGREE,
			$dLon * self::METRES_PER_DEGREE * cos(deg2rad($lat)),
		);
	}
}
