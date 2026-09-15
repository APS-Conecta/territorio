<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Exception\InvalidTaxonomyException;
use OCA\Territorio\Migration\EnsureSeedData;
use OCA\Territorio\Service\TaxonomyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;

/**
 * Renaming a Category and a Subcategory, observed through the tree the app
 * serves.
 *
 * The primary seam for the taxonomy's write path, per issue #40's Testing
 * Decisions: a unit test with mocked mappers cannot see the unique indexes, the
 * absence of any foreign key from a Feature to its Subcategory, or the fact that
 * "otro" repeats across nearly every Category — which is the whole of the risk
 * here. `RampRepaintTest` is the precedent, including reading back through
 * `TaxonomyService::tree()` rather than the table: a test that asserts against
 * the row it just wrote proves the database works, not that the app does.
 */
final class CategoryCrudTest extends IntegrationTestCase {
	/** The Category this test renames. Not one another test paints or counts. */
	private const CATEGORY = 'medioambiente';
	private const SUBCATEGORY = 'punto_limpio';

	/** Labels to put back, keyed "categorySlug" or "categorySlug/subSlug". */
	private array $restore = [];

	/** @var int[] Subcategories this test added, to be removed afterwards. */
	private array $added = [];

	protected function tearDown(): void {
		$taxonomy = Server::get(TaxonomyService::class);
		foreach ($this->restore as $path => $label) {
			$parts = explode('/', $path);
			count($parts) === 1
				? $taxonomy->renameCategory($parts[0], $label)
				: $taxonomy->renameSubcategory($parts[0], $parts[1], $label);
		}
		$this->restore = [];

		// The Features first, which is what the parent does: one of them may be
		// filed under a Subcategory removed just below, and nothing in the schema
		// would catch the orphan (issue #40).
		parent::tearDown();

		if ($this->added === []) {
			return;
		}

		// Raw SQL, for the reason `IntegrationTestCase` gives about hard-deleting
		// a Feature: the app has no way to remove a Subcategory yet (#47), and
		// adding one so the tests could tidy up would be building the next slice
		// to make this one green. Left behind, each run would add a row the next
		// run's duplicate check would collide with.
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete('territorio_subcategory')
			->where($qb->expr()->in('id', $qb->createNamedParameter(
				$this->added, IQueryBuilder::PARAM_INT_ARRAY,
			)))
			->executeStatement();
		$this->added = [];
	}

	/**
	 * Add a Subcategory and register it for cleanup.
	 *
	 * Reads the id back out of the tree the service answered with, rather than
	 * being told it: that tree is what the browser swaps in, so a row that is not
	 * in it is a row no screen would show either.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>} the served tree and the new Subcategory
	 */
	private function add(string $categorySlug, string $label): array {
		$tree = $this->taxonomy()->addSubcategory($categorySlug, $label);
		$slug = TaxonomyService::slugFor($label);
		$subcategory = $this->servedSub($categorySlug, $slug);
		self::assertNotNull(
			$subcategory,
			"«$label» was added under \"$categorySlug\" but is not served as \"$slug\"",
		);
		$this->added[] = (int)$subcategory['id'];

		return [$tree, $subcategory];
	}

	private function taxonomy(): TaxonomyService {
		return Server::get(TaxonomyService::class);
	}

	/** One Category as the app would serve it right now, or null. */
	private function served(string $slug): ?array {
		foreach ($this->taxonomy()->tree() as $category) {
			if ($category['slug'] === $slug) {
				return $category;
			}
		}

		return null;
	}

	/** One Subcategory of one Category, as served. */
	private function servedSub(string $categorySlug, string $slug): ?array {
		foreach ($this->served($categorySlug)['subcategories'] ?? [] as $subcategory) {
			if ($subcategory['slug'] === $slug) {
				return $subcategory;
			}
		}

		return null;
	}

	/** Remember a label so tearDown puts the instance back as it found it. */
	private function remember(string $path): void {
		$parts = explode('/', $path);
		$node = count($parts) === 1
			? $this->served($parts[0])
			: $this->servedSub($parts[0], $parts[1]);
		self::assertNotNull($node, "the seed should have created \"$path\"");
		$this->restore[$path] = $node['label'];
	}

