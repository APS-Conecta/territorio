<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use JsonException;
use OCA\Territorio\Exception\InvalidGeometryException;

/**
 * A GeoJSON geometry, checked once on the way in.
 *
 * Geometry is stored as GeoJSON text with a bounding box beside it, because the
 * database has no PostGIS and no spatial index. Validating here means a stored
 * geometry is always renderable and always has a usable box — a Feature that
 * cannot be measured is a Feature that silently disappears from the map.
 *
 * Depends on no OCP class, so it tests in bare PHP.
 */
final class Geometry {
	/**
	 * The three geometry types this app stores, and the Kind each one makes.
	 * GeometryCollection is deliberately absent: it is valid GeoJSON that keeps
	 * its contents under a different key, so accepting it would yield a Feature
	 * with no measurable extent.
	 */
	private const KINDS = [
		'Point' => Kind::Place,
		'LineString' => Kind::Route,
		'Polygon' => Kind::Zone,
	];

	/** How many arrays deep the positions are nested, per type. */
	private const NESTING = [
		'Point' => 0,
		'LineString' => 1,
		'Polygon' => 2,
	];

	/**
	 * @param list<array{float, float}> $positions every position, flattened
	 */
	private function __construct(
		private readonly string $type,
		private readonly array $coordinates,
		private readonly array $positions,
	) {
	}

	public static function fromJson(string $json): self {
		try {
			$decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new InvalidGeometryException('geometry is not valid JSON: ' . $e->getMessage(), 0, $e);
		}

		if (!is_array($decoded) || array_is_list($decoded)) {
			throw new InvalidGeometryException('a geometry must be a JSON object');
		}

		return self::fromArray($decoded);
	}

	/** @param array<string, mixed> $geometry */
	public static function fromArray(array $geometry): self {
		$type = $geometry['type'] ?? null;

		if (!is_string($type) || !isset(self::KINDS[$type])) {
			throw new InvalidGeometryException(sprintf(
				'unsupported geometry type %s; expected one of %s',
				var_export($type, true),
				implode(', ', array_keys(self::KINDS)),
			));
		}

		$coordinates = $geometry['coordinates'] ?? null;
		if (!is_array($coordinates) || $coordinates === []) {
			throw new InvalidGeometryException($type . ' has no coordinates');
		}

		return new self($type, $coordinates, self::flatten($coordinates, self::NESTING[$type]));
	}

	/**
	 * Whether a geometry of this type can be stored at all.
	 *
	 * Asked before a file is read, by the import, so a row it cannot take is
	 * counted rather than throwing. It reads the same list `fromArray()` enforces
	 * — a second list kept alongside would eventually disagree with this one, and
	 * the disagreement would show up as rows silently dropped.
	 */
	public static function supports(mixed $type): bool {
		return is_string($type) && isset(self::KINDS[$type]);
	}

	public function type(): string {
		return $this->type;
	}

	public function kind(): Kind {
		return self::KINDS[$this->type];
	}

	public function toJson(): string {
		return json_encode(
			['type' => $this->type, 'coordinates' => $this->coordinates],
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
		);
	}

	public function boundingBox(): BoundingBox {
		return BoundingBox::around($this->positions);
	}

	/**
	 * Walk exactly as many levels as the type declares, then read positions.
	 *
	 * Depth-aware rather than "recurse until numbers appear": a Point given a
	 * polygon's nesting is a different mistake from a valid polygon, and only
	 * counting levels tells them apart.
	 *
	 * @return list<array{float, float}>
	 */
	private static function flatten(array $node, int $levels): array {
		if ($levels === 0) {
			return [self::position($node)];
		}

		if ($node === []) {
			throw new InvalidGeometryException('a geometry cannot contain an empty ring or line');
		}

		$positions = [];
		foreach ($node as $child) {
			if (!is_array($child)) {
				throw new InvalidGeometryException('coordinates are nested less deeply than the type requires');
			}
			foreach (self::flatten($child, $levels - 1) as $position) {
				$positions[] = $position;
			}
		}

		return $positions;
	}

	/** @return array{float, float} [longitude, latitude] */
	private static function position(array $position): array {
		if (!array_is_list($position) || count($position) < 2) {
			throw new InvalidGeometryException('a position needs a longitude and a latitude');
		}

		[$longitude, $latitude] = $position;

		// Strings are rejected rather than coerced: "-70.5" reaching here means
		// something upstream stringified the numbers, and quietly accepting it
		// would hide that.
		foreach ([$longitude, $latitude] as $ordinate) {
			if (!is_int($ordinate) && !is_float($ordinate)) {
				throw new InvalidGeometryException('coordinates must be numbers');
			}
		}

		// These catch gross nonsense only. They do NOT catch the classic mistake
		// of swapping latitude and longitude, because for Chile both ordinates
		// are in range for both roles: -33.5 and -70.5 are each a valid latitude
		// and a valid longitude. A swapped Chilean coordinate is accepted here
		// and lands in the South Atlantic.
		// ponytail: range-only check; tighten to the install's Comuna bounds once
		// there is one (issue #9), which is what would actually catch a swap.
		if ($latitude < -90 || $latitude > 90) {
			throw new InvalidGeometryException(sprintf(
				'latitude %s is outside the world; GeoJSON orders positions [longitude, latitude]',
				var_export($latitude, true),
			));
		}
		if ($longitude < -180 || $longitude > 180) {
			throw new InvalidGeometryException(sprintf('longitude %s is outside the world', var_export($longitude, true)));
		}

		return [(float)$longitude, (float)$latitude];
	}
}
