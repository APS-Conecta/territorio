<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use APS\Common\Text;
use OCA\Territorio\Db\Category;
use OCA\Territorio\Db\CategoryMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\Subcategory;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Exception\InvalidTaxonomyException;
use OCA\Territorio\Migration\EnsureSeedData;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use Throwable;

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

	/** Deleted — private const SLUG_LENGTH: sole referencing site was the
	 *  slugFor body that now lives in Text. */
	public function __construct(
		private CategoryMapper $categories,
		private SubcategoryMapper $subcategories,
		private FeatureMapper $features,
		private TaxonomyPolicy $policy,
		private IDBConnection $db,
	) {
	}

	/** @return list<array{id: int, slug: string, label: string, color: string, glyph: ?string, subcategories: list<array{id: int, slug: string, label: string}>}> */
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
				// Null for every Category nobody has picked a mark for, which is
				// all eleven the seed ships: their pictograms are keyed by slug in
				// `src/glyphs.js` and a stored name OVERRIDES that map (#46).
				'glyph' => $category->getGlyph(),
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
	 * @return list<array{id: int, slug: string, label: string, color: string, glyph: ?string, subcategories: list<array{id: int, slug: string, label: string}>}>
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
	 * Choose the pictogram a Category is drawn with, or clear the choice (#46).
	 *
	 * Its own method rather than a second argument to `renameCategory()`, because
	 * the two are never done at the same moment: a name is typed into the row's
	 * field and a mark is clicked out of a grid, and one write path carrying both
	 * would have to invent what "the label was not sent" means.
	 *
	 * Stored against the Category and not against the Subcategory, for ADR-0013's
	 * reason: hue groups Categories and the glyph identifies them, and everything
	 * filed under a Category is drawn with its mark.
	 *
	 * Clearing is `''`, which stores null and puts the Category back to drawing
	 * what `src/glyphs.js` ships for its slug — the neutral, for one the clinic
	 * added. That is a real thing to want: a mark picked in a hurry that turns out
	 * to mean something else is worse than no mark, because it asserts.
	 *
	 * Answers with the whole served tree, like every other write here, and does
	 * not advance the Revision cursor — see `renameCategory()` for both.
	 *
	 * @return list<array{id: int, slug: string, label: string, color: string, glyph: ?string, subcategories: list<array{id: int, slug: string, label: string}>}>
	 * @throws DoesNotExistException when no Category carries that slug
	 * @throws InvalidTaxonomyException when the mark is not one this app will store
	 */
	public function setCategoryGlyph(string $slug, string $glyph): array {
		$category = $this->categories->findBySlug($slug);
		$category->setGlyph(TaxonomyPolicy::glyphName($glyph));
		$this->categories->update($category);

		return $this->tree();
	}


	/**
	 * Rename a Subcategory, addressed within its Category.
	 *
	 * @return list<array{id: int, slug: string, label: string, color: string, glyph: ?string, subcategories: list<array{id: int, slug: string, label: string}>}>
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
	 * The pictogram IS chosen, and it is the one thing here a person can answer
	 * that the app cannot (ADR-0017): no software guesses which mark means
	 * «comité de vivienda». Optional all the same — a Category added without one
	 * draws the neutral fallback `glyphFor()` already returns for a slug it does
	 * not know, which reads as «unclassified» where a blank reads as a rendering
	 * failure.
	 *
	 * Uniqueness is `terr_cat_slug_idx`'s, not this method's, for the reason
	 * `addSubcategory()` gives: a check-then-insert here would be a second
	 * statement of the same rule that two people adding «Comité de vivienda» at
	 * once would both pass. When the row holding the slug shows another name, the
	 * refusal names it (`nameTaken()`, #210).
	 *
	 * Answers with the whole served tree, like every other write here, because it
	 * is the one property the Capas panel, the Subcategoría picker, Análisis and
	 * the map's colours all read. The Revision cursor is not advanced — see
	 * `renameCategory()`, and the `addSubcategory()` docblock in `src/App.vue`
	 * for what that costs a colleague's open browser on a create.
	 *
	 * @return list<array{id: int, slug: string, label: string, color: string, glyph: ?string, subcategories: list<array{id: int, slug: string, label: string}>}>
	 * @throws InvalidTaxonomyException when the label or the mark is not one this app will store
	 */
	public function addCategory(string $label, string $glyph = ''): array {
		$clean = $this->cleanLabel($label);
		// Before the insert, so a name this app will not store refuses the whole
		// add rather than leaving a Category behind with no mark on it.
		$mark = TaxonomyPolicy::glyphName($glyph);
		$slug = self::slugFor($clean);
		if ($slug === '') {
			throw new InvalidTaxonomyException('El nombre necesita alguna letra o algún número.');
		}

		$category = new Category();
		$category->setSlug($slug);
		$category->setLabel($clean);
		$category->setColor($this->policy->nextColour());
		$category->setGlyph($mark);

		// One transaction (L1-16) — the ceiling the ponytail note below this block
		// used to name, paid off: the Category and its «Otro» land together or not
		// at all, and the recovery paragraph ("removeCategory() can take it away")
		// is no longer needed. On a unique violation the statement aborts, the
		// wrapper rolls the transaction back, and the catch OUTSIDE it converts
		// to the sentence — rollback first, conversion second, so the conversion
		// never runs inside a doomed transaction.
		try {
			return $this->transactionally(function () use ($category): array {
				$inserted = $this->categories->insert($category);

				$subcategory = new Subcategory();
				$subcategory->setCategoryId((int)$inserted->getId());
				$subcategory->setSlug(self::FIRST_SUBCATEGORY['slug']);
				$subcategory->setLabel(self::FIRST_SUBCATEGORY['label']);
				$this->subcategories->insert($subcategory);

				return $this->tree();
			});
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			throw $this->nameTaken(
				$clean,
				fn (): Category => $this->categories->findBySlug($slug),
				'Ya existe una categoría con ese nombre.',
				'Ese nombre está reservado por la categoría «%s». Elija otro nombre.',
			);
		}
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
	 * what is caught here is its violation, turned into a sentence. When the row
	 * holding the slug shows another name, the refusal names it (`nameTaken()`,
	 * #210).
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
	 * @return list<array{id: int, slug: string, label: string, color: string, glyph: ?string, subcategories: list<array{id: int, slug: string, label: string}>}>
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
			throw $this->nameTaken(
				$clean,
				fn (): Subcategory => $this->subcategories->findBySlug((int)$category->getId(), $slug),
				'Ya existe una subcategoría con ese nombre en esta categoría.',
				'Ese nombre está reservado por la subcategoría «%s» de esta categoría. Elija otro nombre.',
			);
		}

		return $this->tree();
	}

	/**
	 * The refusal for a label whose derived slug is taken (#210). A rename keeps the
	 * slug (#40), and slugFor() also drops punctuation and cuts at Text::SLUG_LENGTH,
	 * so the row holding it may show another name: «Ya existe» only when that shown
	 * name folds to what was typed, otherwise the sentence names the row. Runs after
	 * the failed insert, outside any transaction; uniqueness stays the index's.
	 *
	 * @param callable(): (Category|Subcategory) $holder
	 */
	private function nameTaken(string $typed, callable $holder, string $sameName, string $heldBy): InvalidTaxonomyException {
		try {
			$label = $holder()->getLabel();
		} catch (DoesNotExistException) { // holder removed since the insert failed
			return new InvalidTaxonomyException($sameName);
		}

		return new InvalidTaxonomyException(
			Text::fold($label) === Text::fold($typed) ? $sameName : sprintf($heldBy, $label),
		);
	}

	/**
	 * Remove a Category the clinic does not use (#47).
	 *
	 * Three refusals, in this order, and the order is the decision (ADR-0016):
	 * structural first, because «División territorial» and «Sin clasificar» would
	 * both pass the in-use guard on an empty install and come back on the next
	 * upgrade anyway; then in use, which is the one the ADR is about; and only
	 * then the delete.
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
	 * One transaction (L1-16), the first of the two ceilings this note used to
	 * name: a failure mid-delete no longer leaves a Category holding some of its
	 * Subcategories. The second ceiling — the count-then-delete race against a
	 * concurrent save — stays named and open: closing it needs the transaction to
	 * also LOCK what it counted, or the foreign key this schema does not carry.
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

		return $this->transactionally(function () use ($category, $subcategories): array {
			foreach ($subcategories as $subcategory) {
				$this->subcategories->delete($subcategory);
			}
			$this->categories->delete($category);

			return $this->tree();
		});
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
	 * What the app itself depends on stays, always (ADR-0016): «División
	 * territorial» and the two rows under it, and «Sin clasificar · Otro».
	 *
	 * The two are structural for the same reason told twice. The division is
	 * where every Sector and every Unidad Vecinal is filed, so without it a
	 * boundary cannot be saved. «Sin clasificar · Otro» is where everything the
	 * app cannot classify goes: `ImportService::prepare()` resolves that exact
	 * pair on every run and refuses the whole file without it, `defaultSubcategoryId()`
	 * in `src/taxonomy.js` starts every new record there — falling through to the
	 * first Category in the tree, «Comercio y economía · Feria», which is the
	 * silent misfiling that function exists to prevent — and
	 * `tools/boundary-features.php` stamps it on every Limit it fetches. The pair
	 * is named once, in the seed that creates it (`EnsureSeedData::FALLBACK`).
	 *
	 * And both come back on the next `occ upgrade`, which is what makes the
	 * refusal necessary rather than merely tidy: without it the Category goes,
	 * imports break, and the seed restores it — the symptom appears and vanishes
	 * with nothing said anywhere.
	 *
	 * Being empty is the ORDINARY state of «Sin clasificar» on a clinic whose
	 * imports resolve cleanly, so the in-use guard would wave it through on
	 * exactly the installs that depend on it. The last-Subcategory guard does
	 * happen to cover its «Otro» today, but only for as long as that is the only
	 * row under it, and `addSubcategory()` accepts a second one deliberately — so
	 * the pair is refused here, where the reason is written down.
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
		if ($category->getSlug() === EnsureSeedData::FALLBACK['category']
			&& ($subcategory === null || $subcategory->getSlug() === EnsureSeedData::FALLBACK['subcategory'])) {
			throw new InvalidTaxonomyException(sprintf(
				'No se puede quitar «%s»: es donde llega lo que no se reconoce al importar. '
				. 'Sin ella ningún archivo se puede importar, y cada registro nuevo quedaría '
				. 'clasificado en otra parte sin que nadie lo decida.',
				$subcategory === null
					? $category->getLabel()
					: $category->getLabel() . ' · ' . $subcategory->getLabel(),
			));
		}

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
	 * The identifier a label makes — the shared rule, stated once in
	 * aps-common's Text (L0-03/L1-05). Kept as a static forward so every
	 * call site (CsvReader headings, the admin write doors, the tests)
	 * keeps working unchanged.
	 */
	public static function slugFor(string $label): string {
		return Text::slugFor($label);
	}

	/** @var ?array<int, array{category: string, categoryLabel: string, subcategory: string, subcategoryLabel: string}> */
	private ?array $subcategoryIndex = null;

	/**
	 * The taxonomy as one flat index, built once per request (L1-20).
	 *
	 * Three services used to walk the tree into their own shape — Import to
	 * match on it, Export to label from it, Assignment to find its divisions in
	 * it — each re-paying the two-table read per call. Keyed by Subcategory id
	 * because that is what a Feature carries; each entry carries both slugs AND
	 * both labels, so the flattest consumer does not come back for a label. The
	 * memo is safe because the writes this service owns happen behind the routes
	 * that re-read the tree through tree(), which stays unmemoised deliberately
	 * (the ramp repair's own lesson, recorded at AssignmentService::divisions()).
	 *
	 * @return array<int, array{category: string, categoryLabel: string, subcategory: string, subcategoryLabel: string}>
	 */
	public function subcategoryIndex(): array {
		if ($this->subcategoryIndex !== null) {
			return $this->subcategoryIndex;
		}

		$index = [];
		foreach ($this->tree() as $category) {
			foreach ($category['subcategories'] as $subcategory) {
				$index[$subcategory['id']] = [
					'category' => $category['slug'],
					'categoryLabel' => $category['label'],
					'subcategory' => $subcategory['slug'],
					'subcategoryLabel' => $subcategory['label'],
				];
			}
		}

		return $this->subcategoryIndex = $index;
	}

	/** @param callable(): T $work @template T */
	private function transactionally(callable $work): mixed {
		$this->db->beginTransaction();
		try {
			$written = $work();
			$this->db->commit();

			return $written;
		} catch (Throwable $e) {
			$this->db->rollBack();

			throw $e;
		}
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