	/** Swallows the repair step's chatter, as RampRepaintTest does. */
	private function silentOutput(): IOutput {
		return new class implements IOutput {
			public function debug(string $message): void {
			}

			public function info($message) {
			}

			public function warning($message) {
			}

			public function startProgress($max = 0) {
			}

			public function advance($step = 1, $description = '') {
			}

			public function finishProgress() {
			}
		};
	}

	public function testACategoryKeepsItsSlugAndItsRecordsWhenItIsRenamed(): void {
		$this->remember(self::CATEGORY);
		$before = $this->served(self::CATEGORY);
		$place = $this->place('Punto limpio de prueba', [
			'subcategoryId' => $this->subcategoryIdBySlug(self::SUBCATEGORY),
		]);

		$this->taxonomy()->renameCategory(self::CATEGORY, 'Medio ambiente y reciclaje');

		$after = $this->served(self::CATEGORY);
		self::assertSame('Medio ambiente y reciclaje', $after['label']);
		self::assertSame($before['slug'], $after['slug'], 'the slug moved, so every record filed under it is adrift');
		self::assertSame($before['id'], $after['id']);
		self::assertSame(
			array_column($before['subcategories'], 'slug'),
			array_column($after['subcategories'], 'slug'),
			'renaming a Category disturbed the Subcategories under it',
		);
		self::assertSame(
			$this->subcategoryIdBySlug(self::SUBCATEGORY),
			(int)Server::get(FeatureMapper::class)->find($place)->getSubcategoryId(),
			'the record is no longer filed where it was',
		);
	}

	public function testASubcategoryKeepsItsSlugAndItsIdWhenItIsRenamed(): void {
		$path = self::CATEGORY . '/' . self::SUBCATEGORY;
		$this->remember($path);
		$before = $this->servedSub(self::CATEGORY, self::SUBCATEGORY);

		$this->taxonomy()->renameSubcategory(self::CATEGORY, self::SUBCATEGORY, 'Punto limpio municipal');

		$after = $this->servedSub(self::CATEGORY, self::SUBCATEGORY);
		self::assertSame('Punto limpio municipal', $after['label']);
		self::assertSame($before['slug'], $after['slug']);
		self::assertSame($before['id'], $after['id'],
			'a Feature travels as a Subcategory id, so a new id is a record pointing at nothing');
	}

	/**
	 * "otro" is in nearly every Category, and the unique index is on
	 * `(category_id, slug)` for exactly that reason. A lookup that forgot the
	 * Category would rename whichever row came first.
	 */
	public function testRenamingASubcategoryLeavesTheSameSlugUnderOtherCategoriesAlone(): void {
		$this->remember(self::CATEGORY . '/otro');
		$elsewhere = $this->servedSub('salud', 'otro');

		$this->taxonomy()->renameSubcategory(self::CATEGORY, 'otro', 'Otro (medioambiente)');

		self::assertSame('Otro (medioambiente)', $this->servedSub(self::CATEGORY, 'otro')['label']);
		self::assertSame($elsewhere['label'], $this->servedSub('salud', 'otro')['label'],
			'the rename reached "otro" under another Category, so it matched on slug alone');
	}

	/**
	 * The seed is idempotent and "does not restore what a clinic renamed"
	 * (EnsureSeedData). That is a promise about an upgrade, and an upgrade is the
	 * one moment nobody is watching — so it is proved here rather than assumed,
	 * by running the repair step in-process the way Nextcloud does.
	 */
	public function testARenameSurvivesAnUpgrade(): void {
		$this->remember(self::CATEGORY);
		$this->remember(self::CATEGORY . '/' . self::SUBCATEGORY);
		$this->taxonomy()->renameCategory(self::CATEGORY, 'Reciclaje');
		$this->taxonomy()->renameSubcategory(self::CATEGORY, self::SUBCATEGORY, 'Punto verde');

		Server::get(EnsureSeedData::class)->run($this->silentOutput());

		self::assertSame('Reciclaje', $this->served(self::CATEGORY)['label'],
			'the seed put the shipped label back over the one the clinic chose');
		self::assertSame('Punto verde', $this->servedSub(self::CATEGORY, self::SUBCATEGORY)['label']);
	}

