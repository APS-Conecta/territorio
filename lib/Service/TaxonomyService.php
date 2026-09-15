<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Db\Category;
use OCA\Territorio\Db\CategoryMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\Subcategory;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Exception\InvalidTaxonomyException;
use OCA\Territorio\Migration\EnsureSeedData;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;

/**
 * The Category tree, as the client needs it.
 *
 * It goes to the browser whole — 11 Categories and a few dozen Subcategories —
 * because it is at once the tree people toggle, the Subcategory picker and the
 * legend, and because Features then travel as a subcategory id rather than
 * repeating a label on every row.
 */
class TaxonomyService {
	/**
	 * `territorio_category.label` and `territorio_subcategory.label` are both
	 * `VARCHAR(128)`. Named here so an over-long label comes back as a message
	 * naming what to fix, the way every other refusal in this app does, rather
	 * than as the database's own error through a 500.
	 */
	private const LABEL_LENGTH = 128;

	/**
	 * `territorio_subcategory.slug` is `VARCHAR(64)`. The label it is derived
	 * from may be twice that, so the cap is reached by ordinary names and not
	 * only by abuse.
	 *
	 * ponytail: two labels that differ only after their 64th slug character
	 * derive to the same slug, so the second is refused with «Ya existe una
	 * subcategoría con ese nombre en esta categoría» — a sentence that is false
	 * about two names the person reads as different. Accepted because a name
	 * that long is not one a clinic types; the upgrade is to widen the column to
	 * `LABEL_LENGTH` in a migration, or to disambiguate the cut slug with a
	 * suffix. Named here rather than at the refusal because this is where the
	 * ceiling is decided.
	 */
	private const SLUG_LENGTH = 64;

	public function __construct(
		private CategoryMapper $categories,
		private SubcategoryMapper $subcategories,
		private FeatureMapper $features,
	) {
	}

	/** @return list<array{id: int, slug: string, label: string, color: string, subcategories: list<array{id: int, slug: string, label: string}>}> */
	public function tree(): array {
		$byCategory = [];
		foreach ($this->subcategories->findAll() as $subcategory) {
			$byCategory[$subcategory->getCategoryId()][] = [
				'id' => (int)$subcategory->getId(),
				'slug' => $subcategory->getSlug(),
				'label' => $subcategory->getLabel(),
			];
		}

		$tree = [];
		foreach ($this->categories->findAll() as $category) {
			$tree[] = [
				'id' => (int)$category->getId(),
				'slug' => $category->getSlug(),
				'label' => $category->getLabel(),
				'color' => $category->getColor(),
				'subcategories' => $byCategory[(int)$category->getId()] ?? [],
			];
		}

		return $tree;
	}

	/**
	 * Rename a Category. The slug does not move, and neither does anything filed
	 * under it.
	 *
	 * A Category is editable data, not code (CONTEXT.md): the clinic types the
	 * words its colleagues use. Only the label changes — the slug is the stable
	 * identifier every Feature, the glyph map and the colour lookup key on, which
	 * is precisely what makes a rename safe for records (issue #40).
	 *
	 * Answers with the whole served tree rather than the one row that changed.
	 * That tree is already what `PageController` puts in initial state, so the
	 * browser can swap its copy for this one and every surface reading it — the
	 * Capas panel, the Subcategoría picker, Análisis, the map's colours — moves
	 * together. A single row would have made the client responsible for splicing
	 * it in, which is a second description of the taxonomy that can disagree with
	 * this one.
	 *
	 * The Revision cursor is deliberately NOT advanced. See the dated correction
	 * on ADR-0002: the counter hangs on a Feature id and a Category is not a
	 * Feature, so another browser reads the new label on its next load.
	 *
	 * @return list<array{id: int, slug: string, label: string, color: string, subcategories: list<array{id: int, slug: string, label: string}>}>
	 * @throws DoesNotExistException when no Category carries that slug
	 * @throws InvalidTaxonomyException when the label is not one this app will store
	 */
	public function renameCategory(string $slug, string $label): array {
		$category = $this->categories->findBySlug($slug);
		$category->setLabel($this->cleanLabel($label));
		$this->categories->update($category);

		return $this->tree();
	}

