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
	 * @return list<array<string, mixed>>
	 */
	public function inView(float $south, float $west, float $north, float $east): array {
		return array_map(
			// Deliberately not toClient(): a boundary feature has no colour, no
			// contact and no assignment, and shipping eleven thousand of those keys
			// would be most of the payload.
			static fn (Feature $feature): array => [
				'id' => (int)$feature->getId(),
				'name' => $feature->getName(),
				'geometry' => json_decode((string)$feature->getGeometry(), true),
			],
			$this->features->findBoundariesIn($south, $west, $north, $east),
		);
	}
}
