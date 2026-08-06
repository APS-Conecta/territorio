<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A batch can be the undoing of another batch.
 *
 * ADR-0009 asks that a revert be itself a change in history, so that a revert
 * can be reverted. Recording the revert as an ordinary batch — one whose
 * Revisions are stamped exactly like an import's — is what makes that true
 * without a second mechanism: undoing a revert is the same code walking a
 * different batch.
 */
class Version000800Date20260807090000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$imports = $schema->getTable('territorio_import');
		if (!$imports->hasColumn('reverts_id')) {
			// Null for a real import. Set to the batch this one undid.
			$imports->addColumn('reverts_id', Types::BIGINT, ['notnull' => false]);
			// Read to answer "has this batch already been reverted?", which is the
			// question that stops someone undoing the same run twice.
			$imports->addIndex(['reverts_id'], 'terr_import_reverts_idx');
		}

		return $schema;
	}
}
