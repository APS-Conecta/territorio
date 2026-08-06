<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use BadFunctionCallException;
use DateTime;
use OCA\Territorio\Service\Action;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * Issues Revisions, and is the history of what changed.
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
 * ADR-0005: history is append-only and is never edited to correct a mistake.
 */
class RevisionLog {
	/** The cursor is one row, and this is it. */
	public const CURSOR_ROW = 1;

	public function __construct(
		private IDBConnection $db,
		private IUserSession $userSession,
	) {
	}

	/**
	 * Take the next Revision and record what it did.
	 *
	 * MUST be called inside a transaction that also writes the Feature. The lock
	 * this takes is only held to the end of that transaction, and it is the lock
	 * — not the number — that makes the cursor correct.
	 *
	 * `$describe` is a callback, not an array, and that is the whole point: it is
	 * invoked AFTER the lock is taken, so the "before" values it reports are the
	 * ones this write is actually about to overwrite. Computing them earlier
	 * reads a row another writer may still be holding — and since every writer
	 * past the first waits here, computing early is most wrong exactly when two
	 * people are editing the same record.
	 *
	 * @param ?callable(): array<string, array{from: mixed, to: mixed}> $describe
	 */
	public function allocate(int $featureId, Action $action, ?callable $describe = null): int {
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

		// Only now, holding the lock, is it safe to ask what is being overwritten.
		$changes = $describe === null ? null : $describe();

		$log = $this->db->getQueryBuilder();
		$log->insert('territorio_revision')->values([
			'id' => $log->createNamedParameter($revision, IQueryBuilder::PARAM_INT),
			'feature_id' => $log->createNamedParameter($featureId, IQueryBuilder::PARAM_INT),
			'changed_at' => $log->createNamedParameter(new DateTime(), 'datetime'),
			'action' => $log->createNamedParameter($action->value),
			'actor' => $log->createNamedParameter($this->actor()),
			'changes' => $log->createNamedParameter(
				$changes === null || $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
			),
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

	/**
	 * One Feature's history, newest first.
	 *
	 * @return list<array{revision: int, action: string, actor: ?string, changedAt: ?string, changes: ?array}>
	 */
	public function forFeature(int $featureId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'action', 'actor', 'changed_at', 'changes')
			->from('territorio_revision')
			->where($qb->expr()->eq('feature_id', $qb->createNamedParameter($featureId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');

		$result = $qb->executeQuery();
		$entries = [];
		while ($row = $result->fetch()) {
			$entries[] = [
				'revision' => (int)$row['id'],
				'action' => (string)$row['action'],
				'actor' => $row['actor'],
				'changedAt' => $row['changed_at'],
				'changes' => $row['changes'] === null ? null : json_decode((string)$row['changes'], true),
			];
		}
		$result->closeCursor();

		return $entries;
	}

	/** Null where nobody was behind the change — an occ import, for instance. */
	private function actor(): ?string {
		return $this->userSession->getUser()?->getUID();
	}
}
