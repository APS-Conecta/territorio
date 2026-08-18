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

	/**
	 * These two messages stay English while every message `fromArray()` throws is
	 * Spanish. That is the language rule, not an oversight: `fromArray()`'s message
	 * is spliced into the refusal a clinician reads, and this one is never read at
	 * all — the sole caller, {@see AssignmentService::boxOf()}, catches without
	 * binding the exception.
	 */
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
				'tipo de geometría %s no admitido; se esperaba uno de %s',
				var_export($type, true),
				implode(', ', array_keys(self::KINDS)),
			));
		}

		$coordinates = $geometry['coordinates'] ?? null;
		if (!is_array($coordinates) || $coordinates === []) {
			throw new InvalidGeometryException($type . ' no tiene coordenadas');
		}

		if ($type === 'Polygon') {
			$coordinates = self::closedRings($coordinates);
		}

		return new self($type, $coordinates, self::flatten($coordinates, self::NESTING[$type]));
	}

	/**
	 * Every ring with its first position repeated last, as GeoJSON requires
	 * (RFC 7946 §3.1.6).
	 *
	 * Real files do not always do it, and KML rings arrive through GeoJsonReader
	 * the same way, so there are two doors. Closing here — once, on the way in —
	 * rather than tolerating an open ring later, because left open it failed in
	 * three places and announced itself in none:
	 *
	 * - `PointInPolygon` needs four positions before a ring has an inside, so an
	 *   unclosed TRIANGLE contained nothing at all and every Place inside it was
	 *   assigned to nobody. Leaflet closes rings when it draws, so the boundary
	 *   looked perfectly correct on the map.
	 * - An unclosed QUAD already has four positions and worked, which made
	 *   closure silently required at three vertices and silently optional at four.
	 * - The app re-exports what it stored, so an invalid ring went back out as
	 *   invalid GeoJSON — to a municipality, in the format they asked for.
	 *
	 * @param array<int, mixed> $rings
	 * @return array<int, array<int, array{float, float}>>
	 */
	private static function closedRings(array $rings): array {
		foreach ($rings as $index => $ring) {
			if (!is_array($ring) || $ring === []) {
				throw new InvalidGeometryException('un anillo de Polygon debe ser una lista de posiciones');
			}

			$positions = array_values($ring);
			$first = $positions[0];
			// `==` and not `===`: JSON decodes -70 as int and -70.0 as float, and a
			// ring closed with the same coordinate written differently is closed.
			if (count($positions) > 1 && end($positions) == $first) {
				array_pop($positions);
			}

			// Three distinct corners is the least that can bound an area. Fewer
			// stores and draws as a line and then contains nothing, which is the
			// same silence in a smaller shape.
			if (count($positions) < 3) {
				throw new InvalidGeometryException(sprintf(
					'un anillo de Polygon necesita al menos tres posiciones para encerrar un área; el anillo %d tiene %d',
					$index,
					count($positions),
				));
			}

			$positions[] = $first;
			$rings[$index] = $positions;
		}

		return $rings;
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
			throw new InvalidGeometryException('una geometría no puede contener un anillo o una línea vacía');
		}

		$positions = [];
		foreach ($node as $child) {
			if (!is_array($child)) {
				throw new InvalidGeometryException('las coordenadas están menos anidadas de lo que exige el tipo');
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
			throw new InvalidGeometryException('una posición necesita longitud y latitud');
		}

		[$longitude, $latitude] = $position;

		// Strings are rejected rather than coerced: "-70.5" reaching here means
		// something upstream stringified the numbers, and quietly accepting it
		// would hide that.
		foreach ([$longitude, $latitude] as $ordinate) {
			if (!is_int($ordinate) && !is_float($ordinate)) {
				throw new InvalidGeometryException('las coordenadas deben ser números');
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
				'la latitud %s está fuera del mundo; GeoJSON ordena las posiciones [longitud, latitud]',
				var_export($latitude, true),
			));
		}
		if ($longitude < -180 || $longitude > 180) {
			throw new InvalidGeometryException(sprintf('la longitud %s está fuera del mundo', var_export($longitude, true)));
		}

		return [(float)$longitude, (float)$latitude];
	}
}
