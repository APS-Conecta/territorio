<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Db\Category;
use OCA\Territorio\Db\CategoryMapper;
use OCA\Territorio\Exception\InvalidTaxonomyException;
use OCA\Territorio\Migration\EnsureSeedData;

/**
 * The taxonomy's identifier and colour policy (L1-24): the rules with no
 * taxonomy WRITE in them, out of TaxonomyService so its write doors read as
 * orchestration and these test as the pure-ish things they are
 * (TaxonomyGlyphTest, TaxonomyColourTest — both re-point to this file).
 * Behaviour-neutral: every body moves verbatim.
 *
 * slugFor is not here: it lives in aps-common's Text with a static forward
 * left on TaxonomyService (Phase 2, L1-05).
 */
final class TaxonomyPolicy {
	/**
	 * `territorio_category.glyph` is `VARCHAR(64)`. Nobody types one — it is
	 * picked out of a grid of the marks the app vendors — so this is about what
	 * arrives on an open route (ADR-0005), not about what somebody meant.
	 */
	private const GLYPH_LENGTH = 64;


	public function __construct(
		private CategoryMapper $categories,
	) {
	}

	/**
	 * The Maki name to store, or null when nothing was picked.
	 *
	 * Checks the SHAPE of the name and not whether the app can draw it: the
	 * inventory belongs to `@mapbox/maki` and lives in `src/glyphs.js`, and a
	 * second copy of 215 names in PHP is exactly the drift `nextColour()` refuses
	 * to introduce for colours. `glyphFor()` draws the neutral for a name it does
	 * not know, which is the same fallback ADR-0017 already requires for a
	 * Category nobody has picked for — so an unknown name degrades to the state
	 * the Category was already in rather than to a blank.
	 *
	 * ponytail: the shape check is the ceiling. A name shaped like a Maki icon
	 * that Maki does not have is stored and silently falls back; the upgrade is to
	 * ship the inventory as seeded data the way `SPARES` is shipped, which is
	 * worth its weight when something other than the picker can write here.
	 *
	 * Public and static because it is pure, like `slugFor()` — the taxonomy's
	 * rules with no database in them are the ones with no screen to notice them
	 * going wrong (`TaxonomyGlyphTest`).
	 *
	 * @throws InvalidTaxonomyException when the name is not shaped like one
	 */
	public static function glyphName(string $glyph): ?string {
		$clean = trim($glyph);
		if ($clean === '') {
			return null;
		}
		// Letters, digits and hyphens: every Maki name — thirteen of the 215 are
		// the `-JP` variants, whose suffix is capitalised — and nothing that could
		// be a path, an underscore-separated slug, or markup, since this string is
		// inlined into the map pin unescaped, as a bundled asset would be. Read
		// back out of this file by `glyphs.test.js`, which fails when the picker
		// offers a mark this line would refuse: a shape check is still a
		// description of Maki's inventory, and it was a narrower one.
		if (preg_match('/^[A-Za-z0-9-]{1,' . self::GLYPH_LENGTH . '}$/', $clean) !== 1) {
			throw new InvalidTaxonomyException('Ese pictograma no existe en esta aplicación.');
		}

		return $clean;
	}

	/**
	 * The colour a new Category is given: the first spare no Category holds.
	 *
	 * "Assigning a colour becomes taking the next unused spare" (ADR-0017). No
	 * counter and no new state — the answer is derived from the Categories that
	 * exist, so a Category removed (#47) hands its spare back, and two adds one
	 * after the other cannot take the same one because the first is a row by the
	 * time the second looks.
	 *
	 * Two SIMULTANEOUS adds can: this reads `findAll()` and then inserts with no
	 * lock, and there is no unique index on `color`. Accepted rather than fixed,
	 * and it is the one rule here `addCategory()` does not hand to the database —
	 * because a duplicate hue is cosmetic. Hue only groups Categories and the
	 * glyph identifies them (ADR-0013), so two Categories sharing one is the same
	 * thing that already happens the moment the spares run out and both take the
	 * neutral. A duplicate SLUG is not cosmetic, which is why that one is an
	 * index and this one is a comment.
	 *
	 * No colour arithmetic here on purpose. The OKLab separation, the WCAG
	 * contrast and the three dichromacy transforms live in `tools/ramp-check.py`,
	 * which derives `EnsureSeedData::SPARES` and re-checks every one of them on
	 * `make lint`; a second implementation in PHP is exactly the drift that check
	 * exists to prevent.
	 *
	 * Compared case-insensitively because a stored colour is a hex somebody may
	 * have typed — `RampRepaintTest` writes one directly, and `#C27B72` is the
	 * same colour as `#c27b72`.
	 *
	 * With the spares gone it answers the neutral, and that is not an error: hue
	 * only groups Categories into families and the glyph identifies them
	 * (ADR-0013), so a Category without a distinct hue is doing what that ADR says
	 * every Category does anyway. The panel says so, on `color === the neutral`,
	 * which is why nothing extra is returned to flag it.
	 */
	public function nextColour(): string {
		$taken = array_map(
			static fn (Category $category): string => strtolower($category->getColor()),
			$this->categories->findAll(),
		);

		foreach (EnsureSeedData::SPARES as $spare) {
			if (!in_array(strtolower($spare), $taken, true)) {
				return $spare;
			}
		}


		return EnsureSeedData::NEUTRAL;
	}
}
