<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use BadFunctionCallException;
use DateTime;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Issues Revisions, and is already the log of what changed.
 *
 * The number comes from a single row in `territorio_cursor`, incremented with an
 * UPDATE that takes a row lock held until the surrounding transaction commits.
 * That is the whole design, and the reason for it is worth stating plainly
 * because the obvious alternative is wrong: an autoincrement guarantees two
 * writers get *different* numbers, but a cursor needs numbers to become
 * *visible in the order they were issued*. With an autoincrement, writer A can
 * take 5, writer B take 6 and commit first — a client polling in between banks
 * 6, and A's change, landing at 5, sits below every cursor forever. The row lock
 * removes the interleaving entirely: writers queue, so commit order is issue
 * order.
 *
 * ponytail: this serialises every write in the app behind one row. A comuna sees
 * a handful of edits a minute, so the queue is never more than one deep. If a
 * bulk import (issue #6) ever makes it a bottleneck, allocate a block of
 * Revisions per batch instead of one per row.
 *
 * Issue #4 adds the actor and the action to these same log rows.
 */
class RevisionLog {
	/** The cursor is one row, and this is it. */
	public const CURSOR_ROW = 1;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Take the next Revision, recording which Feature it belongs to.
	 *
	 * MUST be called inside a transaction that also writes the Feature. The lock
	 * this takes is only held to the end of that transaction, and it is the lock
	 * — not the number — that makes the cursor correct.
	 */
	public function allocate(int $featureId): int {
		if (!$this->db->inTransaction()) {
			// Outside a transaction the lock is released the instant the UPDATE
			// returns, so the Revision becomes visible before the Feature does —
			// and a poll landing in that gap banks a cursor past a change it was
			// never given. Refusing here makes the gap unconstructable instead of
			// merely discouraged.
			throw new BadFunctionCallException(
				'a Revision must be taken inside the transaction that writes the Feature',
			);
		}

		$bump = $this->db->getQueryBuilder();
		$bump->update('territorio_cursor')
			// Unquoted on purpose: backtick quoting is MySQL's alone and would
			// fail on Postgres, and "value" is reserved in none of the three.
			->set('value', $bump->createFunction('value + 1'))
			->where($bump->expr()->eq('id', $bump->createNamedParameter(
				self::CURSOR_ROW, IQueryBuilder::PARAM_INT,
			)));
		$bump->executeStatement();

		$revision = $this->current();

		$log = $this->db->getQueryBuilder();
		$log->insert('territorio_revision')->values([
			'id' => $log->createNamedParameter($revision, IQueryBuilder::PARAM_INT),
			'feature_id' => $log->createNamedParameter($featureId, IQueryBuilder::PARAM_INT),
			'changed_at' => $log->createNamedParameter(new DateTime(), 'datetime'),
		])->executeStatement();

		return $revision;
	}

	/**
	 * The highest Revision that is visible, which is to say committed.
	 *
	 * This is the whole cost of the common "nothing changed" poll: one row read
	 * by primary key, without touching a Feature.
	 */
	public function current(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('value')
			->from('territorio_cursor')
			->where($qb->expr()->eq('id', $qb->createNamedParameter(
				self::CURSOR_ROW, IQueryBuilder::PARAM_INT,
			)));

		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return $value === false || $value === null ? 0 : (int)$value;
	}
}
