<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use BadFunctionCallException;
use OCA\Territorio\Domain\Action;
use OCA\Territorio\Domain\BoundingBox;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Throwable;

/** @template-extends QBMapper<Feature> */
class FeatureMapper extends QBMapper {
	public function __construct(
		IDBConnection $db,
		private RevisionLog $revisions,
		private DatasetMapper $datasets,
	) {
		parent::__construct($db, 'territorio_feature', Feature::class);
	}

	/**
	 * ADR-0005 says no hard DELETE of a Feature anywhere in the app, and this is
	 * the place that rule has to be enforced: QBMapper hands every subclass a
	 * public `delete()`, so without this override the prohibition is a sentence
	 * in a document that nothing stops a future caller from ignoring.
	 */
	public function delete(Entity $entity): Entity {
		throw new BadFunctionCallException(
			'Features are never hard-deleted (ADR-0005); retire them instead',
		);
	}

	/**
	 * Stamping happens here, not in the service, for the same reason `delete()`
	 * refuses here: this is the only door. ADR-0002's cursor is correct exactly
	 * as long as no write escapes without advancing it, and a rule that lives in
	 * a service is a rule the next caller can walk around.
	 *
	 * The transaction is not bookkeeping — it is the correctness. Taking a
	 * Revision and stamping the row must become visible together: if the Revision
	 * lands first, a poll in the gap sees a higher cursor with nothing to fetch,
	 * banks it, and never asks for that change again.
	 */
	public function insert(Entity $entity): Entity {
		return $this->transactionally(function () use ($entity): Entity {
			$inserted = parent::insert($entity);
			$inserted->setRevision($this->revisions->allocate(
				(int)$inserted->getId(),
				Action::Created,
				// A creation's "diff" is every field it arrived with. Recording it
				// means the first version is reconstructable, which is what makes
				// an import revert able to undo a created row (ADR-0009).
				fn (): array => $this->asChanges($inserted->comparable()),
			));

			// parent::update, not $this->update — that would take a second
			// Revision for one change and make every client fetch it twice.
			return parent::update($inserted);
		});
	}

	public function update(Entity $entity): Entity {
		return $this->write($entity, Action::Updated);
	}

	/** ADR-0005: deleting is retiring. The record leaves the map, not the database. */
	public function retire(Feature $feature): Feature {
		$feature->setRetired(true);

		return $this->write($feature, Action::Retired);
	}

	/**
	 * Retire this one and say where it went (issue #16).
	 *
	 * Its own Action rather than a retirement with a note: "fusionado con" is a
	 * different fact from "retirado", and it is the one somebody looking at the
	 * losing record needs. The pointer is a column so that it can be answered
	 * from the record itself, without knowing which pair occasioned the merge.
	 */
	public function merge(Feature $loser, int $survivorId): Feature {
		$loser->setRetired(true);
		$loser->setMergedInto($survivorId);

		return $this->write($loser, Action::Merged);
	}

	/**
	 * Back on the map, and no longer pointing anywhere.
	 *
	 * Clearing the pointer here rather than in a separate un-merge is what makes
	 * a merge "reversible for free" under ADR-0005: there is one way back for a
	 * record that has left the map, and it is this one. A restored record that
	 * still claimed to be merged into another would be visible in the Registry
	 * and describing itself as gone.
	 */
	public function restore(Feature $feature): Feature {
		$feature->setRetired(false);
		$feature->setMergedInto(null);

		return $this->write($feature, Action::Restored);
	}

	/**
	 * The requested live records, by id (L1-17).
	 *
	 * The export's read: the browser sends ids and nothing else, and the whole
	 * Registry was walked to keep a handful. The same world findAll() answers —
	 * no retired records, no Boundary-feature rows — ordered the same way, so
	 * the writers' output order does not move.
	 *
	 * @param int[] $ids
	 * @return Feature[]
	 */
	public function findByIds(array $ids): array {
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('retired', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('id', 'DESC');
		$this->withoutBoundaries($qb);

		return $this->findEntities($qb);
	}

	/**
	 * Every live Place (L1-17) — the duplicate scan's candidate set.
	 *
	 * Places only, for the reason DuplicateService::candidates() states: a
	 * boundary has an extent rather than a coordinate, and "the same place" for
	 * two of them is a different question. Retired records and Boundary-feature
	 * rows are absent for the same reasons findAll() leaves them out — the scan
	 * filtered exactly this set in PHP until now.
	 *
	 * @return Feature[]
	 */
	public function findPlaces(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('retired', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter('place')))
			->orderBy('id', 'DESC');
		$this->withoutBoundaries($qb);

		return $this->findEntities($qb);
	}

