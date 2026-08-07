<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Who took contact details out of the building, and when (ADR-0006, issue #17).
 *
 * A table of its own rather than a row in `territorio_revision` — ADR-0012. That
 * table is the change cursor every open browser polls, and an export changes
 * nothing: logging one there would make twenty maps in a clinic refetch and
 * re-render for a change that does not exist.
 *
 * Nothing here identifies WHICH records were exported, only how many. An export
 * is an act on a set, and storing hundreds of ids per export would grow without
 * bound to answer a question nobody has asked.
 */
class Version001400Date20260808180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('territorio_export')) {
			$table = $schema->createTable('territorio_export');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			// Not nullable, unlike a Revision's actor. A Revision can come from
			// `occ`, which has no logged-in user; an export cannot — it exists only
			// as a request from a person who has just re-entered their password.
			$table->addColumn('actor', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('exported_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('format', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('records', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			// Every row here has contacts today (ADR-0012: only those are logged).
			// The column is here so that the report says what it means rather than
			// relying on the reader knowing that rule.
			$table->addColumn('contacts', Types::BOOLEAN, ['notnull' => true, 'default' => true]);

			$table->setPrimaryKey(['id']);
			// The report reads newest first and nothing else reads this at all.
			$table->addIndex(['exported_at'], 'terr_export_when_idx');
		}

		return $schema;
	}
}
