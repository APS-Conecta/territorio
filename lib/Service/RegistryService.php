<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Db\CategoryMapper;
use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\SubcategoryMapper;

/**
 * What Territorio answers when another app on this instance asks.
 *
 * Inject it and call it — there is no HTTP here on purpose. Apps on one
 * Nextcloud consume each other by injection: it is faster, it joins the caller's
 * transaction, and it needs no authentication because there is no request. An
 * OCS route for callers OUTSIDE the instance would be a new controller over this
 * same service, which is why the judgement lives here and not in a controller.
 *
 * From another app:
 *
 *     public function __construct(
 *         private \OCA\Territorio\Service\RegistryService $territorio,
 *     ) {}
 *
 *     $this->territorio->counts()['categories']['organizacion_social']['subcategories']['taller'];
 *
 * Everything here reads through `FeatureMapper::findAll()`, which is the single
 * place that decides what the Registry currently shows: retired records are out
 * (ADR-0005) and so is every Boundary-feature Dataset, because a comuna's eleven
 * thousand streets are not places anybody means to count. Reading through it
 * rather than writing fresh queries is what keeps this service's answer and the
 * app's own screens the same answer.
 *
 * Counts by Sector and Unidad Vecinal are NOT here yet: ADR-0008 says an
 * assignment is derived from geometry and stored, and nothing stores one until
 * issue #11. They belong on this class when it does.
 */
class RegistryService {
	public function __construct(
		private FeatureMapper $features,
		private CategoryMapper $categories,
		private SubcategoryMapper $subcategories,
	) {
	}

	/**
	 * How many records there are, by Category and Subcategory.
	 *
	 * Keyed by slug rather than by id: ids differ per install and mean nothing to
	 * a caller, while a slug is the stable name the seed and every import use.
	 *
	 * ponytail: counts in PHP over the whole live set rather than a GROUP BY.
	 * That reuses the one definition of "what the Registry shows" instead of
	 * restating it in SQL — reach for an aggregate query when a real catastro
	 * makes this slow, and move the retired-and-boundary rule with it.
	 *
	 * @return array{total: int, unclassified: int, categories: array<string, array{label: string, total: int, subcategories: array<string, int>}>}
	 */
	public function counts(): array {
		$perSubcategory = [];
		$live = $this->features->findAll();
		foreach ($live as $feature) {
			$id = $feature->getSubcategoryId();
			$perSubcategory[$id] = ($perSubcategory[$id] ?? 0) + 1;
		}

		$categories = [];
		$classified = 0;
		foreach ($this->categories->findAll() as $category) {
			$subcategories = [];
			$total = 0;
			foreach ($this->subcategoriesOf((int)$category->getId()) as $subcategory) {
				$count = $perSubcategory[(int)$subcategory->getId()] ?? 0;
				$subcategories[$subcategory->getSlug()] = $count;
				$total += $count;
			}

			$classified += $total;
			$categories[$category->getSlug()] = [
				'label' => $category->getLabel(),
				'total' => $total,
				'subcategories' => $subcategories,
			];
		}

		return [
			'total' => count($live),
			// A record whose Subcategory the taxonomy no longer has is still a
			// record. Dropping it would make this total quietly disagree with the
			// Registry, which is worse than an awkward number.
			'unclassified' => count($live) - $classified,
			'categories' => $categories,
		];
	}

	/**
	 * One record, or null if the Registry does not currently show it.
	 *
	 * Null rather than an exception: "is this id still there" is a fair question,
	 * and a retired record answering null is the honest answer to "show me this".
	 *
	 * @return ?array<string, mixed>
	 */
	public function find(int $id): ?array {
		foreach ($this->features->findAll() as $feature) {
			if ((int)$feature->getId() === $id) {
				return $this->describe($feature);
			}
		}

		return null;
	}

	/**
	 * Every record filed under one Subcategory, named the way a caller would say
	 * it: "organizacion_social", "taller".
	 *
	 * @return list<array<string, mixed>>
	 */
	public function inSubcategory(string $categorySlug, string $subcategorySlug): array {
		$wanted = $this->subcategoryId($categorySlug, $subcategorySlug);
		if ($wanted === null) {
			return [];
		}

		$found = [];
		foreach ($this->features->findAll() as $feature) {
			if ($feature->getSubcategoryId() === $wanted) {
				$found[] = $this->describe($feature);
			}
		}

		return $found;
	}

	/**
	 * A record as another app wants it: slugs rather than ids, because an id is
	 * ours and a slug is shared.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(Feature $feature): array {
		$subcategory = $this->subcategoryById($feature->getSubcategoryId());
		$category = $subcategory === null
			? null
			: $this->categoryById((int)$subcategory->getCategoryId());

		return [
			'id' => (int)$feature->getId(),
			'name' => $feature->getName(),
			'kind' => $feature->getKind(),
			'category' => $category?->getSlug(),
			'subcategory' => $subcategory?->getSlug(),
			'address' => $feature->getAddress(),
			'geometry' => $feature->getGeometry() === null
				? null
				: json_decode($feature->getGeometry(), true),
		];
	}

	private function subcategoryId(string $categorySlug, string $subcategorySlug): ?int {
		foreach ($this->categories->findAll() as $category) {
			if ($category->getSlug() !== $categorySlug) {
				continue;
			}
			foreach ($this->subcategoriesOf((int)$category->getId()) as $subcategory) {
				if ($subcategory->getSlug() === $subcategorySlug) {
					return (int)$subcategory->getId();
				}
			}
		}

		return null;
	}

	/** @return \OCA\Territorio\Db\Subcategory[] */
	private function subcategoriesOf(int $categoryId): array {
		return array_values(array_filter(
			$this->subcategories->findAll(),
			static fn ($subcategory): bool => (int)$subcategory->getCategoryId() === $categoryId,
		));
	}

	private function subcategoryById(int $id): ?\OCA\Territorio\Db\Subcategory {
		foreach ($this->subcategories->findAll() as $subcategory) {
			if ((int)$subcategory->getId() === $id) {
				return $subcategory;
			}
		}

		return null;
	}

	private function categoryById(int $id): ?\OCA\Territorio\Db\Category {
		foreach ($this->categories->findAll() as $category) {
			if ((int)$category->getId() === $id) {
				return $category;
			}
		}

		return null;
	}
}
