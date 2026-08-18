<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Service\AssignmentService;
use OCA\Territorio\Service\BoundingBox;
use OCA\Territorio\Service\Kind;
use OCA\Territorio\Service\TaxonomyService;
use PHPUnit\Framework\TestCase;

/**
 * That deciding whether a record is a boundary does not re-read the taxonomy.
 *
 * `isBoundary()` is asked on every create, update, retire and restore, and it
 * derived its answer from the Category tree — two full table scans per ask, for
 * an answer that cannot change while the service lives. The CHANGELOG has the
 * measurement.
 *
 * The seam is `TaxonomyService`, because "how many times was the tree read" is
 * the whole property and there is nowhere else to observe it. Memoising the tree
 * ITSELF was tried and is wrong — `RampRepaint` writes a Category and reads the
 * tree back in the same request — which is why this narrower thing is cached
 * instead.
 *
 * Two paths ask, and the first version of this change covered only one of them.
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

	/** A Place with a coordinate, so assign() gets as far as reading the boundaries. */
	private function placeAt(float $lat, float $lon): Feature {
		$feature = $this->feature(13);
		// All three, or anchorOf() returns null and assign() never asks: the Kind is
		// what says this record can be inside something, and the stored box is where.
		// The geometry is set too because that is what a real record carries — the two
		// are written together by FeatureService::applyGeometry(), and a fixture that
		// has one without the other is a record production cannot produce.
		$feature->setKind(Kind::Place->value);
		$feature->setGeometry(json_encode(['type' => 'Point', 'coordinates' => [$lon, $lat]]));
		$feature->setBoundingBox(BoundingBox::around([[$lon, $lat]]));

		return $feature;
	}

	/** A Sector big enough to hold the Place above, with the box a real one carries. */
	private function sectorAround(float $lat, float $lon): Feature {
		$ring = [[$lon - 1, $lat - 1], [$lon + 1, $lat - 1], [$lon + 1, $lat + 1], [$lon - 1, $lat + 1], [$lon - 1, $lat - 1]];
		$feature = $this->feature(11);
		$feature->setId(1);
		$feature->setKind(Kind::Zone->value);
		$feature->setGeometry(json_encode(['type' => 'Polygon', 'coordinates' => [$ring]]));
		$feature->setBoundingBox(BoundingBox::around($ring));

		return $feature;
	}

	public function testTheTreeIsReadOnceHoweverManyRecordsAreAsked(): void {
		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->expects(self::once())->method('tree')->willReturn(self::TREE);

		$assignment = new AssignmentService($this->createMock(FeatureMapper::class), $taxonomy);

		$assignment->isBoundary($this->feature(13));
		$assignment->isBoundary($this->feature(11));
		$assignment->isBoundary($this->feature(13));
	}

	/**
	 * And once across the OTHER path, which is the one the first version of this
	 * change left behind: `boundaries()` re-read the tree every time `forget()`
	 * emptied it, and `forget()` fires on every boundary write whose shape moved.
	 * A request that moved N boundaries read the taxonomy N+1 times.
	 */
	public function testTheTreeIsReadOnceAcrossRepeatedBoundaryWrites(): void {
		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->expects(self::once())->method('tree')->willReturn(self::TREE);

		$features = $this->createMock(FeatureMapper::class);
		$features->method('findAll')->willReturn([]);

		$assignment = new AssignmentService($features, $taxonomy);

		// assign() reaches boundaries(); forget() is what a boundary write does.
		for ($i = 0; $i < 5; $i++) {
			$assignment->assign($this->placeAt(-33.5, -70.6));
			$assignment->forget();
		}
	}

	/**
	 * A boundary write reads the Registry once, not twice.
	 *
	 * `FeatureService::reassignAround()` calls `forget()`, then `affectedBy()` —
	 * which scans every record — and then `assign()` on each one, which reaches
	 * `boundaries()` and scanned every record a second time. Two identical full
	 * reads per boundary write, and four statements, since `withoutBoundaries()`
	 * fires its own query in front of each.
	 *
	 * Both halves have to actually run for this to assert anything: the mapper
	 * returns a Place that IS inside the boundary, so `affectedBy()` yields it and
	 * `assign()` is reached. With the memo removed this is red at two calls.
	 */
	public function testTheRegistryIsReadOncePerBoundaryWrite(): void {
		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->method('tree')->willReturn(self::TREE);

		$place = $this->placeAt(-33.5, -70.6);
		$place->setId(2);

		$features = $this->createMock(FeatureMapper::class);
		$features->expects(self::once())->method('findAll')->willReturn([$place]);

		$assignment = new AssignmentService($features, $taxonomy);

		$affected = $assignment->affectedBy($this->sectorAround(-33.5, -70.6), null);

		self::assertCount(1, $affected, 'the Place is inside the Sector, so the second half runs');
		foreach ($affected as $candidate) {
			$assignment->assign($candidate);
		}
	}

	/** And the memo is dropped when a boundary is written, or it would serve stale rows. */
	public function testForgetDropsTheRegistryToo(): void {
		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->method('tree')->willReturn(self::TREE);

		$features = $this->createMock(FeatureMapper::class);
		$features->expects(self::exactly(2))->method('findAll')->willReturn([]);

		$assignment = new AssignmentService($features, $taxonomy);
		$boundary = $this->sectorAround(-33.5, -70.6);

		$assignment->affectedBy($boundary, null);
		$assignment->forget();
		$assignment->affectedBy($boundary, null);
	}

	/** And the answer stays the answer — a cache that changes it is worse than none. */
	public function testABoundaryIsStillRecognisedAndAPlaceIsNot(): void {
		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->method('tree')->willReturn(self::TREE);

		$assignment = new AssignmentService($this->createMock(FeatureMapper::class), $taxonomy);

		// The first ask is what fills the memo; every one after it reads the memo,
		// so both have to be here for this to be about the memo at all.
		self::assertTrue($assignment->isBoundary($this->feature(11)), 'a Sector is a boundary');
		self::assertFalse($assignment->isBoundary($this->feature(13)), 'anything else is not');
		self::assertTrue($assignment->isBoundary($this->feature(12)), 'a Unidad Vecinal is a boundary');
		self::assertFalse($assignment->isBoundary($this->feature(13)));
	}
}
