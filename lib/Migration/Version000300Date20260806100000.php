<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The Revision — the one number that answers both "what has changed since I
 * last looked" and, once issue #4 lands, "who changed this, and when".
 *
 * `territorio_cursor` is a single row holding the highest Revision handed out.
 * It is deliberately NOT an autoincrement: autoincrement guarantees that two
 * writers get different numbers, which is not the property a cursor needs.
 * A cursor needs numbers to become *visible in the order they were issued*, and
 * autoincrement gives no such promise — writer A can take 5, writer B take 6 and
 * commit first, and a client that polls in between banks 6 and never asks for 5
 * again. Incrementing this row takes a row lock held to commit, so writers
 * serialise and commit order matches issue order.
 *
 * `territorio_revision` is the change log, keyed by that Revision. Issue #4 adds
 * the actor and the action to these same rows rather than introducing a second
 * counter: ADR-0002 and ADR-0005 are served by one mechanism, not two.
 *
 * The single cursor row is created by the EnsureSeedData repair step, not here —
 * a migration's postSchemaChange is skipped on a fresh install.
 */
class Version000300Date20260806100000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('territorio_cursor')) {
			$table = $schema->createTable('territorio_cursor');
			// One row, forever. A primary key it does not need, but every table
			// gets one and a fixed id makes the "there is only one" explicit.
			$table->addColumn('id', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('value', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('territorio_revision')) {
			$table = $schema->createTable('territorio_revision');
			// Not autoincrement: the Revision comes from territorio_cursor, so
			// that it is issued and committed under one lock.
			$table->addColumn('id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('feature_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('changed_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			// No index on feature_id. Nothing reads it until issue #4 builds the
			// history view, and an index is paid for on every single write — this
			// is the hottest write path in the app.
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
