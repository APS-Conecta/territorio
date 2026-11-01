<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Restoring the losing record must also undo the survivor edits made by that merge. */
class Version001800Date20261101000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$pairs = $schema->getTable('territorio_duplicate');
		if ($pairs->hasColumn('merge_snapshot')) return null;
		$pairs->addColumn('merge_snapshot', Types::TEXT, ['notnull' => false]);

		return $schema;
	}
}