	/**
	 * The ids of everything still visible (L1-17) — the duplicate queue's
	 * visibility check, which walked the whole Registry into a map per poll to
	 * ask "does this id exist".
	 *
	 * @return list<int>
	 */
	public function findLiveIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('retired', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
		$this->withoutBoundaries($qb);

		$ids = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Everything a person can still see.
	 *
	 * ponytail: unpaginated. One comuna's catastro is hundreds of rows and the
	 * Datos list, the map and the counts all render from the same set anyway;
	 * paginate when a real install makes this slow, not before.
	 *
	 * @return Feature[]
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('retired', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('id', 'DESC');
		$this->withoutBoundaries($qb);

		return $this->findEntities($qb);
	}

	/**
	 * Update only the stored official value, without taking a Revision.
	 *
	 * Deliberately not `update()`. `official` is provenance, not content: it says
	 * what the authority currently publishes about a record whose local edit is
	 * winning anyway, and `Feature::comparable()` rightly excludes it. Pushing it
	 * through the normal path would take a Revision, make every polling client
	 * refetch, and append an "Actualizado" history entry whose diff is empty —
	 * the same emptiness FeatureService::retire() already refuses to create.
	 *
	 * Nothing a person sees changes here, so nothing a person sees needs telling.
	 */
	public function storeOfficial(Feature $feature, string $official): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('official', $qb->createNamedParameter($official))
			->where($qb->expr()->eq('id', $qb->createNamedParameter(
				(int)$feature->getId(), IQueryBuilder::PARAM_INT,
			)))
			->executeStatement();

		$feature->setOfficial($official);
	}

