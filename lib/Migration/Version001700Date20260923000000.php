<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * territorio_import.dataset_id becomes nullable (L1-16).
 *
 * A reassignment sweep records a batch with no Dataset — that null is what
 * marks it, and ImportService::recentBatches() labels it «reasignación de
 * límite» so the person reading the batch list knows which kind of undo this
 * one is. Existing rows are untouched; the column only learns to hold null.
 *
 * Guarded in the seed's own sense: on a schema that already carries the
 * relaxation, the check below changes nothing and the step reports clean.
 */
class Version001700Date20260923000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$column = $schema->getTable('territorio_import')->getColumn('dataset_id');
		if (!$column->getNotnull()) {
			return null;
		}

		$column->setNotnull(false);

		return $schema;
	}
}
