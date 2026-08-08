<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Db\Duplicate;
use OCA\Territorio\Db\DuplicateMapper;
use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Exception\InvalidFeatureException;
use JsonException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Turns what a person typed into a Feature that is safe to store.
 *
 * Everything derivable is derived here and never accepted from the client: Kind
 * follows the geometry, the bounding box is measured from it, and Location
 * quality is forced to absent when there is no geometry to describe. Sector and
 * Unidad Vecinal are not fields at all (ADR-0008).
 *
 * Controllers stay thin over this, so exposing an OCS API later is a new
 * controller rather than a rewrite.
 */
class FeatureService {
	/**
	 * How much Limit is a Limit.
	 *
	 * Bounds rather than validation: nothing here is a security boundary — every
	 * user may already write every field (ADR-0005) — but this is the one field
	 * the browser sends as a structure, into a column with no length of its own,
	 * and a runaway client should not be able to put a megabyte of names in a
	 * sentence somebody has to read.
	 */
	private const LIMIT_PARTS = 500;
	private const LIMIT_NAME = 200;

	/** Optional free-text columns, all handled identically: trim, blank becomes null. */
	private const OPTIONAL_TEXT = [
		'address' => 'setAddress',
		'phone' => 'setPhone',
		'email' => 'setEmail',
		'contactPerson' => 'setContactPerson',
		'contactRole' => 'setContactRole',
		'openingHours' => 'setOpeningHours',
		'notes' => 'setNotes',
		'sourceRef' => 'setSourceRef',
	];

	public function __construct(
		private FeatureMapper $features,
		private SubcategoryMapper $subcategories,
		private RevisionLog $revisions,
		private AssignmentService $assignments,
		private DuplicateMapper $duplicates,
	) {
	}

	/** @return Feature[] */
	public function listAll(): array {
		return $this->features->findAll();
	}

	/**
	 * The cursor a page load hands the client to poll from (ADR-0002).
	 *
	 * Here rather than in the controller so that nothing outside `lib/Service`
	 * has to reach into `Db` for it — a controller that knows about RevisionLog
	 * is a controller an OCS version would have to duplicate (issue #18).
	 */
	public function currentRevision(): int {
		return $this->revisions->current();
	}

	/**
	 * What a client holding `$since` has not seen yet (ADR-0002).
	 *
	 * The common case by far is that nothing has changed, and it costs exactly
	 * one indexed read of the Revision log — the Feature table is not touched at
	 * all. That is what makes a five-second poll from every open map affordable
	 * on an instance with no push infrastructure.
	 *
	 * @return array{revision: int, features: list<array<string, mixed>>}
	 */
	public function changesSince(int $since): array {
		$current = $this->revisions->current();

		if ($current <= $since) {
			return ['revision' => $current, 'features' => []];
		}

		return [
			'revision' => $current,
			'features' => array_map(
				static fn (Feature $feature): array => $feature->toClient(),
				$this->features->findChangedSince($since),
			),
		];
	}

	/**
	 * Retired records, for the screen that offers to bring one back.
	 *
	 * @return Feature[]
	 */
	public function listRetired(): array {
		return $this->features->findRetired();
	}

	/**
	 * ADR-0005: deleting is retiring. Nothing is destroyed, so nothing needs a
	 * confirmation that warns about permanence — because there is none.
	 *
	 * @throws DoesNotExistException
	 */
	public function retire(int $id): Feature {
		return $this->withdraw($id, null);
	}

	/**
	 * Retire one of two records as the same place as the other (issue #16).
	 *
	 * The survivor is checked before anything is written, because a merge into a
	 * record that is itself retired would take both off the map and leave the
	 * pointer aimed at something nobody can see.
	 *
	 * @throws DoesNotExistException when either record is gone
	 */
	public function merge(int $loserId, int $survivorId): Feature {
		if ($loserId === $survivorId) {
			throw new InvalidFeatureException('Un registro no se puede fusionar consigo mismo.');
		}

		$survivor = $this->features->find($survivorId);
		if ($survivor->getRetired()) {
			throw new InvalidFeatureException(
				'El registro que se conserva está retirado; restáurelo antes de fusionar.',
			);
		}

		return $this->withdraw($loserId, $survivorId);
	}

