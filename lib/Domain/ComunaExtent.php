<?php

declare(strict_types=1);

namespace OCA\Territorio\Domain;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Where the map opens for a Comuna: the box of the part of it where people live
 * (ADR-0021, "Where the map opens"). It decides the opening view and nothing
 * else; there is no pan lock.
 *
 * Built only through {@see of()} and {@see forCut()}, and refused rather than
 * repaired when it is not a box: a pair of corners swapped, or longitude
 * given where latitude belongs, would open the map on the wrong ocean and
 * nothing on screen would say why.
 *
 * Depends on no OCP class, so it tests in bare PHP.
 */
final class ComunaExtent implements JsonSerializable {
	/**
	 * The widest a single box may be. The real ones are under 4 degrees; the
	 * extra room is for a comuna that grows, while a box several times that is
	 * the union of every island of an archipelago, which is the mistake the
	 * generator's part rule exists to avoid.
	 */
	private const MAX_SPAN_DEGREES = 6.0;

	private function __construct(
		private readonly BoundingBox $box,
	) {
	}

	/**
	 * Arguments in the generated table's order, west, south, east, north,
	 * which is not BoundingBox's (minLat, minLon, maxLat, maxLon): this is the
	 * one place that crosses between them.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function of(float $west, float $south, float $east, float $north): self {
		foreach ([$west, $south, $east, $north] as $degrees) {
			if (!is_finite($degrees)) {
				throw new InvalidArgumentException('A Comuna extent needs finite numbers.');
			}
		}
		if (abs($west) > 180.0 || abs($east) > 180.0 || abs($south) > 90.0 || abs($north) > 90.0) {
			throw new InvalidArgumentException('A Comuna extent is longitude within 180 and latitude within 90.');
		}
		if ($west >= $east || $south >= $north) {
			throw new InvalidArgumentException('A Comuna extent runs west to east and south to north.');
		}
		if ($east - $west > self::MAX_SPAN_DEGREES || $north - $south > self::MAX_SPAN_DEGREES) {
			throw new InvalidArgumentException('A Comuna extent is at most 6 degrees across.');
		}

		return new self(BoundingBox::fromBounds($south, $west, $north, $east));
	}

	/** The generated box for a CUT, or null when the table has none: the map then opens on the next source. */
	public static function forCut(string $cut): ?self {
		$row = ComunaExtentTable::BY_CUT[$cut] ?? null;

		return $row === null ? null : self::of(...$row);
	}

	public function box(): BoundingBox {
		return $this->box;
	}

	/**
	 * Leaflet's fitBounds order, [[south, west], [north, east]].
	 *
	 * @return array{array{float, float}, array{float, float}}
	 */
	public function jsonSerialize(): array {
		return [
			[$this->box->minLat(), $this->box->minLon()],
			[$this->box->maxLat(), $this->box->maxLon()],
		];
	}
}