	/**
	 * Rename a Subcategory, addressed within its Category.
	 *
	 * @return list<array{id: int, slug: string, label: string, color: string, subcategories: list<array{id: int, slug: string, label: string}>}>
	 * @throws DoesNotExistException when no such Category, or no such Subcategory under it
	 * @throws InvalidTaxonomyException when the label is not one this app will store
	 */
	public function renameSubcategory(string $categorySlug, string $slug, string $label): array {
		$category = $this->categories->findBySlug($categorySlug);
		$subcategory = $this->subcategories->findBySlug((int)$category->getId(), $slug);
		$subcategory->setLabel($this->cleanLabel($label));
		$this->subcategories->update($subcategory);

		return $this->tree();
	}

	/**
	 * The Subcategory every Category is born with.
	 *
	 * All eleven the seed ships carry an «Otro», and a Category with none is a
	 * Category nothing can be filed under: a Feature travels as a Subcategory id,
	 * the picker is a flat list of «Categoría · Subcategoría», and Análisis counts
	 * records. So an empty one would appear in the Capas panel as a branch with
	 * nothing in it and reach neither of the other two surfaces #45 names — the
	 * clinic would have added a name it cannot yet use. The clinic narrows it with
	 * #44's «+» whenever it wants to.
	 */
	private const FIRST_SUBCATEGORY = ['slug' => 'otro', 'label' => 'Otro'];

	/**
	 * Add a Category the seed does not have (#45).
	 *
	 * The clinic types a label and nothing else. The slug is derived, globally
	 * unique, and never typed (#40) — it is what every Feature, the glyph lookup
	 * and the colour lookup key on, and what a later rename leaves alone. The
	 * colour is ASSIGNED, because no person can satisfy its constraints by eye
	 * (ADR-0017): 3:1 against white and against the dark theme, dE-OK 0.05 from
	 * every other Category and both neutrals, and 0.015 from each of them under
	 * three dichromacies.
	 *
	 * No pictogram either. It draws the neutral fallback `glyphFor()` already
	 * returns for a slug it does not know, which reads as «unclassified» where a
	 * blank reads as a rendering failure. Choosing one is #46.
	 *
	 * Uniqueness is `terr_cat_slug_idx`'s, not this method's, for the reason
	 * `addSubcategory()` gives: a check-then-insert here would be a second
	 * statement of the same rule that two people adding «Comité de vivienda» at
	 * once would both pass.
	 *
	 * Answers with the whole served tree, like every other write here, because it
	 * is the one property the Capas panel, the Subcategoría picker, Análisis and
	 * the map's colours all read. The Revision cursor is not advanced — see
	 * `renameCategory()`, and the `addSubcategory()` docblock in `src/App.vue`
	 * for what that costs a colleague's open browser on a create.
	 *
	 * @return list<array{id: int, slug: string, label: string, color: string, subcategories: list<array{id: int, slug: string, label: string}>}>
	 * @throws InvalidTaxonomyException when the label is not one this app will store
	 */
	public function addCategory(string $label): array {
		$clean = $this->cleanLabel($label);
		$slug = self::slugFor($clean);
		if ($slug === '') {
			throw new InvalidTaxonomyException('El nombre necesita alguna letra o algún número.');
		}

		$category = new Category();
		$category->setSlug($slug);
		$category->setLabel($clean);
		$category->setColor($this->nextColour());

		try {
			$category = $this->categories->insert($category);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// Of the name, not of the slug: the slug is derived, so «Comité de
			// Vivienda» collides with «comité de vivienda» and naming the slug
			// would describe something the person never typed.
			throw new InvalidTaxonomyException('Ya existe una categoría con ese nombre.');
		}

		// ponytail: two inserts, no transaction — the ceiling no write path in
		// this service has ever raised. If the «Otro» fails after the Category
		// row lands, the Category reaches neither the picker nor Análisis and
		// nothing can be filed under it. It needs a database failure between two
		// statements, and the recovery is `removeCategory()` below: nothing is
		// filed under it, so the panel lets the person take it away and its
		// spare colour comes back with it.
		$subcategory = new Subcategory();
		$subcategory->setCategoryId((int)$category->getId());
		$subcategory->setSlug(self::FIRST_SUBCATEGORY['slug']);
		$subcategory->setLabel(self::FIRST_SUBCATEGORY['label']);
		$this->subcategories->insert($subcategory);

		return $this->tree();
	}

