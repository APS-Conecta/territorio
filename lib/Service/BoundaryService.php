<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;

/**
 * The lines a Sector's Limit can run along, for the editor that picks them.
 *
 * ADR-0007: a Limit is built by picking real objects rather than by resolving
 * street NAMES, because "which Los Toros?" is settled by a person in one click
 * and guessed wrongly by a geocoder. So this hands back objects with their ids,
 * and the picking is a selection rather than a search result.
 */
class BoundaryService {
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
}
