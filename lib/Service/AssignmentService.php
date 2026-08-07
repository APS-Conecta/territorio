<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;

/**
 * Which Sector and which Unidad Vecinal a record falls in.
 *
 * ADR-0008: the assignment is derived from geometry and stored, never typed. In
 * the curated La Florida catastro 393 of 556 places had an empty sector — 71%
 * blank after a full curation effort — which is the evidence that hand
 * assignment does not survive contact with real work.
 *
 * Resolving reads every boundary the install holds, so it is deliberately NOT
 * done per request: it happens when a record moves, when a boundary is redrawn,
 * and after an import, and everything else reads the stored id.
 */
class AssignmentService {
	public function __construct(
		private FeatureMapper $features,
		private TaxonomyService $taxonomy,
	) {
	}

	/**
	 * Set a record's Sector and Unidad Vecinal from where it is.
	 *
	 * Mutates rather than writes: this runs inside the same save that is already
	 * happening, so the assignment lands in that write, its Revision and its
	 * history entry rather than in a second one.
	 */
	public function assign(Feature $feature): void {
		$point = self::anchorOf($feature);

		foreach (self::DIVISIONS as $slug => $setter) {
			$feature->$setter($point === null ? null : $this->containing($slug, ...$point));
		}
	}

	/** Whether this record is one of the boundaries others are assigned by. */
	public function isBoundary(Feature $feature): bool {
		foreach (array_keys(self::DIVISIONS) as $slug) {
			foreach ($this->boundaries($slug) as $boundary) {
				if ((int)$boundary->getId() === (int)$feature->getId()) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Every record whose assignment might have changed because this boundary did.
	 *
	 * Both the old and the new shape matter: a Place the Sector no longer covers
	 * has to be let go of, not only one it now reaches. The caller passes the
	 * geometry as it was before the edit.
	 *
	 * @return Feature[]
	 */
	public function affectedBy(Feature $boundary, ?string $before): array {
		$boxes = array_values(array_filter([
			$boundary->getGeometry() === null ? null : Geometry::fromJson($boundary->getGeometry())->boundingBox(),
			$before === null ? null : Geometry::fromJson($before)->boundingBox(),
		]));

		$affected = [];
		foreach ($this->features->findAll() as $candidate) {
			if ((int)$candidate->getId() === (int)$boundary->getId()) {
				continue;
			}
			$point = self::anchorOf($candidate);
			if ($point === null) {
				continue;
			}

			foreach ($boxes as $box) {
				if ($box->holds($point[0], $point[1])) {
					$affected[] = $candidate;
					break;
				}
			}
		}

		return $affected;
	}

	/** The Subcategory slug of each division, and where its id is stored. */
	private const DIVISIONS = [
		'sector' => 'setSectorId',
		'unidad_vecinal' => 'setUnidadVecinalId',
	];

	/**
	 * The id of the boundary of this kind that contains the point, or null.
	 *
	 * The bounding box is checked first and it is not an optimisation for its own
	 * sake: walking every ring of every Unidad Vecinal in a comuna for every save
	 * is the difference between a column read and a visible pause. The box is
	 * four indexed columns and rejects almost everything.
	 */
	private function containing(string $slug, float $lon, float $lat): ?int {
		foreach ($this->boundaries($slug) as $boundary) {
			if (!self::boxHolds($boundary, $lon, $lat)) {
				continue;
			}

			$geometry = json_decode((string)$boundary->getGeometry(), true);
			if (($geometry['type'] ?? null) === 'Polygon'
				&& PointInPolygon::contains($geometry['coordinates'] ?? [], $lon, $lat)) {
				return (int)$boundary->getId();
			}
		}

		return null;
	}

	/**
	 * The boundaries of one kind.
	 *
	 * ponytail: reads the live set and filters in PHP, like everything else that
	 * needs the Registry. A comuna is a few dozen Sectores and Unidades Vecinales;
	 * give the mapper a by-subcategory read when that stops being true.
	 *
	 * @return Feature[]
	 */
	private function boundaries(string $slug): array {
		$wanted = null;
		foreach ($this->taxonomy->tree() as $category) {
			foreach ($category['subcategories'] as $subcategory) {
				if ($subcategory['slug'] === $slug) {
					$wanted = $subcategory['id'];
				}
			}
		}
		if ($wanted === null) {
			return [];
		}

		return array_values(array_filter(
			$this->features->findAll(),
			static fn (Feature $feature): bool => $feature->getSubcategoryId() === $wanted
				&& $feature->getGeometry() !== null,
		));
	}

	private static function boxHolds(Feature $feature, float $lon, float $lat): bool {
		return $feature->getMinLat() !== null
			&& $lat >= $feature->getMinLat() && $lat <= $feature->getMaxLat()
			&& $lon >= $feature->getMinLon() && $lon <= $feature->getMaxLon();
	}

	/**
	 * The single coordinate a record is assigned by, or null where it has none.
	 *
	 * Only a Place has one. A Sector is not "in" a Sector, and asking which
	 * Unidad Vecinal a route running across four of them belongs to has no
	 * answer worth storing.
	 *
	 * @return ?array{float, float} [longitude, latitude]
	 */
	private static function anchorOf(Feature $feature): ?array {
		if ($feature->getGeometry() === null || $feature->getKind() !== Kind::Place->value) {
			return null;
		}

		$geometry = json_decode((string)$feature->getGeometry(), true);
		$coordinates = $geometry['coordinates'] ?? null;

		return is_array($coordinates) && count($coordinates) >= 2
			? [(float)$coordinates[0], (float)$coordinates[1]]
			: null;
	}
}
