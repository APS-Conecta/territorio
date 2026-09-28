<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use APS\Common\Text;
use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Domain\BoundingBox;

/**
 * The Boundary features: the lines a Sector's Limit can run along, for the
 * editor that picks them, and every street's name, for the street search.
 *
 * ADR-0007: a Limit is built by picking real objects rather than by resolving
 * street NAMES, because "which Los Toros?" is settled by a person in one click
 * and guessed wrongly by a geocoder. So this hands back objects with their ids,
 * and the picking is a selection rather than a search result. The street search
 * keeps the same rule from the other side: it offers every place a name stands
 * for and moves the map to the one somebody chooses — nothing here resolves a
 * name to one of them.
 */
class BoundaryService {
	/**
	 * How far apart two stretches of one name can be and still be one street.
	 *
	 * Measured on La Florida's cadastre (11,291 named ways, 4,485 names): at
	 * 500 m, 416 names split into 1,031 places — «Pasaje Dos» sixteen times,
	 * «Pasaje Uno» fourteen — while «Avenida La Florida», 128 ways end to end,
	 * stays one row. At 150 m it is 454 names and 697 extra rows.
	 *
	 * ponytail: one number, measured on one comuna. A pair it gets wrong costs a
	 * row too many or too few in a list somebody reads, never a record.
	 */
	public const SAME_PLACE_M = 500.0;

	public function __construct(
		private FeatureMapper $features,
	) {
	}

	/**
	 * The lines on screen, plus which of the ids asked about are no longer there.
	 *
	 * `gone` answers a question the client structurally cannot (issue #51): this
	 * read excludes retired rows and loads only what is in the box, so a cited id
	 * that does not come back means "off-screen OR gone" and the editor cannot
	 * tell which. The server can, in one more query.
	 *
	 * Ids and nothing else. A retired row's `name` or `geometry` must never reach
	 * this payload: `features` is ONE array in the client, feeding the
	 * autocomplete, the clickable layer on the map and the cache that keeps a
	 * picked line's shape — so a retired row arriving with a name and a shape
	 * would be pickable again and would un-grey «Generar». Retiring is what takes
	 * a line out of the editor (ADR-0005), and this keeps it out.
	 *
	 * @param int[] $cited ids the open draft's Limit points at
	 * @return array{features: list<array<string, mixed>>, gone: list<int>}
	 */
	public function inView(float $south, float $west, float $north, float $east, array $cited = []): array {
		return [
			'features' => array_map(
				// Deliberately not toClient(): a boundary feature has no colour, no
				// contact and no assignment, and shipping eleven thousand of those keys
				// would be most of the payload.
				static fn (Feature $feature): array => [
					'id' => (int)$feature->getId(),
					'name' => $feature->getName(),
					'geometry' => json_decode((string)$feature->getGeometry(), true),
				],
				$this->features->findBoundariesIn($south, $west, $north, $east),
			),
			// Cited minus live, so retired, merged away and never-existed all land
			// in one answer — the editor has the same thing to say about each.
			'gone' => array_values(array_diff($cited, $this->features->findLiveAmong($cited))),
		];
	}

	/**
	 * Every street the Cadastral Dataset names, once per place it is — names and
	 * boxes, and NO GEOMETRY, EVER.
	 *
	 * This is the one read of Boundary features that no view bounds, and it is
	 * safe only because of what it leaves out: issue #12 is eleven thousand
	 * lines, and "they are not the map" is about their shapes. Five thousand
	 * names and boxes are about a hundred kilobytes, fetched once per session the
	 * first time somebody focuses the search. A `geometry` key in this payload is
	 * exactly what #12 forbids.
	 *
	 * One row per name AND place: a comuna holds «Pasaje Dos» in sixteen places,
	 * and one row for all of them would fit the map to the whole comuna — a row
	 * that stands for sixteen streets is not a choice anybody can make
	 * (ADR-0007). A long street held as many ways is still one row.
	 *
	 * Ordered by folded name, then by the id of each place's first way. The
	 * browser ranks, and its sort is stable, so this is the order same-name rows
	 * keep.
	 *
	 * @return list<array{name: string, south: float, west: float, north: float, east: float, count: int}>
	 */
	public function names(): array {
		$byName = [];
		foreach ($this->features->boundaryNames() as $way) {
			$key = Text::fold($way['name']);
			// A name of spaces is no name: nothing anybody types reaches it.
			if ($key !== '') {
				$byName[$key][] = $way;
			}
		}
		ksort($byName, SORT_STRING);

		$places = [];
		foreach ($byName as $ways) {
			array_push($places, ...self::places($ways));
		}

		return $places;
	}

	/**
	 * One name's ways, split wherever more than SAME_PLACE_M separates them.
	 *
	 * Single linkage: a way joins a place if it comes within the gap of ANY way
	 * already in it — which is what keeps an avenue held as a hundred touching
	 * ways one row, while two pasajes a kilometre apart stay two.
	 *
	 * ponytail: every pair within one name, no grid. La Florida's worst name is
	 * 128 ways, about eight thousand comparisons; `DuplicateFinder::neighbours()`
	 * is the grid to reach for if a cadastre ever holds one name thousands of
	 * times.
	 *
	 * @param non-empty-list<array{name: string, box: BoundingBox}> $ways in id order
	 * @return list<array{name: string, south: float, west: float, north: float, east: float, count: int}>
	 */
	private static function places(array $ways): array {
		$places = [];
		while ($ways !== []) {
			$place = [array_shift($ways)];
			// $place grows while it is walked: a way that joins is compared with the
			// ones still outside in its turn, which is what makes it single linkage.
			for ($i = 0; $i < count($place); $i++) {
				foreach ($ways as $key => $way) {
					if ($place[$i]['box']->metresTo($way['box']) <= self::SAME_PLACE_M) {
						$place[] = $way;
						unset($ways[$key]);
					}
				}
			}
			$places[] = self::described($place);
		}

		return $places;
	}

	/**
	 * A place as the browser lists it: the first spelling filed, the box around
	 * every way in it, and how many ways that is.
	 *
	 * @param non-empty-list<array{name: string, box: BoundingBox}> $place
	 * @return array{name: string, south: float, west: float, north: float, east: float, count: int}
	 */
	private static function described(array $place): array {
		$box = BoundingBox::around(array_merge(...array_map(
			static fn (array $way): array => [
				[$way['box']->minLon(), $way['box']->minLat()],
				[$way['box']->maxLon(), $way['box']->maxLat()],
			],
			$place,
		)));

		return [
			'name' => $place[0]['name'],
			'south' => $box->minLat(),
			'west' => $box->minLon(),
			'north' => $box->maxLat(),
			'east' => $box->maxLon(),
			'count' => count($place),
		];
	}
}
