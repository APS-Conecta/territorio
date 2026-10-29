<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Migration\EnsureSeedData;
use OCA\Territorio\Service\AssignmentService;
use OCA\Territorio\Domain\BoundingBox;
use OCA\Territorio\Domain\Kind;
use OCA\Territorio\Service\TaxonomyService;
use PHPUnit\Framework\TestCase;

/**
 * That deciding whether a record is a boundary does not re-read the taxonomy.
 *
 * `divisionOf()` is asked on every create, update, retire and restore, and it
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
 *
 * And, below, which boundary claims a record — a different property, kept here
 * because deciding it needs a Registry the test owns outright, which is exactly
 * what this file already builds (#117, #121).
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

	/** A taxonomy as TaxonomyService::subcategoryIndex() flattens it (L1-20:
	 *  Assignment reads the flat index now, not the tree). 11 and 12 are the
	 *  divisions as the seed files them; 14 and 15 reuse their slugs under a
	 *  Category a clinic added, which the open taxonomy allows (#205). */
	private const FLAT_INDEX = [
		11 => ['category' => EnsureSeedData::DIVISIONS_SLUG, 'categoryLabel' => 'División territorial', 'subcategory' => 'sector', 'subcategoryLabel' => 'Sector'],
		12 => ['category' => EnsureSeedData::DIVISIONS_SLUG, 'categoryLabel' => 'División territorial', 'subcategory' => 'unidad_vecinal', 'subcategoryLabel' => 'Unidad Vecinal'],
		13 => ['category' => 'territorio', 'categoryLabel' => 'Territorio', 'subcategory' => 'otro', 'subcategoryLabel' => 'Otro'],
		14 => ['category' => 'organizacion_social', 'categoryLabel' => 'Organización social', 'subcategory' => 'unidad_vecinal', 'subcategoryLabel' => 'Unidad vecinal'],
		15 => ['category' => 'organizacion_social', 'categoryLabel' => 'Organización social', 'subcategory' => 'sector', 'subcategoryLabel' => 'Sector'],
	];

	private function feature(int $subcategoryId): Feature {
		$feature = new Feature();
		$feature->setSubcategoryId($subcategoryId);

		return $feature;
	}

	/** A Place with a coordinate, so assign() gets as far as reading the boundaries. */
	private function placeAt(float $lat, float $lon): Feature {
		$feature = $this->feature(13);
		// All three, or anchor() returns null and assign() never asks: the Kind is
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
		return $this->boundaryAround($lat, $lon, 11, 1);
	}

	/** The same shape in whichever division: a Sector is subcategory 11, a Unidad Vecinal 12. */
	private function boundaryAround(float $lat, float $lon, int $subcategoryId, int $id): Feature {
		$ring = [[$lon - 1, $lat - 1], [$lon + 1, $lat - 1], [$lon + 1, $lat + 1], [$lon - 1, $lat + 1], [$lon - 1, $lat - 1]];
		$feature = $this->feature($subcategoryId);
		$feature->setId($id);
		$feature->setKind(Kind::Zone->value);
		$feature->setGeometry(json_encode(['type' => 'Polygon', 'coordinates' => [$ring]]));
		$feature->setBoundingBox(BoundingBox::around($ring));

		return $feature;
	}

	public function testTheTreeIsReadOnceHoweverManyRecordsAreAsked(): void {
		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->expects(self::once())->method('subcategoryIndex')->willReturn(self::FLAT_INDEX);

		$assignment = new AssignmentService($this->createMock(FeatureMapper::class), $taxonomy);

		$assignment->divisionOf($this->feature(13));
		$assignment->divisionOf($this->feature(11));
		$assignment->divisionOf($this->feature(13));
	}

	/**
	 * And once across the OTHER path, which is the one the first version of this
	 * change left behind: `boundaries()` re-read the tree every time `forget()`
	 * emptied it, and `forget()` fires on every boundary write whose shape moved.
	 * A request that moved N boundaries read the taxonomy N+1 times.
	 */
	public function testTheTreeIsReadOnceAcrossRepeatedBoundaryWrites(): void {
		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->expects(self::once())->method('subcategoryIndex')->willReturn(self::FLAT_INDEX);

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
		$taxonomy->method('subcategoryIndex')->willReturn(self::FLAT_INDEX);

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
		$taxonomy->method('subcategoryIndex')->willReturn(self::FLAT_INDEX);

		$features = $this->createMock(FeatureMapper::class);
		$features->expects(self::exactly(2))->method('findAll')->willReturn([]);

		$assignment = new AssignmentService($features, $taxonomy);
		$boundary = $this->sectorAround(-33.5, -70.6);

		$assignment->affectedBy($boundary, null);
		$assignment->forget();
		$assignment->affectedBy($boundary, null);
	}

	/**
	 * Which boundary claims a record, and what happens when none does.
	 *
	 * Here rather than in `tests/integration/AssignmentTest.php` because "no boundary
	 * covers this point" is a claim about the whole Registry, and that suite runs
	 * against a shared instance the browser gate and every other suite also write
	 * to: the claim went red when somebody else's Sector drifted over the fixture
	 * coordinate (#117, #121). The Registry here is the one row below, so the
	 * claim is about a set the test holds entirely.
	 *
	 * Both halves on purpose: a `boundaries()` that found nothing at all would
	 * make the null pass for a reason that has nothing to do with the point.
	 */
	public function testOnlyABoundaryThatContainsTheRecordClaimsIt(): void {
		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->method('subcategoryIndex')->willReturn(self::FLAT_INDEX);

		$features = $this->createMock(FeatureMapper::class);
		$features->method('findAll')->willReturn([
			$this->sectorAround(-33.5, -70.6),
			// A Unidad Vecinal ten degrees east, holding neither point. Without it the
			// Unidad Vecinal assertion below would hold because the Registry has no
			// Unidad Vecinal to hold anything — true of the fixture, and nothing to do
			// with where the point is.
			$this->boundaryAround(-33.5, -60.0, 12, 2),
		]);

		$assignment = new AssignmentService($features, $taxonomy);

		$inside = $this->placeAt(-33.5, -70.6);
		$assignment->assign($inside);
		self::assertSame(1, $inside->getSectorId(), 'the boundary it is inside');

		// The Unidad Vecinal answers for a point inside it, so its silence below is
		// about that point and not about a division nothing could ever have claimed.
		$inTheOtherDivision = $this->placeAt(-33.5, -60.0);
		$assignment->assign($inTheOtherDivision);
		self::assertSame(2, $inTheOtherDivision->getUnidadVecinalId(), 'the other division is live');

		$outside = $this->placeAt(-33.5, -68.0);
		$assignment->assign($outside);
		self::assertNull($outside->getSectorId(), 'outside every boundary there is, which here is one');
		self::assertNull($outside->getUnidadVecinalId(), 'and outside the Unidad Vecinal there is too');
	}

	/** And the answer stays the answer — a cache that changes it is worse than none. */
	public function testABoundaryIsStillRecognisedAndAPlaceIsNot(): void {
		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->method('subcategoryIndex')->willReturn(self::FLAT_INDEX);

		$assignment = new AssignmentService($this->createMock(FeatureMapper::class), $taxonomy);

		// The first ask is what fills the memo; every one after it reads the memo,
		// so both have to be here for this to be about the memo at all.
		self::assertSame('sector', $assignment->divisionOf($this->feature(11)), 'a Sector is a division boundary');
		self::assertNull($assignment->divisionOf($this->feature(13)), 'anything else is not');
		self::assertSame('unidad_vecinal', $assignment->divisionOf($this->feature(12)), 'a Unidad Vecinal is a division boundary');
		self::assertNull($assignment->divisionOf($this->feature(13)));
	}

	/**
	 * #205: a division is the Subcategory under «División territorial», not any
	 * Subcategory that happens to share its slug. The browser's isDivision() and
	 * ADR-0010 already decide by the Category.
	 */
	public function testASameNamedSubcategoryOutsideTheDivisionCategoryIsNoBoundary(): void {
		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->method('subcategoryIndex')->willReturn(self::FLAT_INDEX);

		$assignment = new AssignmentService($this->createMock(FeatureMapper::class), $taxonomy);

		self::assertNull($assignment->divisionOf($this->feature(14)), '«Unidad vecinal» under «Organización social»');
		self::assertNull($assignment->divisionOf($this->feature(15)), '«Sector» under «Organización social»');
	}

	/**
	 * #205, the assignment half: a Zone filed there claims no Place — alone, and
	 * beside the cadastre Unidad Vecinal it used to outrank by being the newer row
	 * (findAll() is newest first).
	 */
	public function testAZoneFiledUnderASameNamedSubcategoryClaimsNoPlace(): void {
		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->method('subcategoryIndex')->willReturn(self::FLAT_INDEX);
		$impostor = $this->boundaryAround(-33.5, -70.6, 14, 2);

		$features = $this->createMock(FeatureMapper::class);
		$features->method('findAll')->willReturn([$impostor]);
		$alone = $this->placeAt(-33.5, -70.6);
		(new AssignmentService($features, $taxonomy))->assign($alone);
		self::assertNull($alone->getUnidadVecinalId(), 'no Unidad Vecinal covers it');

		// A fresh service, because the Registry is read once per service.
		$features = $this->createMock(FeatureMapper::class);
		$features->method('findAll')->willReturn([$impostor, $this->boundaryAround(-33.5, -70.6, 12, 1)]);
		$beside = $this->placeAt(-33.5, -70.6);
		(new AssignmentService($features, $taxonomy))->assign($beside);
		self::assertSame(1, $beside->getUnidadVecinalId(), 'the cadastre Unidad Vecinal claims it');
	}
}
