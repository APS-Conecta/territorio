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
	/**
	 * The resolved merge this record was the LOSER of, if there is one.
	 *
	 * Asked when a record is restored: a merge that is undone has to put its pair
	 * back in the queue, or the two would be visible again with nothing saying
	 * they might be one place.
	 *
	 * The pair is named by both of its members: the loser being restored and the
	 * survivor its `mergedInto` points at. Rows are stored lowest id first, the
	 * order `DuplicateFinder` emits them in, under the unique terr_dup_pair_idx,
	 * so this is an exact match on one row. A pair the record won, a newer merge
	 * it was on, or a pair that is not Merged (a dismissal is final, ADR-0011)
	 * never matches (#206).
	 */
	public function findMergeOf(int $loserId, int $survivorId): ?Duplicate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Duplicate::MERGED)))
			->andWhere($qb->expr()->eq('first_id', $qb->createNamedParameter(
				min($loserId, $survivorId), IQueryBuilder::PARAM_INT,
			)))
			->andWhere($qb->expr()->eq('second_id', $qb->createNamedParameter(
				max($loserId, $survivorId), IQueryBuilder::PARAM_INT,
			)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Every pair the table already holds, as first id => second id => true.
	 *
	 * Two columns rather than entities: this exists so a scan can skip what is
	 * already recorded, and hydrating a `Duplicate` per row to answer that would
	 * cost more than the inserts it saves.
	 *
	 * Every status, not only pending: a pair somebody dismissed is exactly the one
	 * that must not be offered again (issue #16), and one already merged is not a
	 * candidate either.
	 *
	 * @return array<int, array<int, true>>
	 */
	public function knownPairs(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('first_id', 'second_id')->from($this->getTableName());

		$known = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$known[(int)$row['first_id']][(int)$row['second_id']] = true;
		}
		$result->closeCursor();

		return $known;
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

	/**
	 * Resolve a pair that is still pending.
	 *
	 * The stored row decides, as in record(): of two resolutions that both read
	 * PENDING, the second changes no row. The status always changes away from
	 * PENDING, so the affected-row count is 1 exactly when this call won, on
	 * MySQL/MariaDB, PostgreSQL and SQLite.
	 *
	 * @param Duplicate::MERGED|Duplicate::DISMISSED $status
	 * @return bool whether this call resolved the pair
	 */
	public function resolve(int $id, string $status, ?string $resolvedBy): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($status))
			->set('resolved_by', $qb->createNamedParameter($resolvedBy))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Duplicate::PENDING)));

		return $qb->executeStatement() === 1;
	}
}
