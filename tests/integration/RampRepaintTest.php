<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\CategoryMapper;
use OCA\Territorio\Migration\EnsureSeedData;
use OCA\Territorio\Service\TaxonomyService;
use OCP\Migration\IOutput;
use OCP\Server;

/**
 * The repaint carries the designed ramp to an install that already exists, and
 * stops at a colour the clinic chose for itself.
 *
 * `ensureTaxonomy()` returns the moment the Category table has rows, so on every
 * install but a brand new one a change to the seed reaches nobody. `ensureRamp()`
 * is what carries it — and its guard is the whole of the risk, because a Category
 * is editable data (CONTEXT.md) and a clinic's own colour is a decision, not
 * drift.
 *
 * Written because that guard was proved only by hand, against a dev instance, in
 * the session that introduced it. Nothing re-ran it. Narrowing it to `WHERE slug`
 * would have repainted over every clinic's choice with all six suites green.
 *
 * Acts on `run()` — the public IRepairStep interface Nextcloud itself calls — and
 * observes through `TaxonomyService::tree()`, the same read path that feeds the
 * Capas panel, rather than reading the table back. A test that asserts against
 * the row it just wrote proves the database works, not that the app does.
 */
final class RampRepaintTest extends IntegrationTestCase {
	/**
	 * Colours to put back afterwards.
	 *
	 * Only the ones a test deliberately moved. `repair()` also settles any OTHER
	 * Category sitting on an old seeded colour, and those are not restored —
	 * correctly, because settling them is the behaviour under test and putting
	 * them back would be undoing the app's own work.
	 *
	 * @var array<string, string>
	 */
	private array $restore = [];

	protected function tearDown(): void {
		foreach ($this->restore as $slug => $colour) {
			$this->paint($slug, $colour);
		}
		$this->restore = [];

		parent::tearDown();
	}

	/** Swallows everything: the repair step's chatter is not what is under test. */
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

	private function repair(): void {
		Server::get(EnsureSeedData::class)->run($this->silentOutput());
	}

	/** What the app would serve to a browser right now. */
	private function colourOf(string $slug): ?string {
		foreach (Server::get(TaxonomyService::class)->tree() as $category) {
			if ($category['slug'] === $slug) {
				return $category['color'];
			}
		}

		return null;
	}

	/** Set a Category's colour directly — fixture setup, not the behaviour under test. */
	private function paint(string $slug, string $colour): void {
		foreach (Server::get(CategoryMapper::class)->findAll() as $category) {
			if ($category->getSlug() === $slug) {
				$category->setColor($colour);
				Server::get(CategoryMapper::class)->update($category);

				return;
			}
		}

		self::fail(sprintf('no Category is filed under "%s"', $slug));
	}

	/** Remember what a Category looks like now, so tearDown can put it back. */
	private function remember(string $slug): void {
		$this->restore[$slug] = (string)$this->colourOf($slug);
	}

	public function testAColourTheClinicChoseIsLeftAlone(): void {
		$this->remember('religion');
		$this->paint('religion', '#ff00aa');

		$this->repair();

		self::assertSame('#ff00aa', $this->colourOf('religion'),
			'A Category the clinic recoloured is a decision, not drift. It must survive the repair.');
	}

	/**
	 * The repair step is registered for `post-migration` and so runs on every
	 * upgrade, forever. The second run must find nothing to do — otherwise it is
	 * not idempotent, it is merely slow to notice.
	 */
	public function testRunningItTwiceChangesNothingTheSecondTime(): void {
		$this->remember('educacion');
		$this->paint('educacion', '#2563eb');

		$this->repair();
		$afterFirst = $this->colourOf('educacion');
		$this->repair();

		self::assertSame('#6d7db6', $afterFirst);
		self::assertSame($afterFirst, $this->colourOf('educacion'),
			'The second run repainted something the first had already settled.');
	}

	/**
	 * The guard reads the colour, not only the slug. A Category sitting on
	 * ANOTHER Category's old colour must not be dragged onto that one's new
	 * colour — the match has to be slug AND colour together.
	 */
	public function testTheGuardMatchesOnSlugAndColourTogether(): void {
		$this->remember('seguridad');
		// Educación's old colour, on Seguridad's row.
		$this->paint('seguridad', '#2563eb');

		$this->repair();

		self::assertSame('#2563eb', $this->colourOf('seguridad'),
			'Seguridad was repainted using Educación\'s row, so the guard is matching on colour alone.');
	}
}
