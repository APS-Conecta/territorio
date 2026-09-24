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
	 * Two things a plain "has it been reverted?" gets wrong — both spelled in
	 * the original docblock, unchanged: a revert that undid NOTHING still
	 * counted, and a revert that was ITSELF undone still counted, and both leave
	 * a batch permanently beyond recovery.
	 *
	 * The lineage is resolved with ONE query now (L1-17): every revert row,
	 * read once, walked in memory. The recursion this replaces took a SELECT
	 * per chain link — this runs once per entry in the import dialog's list, 25
	 * times per open, growing with undo depth.
	 *
	 * The walk terminates on the same invariant the recursion did: a revert
	 * always carries a higher id than what it reverts.
	 */
	public function isUndone(int $batchId): bool {
		$revertOf = [];
		foreach ($this->findAllReverts() as $revert) {
			// Newest wins — the one findRevertOf() used to pick per link.
			$revertOf[(int)$revert->getRevertsId()] ??= $revert;
		}

		// Walk the chain in memory, flipping the answer at each link: undone,
		// undone-then-redone, undone-then-redone-then-undone — the parity the
		// recursion computed one SELECT at a time.
		$undone = false;
		$current = $batchId;
		while (isset($revertOf[$current])) {
			$revert = $revertOf[$current];
			if ($revert->getUpdated() === 0) {
				// A no-op link STOPS the walk and keeps the parity accumulated so
				// far — it is not a global false. The recursion answered `false` AT
				// THIS NODE and let the caller flip it; `return false` here would
				// discard those flips (import → undo → no-op redo mis-reads as not
				// undone). `break` is the faithful translation.
				break;
			}

			$undone = !$undone;
			$current = (int)$revert->getId();
		}

		return $undone;
	}

	/** @return ImportBatch[] newest first — every revert row there is (L1-17). */
	private function findAllReverts(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('reverts_id'))
			->orderBy('id', 'DESC');

		return $this->findEntities($qb);
	}

	/** @return ImportBatch[] newest first */
	public function findRecent(int $limit = 25): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'DESC')->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/** Deleted — ImportBatchMapper::findAll(): zero callers anywhere (L1-18).
	 *  The only consumer of batches is findRecent(). Wire it back when an
	 *  admin report needs the whole list. */
}
