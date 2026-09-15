<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Exception\InvalidTaxonomyException;
use OCA\Territorio\Service\TaxonomyService;
use PHPUnit\Framework\TestCase;

/**
 * The pictogram name a Category stores (#46, ADR-0017).
 *
 * Pure, like `slugFor()` beside it and for the same reason: it is a rule with no
 * screen to notice it going wrong. A glyph is never typed — it is picked from a
 * grid of the marks the app vendors — so nothing a person does can produce a bad
 * one, and everything about this is therefore about what ARRIVES on an open
 * route (ADR-0005) rather than about what somebody meant.
 *
 * What it does NOT check is that the name is a mark this app can actually draw:
 * the inventory is Maki's, it lives in `src/glyphs.js`, and PHP has no copy of
 * it. `glyphFor()` draws the neutral for a name it does not know, which is the
 * same fallback ADR-0017 already requires for a Category nobody has chosen for.
 */
final class TaxonomyGlyphTest extends TestCase {
	/** @return array<string, array{0: string, 1: ?string}> */
	public static function accepted(): array {
		return [
			'a Maki name' => ['park', 'park'],
			'one with a hyphen in it' => ['place-of-worship', 'place-of-worship'],
			'one with a digit' => ['building-alt1', 'building-alt1'],
			'surrounding space is not part of the name' => ['  bakery  ', 'bakery'],
			// Clearing the choice: the Category goes back to drawing the neutral,
			// which is what it drew before anybody picked (ADR-0017).
			'nothing chosen at all' => ['', null],
			'nothing but space' => ['   ', null],
		];
	}

	/** @dataProvider accepted */
	public function testAPickedNameIsStoredAsItIs(string $picked, ?string $stored): void {
		self::assertSame($stored, TaxonomyService::glyphName($picked));
	}

	/** @return array<string, array{0: string}> */
	public static function refused(): array {
		return [
			// `glyph` is VARCHAR(64). Without this the refusal arrives as the
			// database's own error through a 500, the way an over-long label did
			// before TaxonomyService started naming that limit.
			'longer than the column' => [str_repeat('a', 65)],
			'a capital, which no Maki name has' => ['Park'],
			'a path, which is what an attempt at the filesystem looks like' => ['../../etc/passwd'],
			'markup, because this string is inlined into the pin unescaped' => ['<svg onload=1>'],
			'an underscore, which is a slug separator and not a Maki one' => ['town_hall'],
		];
	}

	/** @dataProvider refused */
	public function testANameThatIsNotShapedLikeAMarkIsRefused(string $picked): void {
		$this->expectException(InvalidTaxonomyException::class);

		TaxonomyService::glyphName($picked);
	}

	/**
	 * The refusal names what to fix, like every other one this service raises —
	 * it reaches the Capas panel as a 422 and is shown where the person clicked.
	 */
	public function testTheRefusalSaysWhatWasWrong(): void {
		try {
			TaxonomyService::glyphName('Park');
			self::fail('a name no Maki icon carries was accepted');
		} catch (InvalidTaxonomyException $e) {
			self::assertStringContainsString('pictograma', $e->getMessage());
		}
	}
}
