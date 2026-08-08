<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Migration;

use OCA\Territorio\Migration\EnsureSeedData;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * No Category claims to have moved from a colour it is already on.
 *
 * `ensureRamp()` matches on slug AND old colour, so a row whose `was` equals its
 * `color` matches nothing and repaints nothing. Harmless, and completely silent:
 * the entry sits there looking like it does work while an install keeps whatever
 * it had.
 *
 * This is all that is left to assert here, and that is the point. Two earlier
 * tests were deleted rather than kept:
 *
 * - "every Category is recognised" policed a second list of slugs against
 *   TAXONOMY. That list is gone — `was` now lives on the Category's own row, so
 *   the two cannot disagree and there is nothing to police.
 * - "no two Categories share a colour" is subsumed by `tools/ramp-check.py`,
 *   where MIN_SEPARATION rejects anything under dE 0.05 and two identical
 *   colours are dE 0. It caught strictly less than the check already run by
 *   `make lint`.
 *
 * What `ensureRamp()` actually DOES at runtime — repaint an old colour, leave a
 * clinic's own choice alone — is proved against a real database by
 * tests/integration/RampRepaintTest.php. Colour separation and contrast are
 * `tools/ramp-check.py`'s job. This file owns neither.
 */
final class RampTest extends TestCase {
	public function testNoCategoryRepaintsItselfToTheColourItAlreadyHas(): void {
		/** @var array<string, array{label: string, color: string, was?: string}> $taxonomy */
		$taxonomy = (new ReflectionClass(EnsureSeedData::class))->getConstant('TAXONOMY');

		$repainted = 0;
		foreach ($taxonomy as $slug => $category) {
			if (!isset($category['was'])) {
				continue;
			}

			$repainted++;
			self::assertNotSame($category['was'], $category['color'], sprintf(
				'Category "%s" says it moved from %s, which is the colour it is already on. '
					. 'That row repaints nothing.',
				$slug,
				$category['was'],
			));
		}

		self::assertGreaterThan(0, $repainted, 'No Category carries a "was" colour at all, so '
			. 'ensureRamp() has nothing to do and an existing install will never be repainted.');
	}
}
