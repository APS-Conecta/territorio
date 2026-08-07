<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use BadFunctionCallException;
use OCA\Territorio\Service\Action;
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

	public function restore(Feature $feature): Feature {
		$feature->setRetired(false);

		return $this->write($feature, Action::Restored);
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

			return parent::update($entity);
		});
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
			if (!isset($writing[$field])) {
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
