<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Exception\InvalidGeometryException;

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
	/**
	 * The boundaries, read once per request rather than once per record.
	 *
	 * Without it, resolving 500 Places after a Sector moved read the whole
	 * Registry a thousand times in one request, and importing a catastro of 865
	 * rows read it about seventeen hundred. Cleared whenever a boundary is
	 * written, which is the only thing that can make it stale.
	 *
	 * @var ?array<string, Feature[]>
	 */
	private ?array $known = null;

	/**
	 * The division Subcategory ids, read once per request — see
	 * `divisionSubcategoryIds()`. Not cleared when a boundary is written, unlike
	 * `$known` beside it: writing a boundary does not change which Subcategories
	 * ARE boundaries.
	 *
	 * @var ?int[]
	 */
	private ?array $divisionIds = null;

	public function __construct(
		private FeatureMapper $features,
		private TaxonomyService $taxonomy,
	) {
	}

	/** A boundary has changed, so what was read before is no longer true. */
	public function forget(): void {
		$this->known = null;
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
		return in_array($feature->getSubcategoryId(), $this->divisionSubcategoryIds(), true);
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
		// The box it has now comes off the entity, which already carries it. The
		// one it had before has to be measured, and a stored geometry this app can
		// no longer parse must not turn an ordinary save into a 500 — the record
		// is being edited, which is how someone would fix it.
		$boxes = array_values(array_filter([
			$boundary->boundingBox(),
			$before === null ? null : self::boxOf($before),
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
			if ($boundary->boundingBox()?->holds($lon, $lat) !== true) {
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
	 * The boundaries of one kind, read once per request.
	 *
	 * ponytail: one read of the whole live set, filtered in PHP, cached until a
	 * boundary is written. The ceiling is that read, not the filtering — give the
	 * mapper a by-subcategory query when a comuna's Registry is too big to hold.
	 *
	 * @return Feature[]
	 */
	private function boundaries(string $slug): array {
		if ($this->known === null) {
			$wanted = [];
			foreach ($this->taxonomy->tree() as $category) {
				foreach ($category['subcategories'] as $subcategory) {
					if (isset(self::DIVISIONS[$subcategory['slug']])) {
						$wanted[$subcategory['id']] = $subcategory['slug'];
					}
				}
			}

			$this->known = array_fill_keys(array_keys(self::DIVISIONS), []);
			foreach ($this->features->findAll() as $feature) {
				$kind = $wanted[$feature->getSubcategoryId()] ?? null;
				if ($kind !== null && $feature->getGeometry() !== null) {
					$this->known[$kind][] = $feature;
				}
			}
		}

		return $this->known[$slug] ?? [];
	}

	private static function boxOf(string $geometry): ?BoundingBox {
		try {
			return Geometry::fromJson($geometry)->boundingBox();
		} catch (InvalidGeometryException) {
			return null;
		}
	}

	/**
	 * The Subcategory ids that mean "this record is a boundary", read once.
	 *
	 * `isBoundary()` asks on every create, update, retire and restore, and each ask
	 * read the whole Category tree — two full table scans to answer a question whose
	 * answer cannot change while a request runs. Measured on the dev instance: 55,576
	 * sequential scans of `oc_territorio_category` against 431 writes of it; one run
	 * of the integration suite added 543 of them for 933 record writes.
	 *
	 * Here rather than on `TaxonomyService::tree()` itself, which is where this was
	 * first put. That broke `RampRepaint`: the colour repair writes a Category and
	 * reads the tree back in the same request, and a memoised tree served it the
	 * colours from before its own write. Not a hypothetical about #36 — a job that
	 * does it today. What is memoised here is narrower and safe by construction: the
	 * division Subcategories are structural, and #36 settled that they cannot be
	 * removed or renamed away.
	 *
	 * @return int[]
	 */
	private function divisionSubcategoryIds(): array {
		if ($this->divisionIds !== null) {
			return $this->divisionIds;
		}

		$ids = [];
		foreach ($this->taxonomy->tree() as $category) {
			foreach ($category['subcategories'] as $subcategory) {
				if (isset(self::DIVISIONS[$subcategory['slug']])) {
					$ids[] = $subcategory['id'];
				}
			}
		}

		return $this->divisionIds = $ids;
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