	/**
	 * How many records are filed under any of these Subcategories — everything,
	 * with no filter of any kind (#47, ADR-0016).
	 *
	 * Deliberately NOT `findAll()` with a count over it, and this is the whole
	 * point of the method existing: that one drops retired records and boundary
	 * Datasets, because it answers "what is a person looking at". This one
	 * answers "what would be orphaned", and the two are different questions. A
	 * retired record can be restored, and the schema carries no foreign key from
	 * a Feature to its Subcategory that would catch the orphan afterwards — so
	 * conflating the two numbers is exactly how the orphan gets through.
	 *
	 * Boundary Datasets are in for the same reason: an imported Unidad Vecinal is
	 * a Feature carrying a Subcategory id like any other, and a count that left it
	 * out would be the display count wearing a different name.
	 *
	 * One question for a whole list of ids, because a Category is refused on the
	 * total across every Subcategory under it and a Subcategory on its own —
	 * there is one rule and it takes a list.
	 *
	 * @param int[] $subcategoryIds
	 */
	public function countBySubcategory(array $subcategoryIds): int {
		if ($subcategoryIds === []) {
			// `IN ()` is not valid SQL, and a Category with no Subcategory is a
			// real state the seed's «Otro» merely makes rare — nothing can be
			// filed under one, so the honest answer is zero rather than an error.
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->in(
				'subcategory_id',
				$qb->createNamedParameter($subcategoryIds, IQueryBuilder::PARAM_INT_ARRAY),
			));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/** @return Feature[] */
	public function findRetired(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('retired', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('revision', 'DESC');
		$this->withoutBoundaries($qb);

		return $this->findEntities($qb);
	}

	/**
	 * Everything already in a Dataset, keyed by the external id a re-import
	 * matches on — read once per run rather than one query per incoming row.
	 *
	 * Retired records are included deliberately: a record retired here and still
	 * present upstream must be recognised as the same record, not created again
	 * as a second copy the next time the file is imported.
	 *
	 * @return array<string, Feature>
	 */
	public function findByDataset(int $datasetId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('dataset_id', $qb->createNamedParameter($datasetId, IQueryBuilder::PARAM_INT)));

		$byExternalId = [];
		foreach ($this->findEntities($qb) as $feature) {
			$byExternalId[(string)$feature->getExternalId()] = $feature;
		}

		return $byExternalId;
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Feature {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Everything a client holding `$since` has not seen — retired records
	 * INCLUDED, deliberately.
	 *
	 * This is the one read that does not filter them out, and it must not. A
	 * client already holding a record needs to be told it was retired so it can
	 * stop drawing it; filtering here would leave that record on every open map
	 * until someone reloaded. It ships with `retired: true` and the client
	 * removes it.
	 *
	 * Ordered by Revision so a client that stops reading early can resume from
	 * the last one it actually applied, rather than skipping the remainder.
	 *
	 * ponytail: unpaginated, like findAll(). A client that has been away long
	 * enough gets the whole comuna in one response; add a limit and a "there is
	 * more" flag when a real catastro makes that payload hurt.
	 *
	 * @return Feature[]
	 */
	public function findChangedSince(int $since): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->gt('revision', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->orderBy('revision', 'ASC');
		// Boundary features are excluded here too, and this is the read where it
		// matters most: importing a comuna's streets moves the cursor by eleven
		// thousand, and without this every open map would fetch all of them at the
		// next poll to draw none of them.
		$this->withoutBoundaries($qb);

		return $this->findEntities($qb);
	}

	/**
	 * The Boundary features overlapping a box — the inverse of every other read.
	 *
	 * They are excluded everywhere else because a comuna is around eleven
	 * thousand lines and nobody browses those (issue #12). This is the one place
	 * that wants them: the Sector editor, while a boundary is being built out of
	 * them (ADR-0007), and only the ones on screen.
	 *
	 * Overlap rather than containment: a street that runs off the edge of the view
	 * is still one you can click the visible half of.
	 *
	 * @return Feature[]
	 */
	public function findBoundariesIn(float $south, float $west, float $north, float $east, int $limit = 3000): array {
		$boundaries = $this->datasets->boundaryIds();
		if ($boundaries === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('retired', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->in('dataset_id', $qb->createNamedParameter(
				$boundaries, IQueryBuilder::PARAM_INT_ARRAY,
			)))
			->andWhere($qb->expr()->lte('min_lat', $qb->createNamedParameter($north)))
			->andWhere($qb->expr()->gte('max_lat', $qb->createNamedParameter($south)))
			->andWhere($qb->expr()->lte('min_lon', $qb->createNamedParameter($east)))
			->andWhere($qb->expr()->gte('max_lon', $qb->createNamedParameter($west)))
			// ponytail: a flat cap rather than paging, and deliberately NOT sorted by
			// name. Sorted, the cap cut alphabetically — zoomed far enough out every
			// street from roughly S onward disappeared, which is exactly the one
			// somebody then cannot find by typing its name. Unsorted it cuts by
			// nothing in particular, which is honest, and the editor asks again
			// whenever the view settles. Page it when a comuna needs more than this.
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * The name and box of every live Boundary feature — and never its geometry.
	 *
	 * The street search's read (`BoundaryService::names()`), and the one read of
	 * these rows that no view bounds. That is safe only because of what it does
	 * not select: issue #12 is eleven thousand GEOMETRIES, and this is five
	 * narrow columns. `select('*')` here would ship exactly the payload #12 keeps
	 * off every other read.
	 *
	 * No cap, unlike `findBoundariesIn()`: that one is a view and asks again as
	 * the view moves, while a cap here would drop streets from the search for
	 * good — the arbitrary-cut problem that read's own ponytail note describes.
	 *
	 * ponytail: unpaginated and uncached; one comuna is about eleven thousand
	 * rows of five columns, read once per session. Cache it per Revision
	 * (ICache keyed on RevisionLog::current()) when a cadastre makes it hurt.
	 *
	 * By id, so "the first spelling filed" is the same spelling every time.
	 * Unnamed ways are left out: nobody can type what has no name.
	 *
	 * @return list<array{name: string, box: BoundingBox}>
	 */
	public function boundaryNames(): array {
		$boundaries = $this->datasets->boundaryIds();
		if ($boundaries === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('name', 'min_lat', 'min_lon', 'max_lat', 'max_lon')
			->from($this->getTableName())
			->where($qb->expr()->eq('retired', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->in('dataset_id', $qb->createNamedParameter(
				$boundaries, IQueryBuilder::PARAM_INT_ARRAY,
			)))
			->andWhere($qb->expr()->isNotNull('min_lat'))
			// Leaves out a NULL name as well: NULL <> '' is not true.
			->andWhere($qb->expr()->neq('name', $qb->createNamedParameter('')))
			->orderBy('id', 'ASC');

		$ways = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$ways[] = [
				'name' => (string)$row['name'],
				'box' => BoundingBox::around([
					[(float)$row['min_lon'], (float)$row['min_lat']],
					[(float)$row['max_lon'], (float)$row['max_lat']],
				]),
			];
		}
		$result->closeCursor();

		return $ways;
	}

	/**
	 * Which of these ids are still live Boundary features (issue #51).
	 *
	 * The same filter as `findBoundariesIn()` without the box and without the
	 * cap, because the question is about a handful of named ids rather than a
	 * view: a Limit holds at most `FeatureService::LIMIT_PARTS` parts and the
	 * controller cuts the list there. Asking it this way — live ones back, rather
	 * than gone ones — is what makes retired, merged-away and never-existed one
	 * answer instead of three.
	 *
	 * @param int[] $ids
	 * @return list<int>
	 */
	public function findLiveAmong(array $ids): array {
		$boundaries = $this->datasets->boundaryIds();
		if ($ids === [] || $boundaries === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('retired', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->in('dataset_id', $qb->createNamedParameter(
				$boundaries, IQueryBuilder::PARAM_INT_ARRAY,
			)));

		$live = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$live[] = (int)$row['id'];
		}
		$result->closeCursor();

		return $live;
	}

	/**
	 * The live names of these ids — the export's division column (issue #17).
	 *
	 * A record stores its Sector and Unidad Vecinal as ids, and a spreadsheet
	 * cell holding a number is not something a person reads. One bulk read
	 * answering all of an export's records at once, mirroring
	 * `labelledSubcategories()` on the service side.
	 *
	 * No dataset filter, because the question is only "can this id still be
	 * named": a division a person drew and one a cadastre imported answer it
	 * the same. Absent from the map — retired or gone — is the caller's empty
	 * cell, never an id.
	 *
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	public function namesAmong(array $ids): array {
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('retired', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));

		$names = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$names[(int)$row['id']] = (string)$row['name'];
		}
		$result->closeCursor();

		return $names;
	}

	/**
	 * Leave out everything belonging to a Boundary-feature Dataset.
	 *
	 * Applied to the ordinary reads and to no others. `findByDataset()` must see
	 * them or a re-import would create every street a second time; `find()` must
	 * see them or #13's picker could not open one; and a revert reaches them
	 * through the batch, not through here.
	 */
	private function withoutBoundaries(IQueryBuilder $qb): void {
		$boundaries = $this->datasets->boundaryIds();
		if ($boundaries === []) {
			return;
		}

		$qb->andWhere($qb->expr()->orX(
			// A record typed in rather than imported has no Dataset at all, and
			// NOT IN never matches a NULL.
			$qb->expr()->isNull('dataset_id'),
			$qb->expr()->notIn(
				'dataset_id',
				$qb->createNamedParameter($boundaries, IQueryBuilder::PARAM_INT_ARRAY),
			),
		));
	}

	/**
	 * One write, one Revision, one history entry, all inside one transaction.
	 *
	 * A transition re-verifies the row it was decided against after the
	 * Revision is taken (see {@see verifyTransition()}), which is what makes the
	 * refusal in the service a courtesy read rather than the rule: the rule has
	 * to hold inside the write, where the locks are.
	 *
	 * The stored row is re-read here rather than trusted from the caller: the
	 * entity handed in has already been mutated, so its original values are gone,
	 * and history's whole job is to say what they were.
	 */
	private function write(Feature $entity, Action $action): Feature {
		return $this->transactionally(function () use ($entity, $action): Feature {
			$entity->setRevision($this->revisions->allocate(
				(int)$entity->getId(),
				$action,
				// Read inside the callback, which runs after the cursor lock is
				// held. Reading before it would snapshot a row another writer is
				// still finishing, and history would then name a "from" value that
				// was already superseded — the one thing an import revert must not
				// get wrong (ADR-0009).
				fn (): array => $this->diff($this->find((int)$entity->getId()), $entity),
			));

			$this->verifyTransition($entity, $action);

			return parent::update($entity);
		});
	}

	/**
	 * A write that changes map membership re-verifies the row the decision was
	 * made against, under a lock, inside the write's own transaction (#208).
	 *
	 * The services refuse first, in these same words, before opening any
	 * transaction — but every such read precedes the locks: two people retiring
	 * the same record at the same moment both see it live, and the second write
	 * then lands over the first (for a merge of one pair in opposite directions,
	 * both records end up retired into each other, and the pair says one thing
	 * about a decision that was made twice). A rule that lives in the service is
	 * a rule the next caller walks around, so the re-read lives here, where the
	 * write is actually about to happen and the row lock holds until commit.
	 *
	 * It runs after the Revision is taken, not before, and the order is the
	 * correctness: every writer in the app queues at the cursor first, so by the
	 * time this runs there is no other writer mid-flight and these row locks are
	 * uncontended. Locking a row before the cursor would invert against every
	 * ordinary edit — cursor then row — and a merge racing a plain save of the
	 * same record would deadlock, the database killing one of them with a driver
	 * error instead of a refusal. A refusal here still burns nothing: the whole
	 * transaction, Revision included, rolls back.
	 */
	private function verifyTransition(Feature $entity, Action $action): void {
		// Retire and merge leave the map, restore rejoins it; only a membership
		// transition carries a decision that a colleague's write can invalidate.
		// An edit or a creation decides nothing of the kind — last writer wins
		// there, as it always has.
		$rejoining = match ($action) {
			Action::Retired, Action::Merged => false,
			Action::Restored => true,
			Action::Created, Action::Updated => null,
		};
		if ($rejoining === null) {
			return;
		}

		if ($this->lockedRow((int)$entity->getId())->getRetired() !== $rejoining) {
			throw new InvalidFeatureException($rejoining
				? 'El registro no está retirado.'
				: 'El registro ya estaba retirado.');
		}

		// Only a merge has someone to point at, and the pointer is already on the
		// entity: merge() sets mergedInto before writing, so the write can verify
		// its own decision input. A survivor retired under the decision is the
		// interleaving of two opposite merges of one pair, where each request's
		// survivor is the other's loser — and without this look, both records end
		// up retired into each other and both vanish from every read (#208).
		if ($action === Action::Merged) {
			$survivorId = $entity->getMergedInto();
			if ($this->lockedRow((int)$survivorId)->getRetired()) {
				throw new InvalidFeatureException(
					'El registro que se conserva está retirado; restáurelo antes de fusionar.',
				);
			}
		}
	}

	/**
	 * The stored row, locked for the rest of this transaction.
	 *
	 * FOR UPDATE rather than a plain read: under REPEATABLE READ a plain re-read
	 * returns the transaction's own snapshot — the row the decision was made
	 * against, stale by construction — so the guard would guard nothing. A
	 * locking read waits for any in-flight writer and sees what that writer
	 * committed. On SQLite the platform strips the clause, which is sound there
	 * because SQLite admits one writer at a time.
	 *
	 * @throws DoesNotExistException when the row is gone, as `find()` would
	 */
	private function lockedRow(int $id): Feature {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->forUpdate();

		return $this->findEntity($qb);
	}

	/**
	 * What this write actually changes.
	 *
	 * Restricted to the fields the entity will really write: QBMapper only
	 * persists what was set, so comparing every field against a freshly read row
	 * would report a colleague's untouched edit as a change this save made.
	 *
	 * @return array<string, array{from: mixed, to: mixed}>
	 */
	private function diff(Feature $before, Feature $after): array {
		$writing = $after->getUpdatedFields();
		$was = $before->comparable();
		$changes = [];

		foreach ($after->comparable() as $field => $value) {
			if (!isset($writing[Feature::PROPERTY_OF[$field] ?? $field])) {
				continue;
			}
			if (($was[$field] ?? null) !== $value) {
				$changes[$field] = ['from' => $was[$field] ?? null, 'to' => $value];
			}
		}

		return $changes;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, array{from: null, to: mixed}>
	 */
	private function asChanges(array $fields): array {
		return array_map(
			static fn (mixed $value): array => ['from' => null, 'to' => $value],
			array_filter($fields, static fn (mixed $value): bool => $value !== null),
		);
	}

	/** @param callable(): Entity $write */
	private function transactionally(callable $write): Entity {
		$this->db->beginTransaction();
		try {
			$written = $write();
			$this->db->commit();

			return $written;
		} catch (Throwable $e) {
			$this->db->rollBack();

			throw $e;
		}
	}
}
