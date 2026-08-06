<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The Registry's first three tables: the Category/Subcategory taxonomy, and the
 * Features themselves.
 *
 * Geometry is GeoJSON text with a bounding box in four plain columns. There is
 * no PostGIS on the target database and a Nextcloud app must run on MySQL,
 * SQLite and Postgres alike, so there is no geometry type and no spatial index
 * to use — the box is what "what is in view" filters on.
 *
 * Absent on purpose: `sector_id` and `unidad_vecinal_id` are derived from
 * geometry, not stored as editable fields (ADR-0008), and arrive with the
 * boundaries they are derived from. Provenance columns (`dataset_id`,
 * `external_id`) arrive with import (issue #6); `revision` arrives with the
 * change cursor (issue #3). The taxonomy itself is seeded by the EnsureSeedData
 * repair step, not here: a migration's postSchemaChange is skipped entirely on a
 * fresh install. Retirement — the column ADR-0005 needs so that a
 * delete hides a record instead of destroying it — arrives with history in
 * issue #4; until then `FeatureMapper::delete()` refuses outright, so nothing
 * can be lost in the meantime.
 */
class Version000200Date20260806000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('territorio_category')) {
			$table = $schema->createTable('territorio_category');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 128]);
			// Hex, drawn as the swatch beside the Category in the Capas panel.
			$table->addColumn('color', Types::STRING, ['notnull' => true, 'length' => 7]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['slug'], 'terr_cat_slug_idx');
		}

		if (!$schema->hasTable('territorio_subcategory')) {
			$table = $schema->createTable('territorio_subcategory');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('category_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->setPrimaryKey(['id']);
			// Unique within its Category, not globally: nearly every Category ends
			// in an "Otro", and they are different things.
			$table->addUniqueIndex(['category_id', 'slug'], 'terr_subcat_slug_idx');
		}

		if (!$schema->hasTable('territorio_feature')) {
			$table = $schema->createTable('territorio_feature');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('subcategory_id', Types::BIGINT, ['notnull' => true]);
			// place | zone | route — derived from the geometry, never typed.
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			// Null is meaningful and common: the catastro holds real organisations
			// whose address never resolved to a coordinate. They are valid records
			// that appear in Datos and not on the map.
			$table->addColumn('geometry', Types::TEXT, ['notnull' => false]);
			$table->addColumn('min_lat', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('min_lon', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('max_lat', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('max_lon', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('address', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('phone', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('email', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('contact_person', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('contact_role', Types::STRING, ['notnull' => false, 'length' => 128]);
			// exacta | aproximada | sin_coordenada — how well the location is known.
			$table->addColumn('location_quality', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('opening_hours', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$table->addColumn('source_ref', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['subcategory_id'], 'terr_feat_subcat_idx');
			$table->addIndex(['kind'], 'terr_feat_kind_idx');
			// ponytail: no bounding-box index. One comuna holds hundreds of rows,
			// where a scan costs nothing; add one when a viewport query is slow
			// against real data, not before.
		}

		return $schema;
	}

}