	/**
	 * The colour a new Category is given: the first spare no Category holds.
	 *
	 * "Assigning a colour becomes taking the next unused spare" (ADR-0017). No
	 * counter and no new state — the answer is derived from the Categories that
	 * exist, so a Category removed (#47) hands its spare back, and two adds one
	 * after the other cannot take the same one because the first is a row by the
	 * time the second looks.
	 *
	 * Two SIMULTANEOUS adds can: this reads `findAll()` and then inserts with no
	 * lock, and there is no unique index on `color`. Accepted rather than fixed,
	 * and it is the one rule here `addCategory()` does not hand to the database —
	 * because a duplicate hue is cosmetic. Hue only groups Categories and the
	 * glyph identifies them (ADR-0013), so two Categories sharing one is the same
	 * thing that already happens the moment the spares run out and both take the
	 * neutral. A duplicate SLUG is not cosmetic, which is why that one is an
	 * index and this one is a comment.
	 *
	 * No colour arithmetic here on purpose. The OKLab separation, the WCAG
	 * contrast and the three dichromacy transforms live in `tools/ramp-check.py`,
	 * which derives `EnsureSeedData::SPARES` and re-checks every one of them on
	 * `make lint`; a second implementation in PHP is exactly the drift that check
	 * exists to prevent.
	 *
	 * Compared case-insensitively because a stored colour is a hex somebody may
	 * have typed — `RampRepaintTest` writes one directly, and `#C27B72` is the
	 * same colour as `#c27b72`.
	 *
	 * With the spares gone it answers the neutral, and that is not an error: hue
	 * only groups Categories into families and the glyph identifies them
	 * (ADR-0013), so a Category without a distinct hue is doing what that ADR says
	 * every Category does anyway. The panel says so, on `color === the neutral`,
	 * which is why nothing extra is returned to flag it.
	 */
	private function nextColour(): string {
		$taken = array_map(
			static fn (Category $category): string => strtolower($category->getColor()),
			$this->categories->findAll(),
		);

		foreach (EnsureSeedData::SPARES as $spare) {
			if (!in_array(strtolower($spare), $taken, true)) {
				return $spare;
			}
		}

		return EnsureSeedData::NEUTRAL;
	}

	/**
	 * Add a Subcategory under an existing Category (#44).
	 *
	 * No colour and no pictogram: both belong to the parent Category (ADR-0013 —
	 * hue groups Categories and the glyph identifies them), and a Subcategory is
	 * drawn with its Category's. So the clinic types one thing, a label.
	 *
	 * The slug is derived and never typed (issue #40): it is the stable
	 * identifier a later rename leaves alone, and it is unique within its
	 * Category rather than globally — "otro" is in nearly every one of them.
	 *
	 * Uniqueness is the index's, not this method's. A check-then-insert here
	 * would be a second statement of the same rule, one that two people adding
	 * «Taller» at once would both pass; `terr_subcat_slug_idx` is the rule, and
	 * what is caught here is its violation, turned into a sentence.
	 *
	 * Answers with the whole served tree, like the renames, because that is the
	 * one property the Capas panel, the Subcategoría picker, Análisis and the
	 * map's colours all read — see `renameCategory()` for why a single row would
	 * be a second description of the taxonomy.
	 *
	 * The Revision cursor is not advanced here either, for the reason
	 * `renameCategory()` gives — but that costs more on a create than on a
	 * rename, and what it costs is written on `addSubcategory()` in `src/App.vue`,
	 * where the browser that would have to notice lives.
	 *
	 * @return list<array{id: int, slug: string, label: string, color: string, subcategories: list<array{id: int, slug: string, label: string}>}>
	 * @throws DoesNotExistException when no Category carries that slug
	 * @throws InvalidTaxonomyException when the label is not one this app will store
	 */
	public function addSubcategory(string $categorySlug, string $label): array {
		$category = $this->categories->findBySlug($categorySlug);
		$clean = $this->cleanLabel($label);
		$slug = self::slugFor($clean);
		if ($slug === '') {
			throw new InvalidTaxonomyException('El nombre necesita alguna letra o algún número.');
		}

		$subcategory = new Subcategory();
		$subcategory->setCategoryId((int)$category->getId());
		$subcategory->setSlug($slug);
		$subcategory->setLabel($clean);

		try {
			$this->subcategories->insert($subcategory);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// Said of the name and not of the slug: the slug is derived, so
			// «Punto Limpio» collides with «punto limpio» and naming the slug
			// would describe a thing the person never typed.
			throw new InvalidTaxonomyException(
				'Ya existe una subcategoría con ese nombre en esta categoría.',
			);
		}

		return $this->tree();
	}

