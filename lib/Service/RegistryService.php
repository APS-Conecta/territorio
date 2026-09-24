<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;

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
 * Counts by Sector and Unidad Vecinal read the ids stored on each record
 * (ADR-0008) rather than resolving geometry per request — which is the whole
 * reason those ids are stored.
 */
class RegistryService {
	public function __construct(
		private FeatureMapper $features,
		private TaxonomyService $taxonomy,
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
	 * @return array{total: int, unknownSubcategory: int, categories: array<string, array{label: string, total: int, subcategories: array<string, int>}>, bySector: array<int, int>, byUnidadVecinal: array<int, int>}
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
		foreach ($this->taxonomy->tree() as $category) {
			$subcategories = [];
			$total = 0;
			foreach ($category['subcategories'] as $subcategory) {
				$count = $perSubcategory[$subcategory['id']] ?? 0;
				$subcategories[$subcategory['slug']] = $count;
				$total += $count;
			}

			$classified += $total;
			$categories[$category['slug']] = [
				'label' => $category['label'],
				'total' => $total,
				'subcategories' => $subcategories,
			];
		}

		// By the stored assignment, never by asking the geometry again: ADR-0008
		// stores it precisely so that counting stays an ordinary read. Keyed by
		// the boundary's own id, since a Sector is named whatever a clinic names
		// it and two of them may share a name.
		$bySector = [];
		$byUnidadVecinal = [];
		foreach ($live as $feature) {
			if ($feature->getSectorId() !== null) {
				$bySector[$feature->getSectorId()] = ($bySector[$feature->getSectorId()] ?? 0) + 1;
			}
			if ($feature->getUnidadVecinalId() !== null) {
				$id = $feature->getUnidadVecinalId();
				$byUnidadVecinal[$id] = ($byUnidadVecinal[$id] ?? 0) + 1;
			}
		}

		return [
			'total' => count($live),
			'bySector' => $bySector,
			'byUnidadVecinal' => $byUnidadVecinal,
			// NOT "unclassified": "Sin clasificar" is a real seeded Category that
			// someone may have deliberately filed a record under, and it is counted
			// like any other above. This is the other thing — a record pointing at a
			// Subcategory the taxonomy no longer has. Conflating the two would make
			// a broken reference indistinguishable from a decision, which is the
			// distinction `taxonomy.js` and `FeatureList.vue` both already protect.
			'unknownSubcategory' => count($live) - $classified,
			'categories' => $categories,
		];
	}

	/**
	 * One record, or null if the Registry does not currently show it.
	 *
	 * Null rather than an exception: "is this id still there" is a fair question,
	 * and a retired record answering null is the honest answer to "show me this".
	 *
	 * ponytail: reads the whole live set to return one row, because going through
	 * `FeatureMapper::find()` would mean restating here which records are visible
	 * — and two copies of that rule is how a retired record ends up answerable to
	 * another app. Give the mapper a visible-only `find()` when this hurts.
	 *
	 * @return ?array<string, mixed>
	 */
	public function find(int $id): ?array {
		$named = $this->named();
		foreach ($this->features->findAll() as $feature) {
			if ((int)$feature->getId() === $id) {
				return $this->describe($feature, $named);
			}
		}

		return null;
	}

	/**
	 * Every record filed under one Subcategory, named the way a caller would say
	 * it: "organizacion_social", "taller". Null when the pair names nothing —
	 * distinct from [], which means the pair is real and nothing is filed
	 * under it. The distinction is the OCS door's 404 (L0-05).
	 *
	 * @return ?list<array<string, mixed>>
	 */
	public function inSubcategory(string $categorySlug, string $subcategorySlug): ?array {
		$named = $this->named();

		$wanted = null;
		foreach ($named as $id => $names) {
			if ($names['category'] === $categorySlug && $names['subcategory'] === $subcategorySlug) {
				$wanted = $id;
			}
		}
		if ($wanted === null) {
			return null;
		}

		$found = [];
		foreach ($this->features->findAll() as $feature) {
			if ($feature->getSubcategoryId() === $wanted) {
				$found[] = $this->describe($feature, $named);
			}
		}

		return $found;
	}

	/**
	 * Subcategory id → the two slugs that name it, read once per call.
	 *
	 * Once, deliberately. Looking each record's Category and Subcategory up as it
	 * was described meant two more reads of the whole taxonomy per record — two
	 * hundred talleres cost four hundred queries to answer one question.
	 *
	 * @return array<int, array{category: string, subcategory: string}>
	 */
	private function named(): array {
		$named = [];
		foreach ($this->taxonomy->tree() as $category) {
			foreach ($category['subcategories'] as $subcategory) {
				$named[$subcategory['id']] = [
					'category' => $category['slug'],
					'subcategory' => $subcategory['slug'],
				];
			}
		}

		return $named;
	}

	/**
	 * A record as another app wants it: slugs rather than ids, because an id is
	 * ours and a slug is shared.
	 *
	 * @param array<int, array{category: string, subcategory: string}> $named
	 * @return array<string, mixed>
	 */
	private function describe(Feature $feature, array $named): array {
		$names = $named[$feature->getSubcategoryId()] ?? null;

		return [
			'id' => (int)$feature->getId(),
			'name' => $feature->getName(),
			'kind' => $feature->getKind(),
			'category' => $names['category'] ?? null,
			'subcategory' => $names['subcategory'] ?? null,
			'address' => $feature->getAddress(),
			'geometry' => $feature->getGeometry() === null
				? null
				: json_decode($feature->getGeometry(), true),
		];
	}
}
