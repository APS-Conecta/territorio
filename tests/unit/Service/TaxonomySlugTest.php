<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Service\TaxonomyService;
use PHPUnit\Framework\TestCase;

/**
 * The slug a typed label becomes (#44).
 *
 * The one rule on the taxonomy's write path that is pure: no mapper, no index,
 * no database. Everything the add path does *around* it — that the slug is
 * unique within its Category, that the index is what enforces it — is in
 * `CategoryCrudTest`, where those things are real (issue #40, Testing
 * Decisions). This is here because a derived identifier that nobody types is a
 * rule with no screen to notice it going wrong.
 */
final class TaxonomySlugTest extends TestCase {
	/** @return array<string, array{0: string, 1: string}> */
	public static function labels(): array {
		return [
			'a plain name' => ['Comité de vivienda', 'comite_de_vivienda'],
			'the eñe' => ['Ñandú', 'nandu'],
			'case comes down' => ['CESFAM Norte', 'cesfam_norte'],
			'runs of anything collapse to one underscore' => ['Punto  limpio', 'punto_limpio'],
			'surrounding space is not an underscore' => ['  Taller  ', 'taller'],
			'punctuation is a separator, not a character' => ['Programa +60 (piloto)', 'programa_60_piloto'],
			// Deliberately unlike KmlReader::slug(), which drops «de» and «la» to
			// reproduce the slugs the seed already carries.
			'short words are kept' => ['Casa de la cultura', 'casa_de_la_cultura'],
		];
	}

	/** @dataProvider labels */
	public function testALabelBecomesTheSlugItReads(string $label, string $expected): void {
		self::assertSame($expected, TaxonomyService::slugFor($label));
	}

	/**
	 * `slug` is VARCHAR(64). A label can be 128, so the cap is real and not
	 * theoretical — and cutting at 64 must not leave the underscore that was the
	 * 64th character, because a slug ending in a separator is not what the same
	 * label reads as anywhere else.
	 *
	 * Words of four, on purpose: «abc » lands a separator exactly on the 64th
	 * character. A first version of this test used words of three, where the cut
	 * falls mid-word, and it passed with the trim taken back out — a fixture that
	 * cannot reach the case is not a check.
	 */
	public function testTheSlugFitsTheColumnAndDoesNotEndInASeparator(): void {
		$slug = TaxonomyService::slugFor(str_repeat('abc ', 20));

		self::assertSame(str_repeat('abc_', 15) . 'abc', $slug);
		self::assertLessThanOrEqual(64, strlen($slug));
		self::assertStringEndsNotWith('_', $slug);
	}

	/**
	 * A label of punctuation leaves nothing to key on. The service refuses it
	 * rather than storing an empty slug — a second one would then collide with
	 * it, and a refusal about a duplicate would be the first anyone heard of it.
	 */
	public function testALabelWithNothingToKeyOnMakesNoSlug(): void {
		self::assertSame('', TaxonomyService::slugFor('«»'));
	}
}
