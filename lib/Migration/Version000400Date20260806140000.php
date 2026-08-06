<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Retirement, and history on the rows that already carry the Revision.
 *
 * ADR-0005: deleting a Feature retires it. It leaves the map and every default
 * listing, keeps its history, and can be brought back — which is also what makes
 * a merge (issue #16) and an import revert (ADR-0009) reversible for free, since
 * both are expressed as retirement rather than removal.
 *
 * The history columns go onto `territorio_revision`, not a new table. That log
 * already exists and already carries the Revision and the moment; adding the
 * actor, the action and the diff to it is what ADR-0002 and ADR-0005 meant by
 * one mechanism rather than two.
 */
class Version000400Date20260806140000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$features = $schema->getTable('territorio_feature');
		if (!$features->hasColumn('retired')) {
			$features->addColumn('retired', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			// Every default read filters on this, so it earns an index even at a
			// comuna's volumes — unlike feature_id on the log, which nothing reads.
			$features->addIndex(['retired'], 'terr_feat_retired_idx');
		}

		$revisions = $schema->getTable('territorio_revision');
		if (!$revisions->hasColumn('action')) {
			// created | updated | retired | restored
			$revisions->addColumn('action', Types::STRING, ['notnull' => true, 'default' => 'updated', 'length' => 16]);
			// Null for anything the system did without a person behind it — an occ
			// import, say. Null means "nobody", not "unknown".
			$revisions->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 64]);
			// JSON: {"field": {"from": …, "to": …}} for the fields that changed.
			// The previous values are the point, not decoration — ADR-0009 says an
			// import revert "restores what it overwrote", and this is where that
			// comes from.
			$revisions->addColumn('changes', Types::TEXT, ['notnull' => false]);
		}

		return $schema;
	}
}
