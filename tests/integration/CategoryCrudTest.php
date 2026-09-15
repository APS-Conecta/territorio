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

	/** @var int[] Categories this test added, with everything filed under them. */
	private array $addedCategories = [];

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

		$db = Server::get(IDBConnection::class);

		if ($this->added !== []) {
			// Raw SQL, for the reason `IntegrationTestCase` gives about hard-deleting
			// a Feature: the app has no way to remove a Subcategory yet (#47), and
			// adding one so the tests could tidy up would be building the next slice
			// to make this one green. Left behind, each run would add a row the next
			// run's duplicate check would collide with.
			$qb = $db->getQueryBuilder();
			$qb->delete('territorio_subcategory')
				->where($qb->expr()->in('id', $qb->createNamedParameter(
					$this->added, IQueryBuilder::PARAM_INT_ARRAY,
				)))
				->executeStatement();
			$this->added = [];
		}

		if ($this->addedCategories === []) {
			return;
		}

		// Same raw-SQL reason, and it matters more here than for a Subcategory:
		// a Category holds a SPARE COLOUR while it exists (ADR-0017), and the
		// exhaustion test below walks the whole spare list. One run leaving its
		// Categories behind would leave the next run with fewer spares to spend,
		// so the tidying is part of what makes these tests repeatable.
		//
		// Subcategories first: they carry the Category's id and nothing in the
		// schema would catch the orphan (issue #40).
		foreach (['territorio_subcategory' => 'category_id', 'territorio_category' => 'id'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in($column, $qb->createNamedParameter(
					$this->addedCategories, IQueryBuilder::PARAM_INT_ARRAY,
				)))
				->executeStatement();
		}
		$this->addedCategories = [];
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

	/**
	 * Add a Category and register it — with everything under it — for cleanup.
	 *
	 * Reads it back out of the tree the service answered with rather than being
	 * told its id, for the reason `add()` above gives: that tree is what the
	 * browser swaps in, so a row missing from it is a row no screen would show.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>} the served tree and the new Category
	 */
	private function addCategory(string $label): array {
		$tree = $this->taxonomy()->addCategory($label);
		$slug = TaxonomyService::slugFor($label);
		$category = $this->served($slug);
		self::assertNotNull($category, "«$label» was added but is not served as \"$slug\"");
		$this->addedCategories[] = (int)$category['id'];

		return [$tree, $category];
	}

	/**
	 * The spares no Category holds right now, in the order the app hands them out.
	 *
	 * Derived rather than assumed to be all eight: the browser gate adds a
	 * Category per run and cannot remove one until #47, so on the dev instance
	 * these tests read, some spares are already spent. A test that hard-coded
	 * eight would go red on a correct install — and the contract it is really
	 * asserting is "the next unused one" (ADR-0017), which this states exactly.
	 *
	 * @return list<string>
	 */
	private function freeSpares(): array {
		$taken = array_map(
			static fn (array $category): string => strtolower($category['color']),
			$this->taxonomy()->tree(),
		);

		return array_values(array_filter(
			EnsureSeedData::SPARES,
			static fn (string $spare): bool => !in_array(strtolower($spare), $taken, true),
		));
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

	/**
	 * Issue #45: a clinic names a Category the seed does not have, and it is
	 * there — with a slug nobody typed and a colour nobody chose.
	 *
	 * The colour is pinned to the exact spare the app owed it rather than to
	 * "some spare": ADR-0017 says assigning a colour IS taking the next unused
	 * one, and an assertion that merely checks membership passes just as well
	 * against an implementation that always hands out the first.
	 */
	public function testAnAddedCategoryIsServedWithADerivedSlugAndTheNextUnusedSpare(): void {
		$owed = $this->freeSpares();
		self::assertNotEmpty($owed, 'no spare is free on this instance, so this test would assert nothing');

		[$tree, $category] = $this->addCategory('Comité de vivienda');

		self::assertSame('comite_de_vivienda', $category['slug']);
		self::assertSame('Comité de vivienda', $category['label']);
		self::assertGreaterThan(0, $category['id']);
		self::assertSame($owed[0], $category['color'],
			'the Category did not take the next unused spare (ADR-0017)');
		self::assertSame($this->taxonomy()->tree(), $tree,
			'the add answered with something other than the tree the browser swaps in');
	}

	/**
	 * A Category nothing can be filed under is a Category that reaches neither
	 * the Subcategoría picker nor Análisis — a Feature travels as a Subcategory
	 * id, and both of those surfaces are read off the Subcategories. So a new one
	 * arrives with the «Otro» all eleven seeded Categories carry.
	 */
	public function testAnAddedCategoryArrivesWithASubcategoryARecordCanBeFiledUnder(): void {
		[, $category] = $this->addCategory('Comité de seguridad');

		self::assertCount(1, $category['subcategories'],
			'a Category with no Subcategory cannot appear in the picker and cannot hold a record');
		$subcategory = $category['subcategories'][0];
		self::assertSame('otro', $subcategory['slug']);

		$place = $this->place('Sede de prueba', ['subcategoryId' => (int)$subcategory['id']]);

		self::assertSame(
			(int)$subcategory['id'],
			(int)Server::get(FeatureMapper::class)->find($place)->getSubcategoryId(),
			'a record could not be filed under a Category that had just been added',
		);
	}

	/**
	 * The criterion a person would state as "they do not all come out the same
	 * colour". It is the one an implementation holding a counter, or reading the
	 * spare list without looking at what is already taken, gets wrong.
	 */
	public function testTwoCategoriesAddedInARowTakeDifferentColours(): void {
		self::assertGreaterThanOrEqual(2, count($this->freeSpares()),
			'fewer than two spares are free, so both of these would correctly take the neutral');

		[, $first] = $this->addCategory('Comité de deportes');
		[, $second] = $this->addCategory('Comité de cultura');

		self::assertNotSame($first['color'], $second['color'],
			'two Categories added in a row took the same colour');
		self::assertNotSame(EnsureSeedData::NEUTRAL, $second['color'],
			'the second add fell to the neutral while a spare was still free');
	}

	/**
	 * Spend every spare that is left, then one more.
	 *
	 * Eight is a real ceiling and not a round number (ADR-0017), so what happens
	 * at it is behaviour, not an edge case: the Category is still created, it
	 * takes the neutral, and nothing about that is an error — hue only groups
	 * Categories into families and the glyph identifies them (ADR-0013).
	 *
	 * The colours are collected and compared as a set as well as one by one: an
	 * implementation that handed out the same spare twice would pass every
	 * individual "is a spare" assertion.
	 */
	public function testWhenTheSparesRunOutTheCategoryIsStillCreatedAndTakesTheNeutral(): void {
		$owed = $this->freeSpares();

		$given = [];
		foreach ($owed as $index => $spare) {
			[, $category] = $this->addCategory(sprintf('Comité de prueba %d', $index));
			$given[] = $category['color'];
			self::assertSame($spare, $category['color'], sprintf(
				'Category %d took %s where the next unused spare was %s',
				$index, $category['color'], $spare,
			));
		}
		self::assertCount(count($owed), array_unique($given),
			'the same spare was handed out twice');

		[, $overflow] = $this->addCategory('Comité sin color');

		self::assertSame(EnsureSeedData::NEUTRAL, $overflow['color'],
			'with no spare left the Category took something other than the neutral');
		self::assertSame('comite_sin_color', $overflow['slug'],
			'the Category past the ceiling was not created properly — it is still a Category');
		self::assertCount(1, $overflow['subcategories'],
			'the Category past the ceiling arrived without the Subcategory that makes it usable');
	}

	/**
	 * Both halves of the same criterion: the clinic's Category is still there
	 * after an upgrade, and its existence changed nothing about the seeded ones.
	 *
	 * The second half is the one nobody would think to check by hand. `ensureRamp()`
	 * repaints row by row on the OLD colour, and a spare handed to a clinic sits in
	 * the same palette the eleven do — so a guard that matched more loosely would
	 * drag a seeded Category onto a colour a clinic's Category already holds, on an
	 * upgrade, with every other suite green.
	 */
	public function testAnAddedCategorySurvivesAnUpgradeAndLeavesTheSeededElevenUnchanged(): void {
		$before = $this->byslug($this->taxonomy()->tree());

		[, $category] = $this->addCategory('Comité de vivienda social');

		self::assertSame($before, array_diff_key($this->byslug($this->taxonomy()->tree()), [
			$category['slug'] => null,
		]), 'adding a Category changed one that was already there');

		Server::get(EnsureSeedData::class)->run($this->silentOutput());

		$after = $this->byslug($this->taxonomy()->tree());
		self::assertSame($before, array_diff_key($after, [$category['slug'] => null]),
			'the upgrade changed a Category that was already there');
		self::assertArrayHasKey($category['slug'], $after,
			'the upgrade removed a Category the clinic added');
		self::assertSame($category, $after[$category['slug']],
			'the added Category came back repainted, renamed or with a new id');
	}

	/** The served tree keyed by slug, so a comparison does not turn on ordering. */
	private function byslug(array $tree): array {
		return array_column($tree, null, 'slug');
	}

	/**
	 * `terr_cat_slug_idx` is unique on the slug alone — globally, unlike a
	 * Subcategory's — and this is the half that refuses. Asserted on a label
	 * differing only in case and accents, because the rule is on the DERIVED
	 * slug: two names a person reads as the same name are the same name.
	 */
	public function testASecondCategoryWithTheSameNameIsRefused(): void {
		$this->addCategory('Comité de adelanto');
		$before = count($this->taxonomy()->tree());

		try {
			$this->taxonomy()->addCategory('  COMITE DE ADELANTO  ');
			self::fail('a second «Comité de adelanto» was accepted');
		} catch (InvalidTaxonomyException $e) {
			self::assertStringContainsString('Ya existe', $e->getMessage());
		}

		self::assertCount($before, $this->taxonomy()->tree(),
			'the refusal wrote a Category anyway, and the index did not stop it');
	}

	/** @dataProvider blankLabels */
	public function testABlankCategoryNameIsRefusedAndNothingIsWritten(string $label): void {
		$before = count($this->taxonomy()->tree());

		try {
			$this->taxonomy()->addCategory($label);
			self::fail('a blank name was accepted, leaving a panel branch nothing can be read');
		} catch (InvalidTaxonomyException $e) {
			self::assertStringContainsString('vacío', $e->getMessage());
		}

		self::assertCount($before, $this->taxonomy()->tree());
	}

	/**
	 * A name of punctuation passes the blank check and derives to nothing. Stored,
	 * it would be a Category with an empty slug — and the next one would collide
	 * with it, so the first anybody would hear of it is a refusal about a
	 * duplicate that reads as nonsense. Same rule as a Subcategory's, and it is
	 * the service that holds it.
	 */
	public function testACategoryNameWithNothingToKeyOnIsRefused(): void {
		$before = count($this->taxonomy()->tree());

		try {
			$this->taxonomy()->addCategory('«»');
			self::fail('a name that derives to no slug at all was accepted');
		} catch (InvalidTaxonomyException $e) {
			self::assertStringContainsString('letra', $e->getMessage());
		}

		self::assertCount($before, $this->taxonomy()->tree());
	}

	/** The whole tree comes back, because that is what the browser swaps in. */
	public function testTheRenameAnswersWithTheWholeServedTree(): void {
		$this->remember(self::CATEGORY);

		$tree = $this->taxonomy()->renameCategory(self::CATEGORY, 'Medioambiente y aseo');

		self::assertSame($this->taxonomy()->tree(), $tree);
		self::assertContains('Medioambiente y aseo', array_column($tree, 'label'));
	}
}
