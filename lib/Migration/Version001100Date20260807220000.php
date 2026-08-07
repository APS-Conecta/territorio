<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Where a record falls: its Sector and its Unidad Vecinal, stored.
 *
 * Derived from geometry and never typed (ADR-0008). The evidence for storing
 * rather than computing per request is in the curated La Florida catastro: 393
 * of 556 places had an empty sector after a full curation effort, which is what
 * hand-assignment looks like when it meets real work.
 *
 * Stored rather than resolved on the fly so that filtering and counting by
 * Sector stay ordinary indexed reads. Recomputed when the record moves, when a
 * boundary is redrawn, and after an import.
 *
 * Nullable, and null is a valid answer: a record with no coordinate falls in
 * nothing, and so does one outside every boundary the install holds.
 */
class Version001100Date20260807220000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$features = $schema->getTable('territorio_feature');

		foreach (['sector_id', 'unidad_vecinal_id'] as $column) {
			if (!$features->hasColumn($column)) {
				$features->addColumn($column, Types::BIGINT, ['notnull' => false]);
			}
		}

		// Both are filtered and grouped on — "every Place in this Sector" and the
		// counts beside it — so they earn an index each.
		foreach ([
			'sector_id' => 'terr_feat_sector_idx',
			'unidad_vecinal_id' => 'terr_feat_uv_idx',
		] as $column => $name) {
			if (!$features->hasIndex($name)) {
				$features->addIndex([$column], $name);
			}
		}

		// The bounding-box pre-check reads these before it walks any ring, which is
		// what keeps resolving a coordinate against a comuna's worth of boundaries
		// cheap. Deferred in Version000200 with "nothing queries the box yet";
		// this is what queries it.
		if (!$features->hasIndex('terr_feat_bbox_idx')) {
			$features->addIndex(['min_lat', 'max_lat'], 'terr_feat_bbox_idx');
		}

		return $schema;
	}
}
