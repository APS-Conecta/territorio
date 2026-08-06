<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The Revision sequence — the one number that answers both "what has changed
 * since I last looked" and, once issue #4 lands, "who changed this, and when".
 *
 * `territorio_revision` is the allocator: its autoincrement id IS the Revision.
 * A dedicated table rather than a counter in app config because two people
 * saving at the same moment must get different numbers, and an autoincrement is
 * the one portable way to guarantee that across MySQL, SQLite and Postgres.
 *
 * It is also already the change log. Issue #4 adds the actor and the action to
 * these same rows rather than starting a second sequence — ADR-0002 and ADR-0005
 * are deliberately served by one mechanism, not two.
 */
class Version000300Date20260806100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('territorio_revision')) {
			$table = $schema->createTable('territorio_revision');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('feature_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('changed_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['feature_id'], 'terr_rev_feature_idx');
		}

		$features = $schema->getTable('territorio_feature');
		if (!$features->hasColumn('revision')) {
			// Existing rows stay at 0, and a client's opening cursor is the current
			// Revision, so nothing already on screen is replayed as "new". 0 means
			// "never changed since this column existed", which is the truth.
			$features->addColumn('revision', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			// The cursor query is `where revision > ?` and nothing else — this is
			// the index that keeps the common "nothing changed" answer cheap.
			$features->addIndex(['revision'], 'terr_feat_revision_idx');
		}

		return $schema;
	}
}
