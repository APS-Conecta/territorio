<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Domain\BoundingBox;
use OCA\Territorio\Domain\Geometry;
use OCA\Territorio\Domain\Kind;
use OCA\Territorio\Domain\PointInPolygon;
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
	 * The live Registry, read once per request. `affectedBy()` and `boundaries()`
	 * both walked it separately. Cleared by `forget()` for the reason `$known` is.
	 *
	 * @var ?Feature[]
	 */
	private ?array $registry = null;

	/**
	 * Which Subcategory ids are divisions, and which division each is — see
	 * `divisions()`. Not cleared by `forget()`, unlike `$known` and `$registry`
	 * beside it: writing a boundary changes where the boundaries ARE, never which
	 * Subcategories make one.
	 *
	 * @var ?array<int, string>
	 */
	private ?array $divisions = null;

	public function __construct(
		private FeatureMapper $features,
		private TaxonomyService $taxonomy,
	) {
	}

	/** A boundary has changed, so what was read before is no longer true. */
	/** A boundary has changed, so what was read before is no longer true.
	 *
	 * Called ONCE per import now, at batch end (L1-13) — FeatureService::
	 * flushDeferredReassignments() — and still per boundary write everywhere
	 * else. The memo this clears is what makes "read once per request" true in
	 * both worlds: during an import nothing invalidates it, so the file pays one
	 * registry read, and the flush re-reads once for the sweep.
	 */
	public function forget(): void {
		$this->known = null;
		$this->registry = null;
	}

	private function registry(): array {
		return $this->registry ??= $this->features->findAll();
	}

	/**
	 * Set a record's Sector and Unidad Vecinal from where it is.
	 *
	 * Mutates rather than writes: this runs inside the same save that is already
	 * happening, so the assignment lands in that write, its Revision and its
	 * history entry rather than in a second one.
	 */
	public function assign(Feature $feature): void {
		$point = $feature->anchor();

		foreach (self::DIVISIONS as $slug => $setter) {
			$feature->$setter($point === null ? null : $this->containing($slug, ...$point));
		}
	}

	/** The division this record is a boundary of — 'sector', 'unidad_vecinal' — or null. */
	public function divisionOf(Feature $feature): ?string {
		return $this->divisions()[$feature->getSubcategoryId()] ?? null;
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
		foreach ($this->registry() as $candidate) {
			if ((int)$candidate->getId() === (int)$boundary->getId()) {
				continue;
			}
			$point = $candidate->anchor();
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
			$wanted = $this->divisions();

			$this->known = array_fill_keys(array_keys(self::DIVISIONS), []);
			foreach ($this->registry() as $feature) {
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
	 * Which Subcategory ids are divisions, and which division each one is.
	 *
	 * Read once, because two paths ask and both asked the whole Category tree — two
	 * full table scans for an answer that cannot change while the service lives.
	 * `divisionOf()` asks on every create, update, retire and restore;
	 * `boundaries()` asks again each time `forget()` empties it, which is every
	 * boundary write whose shape moved. The CHANGELOG has the measurement.
	 *
	 * As a map rather than a list of ids: `boundaries()` needs to know WHICH
	 * division a Subcategory is, and `divisionOf()` asks the same question — one
	 * taxonomy read per request, keyed by subcategory id, answers both.
	 *
	 * Memoising `TaxonomyService::tree()` itself was tried and is wrong: the ramp
	 * repair writes a Category and reads the tree back in the same request, so a
	 * memo there served it the colours from before its own write and two of
	 * `RampRepaint`'s assertions failed. This is narrower and safe because the
	 * division Subcategories are structural — #40 specifies that neither they nor
	 * their Category can be removed, and that a rename never changes a slug — but
	 * what actually guarantees it today is that nothing in this app writes the
	 * taxonomy outside the install-time repair.
	 *
	 * @return array<int, string> subcategory id => division slug
	 */
	private function divisions(): array {
		if ($this->divisions !== null) {
			return $this->divisions;
		}

		$divisions = [];
		// The shared flat index (L1-20) — one taxonomy derivation, not a third
		// walk of the tree. Same answer, same memo, cheaper by one tree build.
		foreach ($this->taxonomy->subcategoryIndex() as $id => $subcategory) {
			if (isset(self::DIVISIONS[$subcategory['subcategory']])) {
				$divisions[$id] = $subcategory['subcategory'];
			}
		}

		return $this->divisions = $divisions;
	}
}
