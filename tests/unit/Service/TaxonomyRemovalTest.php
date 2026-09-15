<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Db\Category;
use OCA\Territorio\Db\CategoryMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\Subcategory;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Exception\InvalidTaxonomyException;
use OCA\Territorio\Migration\EnsureSeedData;
use OCA\Territorio\Service\TaxonomyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The three refusals removal has to make, and the order it makes them in
 * (ADR-0016, issue #47).
 *
 * Here rather than only in the integration suite because the mappers are the
 * part that has to be OBSERVED, not exercised: the whole of this decision is
 * that nothing is deleted while a refusal is due, and a mock is the only thing
 * that can assert `delete()` was never reached. The integration suite proves the
 * other half — that the count sees a retired record, which no mock can know.
 *
 * `countBySubcategory()` is stubbed with the ids it is HANDED (`->with()`), not
 * merely with a number: a Category is refused on the count over all of its
 * Subcategories, and an implementation that counted only the first would pass
 * every assertion about the message while orphaning everything under the rest.
 */
final class TaxonomyRemovalTest extends TestCase {
	private CategoryMapper&MockObject $categories;
	private SubcategoryMapper&MockObject $subcategories;
	private FeatureMapper&MockObject $features;
	private TaxonomyService $taxonomy;

	protected function setUp(): void {
		$this->categories = $this->createMock(CategoryMapper::class);
		$this->subcategories = $this->createMock(SubcategoryMapper::class);
		$this->features = $this->createMock(FeatureMapper::class);
		$this->taxonomy = new TaxonomyService(
			$this->categories, $this->subcategories, $this->features,
		);
	}

	private static function category(int $id, string $slug, string $label): Category {
		$category = new Category();
		$category->setId($id);
		$category->setSlug($slug);
		$category->setLabel($label);
		$category->setColor('#c27b72');

		return $category;
	}

	private static function subcategory(int $id, int $categoryId, string $slug, string $label): Subcategory {
		$subcategory = new Subcategory();
		$subcategory->setId($id);
		$subcategory->setCategoryId($categoryId);
		$subcategory->setSlug($slug);
		$subcategory->setLabel($label);

		return $subcategory;
	}

	/**
	 * One Category with two Subcategories, and the seeded division beside it, as
	 * the mappers would answer.
	 */
	private function seedMappers(): void {
		$salud = self::category(1, 'salud', 'Salud');
		$divisions = self::category(2, EnsureSeedData::DIVISIONS_SLUG, 'División territorial');
		$this->categories->method('findAll')->willReturn([$salud, $divisions]);
		$this->categories->method('findBySlug')->willReturnCallback(
			static fn (string $slug): Category => match ($slug) {
				'salud' => $salud,
				EnsureSeedData::DIVISIONS_SLUG => $divisions,
				default => throw new \OCP\AppFramework\Db\DoesNotExistException('no'),
			},
		);
		$this->subcategories->method('findAll')->willReturn([
			self::subcategory(11, 1, 'cesfam', 'CESFAM'),
			self::subcategory(12, 1, 'otro', 'Otro'),
			self::subcategory(21, 2, 'sector', 'Sector'),
			self::subcategory(22, 2, 'unidad_vecinal', 'Unidad Vecinal'),
		]);
		$this->subcategories->method('findBySlug')->willReturnCallback(
			function (int $categoryId, string $slug): Subcategory {
				foreach ($this->subcategories->findAll() as $subcategory) {
					if ((int)$subcategory->getCategoryId() === $categoryId && $subcategory->getSlug() === $slug) {
						return $subcategory;
					}
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('no');
			},
		);
	}

	/**
	 * A Category is counted over EVERY Subcategory under it, in one question.
	 * Counting only the one a person happens to be looking at is how the records
	 * under its siblings get orphaned.
	 */
	public function testACategoryIsCountedOverAllOfItsSubcategoriesAtOnce(): void {
		$this->seedMappers();
		$this->features->expects(self::once())
			->method('countBySubcategory')
			->with([11, 12])
			->willReturn(0);

		$this->taxonomy->removeCategory('salud');
	}

	public function testACategoryWithRecordsUnderItIsRefusedAndNothingIsDeleted(): void {
		$this->seedMappers();
		$this->features->method('countBySubcategory')->willReturn(12);
		$this->categories->expects(self::never())->method('delete');
		$this->subcategories->expects(self::never())->method('delete');

		try {
			$this->taxonomy->removeCategory('salud');
			self::fail('a Category holding 12 records was removed, orphaning every one of them');
		} catch (InvalidTaxonomyException $e) {
			self::assertStringContainsString('12', $e->getMessage(), 'the refusal does not say how many');
			self::assertStringContainsString('Capas', $e->getMessage(),
				'the refusal does not say where to go and see them, so it is a dead end (ADR-0016)');
			self::assertStringContainsString('retirados', $e->getMessage(),
				'the refusal does not say that retired records are counted, so the number '
				. 'disagrees with what the panel shows and reads as a bug');
		}
	}

	/** One record is one record — a refusal that reads «hay 1 registros» reads as a bug. */
	public function testTheRefusalCountsInTheSingularWhenThereIsOne(): void {
		$this->seedMappers();
		$this->features->method('countBySubcategory')->willReturn(1);

		try {
			$this->taxonomy->removeSubcategory('salud', 'cesfam');
			self::fail('a Subcategory holding a record was removed');
		} catch (InvalidTaxonomyException $e) {
			self::assertStringContainsString('1 registro clasificado', $e->getMessage());
			self::assertStringNotContainsString('1 registros', $e->getMessage());
		}
	}

	/** The refusal names the thing the person clicked, not its slug. */
	public function testTheRefusalNamesTheCategoryAsItIsWritten(): void {
		$this->seedMappers();
		$this->features->method('countBySubcategory')->willReturn(3);

		try {
			$this->taxonomy->removeCategory('salud');
			self::fail('the Category was removed');
		} catch (InvalidTaxonomyException $e) {
			self::assertStringContainsString('«Salud»', $e->getMessage());
		}
	}

	/**
	 * An empty Category goes, and its Subcategories go with it.
	 *
	 * Leaving them behind would leave rows pointing at a Category id nothing
	 * answers to — and the served tree is built by grouping Subcategories under
	 * the Categories `findAll()` returns, so they would simply vanish from every
	 * screen while staying in the table forever.
	 */
	public function testAnEmptyCategoryIsRemovedWithTheSubcategoriesUnderIt(): void {
		$this->seedMappers();
		$this->features->method('countBySubcategory')->willReturn(0);

		$removedSubcategories = [];
		$this->subcategories->expects(self::exactly(2))
			->method('delete')
			->willReturnCallback(function (Subcategory $subcategory) use (&$removedSubcategories): Subcategory {
				$removedSubcategories[] = $subcategory->getSlug();

				return $subcategory;
			});
		$this->categories->expects(self::once())
			->method('delete')
			->willReturnCallback(static fn (Category $category): Category => $category);

		$this->taxonomy->removeCategory('salud');

		self::assertSame(['cesfam', 'otro'], $removedSubcategories);
	}

	/** A Subcategory goes on its own, and its Category stays. */
	public function testAnEmptySubcategoryIsRemovedAndItsCategoryStays(): void {
		$this->seedMappers();
		$this->features->expects(self::once())
			->method('countBySubcategory')
			->with([11])
			->willReturn(0);
		$this->categories->expects(self::never())->method('delete');
		$this->subcategories->expects(self::once())
			->method('delete')
			->willReturnCallback(function (Subcategory $subcategory): Subcategory {
				self::assertSame('cesfam', $subcategory->getSlug());

				return $subcategory;
			});

		$this->taxonomy->removeSubcategory('salud', 'cesfam');
	}

	/**
	 * «División territorial» is refused before anything is counted (ADR-0016).
	 *
	 * The order matters and is asserted: an empty division would pass the in-use
	 * guard, and the seed would put it back on the next upgrade — so the person
	 * would watch it disappear and reappear with no refusal anywhere.
	 */
	public function testTheStructuralCategoryIsRefusedWithoutEvenCounting(): void {
		$this->seedMappers();
		$this->features->expects(self::never())->method('countBySubcategory');
		$this->categories->expects(self::never())->method('delete');
		$this->subcategories->expects(self::never())->method('delete');

		$this->expectException(InvalidTaxonomyException::class);
		$this->expectExceptionMessageMatches('/divisi[oó]n territorial/iu');

		$this->taxonomy->removeCategory(EnsureSeedData::DIVISIONS_SLUG);
	}

	/** @return list<array{0: string}> */
	public static function structuralSubcategories(): array {
		return [['sector'], ['unidad_vecinal']];
	}

	/**
	 * A Sector and a Unidad Vecinal cannot be saved without one of these, and the
	 * seed restores them on every upgrade (ADR-0016).
	 *
	 * @dataProvider structuralSubcategories
	 */
	public function testNeitherSubcategoryOfTheDivisionCanBeRemoved(string $slug): void {
		$this->seedMappers();
		$this->features->expects(self::never())->method('countBySubcategory');
		$this->subcategories->expects(self::never())->method('delete');

		$this->expectException(InvalidTaxonomyException::class);

		$this->taxonomy->removeSubcategory(EnsureSeedData::DIVISIONS_SLUG, $slug);
	}

	/**
	 * The last Subcategory of a Category stays, and the refusal says what to do
	 * instead.
	 *
	 * Not in #47's criteria, and added because the path #47 opens is the only one
	 * that can reach the state CONTEXT.md says does not exist: "A Category always
	 * carries at least an «Otro» Subcategory… a Category with none is one nothing
	 * can be filed under and one neither the picker nor Análisis shows."
	 * `addCategory()` already goes out of its way to make sure a new Category has
	 * one. Emptying a Category would leave a branch visible in the Capas panel and
	 * absent from the two surfaces that matter, with no refusal anywhere.
	 *
	 * Refused rather than cascaded into removing the Category, for ADR-0016's own
	 * reason: «quitar esta subcategoría» and «quitar toda la categoría» are not
	 * the same sentence and must not be the same button.
	 */
	public function testTheLastSubcategoryOfACategoryCannotBeRemovedOnItsOwn(): void {
		$salud = self::category(1, 'salud', 'Salud');
		$this->categories->method('findBySlug')->willReturn($salud);
		$this->categories->method('findAll')->willReturn([$salud]);
		$only = self::subcategory(11, 1, 'otro', 'Otro');
		$this->subcategories->method('findAll')->willReturn([$only]);
		$this->subcategories->method('findBySlug')->willReturn($only);
		$this->features->method('countBySubcategory')->willReturn(0);
		$this->subcategories->expects(self::never())->method('delete');
		$this->categories->expects(self::never())->method('delete');

		try {
			$this->taxonomy->removeSubcategory('salud', 'otro');
			self::fail('«Salud» was left with no Subcategory: nothing can be filed under it, and '
				. 'it reaches neither the picker nor Análisis (CONTEXT.md)');
		} catch (InvalidTaxonomyException $e) {
			self::assertStringContainsString('categoría', $e->getMessage(),
				'the refusal does not say that removing the whole Category is the way to do this');
		}
	}

	/** The whole served tree comes back, because that is what the browser swaps in. */
	public function testARemovalAnswersWithTheWholeServedTree(): void {
		$this->seedMappers();
		$this->features->method('countBySubcategory')->willReturn(0);
		$this->subcategories->method('delete')
			->willReturnCallback(static fn (Subcategory $s): Subcategory => $s);

		$tree = $this->taxonomy->removeSubcategory('salud', 'cesfam');

		self::assertSame(['salud', EnsureSeedData::DIVISIONS_SLUG], array_column($tree, 'slug'));
	}
}
