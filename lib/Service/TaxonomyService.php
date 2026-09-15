<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Db\CategoryMapper;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Exception\InvalidTaxonomyException;
use OCP\AppFramework\Db\DoesNotExistException;

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
