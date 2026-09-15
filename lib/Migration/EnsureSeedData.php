<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use OCA\Territorio\Db\RevisionLog;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Puts the rows an install cannot function without into place, and carries a
 * change to the seeded taxonomy to installs that already have one.
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
	 * `was` is the colour the seed gave that Category BEFORE the designed ramp, and
	 * is what `ensureRamp()` recognises on an install that already exists. It sits
	 * on the row rather than in a list of its own so the two cannot disagree. A
	 * Category added later has no `was` and is never repainted, which is correct:
	 * it never had an old colour.
	 *
	 * @var array<string, array{label: string, color: string, was?: string, subcategories: array<string, string>}>
	 */
	private const TAXONOMY = [
		'salud' => ['label' => 'Salud', 'color' => '#99564e', 'was' => '#dc2626', 'subcategories' => [
			'cesfam' => 'CESFAM',
			'sapu' => 'SAPU',
			'ccr' => 'CCR',
			'salud_mental' => 'Salud mental',
			'farmacia' => 'Farmacia',
			'red_derivacion' => 'Red de derivación',
			'establecimiento' => 'Establecimiento de salud',
			'otro' => 'Otro',
		]],
		'educacion' => ['label' => 'Educación', 'color' => '#6d7db6', 'was' => '#2563eb', 'subcategories' => [
			'colegio' => 'Colegio',
			'jardin_infantil' => 'Jardín infantil',
			'otro' => 'Otro',
		]],
		'deporte_recreacion' => ['label' => 'Deporte y recreación', 'color' => '#549065', 'was' => '#16a34a', 'subcategories' => [
			'plaza' => 'Plaza o parque',
			'multicancha' => 'Multicancha',
			'otro' => 'Otro',
		]],
		'seguridad' => ['label' => 'Seguridad', 'color' => '#5a68a0', 'was' => '#4338ca', 'subcategories' => [
			'carabineros' => 'Carabineros',
			'bomberos' => 'Bomberos',
			'otro' => 'Otro',
		]],
		'medioambiente' => ['label' => 'Medioambiente', 'color' => '#407b51', 'was' => '#059669', 'subcategories' => [
			'punto_limpio' => 'Punto limpio',
			'otro' => 'Otro',
		]],
		'organizacion_social' => ['label' => 'Organización social', 'color' => '#b06b62', 'was' => '#ea580c', 'subcategories' => [
			'junta_de_vecinos' => 'Junta de vecinos',
			'club_adulto_mayor' => 'Club de adulto mayor',
			'taller' => 'Taller',
			'centro_comunitario' => 'Centro comunitario',
			'otro' => 'Otro',
		]],
		'servicio_publico' => ['label' => 'Servicio público', 'color' => '#0b798a', 'was' => '#0891b2', 'subcategories' => [
			'municipalidad' => 'Municipalidad',
			'otro' => 'Otro',
		]],
		'movilidad_transporte' => ['label' => 'Movilidad y transporte', 'color' => '#2f8e9f', 'was' => '#0d9488', 'subcategories' => [
			'ciclovia' => 'Ciclovía',
			'paradero' => 'Paradero',
			'otro' => 'Otro',
		]],
		'religion' => ['label' => 'Religión', 'color' => '#916393', 'was' => '#7c3aed', 'subcategories' => [
			'iglesia' => 'Iglesia',
			'otro' => 'Otro',
		]],
		'comercio_economia' => ['label' => 'Comercio y economía', 'color' => '#8a7431', 'was' => '#ca8a04', 'subcategories' => [
			'feria' => 'Feria',
			'otro' => 'Otro',
		]],
		'sin_clasificar' => ['label' => 'Sin clasificar', 'color' => '#71777c', 'was' => '#6b7280', 'subcategories' => [
			'otro' => 'Otro',
		]],
	];

	/**
	 * The territorial divisions a CESFAM draws or imports, which are Features
	 * like any other and so need somewhere in the taxonomy to live.
	 *
	 * Unlike the eleven above, this one is structural: without it there is no
	 * Subcategory to file a Sector under and the app cannot save one. So it is
	 * ensured on every run and restored if it is deleted, where an ordinary
	 * Category a clinic removed stays removed.
	 *
	 * The panel does not draw these in the Category tree — CONTEXT.md puts one
	 * toggle per territorial division beside the tree, not inside it.
	 *
	 * @var array{label: string, color: string, subcategories: array<string, string>}
	 */
	private const DIVISIONS = [
		'label' => 'División territorial',
		/*
		 * Inert, and left alone on purpose (issue #29).
		 *
		 * Nothing renders it. A territorial division is not drawn as a dot
		 * anywhere: on the map a Unidad Vecinal is a dashed hairline with no fill
		 * and a Sector is a wash of its own colour, and in the Datos list and the
		 * Análisis chart a division is a ring or the neutral `#949494` (#21,
		 * src/marks.js, src/taxonomy.js `categoryColour`).
		 *
		 * It measures 1.73:1 against the dark theme where WCAG 1.4.11 asks 3:1,
		 * and there was no legible grey to move it to — ADR-0013 measures the
		 * neutral band as full at ΔE 0.055 against a 0.05 floor, and every
		 * candidate outside it reads wrong for a boundary. So the division stopped
		 * being a coloured mark instead. Changing this value would migrate every
		 * install's data to repaint nothing.
		 */
		'color' => '#334155',
		'subcategories' => [
			'sector' => 'Sector',
			// Read from the national cadastre, never drawn (CONTEXT.md). It needs a
			// home here for the same reason Sector does: a Unidad Vecinal is a
			// Feature, and a Feature carries exactly one Subcategory.
			'unidad_vecinal' => 'Unidad Vecinal',
		],
	];

	public const DIVISIONS_SLUG = 'division_territorial';

	/**
	 * Where anything the app cannot classify ends up — seeded above, named here
	 * because three things depend on this exact pair being present and none of
	 * them creates it.
	 *
	 * `ImportService::prepare()` resolves it on every run and refuses the whole
	 * file without it; `defaultSubcategoryId()` in `src/taxonomy.js` starts every
	 * new record there rather than at the first Category in the tree; and
	 * `tools/boundary-features.php` writes it into every Limit it fetches.
	 *
	 * Which is what makes it structural for `TaxonomyService::removeCategory()`
	 * (#47): being EMPTY is its ordinary state, so the in-use guard would let it
	 * go on exactly the installs that depend on it, and this file would put it
	 * back on the next upgrade with nothing said anywhere.
	 *
	 * @var array{category: string, subcategory: string}
	 */
	public const FALLBACK = ['category' => 'sin_clasificar', 'subcategory' => 'otro'];

	/**
	 * The Subcategories of the territorial division this file puts back on every
	 * upgrade — which is exactly why they cannot be removed (ADR-0016, #47).
	 *
	 * Derived from `DIVISIONS` rather than repeated, so a third division ever
	 * shipped here becomes unremovable without anybody remembering to update a
	 * second list. `TaxonomyService::removeSubcategory()` is the only reader.
	 *
	 * @return list<string>
	 */
	public static function divisionSubcategorySlugs(): array {
		return array_keys(self::DIVISIONS['subcategories']);
	}

	/**
	 * The colours a Category added by a clinic can be given, in the order they are
	 * handed out. The app assigns one; nobody is asked to pick a colour by eye,
	 * because the constraints cannot be satisfied that way (ADR-0017).
	 *
	 * Derived by `tools/ramp-check.py`, which owns the arithmetic — OKLab
	 * separation, WCAG contrast and the three dichromacy transforms — and which
	 * re-checks every value here on `make lint`. They are deliberately NOT
	 * recomputed in PHP: two implementations of one rule drift apart silently, and
	 * preventing exactly that drift is why the ramp check exists.
	 *
	 * Eight is a real ceiling rather than a round number. At the chroma ADR-0013
	 * fixes and inside the six hue families it allows, nothing else clears every
	 * gate against the eleven, against both neutrals, and against the other spares.
	 * A clinic that exhausts them still gets its Category: it takes the neutral, and
	 * that is not an error — hue only groups Categories, the glyph identifies them.
	 *
	 * This sits after DIVISIONS_SLUG rather than beside TAXONOMY because the ramp
	 * check reads the Categories out of the slice that ends at that line, and a
	 * bare `'#rrggbb'` inside it would be parsed as a Category.
	 *
	 * @var list<string>
	 */
	public const SPARES = [
		'#c27b72', '#78621d', '#9a8442', '#65a175',
		'#419eaf', '#7c8cc7', '#a072a3', '#b282b4',
	];

	/**
	 * What a Category is given once the spares above are gone (ADR-0017).
	 *
	 * The same grey `src/taxonomy.js` exports as `DEFAULT_SECTOR_COLOUR`, and
	 * the same one `categoryColour()` there already answers with for a whole
	 * Category — this is a Category nobody could give a hue to, which is what
	 * that constant has always meant. Not `UNKNOWN_COLOUR`, which this repo
	 * spent a slice separating from it because it means a BROKEN reference, and
	 * a Category on the neutral is not broken: hue only groups Categories and
	 * the glyph identifies them (ADR-0013).
	 *
	 * It is a second copy of a hex the frontend also holds, in a language that
	 * cannot read the other — so `tools/ramp-check.py` compares the two on every
	 * `make lint`. The panel's whole message rests on `color === the neutral`,
	 * and two values drifting apart would silence it with every suite green.
	 */
	public const NEUTRAL = '#949494';


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
		$this->ensureDivisions($output);
		$this->ensureRamp($output);
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

	/**
	 * Move each Category onto the designed ramp, once.
	 *
	 * `ensureTaxonomy()` above returns the moment the table has any rows, so on
	 * every install that already exists a change to self::TAXONOMY reaches nobody.
	 * This is what carries it there — the same problem `ensureDivisions()` solves
	 * for a missing Subcategory, and the same shape of answer.
	 *
	 * Guarded row by row on the OLD colour, not on the slug alone. A Category is
	 * editable data (CONTEXT.md), so a clinic may have chosen its own colour, and
	 * that choice is not drift to be corrected — it is the clinic saying what it
	 * wants. A row already on the new colour matches nothing either, which is what
	 * makes this safe to run on every upgrade forever.
	 *
	 * The old colour lives on its Category's own row as `was`, rather than in a
	 * second list keyed by the same slugs. A second list can disagree with the
	 * first — a renamed slug there reads an undefined key here and paints the
	 * Category NULL — so it needed a test to police it. Keeping both values on one
	 * row makes that disagreement unrepresentable, which is cheaper than testing
	 * for it.
	 *
	 * See docs/adr/0013-hue-groups-categories-the-glyph-identifies-them.md.
	 */
	private function ensureRamp(IOutput $output): void {
		if (!$this->db->tableExists('territorio_category')) {
			return;
		}

		$repainted = 0;
		foreach (self::TAXONOMY as $slug => $category) {
			// A Category added after the ramp has no old colour to recognise, and
			// nothing to repaint.
			if (!isset($category['was'])) {
				continue;
			}

			$update = $this->db->getQueryBuilder();
			$update->update('territorio_category')
				->set('color', $update->createNamedParameter($category['color']))
				->where($update->expr()->eq('slug', $update->createNamedParameter($slug)))
				->andWhere($update->expr()->eq('color', $update->createNamedParameter($category['was'])));
			$repainted += $update->executeStatement();
		}

		if ($repainted > 0) {
			$output->info(sprintf('Territorio: repainted %d categories onto the designed ramp', $repainted));
		}
	}

	/**
	 * Put the "División territorial" Category and its Subcategories in place,
	 * each one only if its slug is missing. Idempotent row by row rather than
	 * "seed if the table is empty": this runs on installs that already hold a
	 * taxonomy, and a Sector needs its Subcategory to exist on every one of them.
	 */
	private function ensureDivisions(IOutput $output): void {
		if (!$this->db->tableExists('territorio_category')) {
			return;
		}

		$categoryId = $this->categoryIdOf(self::DIVISIONS_SLUG);
		if ($categoryId === null) {
			$insert = $this->db->getQueryBuilder();
			$insert->insert('territorio_category')->values([
				'slug' => $insert->createNamedParameter(self::DIVISIONS_SLUG),
				'label' => $insert->createNamedParameter(self::DIVISIONS['label']),
				'color' => $insert->createNamedParameter(self::DIVISIONS['color']),
			])->executeStatement();
			$categoryId = (int)$insert->getLastInsertId();
			$output->info('Territorio: added the "División territorial" category');
		}

		foreach (self::DIVISIONS['subcategories'] as $slug => $label) {
			if ($this->hasSubcategory($categoryId, $slug)) {
				continue;
			}

			$insert = $this->db->getQueryBuilder();
			$insert->insert('territorio_subcategory')->values([
				'category_id' => $insert->createNamedParameter($categoryId),
				'slug' => $insert->createNamedParameter($slug),
				'label' => $insert->createNamedParameter($label),
			])->executeStatement();
			$output->info(sprintf('Territorio: added the "%s" subcategory', $label));
		}
	}

	private function categoryIdOf(string $slug): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('territorio_category')
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));

		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();

		return $id === false ? null : (int)$id;
	}

	/** Slugs repeat across Categories — "otro" is in nearly all of them. */
	private function hasSubcategory(int $categoryId, string $slug): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('territorio_subcategory')
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)))
			->andWhere($qb->expr()->eq(
				'category_id',
				$qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT),
			));

		$result = $qb->executeQuery();
		$found = $result->fetchOne();
		$result->closeCursor();

		return $found !== false;
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