	/**
	 * Remove a Category the clinic does not use (#47).
	 *
	 * Three refusals, in this order, and the order is the decision (ADR-0016):
	 * structural first, because «División territorial» would pass the in-use
	 * guard on an empty install and come back on the next upgrade anyway; then
	 * in use, which is the one the ADR is about; and only then the delete.
	 *
	 * There is no `?force` and there never was one. ADR-0016 weighed retiring the
	 * records alongside and rejected it — "«remove this layer» and «retire 340
	 * places» are not the same sentence and should not be the same button" — so a
	 * flag that re-offered it would be that decision put back as a query
	 * parameter.
	 *
	 * The Subcategories go with it. They carry the Category's id and nothing in
	 * the schema would catch the orphan (issue #40): left behind they would leave
	 * every screen at once, because `tree()` groups them under the Categories
	 * `findAll()` answers with — and stay in the table forever.
	 *
	 * ponytail: no transaction and no lock, like `addCategory()`'s two inserts.
	 * Two ceilings, both named because neither is free to raise. A database
	 * failure between two of these statements leaves a Category holding fewer
	 * empty Subcategories than it did, which the same click completes on a
	 * retry — nothing is filed under any of them, which is the condition that got
	 * this far. And a Feature saved in the gap between the count and the delete
	 * is orphaned: it is the whole width of one HTTP request against a person who
	 * is removing a layer nobody has used, and the schema carries no foreign key
	 * that would catch it. The upgrade for the first is an `IDBConnection` on this
	 * service and one `beginTransaction`; the second needs that transaction to
	 * also LOCK what it counted, or the foreign key this schema does not have.
	 *
	 * @return list<array{id: int, slug: string, label: string, color: string, subcategories: list<array{id: int, slug: string, label: string}>}>
	 * @throws DoesNotExistException when no Category carries that slug
	 * @throws InvalidTaxonomyException when it is structural, or something is filed under it
	 */
	public function removeCategory(string $slug): array {
		$category = $this->categories->findBySlug($slug);
		$this->refuseIfStructural($category);

		$subcategories = $this->subcategoriesOf((int)$category->getId());
		$this->refuseIfInUse(
			array_map(static fn (Subcategory $s): int => (int)$s->getId(), $subcategories),
			$category->getLabel(),
		);

		foreach ($subcategories as $subcategory) {
			$this->subcategories->delete($subcategory);
		}
		$this->categories->delete($category);

		return $this->tree();
	}

