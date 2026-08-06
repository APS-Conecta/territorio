<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Datasets, import batches, and the provenance a Feature needs to survive
 * re-import.
 *
 * ADR-0003: a record someone has edited is not overwritten by a later import,
 * and the incoming official value is kept beside the override so "official vs
 * local" stays answerable and the override can be undone. That is what
 * `overridden` and `official` are for — dropping the official value would make
 * the override permanent in practice, because nothing would remember what the
 * authority actually said.
 *
 * ADR-0009: every run is recorded with the range of Revisions it produced, and
 * that range cannot be reconstructed afterwards. Issue #7 reverts a batch by
 * reading it.
 */
class Version000600Date20260806180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('territorio_dataset')) {
			$table = $schema->createTable('territorio_dataset');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 128]);
			// cadastral | curated — see CONTEXT.md. Only a Cadastral Dataset is
			// ever re-imported, and only its records can be overridden.
			$table->addColumn('origin', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['slug'], 'terr_dataset_slug_idx');
		}

		if (!$schema->hasTable('territorio_import')) {
			$table = $schema->createTable('territorio_import');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('dataset_id', Types::BIGINT, ['notnull' => true]);
			// Null where nobody was behind it. An occ run names itself with --actor.
			$table->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('started_at', Types::DATETIME, ['notnull' => true]);
			// The Revision immediately BEFORE the run, and the last one it made.
			// Everything the batch did lives in (revision_from, revision_to].
			$table->addColumn('revision_from', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('revision_to', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('created', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('updated', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('skipped', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			// Rows the file itself repeated verbatim. The La Florida catastro has
			// 18 of them across 865 features; collapsing is right, silence is not.
			$table->addColumn('duplicates', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('unsupported', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			// Rows already present and identical. A re-import of an unchanged file
			// is all of these and writes nothing at all — which is what makes
			// "running it twice changes nothing" literally true.
			$table->addColumn('unchanged', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['dataset_id'], 'terr_import_dataset_idx');
		}

		$features = $schema->getTable('territorio_feature');
		if (!$features->hasColumn('dataset_id')) {
			// Null for a record typed into the app by hand: it belongs to no
			// imported Dataset and nothing will ever republish it.
			$features->addColumn('dataset_id', Types::BIGINT, ['notnull' => false]);
			$features->addColumn('external_id', Types::STRING, ['notnull' => false, 'length' => 128]);
			// The last values the authority published, kept even when a local edit
			// wins, so the two can be compared and the override undone (ADR-0003).
			$features->addColumn('official', Types::TEXT, ['notnull' => false]);
			$features->addColumn('overridden', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			// What a re-import matches on. Unique per Dataset, not globally: two
			// authorities may legitimately use the same id for different things.
			$features->addUniqueIndex(['dataset_id', 'external_id'], 'terr_feat_external_idx');
		}

		return $schema;
	}
}