	/**
	 * A record leaves the map, either withdrawn or merged into another.
	 *
	 * One path for both because everything after the reason is identical, and
	 * because the assignment cleanup below is the kind of thing that gets added
	 * to one of two copies and not the other.
	 *
	 * @throws DoesNotExistException
	 */
	private function withdraw(int $id, ?int $into): Feature {
		$feature = $this->features->find($id);
		if ($feature->getRetired()) {
			// History is append-only and never tidied, so a no-op that still logged
			// would leave a second "Retirado" entry describing nothing.
			throw new InvalidFeatureException('El registro ya estaba retirado.');
		}

		$gone = $into === null
			? $this->features->retire($feature)
			: $this->features->merge($feature, $into);

		// A retired Sector is gone from every read that resolves an assignment, so
		// everything it held has to be let go of — otherwise a record keeps an id
		// pointing at a boundary the app will not show, and the sidebar says "sin
		// asignar" about a record that is assigned (ADR-0005, ADR-0008).
		$this->reassignAround($gone, $gone->getGeometry());

		return $gone;
	}

	/** @throws DoesNotExistException */
	public function restore(int $id): Feature {
		$feature = $this->features->find($id);
		if (!$feature->getRetired()) {
			throw new InvalidFeatureException('El registro no está retirado.');
		}

		$mergedInto = $feature->getMergedInto();
		$restored = $this->features->restore($feature);
		// And a restored one takes back what falls inside it.
		$this->reassignAround($restored, null);

		// Undoing a merge puts its pair back in the queue. Both records are on the
		// map again and still look like one place, so the question is open again —
		// and leaving it closed would mean the only way to reconsider a merge is to
		// wait for an import to notice the pair a second time.
		//
		// Read from the record BEFORE the restore cleared it, and only for the
		// record that lost: restoring the survivor of a merge, retired later for
		// some unrelated reason, must not reopen a pair whose other member is
		// still gone.
		$merge = $mergedInto === null ? null : $this->duplicates->findMergeOf($id);
		if ($merge !== null) {
			$merge->setStatus(Duplicate::PENDING);
			$merge->setResolvedBy(null);
			$this->duplicates->update($merge);
		}

		return $restored;
	}

	/**
	 * @return list<array<string, mixed>>
	 * @throws DoesNotExistException when there is no such Feature
	 */
	public function history(int $id): array {
		// Reading the Feature first so a history request for something that never
		// existed is a 404 rather than a convincing empty list.
		$this->features->find($id);

		return $this->revisions->forFeature($id);
	}

	/**
	 * @param array<string, mixed> $input
	 * @param ?Provenance $provenance set only by an import; never read from a request
	 */
	public function create(array $input, ?Provenance $provenance = null): Feature {
		$feature = $this->apply(new Feature(), $input);
		$this->applyProvenance($feature, $provenance);

		$created = $this->features->insert($feature);

		// A boundary that did not exist a moment ago covers records that were
		// resolved before it. Without this, importing a comuna's Unidades
		// Vecinales into an install that already holds its catastro leaves every
		// one of those records assigned to nothing — which is the case ADR-0008
		// exists for, arriving through the door it is most likely to arrive by.
		$this->reassignAround($created, null);

		return $created;
	}

	/**
	 * @param array<string, mixed> $input
	 * @throws DoesNotExistException when there is no such Feature
	 */
	public function update(int $id, array $input, ?Provenance $provenance = null): Feature {
		$feature = $this->features->find($id);

		// A retired record is invisible on every map, so an edit saved into one
		// would simply vanish — including one made by someone who had the record
		// open when a colleague retired it. Restoring first makes the edit land
		// somewhere people can see.
		if ($feature->getRetired()) {
			throw new InvalidFeatureException('El registro está retirado. Restáurelo antes de editarlo.');
		}

		// Read before the entity is mutated: reassigning after a boundary moves
		// needs the shape it used to have as well as the one it has now, or a
		// record the Sector no longer covers is never let go of.
		$before = $feature->getGeometry();
		$written = $this->write($feature, $input, $provenance, markOverride: $provenance === null);
		$this->reassignAround($written, $before);

		return $written;
	}

	/**
	 * Redrawing a boundary reassigns everything it gained or lost.
	 *
	 * ADR-0008 is explicit that this is the point rather than a side effect, and
	 * that it "must be visible in history like any other change" — so each
	 * reassignment is an ordinary write with its own Revision and its own history
	 * entry, not a silent column update.
	 *
	 * Only when the shape actually moved. Renaming a Sector or recolouring it
	 * changes nobody's assignment, and spending a Revision per Place to discover
	 * that would make every edit to a boundary expensive.
	 */
	private function reassignAround(Feature $boundary, ?string $before): void {
		if (!$this->assignments->isBoundary($boundary)) {
			return;
		}
		// Retiring and restoring pass the same geometry on purpose: what changed is
		// whether it counts at all, not its shape.
		if ($boundary->getGeometry() === $before && !$boundary->getRetired() && $before !== null) {
			return;
		}

		// What was read before this write no longer describes the Registry.
		$this->assignments->forget();

		foreach ($this->assignments->affectedBy($boundary, $before) as $affected) {
			$was = [$affected->getSectorId(), $affected->getUnidadVecinalId()];
			$this->assignments->assign($affected);

			// Only where it really changed: the box around a redrawn Sector holds
			// plenty of records whose answer is the same as before, and writing
			// those would fill history with entries whose diff is empty.
			if ($was !== [$affected->getSectorId(), $affected->getUnidadVecinalId()]) {
				$this->features->update($affected);
			}
		}
	}

