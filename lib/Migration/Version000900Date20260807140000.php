<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A Sector carries its own colour.
 *
 * Colour is per-Category everywhere else, and that is right for a taxonomy: every
 * colegio is the same blue. Sectores are the exception — they all share one
 * Subcategory and a clinic tells them apart precisely by colour, so the colour
 * has to live on the record.
 *
 * Nullable, and null means "take the Category's colour". No backfill: every
 * record that existed before this migration is one that should keep doing that.
 */
class Version000900Date20260807140000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$features = $schema->getTable('territorio_feature');
		if (!$features->hasColumn('colour')) {
			// 7 characters: '#rrggbb', which is what the service accepts.
			$features->addColumn('colour', Types::STRING, ['notnull' => false, 'length' => 7]);
		}

		return $schema;
	}
}
