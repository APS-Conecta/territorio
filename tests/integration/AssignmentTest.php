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
		self::assertNotSame($sector, $this->stored($outside)->getSectorId());

		// Drag the whole boundary east.
		Server::get(FeatureService::class)->update($sector, [
			'name' => 'Sector que se mueve',
			'subcategoryId' => $this->sectorSubcategoryId(),
			'geometry' => ['type' => 'Polygon', 'coordinates' => self::EAST],
		]);

		// Not null — NOT THIS ONE. This runs against an instance other things have
		// written to, including the browser gate, and any Sector somebody else
		// drew may legitimately contain this coordinate. What the redraw promises
		// is that the record let go of the boundary that moved away from it, and
		// asserting null asserts something about the whole instance instead.
		self::assertNotSame($sector, $this->stored($inside)->getSectorId(), 'the one it left');
		self::assertSame($sector, $this->stored($outside)->getSectorId(), 'the one it reached');

		// Visible, not silent: the reassignment is its own entry, and it says what
		// changed. Found by action rather than by index — `?? ` on an array that is
		// always set never falls through, which is an assertion that cannot fail.
		$history = Server::get(FeatureService::class)->history($inside);
		self::assertCount(2, $history, 'created, then reassigned');

		$updates = array_values(array_filter(
			$history,
			static fn (array $entry): bool => $entry['action'] === 'updated',
		));
		self::assertCount(1, $updates, 'the reassignment is an ordinary edit');
		self::assertArrayHasKey('sectorId', $updates[0]['changes']);
		self::assertSame($sector, $updates[0]['changes']['sectorId']['from']);
		self::assertNull($updates[0]['changes']['sectorId']['to']);
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

	/**
	 * A boundary imported into an install that already holds records takes them
	 * on. This is the case ADR-0008 exists for arriving through the likeliest
	 * door: the comuna's Unidades Vecinales land after the catastro does, and
	 * without this every one of those records stays assigned to nothing.
	 */
	public function testABoundaryCreatedAfterTheRecordsStillClaimsThem(): void {
		// Somewhere nothing else reaches: this runs against an instance people have
		// been drawing on, and asserting "assigned to nothing" first would only be
		// measuring how empty it happened to be.
		$place = $this->place('Escuela que ya estaba', ['geometry' => self::at(-71.90, -30.10)]);
		$before = $this->stored($place)->getSectorId();

		$sector = $this->sector('Sector que llega después', [[
			[-71.92, -30.12], [-71.88, -30.12], [-71.88, -30.08], [-71.92, -30.08], [-71.92, -30.12],
		]]);

		self::assertNotSame($before, $this->stored($place)->getSectorId());
		self::assertSame($sector, $this->stored($place)->getSectorId());
	}

	/**
	 * A retired Sector is absent from every read that resolves an assignment, so
	 * a record must not keep an id pointing at it — the sidebar would say "sin
	 * asignar" about a record that is assigned (ADR-0005).
	 */
	public function testRetiringASectorLetsGoOfWhatItHeld(): void {
		$sector = $this->sector('Sector que se retira', self::WEST);
		$place = $this->place('Escuela dentro', ['geometry' => self::at(-70.58, -33.52)]);
		self::assertSame($sector, $this->stored($place)->getSectorId());

		Server::get(FeatureService::class)->retire($sector);
		// Not this one any more. Null would be a claim about every Sector on the
		// instance, and this one is shared.
		self::assertNotSame($sector, $this->stored($place)->getSectorId());

		Server::get(FeatureService::class)->restore($sector);
		self::assertSame($sector, $this->stored($place)->getSectorId(), 'and takes it back');
	}

	/**
	 * #18's deferred half: counts by the stored id, never by asking the geometry.
	 *
	 * Somewhere nothing else reaches, so the count is this test's two records and
	 * not however many the instance already had inside WEST.
	 */
	public function testTheServiceCountsBySector(): void {
		$sector = $this->sector('Sector contado', [[
			[-72.02, -30.52], [-71.98, -30.52], [-71.98, -30.48], [-72.02, -30.48], [-72.02, -30.52],
		]]);
		$this->place('Una', ['geometry' => self::at(-72.00, -30.50)]);
		$this->place('Otra', ['geometry' => self::at(-71.99, -30.51)]);

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
