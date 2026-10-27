<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use JsonException;
use OCA\Territorio\Db\Duplicate;
use OCA\Territorio\Db\DuplicateMapper;
use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Db\ImportBatch;
use OCA\Territorio\Db\ImportBatchMapper;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Domain\Geometry;
use OCA\Territorio\Domain\Kind;
use OCA\Territorio\Domain\LocationQuality;
use OCA\Territorio\Domain\Provenance;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use DateTime;

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
	 *
	 * Public because `BoundaryController` caps the ids one read may ask about at
	 * the same number and there is no honest way for the two to differ: the
	 * question is "which of my parts are gone", and a Limit cannot hold more.
	 */
	public const LIMIT_PARTS = 500;
	private const LIMIT_NAME = 200;

	/**
	 * Optional free-text columns, all handled identically: trim, blank becomes
	 * null — and a value the column cannot store is refused with the app's own
	 * sentence instead of the database's error through a 500 (L1-21). Each cap
	 * is its column's own VARCHAR width (Version000200), so the sentence names
	 * the field to fix and the number to fix it to. `notes` carries no cap: its
	 * column is TEXT, and no length of note has ever been a storage problem.
	 */
	private const OPTIONAL_TEXT = [
		'address' => ['setAddress', 'La dirección', 255],
		'phone' => ['setPhone', 'El teléfono', 64],
		'email' => ['setEmail', 'El correo', 255],
		'contactPerson' => ['setContactPerson', 'La persona de contacto', 128],
		'contactRole' => ['setContactRole', 'El cargo', 128],
		'openingHours' => ['setOpeningHours', 'El horario', 255],
		'notes' => ['setNotes', null, null],
		'sourceRef' => ['setSourceRef', 'La fuente', 255],
	];

	public function __construct(
		private FeatureMapper $features,
		private SubcategoryMapper $subcategories,
		private RevisionLog $revisions,
		private AssignmentService $assignments,
		private DuplicateMapper $duplicates,
		// The sweep's own machinery (L1-13/L1-16): batches for the synthetic
		// reassignment-sweep row, session for who swept. FeatureService was
		// already the write door; the sweep is a write-door consequence.
		private ImportBatchMapper $batches,
		private IUserSession $session,
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
		$this->reassignAround($gone, $gone->getGeometry(), $this->assignments->divisionOf($gone));

		return $gone;
	}

	/** @throws DoesNotExistException */
	public function restore(int $id): Feature {
		$feature = $this->features->find($id);
		if (!$feature->getRetired()) {
			throw new InvalidFeatureException('El registro no está retirado.');
		}

		$mergedInto = $feature->getMergedInto();
		// Sweeps skip retired records, so a returning record derives its own assignment again (ADR-0008).
		$this->assignments->assign($feature);
		$restored = $this->features->restore($feature);
		// And a restored one takes back what falls inside it.
		$this->reassignAround($restored, null, $this->assignments->divisionOf($restored));

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
		$feature = $this->apply(new Feature(), $input, whole: true);
		$this->applyProvenance($feature, $provenance);

		$created = $this->features->insert($feature);

		// A boundary that did not exist a moment ago covers records that were
		// resolved before it. Without this, importing a comuna's Unidades
		// Vecinales into an install that already holds its catastro leaves every
		// one of those records assigned to nothing — which is the case ADR-0008
		// exists for, arriving through the door it is most likely to arrive by.
		$this->reassignAround($created, null, null);

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

		return $this->write($feature, $input, $provenance, markOverride: $provenance === null);
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
	 *
	 * Or when the record became a boundary, or stopped being one, or changed from
	 * one division to another. Reclassifying a Zone into `sector` redraws the map
	 * without touching a shape, and so does reclassifying a Sector back out — the
	 * geometry is identical either way, so `$before` cannot see it and the sweep
	 * used to be skipped in both directions (#84). `$wasDivision` is the division
	 * the record was a boundary of before this write, or null.
	 */
	private function reassignAround(Feature $boundary, ?string $before, ?string $wasDivision): void {
		$isDivision = $this->assignments->divisionOf($boundary);

		// Nothing to sweep for a record that is not a boundary and never was. One
		// that has just stopped being a boundary still owes every record it held.
		if ($isDivision === null && $wasDivision === null) {
			return;
		}
		// Retiring and restoring pass the same geometry on purpose: what changed is
		// whether it counts at all, not its shape. The division is compared as a
		// slug, not as "is it a boundary": Sector to Unidad Vecinal is a redraw of
		// the map with the shape untouched, and a bool says both sides are true.
		if ($isDivision === $wasDivision
			&& $boundary->getGeometry() === $before && !$boundary->getRetired() && $before !== null) {
			return;
		}

		// An import collects its boundary events and sweeps once at batch end
		// (L1-13); everybody else sweeps here and now, stamped with a batch of its
		// own so a crash mid-sweep is revertible like an import (L1-16).
		if ($this->deferredReassignments !== null) {
			$this->deferredReassignments[(int)$boundary->getId()] = [$boundary, $before];

			return;
		}

		// What was read before this write no longer describes the Registry.
		$this->assignments->forget();
		$this->sweepAround($boundary, $before, stamp: true);
	}

	/**
	 * One boundary's reassignment sweep. The write of each record it moved,
	 * stamped with the sweep's own batch when it is not already inside an
	 * import's (L1-16): every Revision the sweep takes carries the batch's id,
	 * so the half-finished sweep a crash leaves behind is undone from the batch
	 * list exactly like an import.
	 *
	 * The batch row is created LAZILY, on the first record the sweep actually
	 * rewrites: a rename that moves nobody writes no batch, and the import dialog
	 * fills with imports, not with noise.
	 */
	private function sweepAround(Feature $boundary, ?string $before, bool $stamp): void {
		$batch = null;
		$rewritten = 0;

		foreach ($this->assignments->affectedBy($boundary, $before) as $affected) {
			$was = [$affected->getSectorId(), $affected->getUnidadVecinalId()];
			$this->assignments->assign($affected);

			// Only where it really changed: the box around a redrawn Sector holds
			// plenty of records whose answer is the same as before, and writing
			// those would fill history with entries whose diff is empty.
			if ($was === [$affected->getSectorId(), $affected->getUnidadVecinalId()]) {
				continue;
			}

			if ($stamp) {
				$batch ??= $this->newSweepBatch();
				$affected = $this->revisions->duringImport(
					(int)$batch->getId(),
					fn (): Feature => $this->features->update($affected),
				);
			} else {
				$affected = $this->features->update($affected);
			}
			$rewritten++;
		}

		if ($batch !== null) {
			$batch->setUpdated($rewritten);
			$batch->setRevisionTo($this->revisions->current());
			$this->batches->update($batch);
		}
	}

	/** A reassignment sweep's batch row: no Dataset — the null is what marks it
	 *  (L1-16; the migration relaxes the column), and recentBatches() labels it
	 *  «reasignación de límite» so the person reading the batch list knows what
	 *  kind of undo this one is. */
	private function newSweepBatch(): ImportBatch {
		$batch = new ImportBatch();
		$batch->setDatasetId(null);
		$batch->setActor($this->session->getUser()?->getUID());
		$batch->setStartedAt(new DateTime());
		$batch->setRevisionFrom($this->revisions->current());
		$batch->setRevisionTo($this->revisions->current());

		return $this->batches->insert($batch);
	}

	/** Boundary events collected during an import, keyed by record id — null
	 *  whenever no import is running (L1-13). */
	private ?array $deferredReassignments = null;

	/**
	 * Run $work with boundary reassignment DEFERRED (L1-13).
	 *
	 * The import's one way in wraps its run in this: reassignAround() collects
	 * instead of sweeping, and flushDeferredReassignments() — called by the
	 * importer inside its batch stamp — sweeps once for the whole file. The
	 * set/restore pair is the same ambient-scope pattern RevisionLog::
	 * duringImport() uses, correct for the same per-request reason.
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	public function deferringReassignments(callable $work): mixed {
		$previous = $this->deferredReassignments;
		$this->deferredReassignments = [];
		try {
			return $work();
		} finally {
			$this->deferredReassignments = $previous;
		}
	}

	/**
	 * The one sweep an import owes, run inside its batch stamp (L1-13).
	 *
	 * Boundaries created or edited by the batch, keyed by record — one write per
	 * record per import, so the last write's before/after is the one that counts.
	 * The union of old and new boxes in affectedBy() is what heals the rows whose
	 * own assign() ran against the pre-import memo: a Place written before the
	 * boundary that will cover it is inside that boundary's new box, and the
	 * sweep reaches it exactly as the per-row sweep used to.
	 */
	public function flushDeferredReassignments(): void {
		$deferred = $this->deferredReassignments ?? [];
		if ($deferred === []) {
			return;
		}

		// Once, for the whole file: this is the invalidation the per-row sweep
		// used to pay M times.
		$this->assignments->forget();

		foreach ($deferred as [$boundary, $before]) {
			$this->sweepAround($boundary, $before, stamp: false);
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
		return $this->write($this->features->find($id), $input, null, markOverride: false);
	}

	/** @param array<string, mixed> $input */
	private function write(
		Feature $feature,
		array $input,
		?Provenance $provenance,
		bool $markOverride,
	): Feature {
		// Read before the entity is mutated: letting go of what a boundary no
		// longer covers needs the shape and the division it had, and after the
		// write there is no way to ask for either. Here, at the one door both
		// update() and revertTo() come through, so neither can forget it (#84).
		$before = $feature->getGeometry();
		$wasDivision = $this->assignments->divisionOf($feature);
		$wasImported = $feature->getDatasetId() !== null;
		$this->apply($feature, $input, whole: false);
		$this->applyProvenance($feature, $provenance);

		// #109: a Unidad Vecinal's polygon comes from the catastro, and a
		// person's save carrying a redrawn one would silently move the line
		// every assignment in the comuna is derived from (ADR-0008). Refused
		// here, at the one door a person's edit comes through. The compare is
		// stored-form, not key-presence: the client always sends the geometry
		// key (payload(), src/draft.js), so key-presence would make every UV
		// uneditable — and a polygon re-sent unchanged re-normalizes to the
		// identical bytes, so a rename flows (#63 is the bug this must not
		// repeat). $wasDivision is the division the record WAS, read before
		// apply(), so reclassifying a UV and reshaping it in one save cannot
		// slip past. Imports and reverts pass markOverride: false — they are
		// the doors that may legitimately reshape one.
		if ($markOverride && $wasDivision === 'unidad_vecinal'
			&& $feature->getGeometry() !== $before) {
			throw new InvalidFeatureException(
				'La forma de una Unidad Vecinal viene del catastro y no se puede redibujar aquí. '
				. 'Corrija el dato en el catastro y vuelva a importarlo.',
			);
		}

		// A person editing an imported record is what ADR-0003 calls a local
		// override: it states what is true on the ground, so it outranks whatever
		// the authority publishes next. Only a person's edit sets this — an import
		// passes its Provenance, and a revert asks not to.
		if ($markOverride && $wasImported) {
			$feature->setOverridden(true);
		}

		$written = $this->features->update($feature);
		$this->reassignAround($written, $before, $wasDivision);

		return $written;
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
	private function apply(Feature $feature, array $input, bool $whole): Feature {
		$this->applyName($feature, $input);
		$this->applySubcategory($feature, $input);
		$this->applyText($feature, $input);
		$this->applyColour($feature, $input);
		$this->applyLimit($feature, $input);

		$this->applyGeometry($feature, $input, $whole);
		$this->applyQuality($feature, $input);

		// Derived here, at the one door every write comes through, so no caller can
		// save a record whose stored Sector disagrees with where it now is
		// (ADR-0008). It lands in this save's Revision and history entry rather
		// than a second one.
		$this->assignments->assign($feature);

		return $feature;
	}

	/** The record's name — first, as at HEAD, so a request carrying two
	 *  problems hears about the name before anything else (L1-19: same door,
	 *  same order, same sentences). */
	private function applyName(Feature $feature, array $input): void {
		$name = trim((string)($input['name'] ?? ''));
		if ($name === '') {
			throw new InvalidFeatureException('El registro necesita un nombre.');
		}
		$feature->setName($name);
	}

	private function applySubcategory(Feature $feature, array $input): void {
		$subcategoryId = (int)($input['subcategoryId'] ?? 0);
		try {
			$this->subcategories->find($subcategoryId);
		} catch (DoesNotExistException $e) {
			throw new InvalidFeatureException(
				sprintf('La subcategoría %d no existe.', $subcategoryId), 0, $e,
			);
		}
		$feature->setSubcategoryId($subcategoryId);
	}

	/** The optional free-text fields — the text policy, one home (L1-19, L1-21). */
	private function applyText(Feature $feature, array $input): void {
		// Only fields actually submitted are written. A request that omits one
		// leaves it alone rather than blanking it: history can now say what a
		// field used to hold, but silently blanking it would still be a change
		// nobody asked for. Clearing a field is possible — send it empty.
		foreach (self::OPTIONAL_TEXT as $key => [$setter, $label, $cap]) {
			if (!array_key_exists($key, $input)) {
				continue;
			}
			$value = trim((string)$input[$key]);
			if ($cap !== null && mb_strlen($value) > $cap) {
				throw new InvalidFeatureException(sprintf(
					'%s no puede pasar de %d caracteres.', $label, $cap,
				));
			}
			$feature->$setter($value === '' ? null : $value);
		}
	}

	private function applyColour(Feature $feature, array $input): void {
		if (array_key_exists('colour', $input)) {
			$feature->setColour(self::colour($input['colour']));
		}
	}

	private function applyLimit(Feature $feature, array $input): void {
		// Omitted leaves it alone, like every optional field above — and that is
		// what makes the Limit survive hand-adjustment (issue #15). A client
		// dragging a vertex sends the geometry; it must not have to remember to
		// send the Limit back unchanged in order to keep it.
		if (array_key_exists('limit', $input)) {
			$feature->setBoundaryLimit($this->limit($input['limit']));
		}
	}

	/** Location quality's own door (issue #83): the with-geometry case stays
	 *  inside applyGeometry, the one home of the four columns it measures, so
	 *  this helper runs only when no geometry was submitted.
	 *
	 *  Both conditions carry their weight. `!array_key_exists('geometry')` keeps
	 *  the with-geometry case in one place instead of two that can disagree; and
	 *  reading `locationQuality` with `array_key_exists` rather than applying it
	 *  unconditionally is the rule stated for every optional field, which
	 *  matters most here: `locationQuality()` reads an absent value as `exacta`,
	 *  so an unconditional write would reset a stored `aproximada` on every
	 *  update that never mentioned the field — issue #63 again, by another name.
	 *
	 *  Refused rather than ignored when nothing is stored: `Absent` is derived
	 *  from having no geometry and never chosen, so a quality for a coordinate
	 *  that does not exist has to be a 4xx the caller can read, not a silent 200.
	 *
	 *  One deliberate disagreement with the optional-text rule, where a field
	 *  submitted empty CLEARS it: submitted empty here means `exacta`, not
	 *  cleared, because `locationQuality()` defaults that way and a record that
	 *  has a coordinate has no null state for how well it is known. The default
	 *  belongs to the helper applyGeometry shares, so it is stated here rather
	 *  than forked. */
	private function applyQuality(Feature $feature, array $input): void {
		if (!array_key_exists('locationQuality', $input) || array_key_exists('geometry', $input)) {
			return;
		}

		if ($feature->getGeometry() === null) {
			throw new InvalidFeatureException(
				'La Exactitud describe una coordenada: el registro no tiene ninguna.',
			);
		}

		$feature->setLocationQuality($this->locationQuality($input));
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
	 * The ids a Limit names are validated against the live-boundary set at write
	 * time (L1-21): `findLiveAmong()` is the same read the picker's door uses, so
	 * what a Limit points at is always something the app will resolve — a
	 * reference to a boundary somebody retired between picking and saving is
	 * refused with the sentence naming how many parts to drop, not stored as a
	 * dangling id the editor quietly renders as a gap.
	 *
	 * @return ?string JSON
	 */
	private function limit(mixed $value): ?string {
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

		$ids = [];
		foreach ($parts as $part) {
			if (isset($part['featureId'])) {
				$ids[] = $part['featureId'];
			}
		}
		$dangling = array_values(array_diff($ids, $this->features->findLiveAmong($ids)));
		if ($dangling !== []) {
			throw new InvalidFeatureException(sprintf(
				'El límite nombra %d frontera%s que ya no %s en el mapa; quite esas partes.',
				count($dangling),
				count($dangling) === 1 ? '' : 's',
				count($dangling) === 1 ? 'está' : 'están',
			));
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
	private function applyGeometry(Feature $feature, array $input, bool $whole): void {
		// Absent is not empty, and this is the one field that measures four columns
		// at once: the shape, the bounding box, the Kind and the Location quality.
		// Reading the key with `?? null` made an omitted geometry indistinguishable
		// from an explicitly empty one, so an update that never mentioned it wiped
		// all four and reported success (issue #63). Every optional field above
		// already draws this distinction with `array_key_exists`; the comment there
		// states the rule, and this is the field it was not true of.
		//
		// `$whole` is what tells the two doors apart, and it is a parameter rather
		// than something the caller normalises first: a create states the WHOLE
		// record, so an omitted geometry there means it HAS none — a new Feature
		// carries `kind = ''`, which is not a Kind, so the empty branch is what
		// gives it Place and Absent. An update states what CHANGED, so an omitted
		// geometry leaves all four alone. Threading it here keeps that rule in the
		// function that applies it: the class docblock plans a second door
		// ("exposing an OCS API later is a new controller"), and a rule enforced at
		// one call site is a rule the next door forgets.
		//
		// Sending `geometry` explicitly empty still clears all four — clearing a
		// shape stays possible, it just has to be asked for.
		if (!$whole && !array_key_exists('geometry', $input)) {
			return;
		}

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
