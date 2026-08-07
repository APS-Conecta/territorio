<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

/**
 * Which records might be the same place (issue #16).
 *
 * Finds CANDIDATES and merges nothing. That asymmetry is the whole design: a
 * wrong merge fails silently and is discovered by somebody phoning the wrong
 * place, while a wrong candidate costs one glance at a queue. So this is allowed
 * to be generous — but not so generous that the queue stops being read, which is
 * why "Sede Junta de Vecinos" thirty times across a comuna is thirty places and
 * not 435 pairs.
 *
 * Two rules, because two shapes appear in the real data:
 *
 *  - The same coordinate. One building catalogued twice by two sources, under
 *    two names. Metres apart counts: a GPS reading and a hand-placed pin for one
 *    entrance never land on the same number.
 *  - A similar name a short distance away. One institution spelled differently
 *    by two sources, geocoded to the street by one and to the door by the other.
 *
 * Pure and static: no database, no entities. The persisting, the dismissing and
 * the merging live in DuplicateService, and the rule lives here where it can be
 * argued with in a unit test.
 */
final class DuplicateFinder {
	/** Metres within which two coordinates are the same coordinate. */
	private const SAME_PLACE = 25.0;

	/** Metres within which a similar name is worth asking about. */
	private const SAME_BLOCK = 150.0;

	/**
	 * How alike two names must be, as a percentage, to count as the same name.
	 *
	 * 85 keeps "Cesfam Bellavista" and "CESFAM Bellavista" together while leaving
	 * "Escuela Bellavista" and "Liceo Bellavista" apart — they share a lot of
	 * letters and are two different schools.
	 */
	private const ALIKE = 85.0;

	/** Metres per degree of latitude, near enough anywhere. */
	private const METRES_PER_DEGREE = 111_320.0;

	/**
	 * Every pair worth a person's attention, each offered once.
	 *
	 * @param list<array{id: int, name: string, lat: ?float, lon: ?float}> $records
	 * @return list<CandidatePair>
	 */
	public static function pairs(array $records): array {
		// A record with no coordinate is not near anything, and pairing on name
		// alone would put every "Sede Junta de Vecinos" in the comuna together.
		$located = array_values(array_filter(
			$records,
			static fn (array $r): bool => $r['lat'] !== null && $r['lon'] !== null,
		));

		$pairs = [];
		foreach (self::neighbours($located) as [$one, $other]) {
			$reason = self::reason($one, $other);
			if ($reason === null) {
				continue;
			}

			// Lowest id first, so a pair looks the same however the records
			// arrived and can be stored under a key that will not repeat.
			$pairs[] = new CandidatePair(
				min($one['id'], $other['id']),
				max($one['id'], $other['id']),
				$reason,
			);
		}

		return $pairs;
	}

	/**
	 * Why these two are a candidate, or null if they are not.
	 *
	 * @param array{id: int, name: string, lat: ?float, lon: ?float} $one
	 * @param array{id: int, name: string, lat: ?float, lon: ?float} $other
	 */
	private static function reason(array $one, array $other): ?string {
		$metres = self::metresBetween($one['lat'], $one['lon'], $other['lat'], $other['lon']);

		if ($metres <= self::SAME_PLACE) {
			return 'coordenada';
		}

		return $metres <= self::SAME_BLOCK && self::alike($one['name'], $other['name'])
			? 'nombre'
			: null;
	}

	/**
	 * Whether two names are the same name written differently.
	 *
	 * similar_text() rather than levenshtein(): the differences here are dropped
	 * words and different spellings of one word ("Jardín Infantil Rayún" against
	 * "JARDIN INFANTIL RAYUN"), and a percentage of shared text answers that,
	 * where an edit distance answers "how many keystrokes" and has to be scaled
	 * by length before it means anything.
	 */
	private static function alike(string $one, string $other): bool {
		$one = self::fold($one);
		$other = self::fold($other);

		if ($one === '' || $other === '') {
			return false;
		}

		// One name contained whole in the other is the commonest form of this:
		// "CESFAM Bellavista" against "CESFAM Bellavista (SSMSO)". Percentages
		// punish the extra words; containment does not.
		if (str_contains($one, $other) || str_contains($other, $one)) {
			return true;
		}

		similar_text($one, $other, $percent);

		return $percent >= self::ALIKE;
	}

	/**
	 * Accents off, case down, runs of whitespace to one space.
	 *
	 * The same folding the Registry search does, for the same reason: two sources
	 * disagreeing about an accent are not describing two places.
	 */
	private static function fold(string $value): string {
		$folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
		if ($folded === false) {
			$folded = $value;
		}

		return trim((string)preg_replace('/\s+/', ' ', mb_strtolower($folded)));
	}

	/**
	 * The pairs close enough to be worth comparing at all.
	 *
	 * A comuna's cadastre is thousands of records and comparing every one against
	 * every other is millions of comparisons for a handful of answers. Records go
	 * into cells a little larger than the widest rule, and each is compared with
	 * its own cell and the three neighbouring cells that have not been done yet —
	 * so every nearby pair is seen exactly once and distant ones never are.
	 *
	 * @param list<array{id: int, name: string, lat: ?float, lon: ?float}> $records
	 * @return \Generator<int, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	private static function neighbours(array $records): \Generator {
		$size = self::SAME_BLOCK / self::METRES_PER_DEGREE;

		$cells = [];
		foreach ($records as $record) {
			$row = (int)floor($record['lat'] / $size);
			$column = (int)floor($record['lon'] / $size);
			$cells[$row . ':' . $column][] = $record;
		}

		foreach ($cells as $key => $here) {
			[$row, $column] = array_map('intval', explode(':', $key));

			foreach ($here as $index => $one) {
				foreach (array_slice($here, $index + 1) as $other) {
					yield [$one, $other];
				}
			}

			// Half the ring, so a pair straddling a cell edge is yielded by one of
			// the two cells and not by both.
			foreach ([[0, 1], [1, -1], [1, 0], [1, 1]] as [$dRow, $dColumn]) {
				foreach ($cells[($row + $dRow) . ':' . ($column + $dColumn)] ?? [] as $other) {
					foreach ($here as $one) {
						yield [$one, $other];
					}
				}
			}
		}
	}

	/**
	 * Metres between two coordinates.
	 *
	 * Equirectangular rather than haversine: over the couple of hundred metres
	 * these rules care about the difference is millimetres, and the thresholds are
	 * judgement calls to a tolerance of tens of metres. Latitude is scaled by
	 * cos(lat) so it stays right at Santiago's -33° rather than only at the
	 * equator.
	 */
	private static function metresBetween(float $lat, float $lon, float $otherLat, float $otherLon): float {
		$dLat = ($otherLat - $lat) * self::METRES_PER_DEGREE;
		$dLon = ($otherLon - $lon) * self::METRES_PER_DEGREE * cos(deg2rad(($lat + $otherLat) / 2));

		return hypot($dLat, $dLon);
	}
}