	/**
	 * «División territorial» is the one the seed ensures row by row rather than
	 * only when the table is empty, so it is the rename most likely to be undone.
	 */
	public function testEvenTheStructuralCategorySurvivesAnUpgrade(): void {
		$this->remember(EnsureSeedData::DIVISIONS_SLUG);
		$this->taxonomy()->renameCategory(EnsureSeedData::DIVISIONS_SLUG, 'División territorial (comuna)');

		Server::get(EnsureSeedData::class)->run($this->silentOutput());

		self::assertSame(
			'División territorial (comuna)',
			$this->served(EnsureSeedData::DIVISIONS_SLUG)['label'],
			'ensureDivisions() matches on slug, so a renamed division must not be restored — '
			. 'and a second row must not appear beside it',
		);
	}

	/** @return list<array{0: string}> */
	public static function blankLabels(): array {
		return [[''], ['   '], ["\t\n"]];
	}

	/** @dataProvider blankLabels */
	public function testABlankLabelIsRefusedAndSaysWhy(string $label): void {
		$before = $this->served(self::CATEGORY)['label'];

		try {
			$this->taxonomy()->renameCategory(self::CATEGORY, $label);
			self::fail('a blank label was accepted, leaving a panel row nothing can be clicked back into');
		} catch (InvalidTaxonomyException $e) {
			self::assertStringContainsString('vacío', $e->getMessage());
		}

		self::assertSame($before, $this->served(self::CATEGORY)['label'],
			'the refusal still wrote something');
	}

	/** Same refusal for a Subcategory: one rule, and it is the service that holds it. */
	public function testABlankSubcategoryLabelIsRefusedToo(): void {
		$this->expectException(InvalidTaxonomyException::class);

		$this->taxonomy()->renameSubcategory(self::CATEGORY, self::SUBCATEGORY, '  ');
	}

	/**
	 * `label` is VARCHAR(128). Without this the refusal would arrive as the
	 * database's own error through a 500, which names nothing a person can fix.
	 */
	public function testAnOverlongLabelIsRefusedRatherThanTruncatedOrThrown(): void {
		$before = $this->served(self::CATEGORY)['label'];

		try {
			$this->taxonomy()->renameCategory(self::CATEGORY, str_repeat('á', 129));
			self::fail('a 129-character label was accepted into a 128-character column');
		} catch (InvalidTaxonomyException $e) {
			self::assertStringContainsString('128', $e->getMessage());
		}

		self::assertSame($before, $this->served(self::CATEGORY)['label']);
	}

	public function testRenamingACategoryNobodyHasIsNotFound(): void {
		$this->expectException(DoesNotExistException::class);

		$this->taxonomy()->renameCategory('no-existe', 'Cualquier cosa');
	}

	public function testRenamingASubcategoryNobodyHasIsNotFound(): void {
		$this->expectException(DoesNotExistException::class);

		$this->taxonomy()->renameSubcategory(self::CATEGORY, 'no-existe', 'Cualquier cosa');
	}

	/**
	 * Issue #44: a clinic names something the seed does not have, and it is there
	 * — under the Category it was added to, with a slug nobody typed.
	 */
	public function testAnAddedSubcategoryIsServedUnderItsCategoryWithADerivedSlug(): void {
		[$tree, $subcategory] = $this->add(self::CATEGORY, 'Punto de reciclaje móvil');

		self::assertSame('punto_de_reciclaje_movil', $subcategory['slug']);
		self::assertSame('Punto de reciclaje móvil', $subcategory['label']);
		self::assertGreaterThan(0, $subcategory['id']);
		self::assertSame($this->taxonomy()->tree(), $tree,
			'the add answered with something other than the tree the browser swaps in');
		self::assertNull($this->servedSub('salud', 'punto_de_reciclaje_movil'),
			'the new Subcategory appeared under a Category nobody added it to');
	}

	/**
	 * The acceptance criterion a person would state as "I can use it straight
	 * away". A Feature travels as a Subcategory id, so the id the tree serves has
	 * to be one a record can actually be filed under.
	 */
	public function testARecordCanBeFiledUnderANewSubcategoryStraightAway(): void {
		[, $subcategory] = $this->add(self::CATEGORY, 'Compostaje comunitario');

		$place = $this->place('Compostera de prueba', ['subcategoryId' => (int)$subcategory['id']]);

		self::assertSame(
			(int)$subcategory['id'],
			(int)Server::get(FeatureMapper::class)->find($place)->getSubcategoryId(),
			'the record was not filed under the Subcategory that was just added',
		);
	}

