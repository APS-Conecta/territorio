<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\CategoryMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\Kind;
use OCP\Server;

/**
 * A Sector is a Feature like any other — that is the whole design (CONTEXT.md).
 * It gets history, retirement, the change feed and the revert for free, and what
 * is left to prove is that the parts that ARE new survive the real database: a
 * Polygon in a text column, a colour of its own, and the Subcategory the seed
 * has to put there for a Sector to have somewhere to go.
 */
final class SectorTest extends IntegrationTestCase {
	/** A closed ring in La Florida, as geoman hands one over. */
	private const BOUNDARY = [
		'type' => 'Polygon',
		'coordinates' => [[
			[-70.60, -33.53],
			[-70.57, -33.53],
			[-70.57, -33.51],
			[-70.60, -33.51],
			[-70.60, -33.53],
		]],
	];

	public function testTheSeedLeavesSomewhereToFileASector(): void {
		$divisions = null;
		foreach (Server::get(CategoryMapper::class)->findAll() as $category) {
			if ($category->getSlug() === 'division_territorial') {
				$divisions = $category;
			}
		}

		self::assertNotNull($divisions, 'the seed should have added the division category');

		$slugs = [];
		foreach (Server::get(SubcategoryMapper::class)->findAll() as $subcategory) {
			if ((int)$subcategory->getCategoryId() === (int)$divisions->getId()) {
				$slugs[] = $subcategory->getSlug();
			}
		}

		self::assertContains('sector', $slugs);
	}

	public function testADrawnSectorSurvivesTheRoundTrip(): void {
		$id = $this->sector('Sector Norte', '#16a34a');

		$read = Server::get(FeatureMapper::class)->find($id);

		self::assertSame(Kind::Zone->value, $read->getKind());
		self::assertSame('#16a34a', $read->getColour());
		self::assertSame(
			self::BOUNDARY,
			json_decode((string)$read->getGeometry(), true),
			'the ring must come back exactly as it was drawn, closed and in order',
		);
		// Measured across the whole ring, not from one vertex: the bounding box is
		// what puts a Sector on the map at all.
		self::assertEqualsWithDelta(-33.53, $read->getMinLat(), 1e-9);
		self::assertEqualsWithDelta(-70.57, $read->getMaxLon(), 1e-9);
	}

	/**
	 * Naming is clinic-specific and unconstrained (issue #10). A bare number is
	 * the case worth pinning: it is the one a stricter column type or a helpful
	 * cast would quietly turn into something else.
	 */
	public function testASectorMayBeNamedWhateverTheClinicCallsIt(): void {
		foreach (['3', 'Rojo', 'Sector Sur', '2 Norte'] as $name) {
			$read = Server::get(FeatureMapper::class)->find($this->sector($name, '#dc2626'));

			self::assertSame($name, $read->getName());
		}
	}

	/** Dragging a vertex is an ordinary edit: new ring, new box, one history entry. */
	public function testReshapingMovesTheBoundaryAndItsBoundingBox(): void {
		$id = $this->sector('Sector Poniente', '#2563eb');

		$moved = self::BOUNDARY;
		$moved['coordinates'][0][1] = [-70.55, -33.53];
		$moved['coordinates'][0][2] = [-70.55, -33.51];

		Server::get(FeatureService::class)->update($id, [
			'name' => 'Sector Poniente',
			'subcategoryId' => $this->subcategoryIdBySlug('sector'),
			'geometry' => $moved,
		]);

		$read = Server::get(FeatureMapper::class)->find($id);
		self::assertEqualsWithDelta(-70.55, $read->getMaxLon(), 1e-9);

		$history = Server::get(FeatureService::class)->history($id);
		self::assertCount(2, $history, 'created, then edited');
	}

	/** A record with no colour of its own takes its Category's, and says so as null. */
	public function testColourIsOptionalEverywhereElse(): void {
		$read = Server::get(FeatureMapper::class)->find($this->place('Plaza sin color'));

		self::assertNull($read->getColour());
	}

	private function sector(string $name, string $colour): int {
		$id = (int)Server::get(FeatureService::class)->create([
			'name' => $name,
			'subcategoryId' => $this->subcategoryIdBySlug('sector'),
			'colour' => $colour,
			'geometry' => self::BOUNDARY,
		])->getId();
		$this->created[] = $id;

		return $id;
	}
}
