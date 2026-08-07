<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A Sector's Limit: what it runs along, in words (issue #15).
 *
 * Stored beside the geometry rather than derived from it, because they answer
 * different questions and stop agreeing on purpose. The geometry is the line the
 * teams agreed on; the Limit is what somebody says when asked where the sector
 * ends — "Vicuña Mackenna, el canal, y por detrás del colegio". Dragging a
 * vertex changes the first and must not touch the second.
 *
 * TEXT holding JSON, like `geometry` and `official` beside it. A table of parts
 * would let the database enforce the reference to a Boundary feature, which
 * sounds better than it is: a Limit deliberately outlives the features it names
 * — the names are kept precisely so it still reads after a re-import — and half
 * its parts reference nothing at all, because no dataset holds them.
 */
class Version001500Date20260808220000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$features = $schema->getTable('territorio_feature');
		if (!$features->hasColumn('boundary_limit')) {
			// Not `limit`: it is a reserved word in every database this could run
			// on, and a column that needs quoting everywhere is a column somebody
			// eventually forgets to quote.
			$features->addColumn('boundary_limit', Types::TEXT, ['notnull' => false]);
		}

		return $schema;
	}
}
