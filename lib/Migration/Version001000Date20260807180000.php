<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A Dataset can hold Boundary features rather than places.
 *
 * The comuna's named streets, waterways and railways are around eleven thousand
 * lines for La Florida. They are Features like anything else and go through the
 * same import, but they are not the map: they are what a Sector's Limit runs
 * along, wanted while a boundary is being drawn and never before. Marking the
 * Dataset is what keeps them out of every ordinary read at once — the map, the
 * Datos list, the counts and the change feed — instead of each of those learning
 * to skip them separately.
 *
 * Defaults to false, so every Dataset that already exists stays exactly what it
 * was.
 */
class Version001000Date20260807180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$datasets = $schema->getTable('territorio_dataset');
		if (!$datasets->hasColumn('boundary')) {
			$datasets->addColumn('boundary', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		}

		return $schema;
	}
}
