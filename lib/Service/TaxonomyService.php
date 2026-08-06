<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Db\CategoryMapper;
use OCA\Territorio\Db\SubcategoryMapper;

/**
 * The Category tree, as the client needs it.
 *
 * It goes to the browser whole — 11 Categories and a few dozen Subcategories —
 * because it is at once the tree people toggle, the Subcategory picker and the
 * legend, and because Features then travel as a subcategory id rather than
 * repeating a label on every row.
 */
class TaxonomyService {
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
}