	/**
	 * Remove one Subcategory, addressed within its Category.
	 *
	 * The same two refusals in the same order as `removeCategory()` — structural,
	 * then what is filed under it — which is why both guards take a label and a
	 * list of ids rather than a Category or a Subcategory: there is one rule, and
	 * what changes between the two callers is only what is being counted. A third
	 * refusal follows them, belonging to this path alone; the comment on it says
	 * why it is last.
	 *
	 * @return list<array{id: int, slug: string, label: string, color: string, subcategories: list<array{id: int, slug: string, label: string}>}>
	 * @throws DoesNotExistException when no such Category, or no such Subcategory under it
	 * @throws InvalidTaxonomyException when it is structural, or something is filed under it
	 */
	public function removeSubcategory(string $categorySlug, string $slug): array {
		$category = $this->categories->findBySlug($categorySlug);
		$subcategory = $this->subcategories->findBySlug((int)$category->getId(), $slug);
		$this->refuseIfStructural($category, $subcategory);
		$this->refuseIfInUse([(int)$subcategory->getId()], $subcategory->getLabel());

		// Not one of #47's criteria, and here because this is the only path that
		// can reach a state CONTEXT.md says does not exist: "A Category always
		// carries at least an «Otro» Subcategory… a Category with none is one
		// nothing can be filed under and one neither the picker nor Análisis
		// shows." `addCategory()` already goes out of its way to give a new one
		// its «Otro»; emptying an old one would leave a branch in the Capas panel
		// and nothing on the two surfaces that matter.
		//
		// Refused rather than cascaded into removing the Category, for ADR-0016's
		// own reason about the retire-alongside option: «quitar esta subcategoría»
		// and «quitar toda la categoría» are not the same sentence and must not be
		// the same button.
		//
		// LAST of the three, and the order carries weight: every Category a clinic
		// adds arrives with exactly its «Otro» (`addCategory()`), so a Category
		// with one Subcategory holding records is the ordinary case rather than a
		// corner. Refused here first it would answer with no count and no pointer,
		// and the way out it names — remove the whole Category — is itself refused
		// for those same records: two refusals to reach the sentence ADR-0016 asks
		// for, which is the dead end that ADR is written to prevent. So what is
		// filed under the row is always reported first.
		if (count($this->subcategoriesOf((int)$category->getId())) === 1) {
			throw new InvalidTaxonomyException(sprintf(
				'No se puede quitar «%s»: es la única subcategoría de «%s», y una categoría '
				. 'sin subcategorías no sirve para clasificar nada. Quite la categoría entera '
				. 'si ya no la usa.',
				$subcategory->getLabel(),
				$category->getLabel(),
			));
		}

		$this->subcategories->delete($subcategory);

		return $this->tree();
	}

	/**
	 * The Subcategories under one Category, read out of the whole list.
	 *
	 * `findAll()` and a filter rather than a query of its own: the tree is eleven
	 * Categories and a few dozen Subcategories, this service already loads all of
	 * it on every request for `tree()`, and a `findByCategory()` would be a second
	 * way of asking the same question.
	 *
	 * @return list<Subcategory>
	 */
	private function subcategoriesOf(int $categoryId): array {
		return array_values(array_filter(
			$this->subcategories->findAll(),
			static fn (Subcategory $subcategory): bool => (int)$subcategory->getCategoryId() === $categoryId,
		));
	}

	/**
	 * «División territorial» and everything under it stay, always (ADR-0016).
	 *
	 * The Category by slug, and a Subcategory by BOTH its Category's slug and its
	 * own. The reason those two Subcategories cannot go is that the seed restores
	 * them on every upgrade, so the rule is asked of the list the seed actually
	 * ships rather than of "anything under the division". A third row posted
	 * under it by hand — which `addSubcategory()` still accepts, deliberately,
	 * for the reason `App.vue` gives — is restored by nothing and nothing depends
	 * on it, so refusing that too would only make a mistake permanent.
	 *
	 * Refused here rather than at save time, and the panel says the same thing
	 * where the control would otherwise be: this is the backstop for a request
	 * that did not come from the panel, not the way a person is meant to find out.
	 *
	 * @throws InvalidTaxonomyException
	 */
	private function refuseIfStructural(Category $category, ?Subcategory $subcategory = null): void {
		if ($category->getSlug() !== EnsureSeedData::DIVISIONS_SLUG) {
			return;
		}
		if ($subcategory !== null && !in_array(
			$subcategory->getSlug(), EnsureSeedData::divisionSubcategorySlugs(), true,
		)) {
			return;
		}

		throw new InvalidTaxonomyException(sprintf(
			'No se puede quitar «%s»: la división territorial es parte de la estructura del '
			. 'mapa, y todo sector y toda unidad vecinal se guarda en ella.',
			$subcategory?->getLabel() ?? $category->getLabel(),
		));
	}

