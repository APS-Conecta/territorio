<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<ImportBatch> */
class ImportBatchMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'territorio_import', ImportBatch::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): ImportBatch {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/** The most recent batch that undid this one, if any. */
	public function findRevertOf(int $batchId): ?ImportBatch {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('reverts_id', $qb->createNamedParameter($batchId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Whether this batch's effects are currently undone.
	 *
	 * Two things a plain "has it been reverted?" gets wrong, and both leave a
	 * batch permanently beyond recovery, which is the opposite of what ADR-0009
	 * asks of the only undo the app has:
	 *
	 *  - A revert that undid NOTHING still counted. Undo an import whose rows were
	 *    all later overwritten by a second import and every entry is skipped —
	 *    yet the batch was marked spent, so once the second import was itself
	 *    undone, the first could never be.
	 *  - A revert that was ITSELF undone still counted, so import → undo →
	 *    redo left the original blocked forever.
	 *
	 * Recursion terminates because a revert always has a higher id than what it
	 * reverts.
	 */
	public function isUndone(int $batchId): bool {
		$revert = $this->findRevertOf($batchId);

		if ($revert === null || $revert->getUpdated() === 0) {
			return false;
		}

		return !$this->isUndone((int)$revert->getId());
	}

	/** @return ImportBatch[] newest first */
	public function findRecent(int $limit = 25): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'DESC')->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/** @return ImportBatch[] newest first */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'DESC');

		return $this->findEntities($qb);
	}
}
