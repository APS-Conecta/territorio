<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use BadFunctionCallException;
use DateTime;
use OCA\Territorio\Domain\Action;
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

	/**
	 * The import currently running, if one is. Every Revision taken while it is
	 * set is stamped with it, which is what makes a batch a set of rows rather
	 * than a window of time somebody else's edit can fall into.
	 */
	private ?int $importId = null;

	public function __construct(
		private IDBConnection $db,
		private IUserSession $userSession,
	) {
	}

	/**
	 * Run $work with every Revision it takes attributed to $importId.
	 *
	 * Ambient context, kept deliberately (L1-14): threading a batch id through
	 * every mapper write would touch six signatures to avoid one instance field,
	 * and the field is correct under Nextcloud's per-request container — this
	 * service is never shared across requests, and the set/restore pair below is
	 * what makes nesting honest. This is the IMPORT-RUN scope: set by the one way
	 * in (ImportService::apply), by a revert, and by a reassignment sweep's own
	 * synthetic batch (L1-16) — a Revision stamped here belongs to a batch that
	 * can be undone, which is the whole reason the field exists.
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	public function duringImport(int $importId, callable $work): mixed {
		$previous = $this->importId;
		$this->importId = $importId;
		try {
			return $work();
		} finally {
			// Restored rather than nulled: if an import ever nests, the outer one
			// must not silently lose its own attribution.
			$this->importId = $previous;
		}
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
			'import_id' => $log->createNamedParameter($this->importId, IQueryBuilder::PARAM_INT),
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
	 * @return list<array{revision: int, action: string, actor: ?string, changedAt: string, changes: ?array}>
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
				// UTC wall-clock time with no zone: Nextcloud forces the process to UTC,
				// and allocate() writes `new DateTime()` in it (#211).
				'changedAt' => (new \DateTimeImmutable((string)$row['changed_at'], new \DateTimeZone('UTC')))
					->format(\DateTimeInterface::ATOM),
				'changes' => $row['changes'] === null ? null : json_decode((string)$row['changes'], true),
			];
		}
		$result->closeCursor();

		return $entries;
	}

	/**
	 * Everything one batch did, newest first.
	 *
	 * Newest first, which is the right order for undoing. A revert writes a
	 * Revision as it goes, so once the newest entry for a record is undone, the
	 * next entry for that same record finds the revert's own write sitting on it
	 * — which is why RevertService counts its own batch as part of the lineage it
	 * is allowed to walk past. Without that, the older entry looked like a
	 * colleague's work and was reported as left alone.
	 *
	 * An earlier version of this comment said no import touches one record twice,
	 * because duplicate rows are collapsed before anything is written. That was
	 * true of duplicate rows and missed the other way it happens: importing a
	 * boundary reassigns every Place it covers (ADR-0008), so one file holding a
	 * Place and a Sector over it gives that Place a Created and an Updated entry
	 * in the same batch. Issue #30.
	 *
	 * Read by `import_id`, never by a range of Revision numbers — writes are
	 * serialised behind one cursor, so a range would sweep up whatever a
	 * colleague saved while the import was running.
	 *
	 * @return list<array{revision: int, featureId: int, action: string, changes: ?array}>
	 */
	public function forImport(int $importId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'feature_id', 'action', 'changes')
			->from('territorio_revision')
			->where($qb->expr()->eq('import_id', $qb->createNamedParameter($importId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');

		$result = $qb->executeQuery();
		$entries = [];
		while ($row = $result->fetch()) {
			$entries[] = [
				'revision' => (int)$row['id'],
				'featureId' => (int)$row['feature_id'],
				'action' => (string)$row['action'],
				'changes' => $row['changes'] === null ? null : json_decode((string)$row['changes'], true),
			];
		}
		$result->closeCursor();

		return $entries;
	}

	/**
	 * The newest Revision affecting one Feature.
	 *
	 * A revert compares this against the Revision it is about to undo: if
	 * something newer exists, the record has been touched since and undoing it
	 * would silently discard whatever that was.
	 *
	 * The batch that made the newest Revision comes back too, because "touched
	 * since" has an exception: a batch's own undo, and the undo of that undo, are
	 * not somebody else's work. Without knowing which batch touched it, undo →
	 * redo → undo is refused as though a colleague had intervened.
	 */
	public function newestFor(int $featureId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'import_id')
			->from('territorio_revision')
			->where($qb->expr()->eq('feature_id', $qb->createNamedParameter($featureId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row === false
			? ['revision' => 0, 'importId' => null]
			: ['revision' => (int)$row['id'], 'importId' => $row['import_id'] === null ? null : (int)$row['import_id']];
	}

	/** Null where nobody was behind the change — an occ import, for instance. */
	private function actor(): ?string {
		return $this->userSession->getUser()?->getUID();
	}
}