	/**
	 * Put a record back to values it previously held, without calling it a local
	 * override.
	 *
	 * A revert is not a person deciding what is true on the ground — it is the
	 * undoing of a batch. Going through `update()` would mark every reverted
	 * record as overridden, and every future import would then skip them for good
	 * (ADR-0003), which is a strange and permanent consequence of pressing undo.
	 *
	 * It still has to reassign, though, which is the half this skipped. Putting a
	 * boundary's old shape back moves the line, and ADR-0008 says what falls
	 * inside a boundary is derived from that line rather than stored by hand — so
	 * a revert that restored the geometry and left the assignments alone produced
	 * exactly the state ADR-0008 exists to prevent. Only the override marking is
	 * different from `update()`; the geometry consequences are identical.
	 *
	 * @param array<string, mixed> $input
	 * @throws DoesNotExistException
	 */
	public function revertTo(int $id, array $input): Feature {
		$feature = $this->features->find($id);
		// Read before the entity is mutated, exactly as update() does: letting go
		// of what a boundary no longer covers needs the shape it used to have.
		$before = $feature->getGeometry();
		$written = $this->write($feature, $input, null, markOverride: false);
		$this->reassignAround($written, $before);

		return $written;
	}

	/** @param array<string, mixed> $input */
	private function write(
		Feature $feature,
		array $input,
		?Provenance $provenance,
		bool $markOverride,
	): Feature {
		$wasImported = $feature->getDatasetId() !== null;
		$this->apply($feature, $input);
		$this->applyProvenance($feature, $provenance);

		// A person editing an imported record is what ADR-0003 calls a local
		// override: it states what is true on the ground, so it outranks whatever
		// the authority publishes next. Only a person's edit sets this — an import
		// passes its Provenance, and a revert asks not to.
		if ($markOverride && $wasImported) {
			$feature->setOverridden(true);
		}

		return $this->features->update($feature);
	}

	/** Import-only: says which Dataset this record belongs to and what the
	 *  authority published, which is kept even when a local edit wins. */
	private function applyProvenance(Feature $feature, ?Provenance $provenance): void {
		if ($provenance === null) {
			return;
		}

		$feature->setDatasetId($provenance->datasetId);
		$feature->setExternalId($provenance->externalId);
		$feature->setOfficial($provenance->officialAsJson());
	}

	/** @param array<string, mixed> $input */
	private function apply(Feature $feature, array $input): Feature {
		$name = trim((string)($input['name'] ?? ''));
		if ($name === '') {
			throw new InvalidFeatureException('El registro necesita un nombre.');
		}
		$feature->setName($name);

		$subcategoryId = (int)($input['subcategoryId'] ?? 0);
		try {
			$this->subcategories->find($subcategoryId);
		} catch (DoesNotExistException $e) {
			throw new InvalidFeatureException(
				sprintf('La subcategoría %d no existe.', $subcategoryId), 0, $e,
			);
		}
		$feature->setSubcategoryId($subcategoryId);

		// Only fields actually submitted are written. A request that omits one
		// leaves it alone rather than blanking it: history can now say what a
		// field used to hold, but silently blanking it would still be a change
		// nobody asked for. Clearing a field is possible — send it empty.
		foreach (self::OPTIONAL_TEXT as $key => $setter) {
			if (!array_key_exists($key, $input)) {
				continue;
			}
			$value = trim((string)$input[$key]);
			$feature->$setter($value === '' ? null : $value);
		}

		if (array_key_exists('colour', $input)) {
			$feature->setColour(self::colour($input['colour']));
		}

		// Omitted leaves it alone, like every optional field above — and that is
		// what makes the Limit survive hand-adjustment (issue #15). A client
		// dragging a vertex sends the geometry; it must not have to remember to
		// send the Limit back unchanged in order to keep it.
		if (array_key_exists('limit', $input)) {
			$feature->setBoundaryLimit(self::limit($input['limit']));
		}

		$this->applyGeometry($feature, $input);

		// Derived here, at the one door every write comes through, so no caller can
		// save a record whose stored Sector disagrees with where it now is
		// (ADR-0008). It lands in this save's Revision and history entry rather
		// than a second one.
		$this->assignments->assign($feature);

		return $feature;
	}

