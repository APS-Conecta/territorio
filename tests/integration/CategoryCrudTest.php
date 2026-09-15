<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Exception\InvalidTaxonomyException;
use OCA\Territorio\Migration\EnsureSeedData;
use OCA\Territorio\Service\TaxonomyService;
use OCP\AppFramework\Db\DoesNotExistException;
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

	protected function tearDown(): void {
		$taxonomy = Server::get(TaxonomyService::class);
		foreach ($this->restore as $path => $label) {
			$parts = explode('/', $path);
			count($parts) === 1
				? $taxonomy->renameCategory($parts[0], $label)
				: $taxonomy->renameSubcategory($parts[0], $parts[1], $label);
		}
		$this->restore = [];

		parent::tearDown();
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

	/** The whole tree comes back, because that is what the browser swaps in. */
	public function testTheRenameAnswersWithTheWholeServedTree(): void {
		$this->remember(self::CATEGORY);

		$tree = $this->taxonomy()->renameCategory(self::CATEGORY, 'Medioambiente y aseo');

		self::assertSame($this->taxonomy()->tree(), $tree);
		self::assertContains('Medioambiente y aseo', array_column($tree, 'label'));
	}
}
