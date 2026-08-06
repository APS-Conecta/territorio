<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Ties each Revision to the import that made it, if any.
 *
 * The batch used to be described by a range of Revisions, `(from, to]`, read at
 * either end of the run. That is a clock window, not a set: every write in the
 * app is serialised behind one cursor row, so a 538-row import takes long enough
 * that a colleague saving a record lands squarely inside the window. ADR-0009
 * requires a revert to restore what the batch overwrote "touching nothing else",
 * and a revert driven by that window would have retired the colleague's edit.
 *
 * A stamp on the row is the set. The range stays on the batch, but only as
 * something to show a person — never as the thing a revert acts on.
 */
class Version000700Date20260806220000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$revisions = $schema->getTable('territorio_revision');
		if (!$revisions->hasColumn('import_id')) {
			// Null for everything a person did directly, which is most of it.
			$revisions->addColumn('import_id', Types::BIGINT, ['notnull' => false]);
			// Issue #7 reverts by reading exactly this.
			$revisions->addIndex(['import_id'], 'terr_rev_import_idx');
		}

		return $schema;
	}
}