	/**
	 * What a Sector runs along, as JSON, or null.
	 *
	 * Rebuilt part by part rather than stored as it arrived. This is the one
	 * field the browser sends as a structure rather than a string, so it is the
	 * one place a request could put arbitrary shapes into a column that is read
	 * back and rendered — and a name is a name whatever else came with it.
	 *
	 * An empty Limit is null rather than `[]`: a Sector drawn by hand has no
	 * Limit at all (ADR-0007), and "[]" and "no answer" reading differently in
	 * history would be a distinction with nothing behind it.
	 *
	 * @return ?string JSON
	 */
	private static function limit(mixed $value): ?string {
		if (!is_array($value)) {
			return null;
		}

		$parts = [];
		foreach ($value as $part) {
			if (!is_array($part)) {
				continue;
			}

			// A Limit is read aloud. Picking every street in a comuna would record
			// eleven thousand parts into a TEXT column and produce a sentence
			// nobody can read, so the cap is where a sentence stops being one —
			// and it is generous, because a Sector really can follow a dozen
			// streets held as dozens of ways each.
			if (count($parts) >= self::LIMIT_PARTS) {
				break;
			}

			// A name that is not a string is not a name. `(string)` on an array
			// gives the literal "Array" and a PHP warning, which would store a part
			// reading "Array" and blame nobody.
			$given = $part['name'] ?? null;
			$name = is_string($given) || is_int($given) || is_float($given)
				? mb_substr(trim((string)$given), 0, self::LIMIT_NAME)
				: '';
			if ($name === '') {
				// A part nobody can read is not a part. It would render as a gap in
				// the sentence and there would be nothing to say about it.
				continue;
			}

			// Whole numbers only. is_numeric() would take 12.9, " 41" and "1e3"
			// and cast them to 12, 41 and 1000 — a part silently pointing at a
			// different Boundary feature from the one it names.
			$featureId = $part['featureId'] ?? null;
			$isId = is_int($featureId)
				|| (is_string($featureId) && ctype_digit($featureId));

			$parts[] = $isId
				? ['featureId' => (int)$featureId, 'name' => $name]
				: ['name' => $name];
		}

		if ($parts === []) {
			return null;
		}

		try {
			return json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
		} catch (JsonException $e) {
			// Without the flag this returns false, which goes straight into the
			// column and destroys the Limit silently — a name that is not valid
			// UTF-8 is all it takes. Refused instead, like `colour()` two
			// functions down refuses what it cannot store.
			throw new InvalidFeatureException('El límite tiene texto que no se puede guardar.', 0, $e);
		}
	}

	/**
	 * A Sector's own colour, or null for a record that takes its Category's.
	 *
	 * Checked here rather than escaped downstream: the colour reaches the browser
	 * inside a `style` attribute and a Leaflet path option, so refusing anything
	 * that is not a hex colour leaves one place to be right about it instead of
	 * one per template. Stored lower-case so `#A1B2C3` and `#a1b2c3` do not read
	 * as a change in history.
	 */
	private static function colour(mixed $value): ?string {
		$colour = strtolower(trim((string)$value));
		if ($colour === '') {
			return null;
		}

		if (preg_match('/^#[0-9a-f]{6}$/', $colour) !== 1) {
			throw new InvalidFeatureException(sprintf('Color inválido: "%s". Use #rrggbb.', $colour));
		}

		return $colour;
	}

	/**
	 * Geometry, and everything measured from it, in one place — so a Feature can
	 * never carry a bounding box that belongs to a shape it no longer has.
	 *
	 * @param array<string, mixed> $input
	 */
	private function applyGeometry(Feature $feature, array $input): void {
		$raw = $input['geometry'] ?? null;

		if (!is_array($raw) || $raw === []) {
			$feature->setGeometry(null);
			$feature->setBoundingBox(null);
			$feature->setKind(Kind::Place->value);
			// Nothing to describe, so this is not the user's to choose.
			$feature->setLocationQuality(LocationQuality::Absent->value);

			return;
		}

		$geometry = Geometry::fromArray($raw);

		$feature->setGeometry($geometry->toJson());
		$feature->setBoundingBox($geometry->boundingBox());
		$feature->setKind($geometry->kind()->value);
		$feature->setLocationQuality($this->locationQuality($input));
	}

	/** @param array<string, mixed> $input */
	private function locationQuality(array $input): string {
		$requested = trim((string)($input['locationQuality'] ?? ''));
		if ($requested === '') {
			return LocationQuality::Exact->value;
		}

		$quality = LocationQuality::tryFrom($requested);
		if ($quality === null || $quality === LocationQuality::Absent) {
			// Absent is derived from having no geometry; claiming it while
			// supplying one would leave the record contradicting itself.
			throw new InvalidFeatureException(sprintf('Exactitud desconocida: "%s".', $requested));
		}

		return $quality->value;
	}
}
