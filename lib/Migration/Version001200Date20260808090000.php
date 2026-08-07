<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * An index for the one read that asks the database about a bounding box.
 *
 * The Sector editor loads the Boundary features on screen and nothing else
 * (issue #13, ADR-0007): a comuna is around eleven thousand lines, and the box
 * is what turns "all of them" into "the few hundred you can see".
 *
 * Deferred twice before this on purpose — Version000200 said "nothing queries
 * the box yet" and it was still true when the derived assignment landed, which
 * filters in PHP over a set the app has already read. This is the query.
 */
class Version001200Date20260808090000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$features = $schema->getTable('territorio_feature');

		// Latitude first: it is the narrower of the two in a comuna-shaped box, so
		// it discards more rows before longitude is looked at.
		if (!$features->hasIndex('terr_feat_bbox_idx')) {
			$features->addIndex(['min_lat', 'max_lat'], 'terr_feat_bbox_idx');
		}

		return $schema;
	}
}
