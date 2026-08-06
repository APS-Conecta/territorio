<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use BadFunctionCallException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Feature> */
class FeatureMapper extends QBMapper {
	public function __construct(
		IDBConnection $db,
		private RevisionLog $revisions,
	) {
		parent::__construct($db, 'territorio_feature', Feature::class);
	}

	/**
	 * Stamping happens here, not in the service, for the same reason `delete()`
	 * refuses here: this is the only door. ADR-0002's cursor is correct exactly
	 * as long as no write escapes without advancing it, and a rule that lives in
	 * a service is a rule the next caller can walk around.
	 *
	 * Three statements on insert, because a Feature has no id to log against
	 * until it exists. At a comuna's volumes that is not worth avoiding.
	 */
	public function insert(Entity $entity): Entity {
		$inserted = parent::insert($entity);
		$inserted->setRevision($this->revisions->allocate((int)$inserted->getId()));

		// parent::update, not $this->update — that would allocate a second
		// Revision for the same change and make the client fetch it twice.
		return parent::update($inserted);
	}

	public function update(Entity $entity): Entity {
		$entity->setRevision($this->revisions->allocate((int)$entity->getId()));

		return parent::update($entity);
	}

	/**
	 * Everything a client holding `$since` has not seen.
	 *
	 * Ordered by Revision so a client that stops reading early can resume from
	 * the last one it actually applied, rather than skipping the remainder.
	 *
	 * @return Feature[]
	 */
	public function findChangedSince(int $since): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->gt('revision', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->orderBy('revision', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * ADR-0005 says no hard DELETE of a Feature anywhere in the app, and this is
	 * the place that rule has to be enforced: QBMapper hands every subclass a
	 * public `delete()`, so without this override the prohibition is a sentence
	 * in a document that nothing stops a future caller from ignoring.
	 *
	 * Deleting is retirement, which arrives with history in issue #4. Until then
	 * a Feature cannot be removed at all, which is the safe direction to be
	 * wrong in.
	 */
	public function delete(Entity $entity): Entity {
		throw new BadFunctionCallException(
			'Features are never hard-deleted (ADR-0005); retire them instead, once issue #4 lands',
		);
	}

	/**
	 * Every Feature, newest first.
	 *
	 * ponytail: unpaginated. One comuna's catastro is hundreds of rows and the
	 * Datos list, the map and the counts all render from the same set anyway;
	 * paginate when a real install makes this slow, not before.
	 *
	 * @return Feature[]
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'DESC');

		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Feature {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity($qb);
	}
}
