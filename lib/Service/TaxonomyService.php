<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Db\CategoryMapper;
use OCA\Territorio\Db\Subcategory;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Exception\InvalidTaxonomyException;
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
	 */
	private const SLUG_LENGTH = 64;

	public function __construct(
		private CategoryMapper $categories,
		private SubcategoryMapper $subcategories,
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