	/**
	 * Refuse while anything is filed under these Subcategories, saying how many
	 * and where to go and see them (ADR-0016).
	 *
	 * The count is `countBySubcategory()` and never the one Análisis shows: that
	 * one describes what a person is looking at, filters and all, and leaves out
	 * retired records and boundary Datasets. This one has to describe what would
	 * be orphaned.
	 *
	 * Which is why the sentence SAYS the retired ones are in it. A person who
	 * filters Capas down to this one Category and counts nine against a refusal
	 * that said twelve has been handed a contradiction; naming the difference and
	 * the list it is visible in turns it back into a next step, which is the half
	 * of ADR-0016 that is easiest to drop.
	 *
	 * @param int[] $subcategoryIds
	 * @throws InvalidTaxonomyException
	 */
	private function refuseIfInUse(array $subcategoryIds, string $label): void {
		$count = $this->features->countBySubcategory($subcategoryIds);
		if ($count === 0) {
			return;
		}

		throw new InvalidTaxonomyException(sprintf(
			'No se puede quitar «%s»: hay %d %s ahí, incluidos los retirados. Para verlos, '
			. 'deje visible solo «%s» en Capas y revise «Ver retirados» en Datos. '
			. 'Reclasifíquelos y vuelva a intentarlo.',
			$label,
			$count,
			$count === 1 ? 'registro clasificado' : 'registros clasificados',
			$label,
		));
	}

	/**
	 * The identifier a label makes: accents off, case down, everything else a
	 * separator.
	 *
	 * Not `KmlReader::slug()`, which drops «y», «de», «la» and «el» so that a KML
	 * Folder name lands on a slug the seed already carries. That is a heuristic
	 * for matching existing rows and the wrong rule for a name somebody just
	 * typed: «Casa de la cultura» is `casa_de_la_cultura` here, and nothing has
	 * to recognise it afterwards.
	 *
	 * Decomposes and drops the combining marks rather than mapping the seven
	 * Spanish letters, for the reason `DuplicateFinder::fold()` gives: a clinic
	 * types what it types, and a list of seven is a list that is missing one.
	 *
	 * Public and static because it is pure — the only rule on this write path
	 * that has no database in it, and the only one with no screen that would show
	 * it going wrong (`TaxonomySlugTest`).
	 */
	public static function slugFor(string $label): string {
		$decomposed = \Normalizer::normalize($label, \Normalizer::FORM_D);
		$stripped = preg_replace('/\p{Mn}/u', '', $decomposed === false ? $label : $decomposed);
		$slug = (string)preg_replace('/[^a-z0-9]+/', '_', mb_strtolower((string)$stripped));

		// Trimmed again after the cut, so a name cut mid-separator does not make
		// a slug the same label would not make twice.
		return trim(mb_substr(trim($slug, '_'), 0, self::SLUG_LENGTH), '_');
	}

	/**
	 * What a person typed, or a refusal that says what to fix.
	 *
	 * Trimmed first, like every other name this app stores (`FeatureService`):
	 * a label of spaces is a blank label, and a panel row with nothing in it
	 * cannot be clicked back into shape.
	 */
	private function cleanLabel(string $label): string {
		$clean = trim($label);
		if ($clean === '') {
			throw new InvalidTaxonomyException('El nombre no puede quedar vacío.');
		}
		if (mb_strlen($clean) > self::LABEL_LENGTH) {
			throw new InvalidTaxonomyException(sprintf(
				'El nombre no puede pasar de %d caracteres.', self::LABEL_LENGTH,
			));
		}

		return $clean;
	}
}
