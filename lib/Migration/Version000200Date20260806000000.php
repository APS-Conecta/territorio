<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
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
 * change cursor (issue #3).
 */
class Version000200Date20260806000000 extends SimpleMigrationStep {
	/**
	 * The taxonomy an install starts with: the eleven categories, each with the
	 * subcategories the La Florida catastro actually used, plus `taller`, which
	 * the clinic asked for. It is a starting point, not a fixed list — a Category
	 * is editable data, and adding a row makes it appear in the Capas panel.
	 *
	 * @var array<string, array{label: string, color: string, subcategories: array<string, string>}>
	 */
	private const TAXONOMY = [
		'salud' => ['label' => 'Salud', 'color' => '#dc2626', 'subcategories' => [
			'cesfam' => 'CESFAM',
			'sapu' => 'SAPU',
			'ccr' => 'CCR',
			'salud_mental' => 'Salud mental',
			'farmacia' => 'Farmacia',
			'red_derivacion' => 'Red de derivación',
			'establecimiento' => 'Establecimiento de salud',
			'otro' => 'Otro',
		]],
		'educacion' => ['label' => 'Educación', 'color' => '#2563eb', 'subcategories' => [
			'colegio' => 'Colegio',
			'jardin_infantil' => 'Jardín infantil',
			'otro' => 'Otro',
		]],
		'deporte_recreacion' => ['label' => 'Deporte y recreación', 'color' => '#16a34a', 'subcategories' => [
			'plaza' => 'Plaza o parque',
			'multicancha' => 'Multicancha',
			'otro' => 'Otro',
		]],
		'seguridad' => ['label' => 'Seguridad', 'color' => '#4338ca', 'subcategories' => [
			'carabineros' => 'Carabineros',
			'bomberos' => 'Bomberos',
			'otro' => 'Otro',
		]],
		'medioambiente' => ['label' => 'Medioambiente', 'color' => '#059669', 'subcategories' => [
			'punto_limpio' => 'Punto limpio',
			'otro' => 'Otro',
		]],
		'organizacion_social' => ['label' => 'Organización social', 'color' => '#ea580c', 'subcategories' => [
			'junta_de_vecinos' => 'Junta de vecinos',
			'club_adulto_mayor' => 'Club de adulto mayor',
			'taller' => 'Taller',
			'centro_comunitario' => 'Centro comunitario',
			'otro' => 'Otro',
		]],
		'servicio_publico' => ['label' => 'Servicio público', 'color' => '#0891b2', 'subcategories' => [
			'municipalidad' => 'Municipalidad',
			'otro' => 'Otro',
		]],
		'movilidad_transporte' => ['label' => 'Movilidad y transporte', 'color' => '#0d9488', 'subcategories' => [
			'ciclovia' => 'Ciclovía',
			'paradero' => 'Paradero',
			'otro' => 'Otro',
		]],
		'religion' => ['label' => 'Religión', 'color' => '#7c3aed', 'subcategories' => [
			'iglesia' => 'Iglesia',
			'otro' => 'Otro',
		]],
		'comercio_economia' => ['label' => 'Comercio y economía', 'color' => '#ca8a04', 'subcategories' => [
			'feria' => 'Feria',
			'otro' => 'Otro',
		]],
		'sin_clasificar' => ['label' => 'Sin clasificar', 'color' => '#6b7280', 'subcategories' => [
			'otro' => 'Otro',
		]],
	];

	public function __construct(
		private IDBConnection $db,
	) {
	}

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

	/**
	 * Seed the taxonomy, once. Guarded on the table being empty rather than on
	 * the migration having run, so re-running against a populated install cannot
	 * duplicate a Category — and so a clinic that has renamed or deleted one does
	 * not get it silently restored.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$existing = $this->db->getQueryBuilder();
		$existing->select($existing->func()->count('*', 'total'))->from('territorio_category');
		$result = $existing->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();

		if ($total > 0) {
			$output->info('Territorio: taxonomy already present, leaving it alone');
			return;
		}

		foreach (self::TAXONOMY as $slug => $category) {
			$insert = $this->db->getQueryBuilder();
			$insert->insert('territorio_category')->values([
				'slug' => $insert->createNamedParameter($slug),
				'label' => $insert->createNamedParameter($category['label']),
				'color' => $insert->createNamedParameter($category['color']),
			])->executeStatement();
			$categoryId = $insert->getLastInsertId();

			foreach ($category['subcategories'] as $subSlug => $subLabel) {
				$sub = $this->db->getQueryBuilder();
				$sub->insert('territorio_subcategory')->values([
					'category_id' => $sub->createNamedParameter($categoryId),
					'slug' => $sub->createNamedParameter($subSlug),
					'label' => $sub->createNamedParameter($subLabel),
				])->executeStatement();
			}
		}

		$output->info(sprintf('Territorio: seeded %d categories', count(self::TAXONOMY)));
	}
}
