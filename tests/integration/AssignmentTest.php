<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\RegistryService;
use OCP\Server;

/**
 * A Place's Sector, derived from where it is (ADR-0008).
 *
 * The evidence for deriving rather than typing is in the catastro this app
 * inherits: 393 of 556 places had an empty sector after a full curation effort.
 * So these check the two things that make derivation worth having — that it
 * happens without anyone doing it, and that redrawing a boundary carries every
 * record inside it along, visibly.
 */
final class AssignmentTest extends IntegrationTestCase {
	/** A square around (-70.60..-70.56, -33.54..-33.50). */
	private const WEST = [[
		[-70.60, -33.54], [-70.56, -33.54], [-70.56, -33.50], [-70.60, -33.50], [-70.60, -33.54],
	]];

	/** The same square, shifted east so it no longer covers -70.59. */
	private const EAST = [[
		[-70.55, -33.54], [-70.51, -33.54], [-70.51, -33.50], [-70.55, -33.50], [-70.55, -33.54],
	]];

	public function testCreatingAPlaceInsideASectorAssignsIt(): void {
		$sector = $this->sector('Sector Poniente', self::WEST);
		$place = $this->place('Escuela dentro', ['geometry' => self::at(-70.58, -33.52)]);

		self::assertSame($sector, $this->stored($place)->getSectorId());
	}

	public function testAPlaceOutsideEveryBoundaryIsAssignedToNone(): void {
		$this->sector('Sector Poniente', self::WEST);
		$place = $this->place('Escuela lejos', ['geometry' => self::at(-70.40, -33.52)]);

		self::assertNull($this->stored($place)->getSectorId());
	}

	/**
	 * "A Place with no coordinate resolves to no Sector and no Unidad Vecinal
	 * without erroring" — the catastro holds organisations whose address never
	 * resolved, and they are valid records.
	 */
	public function testAPlaceWithNoCoordinateResolvesToNothingAndDoesNotThrow(): void {
		$this->sector('Sector Poniente', self::WEST);
		$place = $this->place('Junta sin coordenada', ['geometry' => null]);

		$stored = $this->stored($place);
		self::assertNull($stored->getSectorId());
		self::assertNull($stored->getUnidadVecinalId());
	}

	/** Moving a Place re-resolves it, because the answer depends on where it is. */
	public function testMovingAPlaceReassignsIt(): void {
		$sector = $this->sector('Sector Poniente', self::WEST);
		$place = $this->place('Escuela que se muda', ['geometry' => self::at(-70.58, -33.52)]);
		self::assertSame($sector, $this->stored($place)->getSectorId());

		Server::get(FeatureService::class)->update($place, [
			'name' => 'Escuela que se muda',
			'subcategoryId' => $this->anySubcategoryId(),
			'geometry' => self::at(-70.40, -33.52),
		]);

		self::assertNull($this->stored($place)->getSectorId(), 'it moved out and was not let go of');
	}

	/**
	 * The criterion ADR-0008 calls the point: "Redrawing a Sector reassigns every
	 * Place inside it… and it must be visible in history like any other change."
	 */
	public function testRedrawingASectorReassignsWhatItGainedAndLost(): void {
		$sector = $this->sector('Sector que se mueve', self::WEST);
		$inside = $this->place('Queda fuera', ['geometry' => self::at(-70.58, -33.52)]);
		$outside = $this->place('Queda dentro', ['geometry' => self::at(-70.53, -33.52)]);

		self::assertSame($sector, $this->stored($inside)->getSectorId());
		self::assertNull($this->stored($outside)->getSectorId());

		// Drag the whole boundary east.
		Server::get(FeatureService::class)->update($sector, [
			'name' => 'Sector que se mueve',
			'subcategoryId' => $this->sectorSubcategoryId(),
			'geometry' => ['type' => 'Polygon', 'coordinates' => self::EAST],
		]);

		self::assertNull($this->stored($inside)->getSectorId(), 'the one it left');
		self::assertSame($sector, $this->stored($outside)->getSectorId(), 'the one it reached');

		// Visible, not silent: each reassignment is an ordinary write.
		$history = Server::get(FeatureService::class)->history($inside);
		self::assertCount(2, $history, 'created, then reassigned');
		self::assertArrayHasKey('sectorId', $history[0]['changes'] ?? $history[1]['changes'] ?? []);
	}

	/** Renaming a boundary moves nobody, and must not spend a Revision saying so. */
	public function testRenamingASectorReassignsNobody(): void {
		$sector = $this->sector('Sector con nombre feo', self::WEST);
		$place = $this->place('Escuela quieta', ['geometry' => self::at(-70.58, -33.52)]);
		$before = $this->stored($place)->getRevision();

		Server::get(FeatureService::class)->update($sector, [
			'name' => 'Sector con nombre mejor',
			'subcategoryId' => $this->sectorSubcategoryId(),
			'geometry' => ['type' => 'Polygon', 'coordinates' => self::WEST],
		]);

		self::assertSame($before, $this->stored($place)->getRevision());
	}

	/** #18's deferred half: counts by the stored id, never by asking the geometry. */
	public function testTheServiceCountsBySector(): void {
		$sector = $this->sector('Sector contado', self::WEST);
		$this->place('Una', ['geometry' => self::at(-70.58, -33.52)]);
		$this->place('Otra', ['geometry' => self::at(-70.57, -33.53)]);

		self::assertSame(2, Server::get(RegistryService::class)->counts()['bySector'][$sector] ?? 0);
	}

	/** @return array<string, mixed> */
	private static function at(float $lon, float $lat): array {
		return ['type' => 'Point', 'coordinates' => [$lon, $lat]];
	}

	private function stored(int $id): \OCA\Territorio\Db\Feature {
		return Server::get(FeatureMapper::class)->find($id);
	}

	/** @param array<int, array<int, array{float, float}>> $rings */
	private function sector(string $name, array $rings): int {
		$id = (int)Server::get(FeatureService::class)->create([
			'name' => $name,
			'subcategoryId' => $this->sectorSubcategoryId(),
			'colour' => '#16a34a',
			'geometry' => ['type' => 'Polygon', 'coordinates' => $rings],
		])->getId();
		$this->created[] = $id;

		return $id;
	}

	private function sectorSubcategoryId(): int {
		foreach (Server::get(SubcategoryMapper::class)->findAll() as $subcategory) {
			if ($subcategory->getSlug() === 'sector') {
				return (int)$subcategory->getId();
			}
		}

		self::fail('the seed should have created the "sector" subcategory');
	}
}