	/**
	 * `terr_subcat_slug_idx` is `(category_id, slug)`, and this is the half of it
	 * that refuses. Asserted on a label that differs only in case and accents,
	 * because the rule is on the DERIVED slug: two rows a person would read as
	 * the same name are the same name.
	 */
	public function testASecondSubcategoryWithTheSameNameInTheSameCategoryIsRefused(): void {
		$this->add(self::CATEGORY, 'Huerta vecinal');

		try {
			$this->taxonomy()->addSubcategory(self::CATEGORY, '  HUERTA VECINAL  ');
			self::fail('a second «Huerta vecinal» was accepted into the same Category');
		} catch (InvalidTaxonomyException $e) {
			self::assertStringContainsString('Ya existe', $e->getMessage());
		}

		self::assertCount(
			1,
			array_filter(
				$this->served(self::CATEGORY)['subcategories'],
				static fn (array $row): bool => $row['slug'] === 'huerta_vecinal',
			),
			'the refusal still wrote a second row, and the index did not stop it',
		);
	}

	/**
	 * The other half of the same index: the same name under a DIFFERENT Category
	 * is a different thing, which is why "otro" is in nearly all of them. A
	 * unique index on the slug alone would refuse this.
	 */
	public function testTheSameNameUnderADifferentCategoryIsAccepted(): void {
		[, $here] = $this->add(self::CATEGORY, 'Mesa de trabajo');
		[, $elsewhere] = $this->add('salud', 'Mesa de trabajo');

		self::assertSame($here['slug'], $elsewhere['slug']);
		self::assertNotSame($here['id'], $elsewhere['id'],
			'the second add returned the first row, so nothing was created');
	}

	/** @dataProvider blankLabels */
	public function testABlankSubcategoryNameIsRefusedAndNothingIsWritten(string $label): void {
		$before = count($this->served(self::CATEGORY)['subcategories']);

		try {
			$this->taxonomy()->addSubcategory(self::CATEGORY, $label);
			self::fail('a blank name was accepted, leaving a panel row nothing can be read');
		} catch (InvalidTaxonomyException $e) {
			self::assertStringContainsString('vacío', $e->getMessage());
		}

		self::assertCount($before, $this->served(self::CATEGORY)['subcategories']);
	}

	/**
	 * A name of punctuation trims to something, passes the blank check and then
	 * derives to nothing. Stored, it would be an empty slug — and the next one
	 * would collide with it, so the first anyone would hear of it is a refusal
	 * about a duplicate that reads as nonsense.
	 */
	public function testANameWithNothingToKeyOnIsRefusedRatherThanStoredWithAnEmptySlug(): void {
		$before = count($this->served(self::CATEGORY)['subcategories']);

		try {
			$this->taxonomy()->addSubcategory(self::CATEGORY, '«»');
			self::fail('a name that derives to no slug at all was accepted');
		} catch (InvalidTaxonomyException $e) {
			self::assertStringContainsString('letra', $e->getMessage());
		}

		self::assertCount($before, $this->served(self::CATEGORY)['subcategories']);
	}

	public function testAddingUnderACategoryNobodyHasIsNotFound(): void {
		$this->expectException(DoesNotExistException::class);

		$this->taxonomy()->addSubcategory('no-existe', 'Cualquier cosa');
	}

	/**
	 * The seed fills the taxonomy only into an empty table, so an added row
	 * should survive — but "should" is what `testARenameSurvivesAnUpgrade` was
	 * written to stop anybody saying, and an upgrade is the moment nobody is
	 * watching.
	 */
	public function testANewSubcategorySurvivesAnUpgrade(): void {
		[, $subcategory] = $this->add(self::CATEGORY, 'Reciclaje vecinal');

		Server::get(EnsureSeedData::class)->run($this->silentOutput());

		$after = $this->servedSub(self::CATEGORY, 'reciclaje_vecinal');
		self::assertNotNull($after, 'the upgrade removed a Subcategory the clinic added');
		self::assertSame($subcategory['id'], $after['id'],
			'the row was rewritten with a new id, so every record filed under it is adrift');
	}

	/** The whole tree comes back, because that is what the browser swaps in. */
	public function testTheRenameAnswersWithTheWholeServedTree(): void {
		$this->remember(self::CATEGORY);

		$tree = $this->taxonomy()->renameCategory(self::CATEGORY, 'Medioambiente y aseo');

		self::assertSame($this->taxonomy()->tree(), $tree);
		self::assertContains('Medioambiente y aseo', array_column($tree, 'label'));
	}
}
