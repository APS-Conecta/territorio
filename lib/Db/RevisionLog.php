<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use DateTime;
use OCP\IDBConnection;

/**
 * Allocates Revisions, and is already the log of what changed.
 *
 * A table rather than a counter in app config: two people saving at the same
 * moment must come away with different numbers, and an autoincrement primary key
 * is the one way to guarantee that portably across MySQL, SQLite and Postgres.
 * The allocated id IS the Revision.
 *
 * Issue #4 adds the actor and the action to these same rows. ADR-0002's cursor
 * and ADR-0005's history are deliberately one mechanism, so there is never a
 * second sequence to keep in step with this one.
 *
 * Not a QBMapper: nothing reads these rows back as entities yet, and inventing
 * an Entity for a two-column insert would be more machinery than it saves.
 */
class RevisionLog {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** Take the next Revision, recording which Feature it belongs to. */
	public function allocate(int $featureId): int {
		$insert = $this->db->getQueryBuilder();
		$insert->insert('territorio_revision')->values([
			'feature_id' => $insert->createNamedParameter($featureId),
			'changed_at' => $insert->createNamedParameter(new DateTime(), 'datetime'),
		])->executeStatement();

		return (int)$insert->getLastInsertId();
	}

	/**
	 * The newest Revision, or 0 on an install where nothing has changed yet.
	 *
	 * This is the whole cost of the common "nothing changed" poll: one indexed
	 * read of the primary key, answered without touching a Feature row.
	 */
	public function current(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('id'))->from('territorio_revision');

		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();

		return $max === false || $max === null ? 0 : (int)$max;
	}
}
