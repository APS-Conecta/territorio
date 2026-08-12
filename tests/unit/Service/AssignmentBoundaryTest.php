<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Service\AssignmentService;
use OCA\Territorio\Service\TaxonomyService;
use PHPUnit\Framework\TestCase;

/**
 * That deciding whether a record is a boundary does not re-read the taxonomy.
 *
 * `isBoundary()` is asked on every create, update, retire and restore, and it
 * derives its answer from the Category tree — two full table scans per ask.
 * Measured on the dev instance before this: 55,576 sequential scans of
 * `oc_territorio_category` against 431 writes of that table, and one run of the
 * integration suite added 543 of them for 933 record writes.
 *
 * The seam is `TaxonomyService`, because "how many times was the tree read" is
 * the whole property and there is nowhere else to observe it. Memoising the tree
 * ITSELF was tried and is wrong — `RampRepaint` writes a Category and reads the
 * tree back in the same request — which is why this narrower thing is cached
 * instead.
 */
final class AssignmentBoundaryTest extends TestCase {
	private const TREE = [[
		'id' => 1,
		'slug' => 'territorio',
		'label' => 'Territorio',
		'color' => '#916393',
		'subcategories' => [
			['id' => 11, 'slug' => 'sector', 'label' => 'Sector'],
			['id' => 12, 'slug' => 'unidad_vecinal', 'label' => 'Unidad Vecinal'],
			['id' => 13, 'slug' => 'otro', 'label' => 'Otro'],
		],
	]];

	private function feature(int $subcategoryId): Feature {
		$feature = new Feature();
		$feature->setSubcategoryId($subcategoryId);

		return $feature;
	}

	public function testTheTreeIsReadOnceHoweverManyRecordsAreAsked(): void {
		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->expects(self::once())->method('tree')->willReturn(self::TREE);

		$assignment = new AssignmentService($this->createMock(FeatureMapper::class), $taxonomy);

		for ($i = 0; $i < 50; $i++) {
			$assignment->isBoundary($this->feature(13));
		}
	}

	/** And the answer stays the answer — a cache that changes it is worse than none. */
	public function testABoundaryIsStillRecognisedAndAPlaceIsNot(): void {
		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->method('tree')->willReturn(self::TREE);

		$assignment = new AssignmentService($this->createMock(FeatureMapper::class), $taxonomy);

		self::assertTrue($assignment->isBoundary($this->feature(11)), 'a Sector is a boundary');
		self::assertTrue($assignment->isBoundary($this->feature(12)), 'a Unidad Vecinal is a boundary');
		self::assertFalse($assignment->isBoundary($this->feature(13)), 'anything else is not');
		// Repeated, because the first ask is what fills the cache and the second is
		// the one that could read a different answer out of it.
		self::assertTrue($assignment->isBoundary($this->feature(11)));
		self::assertFalse($assignment->isBoundary($this->feature(13)));
	}
}
