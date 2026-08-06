<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use BadFunctionCallException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<Feature> */
class FeatureMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'territorio_feature', Feature::class);
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
