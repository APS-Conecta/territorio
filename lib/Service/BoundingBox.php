<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

/**
 * The rectangle a geometry fits inside, in degrees.
 *
 * This exists because the database has no PostGIS and therefore no spatial
 * index. Four plain indexed columns carrying this box are what every "what is
 * in view" query filters on before anything looks at the geometry itself.
 */
final class BoundingBox {
	private function __construct(
		private readonly float $minLat,
		private readonly float $minLon,
		private readonly float $maxLat,
		private readonly float $maxLon,
	) {
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
}
