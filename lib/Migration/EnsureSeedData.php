<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use OCA\Territorio\Db\RevisionLog;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Puts the rows an install cannot function without into place.
 *
 * A repair step, NOT a migration's `postSchemaChange`, and the distinction is
 * not academic: `MigrationService::executeStep()` skips both `preSchemaChange`
 * and `postSchemaChange` when it is called with `$schemaOnly`, which is exactly
 * how a first-time `occ app:enable` applies an app's schema. Seeds written there
 * run on every upgrade and on no fresh install — the one case that matters most,
 * and the one a developer upgrading their own dev instance never sees.
 *
 * Registered for both `install` and `post-migration` in `appinfo/info.xml`.
 * Everything here is guarded and idempotent, so running it repeatedly is safe,
 * and a clinic that has renamed or deleted a Category does not get it silently
 * restored.
 */
class EnsureSeedData implements IRepairStep {
	/**
	 * The taxonomy an install starts with: the eleven categories, each with the
	 * subcategories the La Florida catastro actually used, plus `taller`, which
	 * the clinic asked for. A starting point, not a fixed list — a Category is
	 * editable data, and adding a row makes it appear in the app.
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

	public function getName(): string {
		return 'Territorio: ensure the taxonomy and the revision cursor exist';
	}

	public function run(IOutput $output): void {
		$this->ensureCursor($output);
		$this->ensureTaxonomy($output);
	}

	/** Without this row the first write has nothing to increment. */
	private function ensureCursor(IOutput $output): void {
		if (!$this->db->tableExists('territorio_cursor') || $this->hasRows('territorio_cursor')) {
			return;
		}

		$insert = $this->db->getQueryBuilder();
		$insert->insert('territorio_cursor')->values([
			'id' => $insert->createNamedParameter(RevisionLog::CURSOR_ROW),
			'value' => $insert->createNamedParameter(0),
		])->executeStatement();

		$output->info('Territorio: revision cursor initialised');
	}

	private function ensureTaxonomy(IOutput $output): void {
		if (!$this->db->tableExists('territorio_category') || $this->hasRows('territorio_category')) {
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

	private function hasRows(string $table): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table);
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}
}
