<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The queue of records that might be the same place, and where a merged one went
 * (issue #16).
 *
 * A pair is a row rather than a computed answer because the decisions taken
 * about it have to survive: "these two are not duplicates" is a judgement nobody
 * should have to make twice, and a queue that re-offered a dismissed pair after
 * every import would be abandoned within a week.
 *
 * `merged_into` sits on the Feature, not on the pair, because it is the mapping
 * itself — the thing `fonasa_eliminados.csv` was keeping by hand. It has to be
 * answerable from the retired record ("where did this go?") without knowing
 * which pair, if any, was the occasion for the merge.
 */
class Version001300Date20260808140000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$features = $schema->getTable('territorio_feature');
		if (!$features->hasColumn('merged_into')) {
			$features->addColumn('merged_into', Types::BIGINT, ['notnull' => false]);
		}

		if (!$schema->hasTable('territorio_duplicate')) {
			$table = $schema->createTable('territorio_duplicate');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			// Always the lower id first, which is what makes the unique index below
			// able to say "this pair is already known" whichever order it arrives in.
			$table->addColumn('first_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('second_id', Types::BIGINT, ['notnull' => true]);
			// Why they were put together — a coordinate or a name. The reviewer is
			// answering a different question in each case.
			$table->addColumn('reason', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('status', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'pending',
			]);
			$table->addColumn('found_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('resolved_by', Types::STRING, ['notnull' => false, 'length' => 64]);

			$table->setPrimaryKey(['id']);
			// A pair is known once. Without this, every re-import would offer the
			// same pairs again — including the ones somebody already dismissed.
			$table->addUniqueIndex(['first_id', 'second_id'], 'terr_dup_pair_idx');
			// The queue's own read: the outstanding ones.
			$table->addIndex(['status'], 'terr_dup_status_idx');
		}

		return $schema;
	}
}
