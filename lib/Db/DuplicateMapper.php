<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Duplicate> */
class DuplicateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'territorio_duplicate', Duplicate::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Duplicate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * The outstanding pairs, oldest first.
	 *
	 * @return list<Duplicate>
	 */
	public function findPending(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Duplicate::PENDING)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	public function countPending(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Duplicate::PENDING)));

		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();

		return $total;
	}

	/**
	 * The pending pair either of these belongs to, if there is one.
	 *
	 * Asked when a record is restored: a merge that is undone has to put its pair
	 * back in the queue, or the two would be visible again with nothing saying
	 * they might be one place.
	 */
	public function findMergeOf(int $featureId): ?Duplicate {
		$qb = $this->db->getQueryBuilder();
		$id = $qb->createNamedParameter($featureId, IQueryBuilder::PARAM_INT);
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Duplicate::MERGED)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('first_id', $id),
				$qb->expr()->eq('second_id', $id),
			))
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Record a pair, unless it is already known.
	 *
	 * The unique index does the deciding rather than a read-then-write: two
	 * imports running at once would both see "not there" and both insert, and the
	 * loser of that race is the one whose insert the database refuses. Which is
	 * the right answer — the pair is recorded either way.
	 *
	 * @return bool whether this pair was new
	 */
	public function record(Duplicate $pair): bool {
		try {
			$this->insert($pair);

			return true;
		} catch (Exception $e) {
			if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}

			throw $e;
		}
	}
}
