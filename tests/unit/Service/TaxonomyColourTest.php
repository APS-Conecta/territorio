<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Db\Category;
use OCA\Territorio\Db\CategoryMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\Subcategory;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Migration\EnsureSeedData;
use OCA\Territorio\Service\TaxonomyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Which colour a new Category is given, at every state the spare pool can be in
 * (ADR-0017, issues #45 and #47).
 *
 * Here rather than only in `CategoryCrudTest` because the pool is a FINITE
 * SHARED RESOURCE and the integration suite cannot choose what state it is in:
 * on a long-lived instance every spare may already be held — by a clinic, or by
 * the browser gate's own leftovers, which cannot be taken away because a retired
 * record still counts against removal (ADR-0016). On that instance every add
 * correctly answers the neutral, and "two Categories take different colours" or
 * "the spare comes back" are not false, they are UNOBSERVABLE: every
 * implementation, right or wrong, hands out the neutral once the pool is drained
 * (issue #134).
 *
 * Mocked mappers make the pool an input. Exhausted, half-taken and empty are
 * three states written down here rather than three states waited for, so the
 * branches an instance may never reach are measured on every `make test`,
 * including CI — which deliberately does not run the integration suite.
 *
 * What this cannot see, and `CategoryCrudTest` still must: that `findAll()`
 * really answers with every Category on the real table, and that a colour stored
 * by another path comes back the way it went in. The rule is pure; reading the
 * table is not.
 */
final class TaxonomyColourTest extends TestCase {
	/** A colour no spare uses: the one the seed gives «Salud» (EnsureSeedData). */
	private const SEEDED = '#99564e';

	private CategoryMapper&MockObject $categories;
	private SubcategoryMapper&MockObject $subcategories;
	private FeatureMapper&MockObject $features;
	private TaxonomyService $taxonomy;

	/**
	 * The Categories the mappers answer with — the pool state, as a value.
	 *
	 * @var list<Category>
	 */
	private array $live = [];

	protected function setUp(): void {
		$this->categories = $this->createMock(CategoryMapper::class);
		$this->subcategories = $this->createMock(SubcategoryMapper::class);
		$this->features = $this->createMock(FeatureMapper::class);
		$this->taxonomy = new TaxonomyService(
			$this->categories, $this->subcategories, $this->features,
		);

		// A fake rather than a stub: `nextColour()` reads the Categories that
		// exist AFTER the previous insert, which is the whole of ADR-0017's
		// derivation. Frozen to the pool the first call saw, the two-in-a-row
		// test below goes red against the CORRECT implementation — it hands out
		// the first spare twice — which was watched, and is what "the pool is an
		// input" has to mean here.
		$this->categories->method('findAll')->willReturnCallback(fn (): array => $this->live);
		$this->categories->method('insert')->willReturnCallback(
			function (Category $category): Category {
				$category->setId(count($this->live) + 100);
				$this->live[] = $category;

				return $category;
			},
		);
		$this->categories->method('findBySlug')->willReturnCallback(
			function (string $slug): Category {
				foreach ($this->live as $category) {
					if ($category->getSlug() === $slug) {
						return $category;
					}
				}

				self::fail("the test asked for \"$slug\", which it never created");
			},
		);
		$this->categories->method('delete')->willReturnCallback(
			function (Category $gone): Category {
				$this->live = array_values(array_filter(
					$this->live,
					static fn (Category $category): bool => $category->getId() !== $gone->getId(),
				));

				return $gone;
			},
		);
		// Nothing here is about Subcategories; `addCategory()` inserts an «Otro»
		// and `tree()` reads them back, and both may answer with nothing.
		$this->subcategories->method('findAll')->willReturn([]);
		$this->subcategories->method('insert')->willReturnArgument(0);
	}

	/** Put Categories on the instance holding exactly these colours. */
	private function holding(string ...$colours): void {
		foreach ($colours as $index => $colour) {
			$category = new Category();
			$category->setId($index + 1);
			$category->setSlug('ya_existe_' . $index);
			$category->setLabel('Ya existe ' . $index);
			$category->setColor($colour);
			$this->live[] = $category;
		}
	}

	/** Add a Category and answer with the colour the app gave it. */
	private function colourGivenTo(string $label): string {
		$this->taxonomy->addCategory($label);
		foreach ($this->live as $category) {
			if ($category->getSlug() === TaxonomyService::slugFor($label)) {
				return $category->getColor();
			}
		}

		self::fail("«$label» was added but no Category carries its slug");
	}

	public function testANewCategoryTakesTheFirstSpareNobodyHolds(): void {
		$this->holding(self::SEEDED);

		self::assertSame(EnsureSeedData::SPARES[0], $this->colourGivenTo('Comité de vivienda'));
	}

	/**
	 * The criterion a person states as "they do not all come out the same
	 * colour", pinned to the two exact spares rather than to "different": an
	 * implementation that handed out the first spare twice fails on the second
	 * value.
	 *
	 * What it does NOT separate is the derivation from a counter: while nothing
	 * has been handed back, `SPARES[count($taken) - 1]` answers with these same
	 * two spares and passes here. Watched, not predicted — that implementation
	 * was injected and this test stayed green. The hand-back below is the one
	 * that went red, and it is the only one that did.
	 */
	public function testTwoCategoriesAddedInARowTakeTheFirstTwoSpares(): void {
		$this->holding(self::SEEDED);

		self::assertSame(EnsureSeedData::SPARES[0], $this->colourGivenTo('Comité de deportes'));
		self::assertSame(EnsureSeedData::SPARES[1], $this->colourGivenTo('Comité de cultura'));
	}

	/**
	 * A spare somebody already holds is skipped — and holding it in capitals is
	 * still holding it. `RampRepaintTest` writes a colour directly and a stored
	 * hex is one a person may have typed, so the comparison is the one
	 * `nextColour()` documents rather than the one a reader assumes.
	 */
	public function testASpareAlreadyHeldIsSkippedWhateverCaseItIsStoredIn(): void {
		$this->holding(self::SEEDED, strtoupper(EnsureSeedData::SPARES[0]));

		self::assertSame(EnsureSeedData::SPARES[1], $this->colourGivenTo('Comité de adelanto'));
	}

	/**
	 * The drained pool, which is the state issue #134's instance is permanently
	 * in: the Category is still created and takes the neutral, and so does the
	 * next one. Two Categories sharing the neutral is not an error — hue only
	 * groups Categories and the glyph identifies them (ADR-0013).
	 *
	 * The second add is what makes this more than a restatement of the first, and
	 * what it catches is narrower than "a counter": an implementation that
	 * answers the neutral once and then resumes handing out spares gives the
	 * second Category one somebody already holds. Injected and watched going red
	 * on this line, and on this line only.
	 *
	 * A counter is NOT caught here: past the end of the list a counter answers
	 * the neutral too, so both adds agree with the derivation. The hand-back
	 * below is where that one shows.
	 */
	public function testWithEverySpareHeldTheCategoryIsStillCreatedAndTakesTheNeutral(): void {
		$this->holding(self::SEEDED, ...EnsureSeedData::SPARES);

		self::assertSame(EnsureSeedData::NEUTRAL, $this->colourGivenTo('Comité sin color'));
		self::assertSame(EnsureSeedData::NEUTRAL, $this->colourGivenTo('Comité sin color tampoco'));
	}

	/**
	 * ADR-0017's reason for having no counter: removing a Category hands its
	 * spare back, because the answer is derived from the Categories that exist.
	 *
	 * The one taken away is the FOURTH spare rather than the last, so a hand-back
	 * that merely moved a high-water mark backwards would answer with the eighth
	 * and fail here.
	 */
	public function testARemovedCategoryHandsItsSpareBack(): void {
		$this->holding(self::SEEDED, ...EnsureSeedData::SPARES);
		$this->features->method('countBySubcategory')->willReturn(0);
		$freed = EnsureSeedData::SPARES[3];

		$this->taxonomy->removeCategory('ya_existe_4');

		self::assertSame($freed, $this->colourGivenTo('Comité siguiente'));
	}

	/**
	 * And a Category still holding records keeps its spare, because the removal
	 * never happens (ADR-0016). Worth stating beside the hand-back: the pair is
	 * why the browser gate's leftovers can hold the whole pool on a long-lived
	 * instance and nothing can hand it back.
	 */
	public function testACategoryThatCannotBeRemovedKeepsItsSpare(): void {
		$this->holding(self::SEEDED, ...EnsureSeedData::SPARES);
		$this->features->method('countBySubcategory')->willReturn(2);

		try {
			$this->taxonomy->removeCategory('ya_existe_4');
			self::fail('a Category with records under it was removed');
		} catch (\OCA\Territorio\Exception\InvalidTaxonomyException) {
		}

		self::assertSame(EnsureSeedData::NEUTRAL, $this->colourGivenTo('Comité siguiente'));
	}
}
