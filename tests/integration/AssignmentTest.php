<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Domain\Action;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\ImportService;
use OCA\Territorio\Service\RegistryService;
use OCA\Territorio\Service\RevertService;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;

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
	private const WEST = ['type' => 'Polygon', 'coordinates' => [[
		[-70.60, -33.54], [-70.56, -33.54], [-70.56, -33.50], [-70.60, -33.50], [-70.60, -33.54],
	]]];

	/** The same square, shifted east so it no longer covers -70.59. */
	private const EAST = ['type' => 'Polygon', 'coordinates' => [[
		[-70.55, -33.54], [-70.51, -33.54], [-70.51, -33.50], [-70.55, -33.50], [-70.55, -33.54],
	]]];

	/** Far from the seed's own boundaries, so only this test's shape can answer. */
	private const REMOTE = ['type' => 'Polygon', 'coordinates' => [[
		[-71.02, -29.52], [-70.98, -29.52], [-70.98, -29.48], [-71.02, -29.48], [-71.02, -29.52],
	]]];

	public function testCreatingAPlaceInsideASectorAssignsIt(): void {
		$sector = $this->sector('Sector Poniente', ['geometry' => self::WEST]);
		$place = $this->place('Escuela dentro', ['geometry' => self::at(-70.58, -33.52)]);

		self::assertSame($sector, $this->stored($place)->getSectorId());
	}

	/**
	 * A ring GeoJSON would call invalid still has to work, because the files real
	 * cadastres ship are not always valid and a boundary that claims nobody says
	 * nothing about it — it draws correctly and assigns no one.
	 *
	 * Three positions, first not repeated last. `PointInPolygon` needs four before
	 * a ring has an inside, so before Geometry closed rings on the way in this
	 * Sector contained nothing at all.
	 */
	public function testAnUnclosedRingStillClaimsWhatIsInsideIt(): void {
		$triangle = [
			'type' => 'Polygon',
			'coordinates' => [[[-70.60, -33.54], [-70.56, -33.54], [-70.56, -33.50]]],
		];
		$sector = $this->sector('Sector triángulo', ['geometry' => $triangle]);

		$place = $this->place('Escuela dentro del triángulo', ['geometry' => self::at(-70.58, -33.53)]);

		self::assertSame($sector, $this->stored($place)->getSectorId());
	}

	/**
	 * A boundary does not claim what falls outside it.
	 *
	 * It used to assert "assigned to nothing", which is a claim about every
	 * boundary the install holds — and this suite runs against an instance the
	 * browser gate and every other suite also write to (#117, #121). The null
	 * half is proven where the Registry is a set the test owns entirely, in
	 * `tests/unit/Service/AssignmentBoundaryTest.php`:
	 * `testOnlyABoundaryThatContainsTheRecordClaimsIt`.
	 */
	public function testAPlaceOutsideABoundaryIsNotClaimedByIt(): void {
		$sector = $this->sector('Sector Poniente', ['geometry' => self::WEST]);
		$place = $this->place('Escuela lejos', ['geometry' => self::at(-70.40, -33.52)]);

		self::assertNotSame($sector, $this->stored($place)->getSectorId());
	}

	/**
	 * "A Place with no coordinate resolves to no Sector and no Unidad Vecinal
	 * without erroring" — the catastro holds organisations whose address never
	 * resolved, and they are valid records.
	 */
	public function testAPlaceWithNoCoordinateResolvesToNothingAndDoesNotThrow(): void {
		$this->sector('Sector Poniente', ['geometry' => self::WEST]);
		$place = $this->place('Junta sin coordenada', ['geometry' => null]);

		$stored = $this->stored($place);
		self::assertNull($stored->getSectorId());
		self::assertNull($stored->getUnidadVecinalId());
	}

	/** Moving a Place re-resolves it, because the answer depends on where it is. */
	public function testMovingAPlaceReassignsIt(): void {
		$sector = $this->sector('Sector Poniente', ['geometry' => self::WEST]);
		$place = $this->place('Escuela que se muda', ['geometry' => self::at(-70.58, -33.52)]);
		self::assertSame($sector, $this->stored($place)->getSectorId());

		Server::get(FeatureService::class)->update($place, [
			'name' => 'Escuela que se muda',
			'subcategoryId' => $this->anySubcategoryId(),
			'geometry' => self::at(-70.40, -33.52),
		]);

		// Not null, for the reason the redraw test below gives at length: where it
		// landed depends on what else the instance holds, and what the move
		// promises is that the answer was derived again rather than carried along.
		self::assertNotSame($sector, $this->stored($place)->getSectorId(), 'it moved out and was not let go of');
	}

	/**
	 * The criterion ADR-0008 calls the point: "Redrawing a Sector reassigns every
	 * Place inside it… and it must be visible in history like any other change."
	 */
	public function testRedrawingASectorReassignsWhatItGainedAndLost(): void {
		// Somebody else's Sector over the same coordinate, drawn first so this
		// test's own boundary still answers for the Place while it covers it
		// (`findAll()` reads newest first). This is what a shared instance looks
		// like on an ordinary day — a crashed browser-gate run left one behind and
		// the history assertion below went red for it (#117, #121) — so it is
		// arranged here rather than waited for, and the awkward case is measured
		// on every run instead of on the unlucky one.
		$rival = $this->sector('Sector de otra persona');
		try {
			$sector = $this->sector('Sector que se mueve', ['geometry' => self::WEST]);
			$inside = $this->place('Queda fuera', ['geometry' => self::at(-70.58, -33.52)]);
			$outside = $this->place('Queda dentro', ['geometry' => self::at(-70.53, -33.52)]);

			self::assertSame($sector, $this->stored($inside)->getSectorId(), 'the newest boundary over it answers');
			self::assertNotSame($sector, $this->stored($outside)->getSectorId());

			// Drag the whole boundary east.
			Server::get(FeatureService::class)->update($sector, [
				'name' => 'Sector que se mueve',
				'subcategoryId' => $this->subcategoryIdBySlug('sector'),
				'geometry' => self::EAST,
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
			// And not null here either, which is the same whole-instance claim the
			// `NOT THIS ONE` comment above forbids: `to` is null only while nothing
			// else covers the coordinate. What the redraw promises is that the record
			// left the boundary that moved away from it; which boundary picked it up
			// is a fact about the Registry, not about the redraw.
			//
			// Note this line restates the one above rather than guarding it —
			// `FeatureMapper::diff()` records a field only when its value actually
			// changed, so a recorded sectorId change has from !== to by construction.
			// The assertion that catches a regression is `the one it left`.
			self::assertNotSame($sector, $updates[0]['changes']['sectorId']['to'], 'recorded as leaving it');
		} finally {
			// Retired through the app rather than left to the raw-SQL teardown, and in
			// `finally` because a failing assertion above is exactly the run where it
			// matters: retiring a boundary releases what it claimed (#30), while deleting
			// one leaves every record it covered pointing at an id no read resolves.
			Server::get(FeatureService::class)->retire($rival);
		}
	}

	/** Renaming a boundary moves nobody, and must not spend a Revision saying so. */
	public function testRenamingASectorReassignsNobody(): void {
		$sector = $this->sector('Sector con nombre feo', ['geometry' => self::WEST]);
		$place = $this->place('Escuela quieta', ['geometry' => self::at(-70.58, -33.52)]);
		$before = $this->stored($place)->getRevision();

		Server::get(FeatureService::class)->update($sector, [
			'name' => 'Sector con nombre mejor',
			'subcategoryId' => $this->subcategoryIdBySlug('sector'),
			'geometry' => self::WEST,
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

		$sector = $this->sector('Sector que llega después', ['geometry' => [
			'type' => 'Polygon',
			'coordinates' => [[
				[-71.92, -30.12], [-71.88, -30.12], [-71.88, -30.08], [-71.92, -30.08], [-71.92, -30.12],
			]],
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
		$sector = $this->sector('Sector que se retira', ['geometry' => self::WEST]);
		$place = $this->place('Escuela dentro', ['geometry' => self::at(-70.58, -33.52)]);
		self::assertSame($sector, $this->stored($place)->getSectorId());

		Server::get(FeatureService::class)->retire($sector);
		// Not this one any more. Null would be a claim about every Sector on the
		// instance, and this one is shared.
		self::assertNotSame($sector, $this->stored($place)->getSectorId());

		Server::get(FeatureService::class)->restore($sector);
		self::assertSame($sector, $this->stored($place)->getSectorId(), 'and takes it back');
	}

	/** @return array<string, array{string, string, string}> slug, history field, getter */
	public static function divisions(): array {
		return [
			'Sector' => ['sector', 'sectorId', 'getSectorId'],
			'Unidad Vecinal' => ['unidad_vecinal', 'unidadVecinalId', 'getUnidadVecinalId'],
		];
	}

	/** @return array<string, array{string, string, string, string}> division, then the door out */
	public static function divisionsAndDoors(): array {
		$rows = [];
		foreach (self::divisions() as $division => $row) {
			foreach (['retire', 'merge'] as $door) {
				$rows["$division, $door"] = [...$row, $door];
			}
		}

		return $rows;
	}

	/**
	 * A record that comes back derives its own assignment again (#201). Boundary
	 * sweeps skip retired records, so retiring the boundary it held never reached
	 * it; restoring must not bring back an id no read resolves (ADR-0008). Undoing
	 * a merge is the same door.
	 */
	#[DataProvider('divisionsAndDoors')]
	public function testRestoringARecordLetsGoOfABoundaryRetiredMeanwhile(
		string $slug,
		string $field,
		string $getter,
		string $door,
	): void {
		$service = Server::get(FeatureService::class);
		$boundary = $this->sector('Límite que se retira', [
			'geometry' => self::REMOTE,
			'subcategoryId' => $this->subcategoryIdBySlug($slug),
		]);
		$place = $this->place('Escuela que vuelve', ['geometry' => self::at(-71.00, -29.50)]);
		self::assertSame($boundary, $this->stored($place)->$getter());

		$door === 'merge'
			? $service->merge($place, $this->place('Escuela que se conserva'))
			: $service->retire($place);
		$service->retire($boundary);
		$service->restore($place);

		// Not null: see the redraw test above — another boundary may legitimately
		// hold this point. What is promised is that it let go of THIS one.
		$restored = $this->stored($place);
		self::assertNotSame($boundary, $restored->$getter(), 'it came back holding a retired boundary');
		self::assertNull($restored->getMergedInto());

		$history = $service->history($place);
		self::assertCount(3, $history, 'created, sent out, restored');
		self::assertSame(Action::Restored->value, $history[0]['action']);
		$changes = $history[0]['changes'] ?? [];
		self::assertArrayHasKey($field, $changes, 'the change belongs on the restore entry itself');
		self::assertSame($boundary, $changes[$field]['from']);
		self::assertNotSame($boundary, $changes[$field]['to'], 'recorded as letting go of it');
	}

	/** The other half of #201: a boundary drawn while the record was away claims it on return. */
	#[DataProvider('divisions')]
	public function testRestoringARecordTakesABoundaryCreatedMeanwhile(string $slug, string $field, string $getter): void {
		$service = Server::get(FeatureService::class);
		$place = $this->place('Escuela que vuelve', ['geometry' => self::at(-71.00, -29.50)]);
		$service->retire($place);

		$boundary = $this->sector('Límite que llega mientras tanto', [
			'geometry' => self::REMOTE,
			'subcategoryId' => $this->subcategoryIdBySlug($slug),
		]);
		$service->restore($place);

		self::assertSame($boundary, $this->stored($place)->$getter());
	}

	/** Deriving again is not reassigning: an answer that did not change is not history. */
	public function testRestoringARecordWhoseBoundariesHeldRecordsNoAssignmentChange(): void {
		$service = Server::get(FeatureService::class);
		$sector = $this->sector('Sector que se queda', ['geometry' => self::REMOTE]);
		$place = $this->place('Escuela que vuelve', ['geometry' => self::at(-71.00, -29.50)]);

		$service->retire($place);
		$service->restore($place);

		self::assertSame($sector, $this->stored($place)->getSectorId());
		$changes = $service->history($place)[0]['changes'] ?? [];
		self::assertArrayNotHasKey('sectorId', $changes);
		self::assertArrayNotHasKey('unidadVecinalId', $changes);
	}

	/** Redoing an undone import restores through the same door, so it derives again too. */
	public function testRedoingAnUndoneImportDerivesTheAssignmentAgain(): void {
		$sector = $this->sector('Sector que se retira', ['geometry' => self::REMOTE]);

		$slug = 'redo-assignment-' . uniqid();
		$json = <<<JSON
		{"type":"FeatureCollection","features":[
		  {"type":"Feature","geometry":{"type":"Point","coordinates":[-71.00,-29.50]},
		   "properties":{"uid":"redo:place","name":"Escuela importada"}}
		]}
		JSON;
		$batch = Server::get(ImportService::class)->importFile($json, 'datos.geojson', $slug, 'Prueba', 'tester');
		$this->datasets[] = $slug;

		$datasetId = (int)Server::get(DatasetMapper::class)->findBySlug($slug)->getId();
		$imported = static fn (): Feature => Server::get(FeatureMapper::class)->findByDataset($datasetId)['redo:place'];
		self::assertSame($sector, $imported()->getSectorId());

		$revert = Server::get(RevertService::class);
		$undo = $revert->revert((int)$batch->getId(), 'tester');
		Server::get(FeatureService::class)->retire($sector);
		$revert->revert((int)$undo->getId(), 'tester');

		self::assertFalse((bool)$imported()->getRetired(), 'the redo brings it back');
		self::assertNotSame($sector, $imported()->getSectorId(), 'without the Sector retired meanwhile');
	}

	/**
	 * #18's deferred half: counts by the stored id, never by asking the geometry.
	 *
	 * Somewhere nothing else reaches, so the count is this test's two records and
	 * not however many the instance already had inside WEST.
	 */
	public function testTheServiceCountsBySector(): void {
		$sector = $this->sector('Sector contado', ['geometry' => [
			'type' => 'Polygon',
			'coordinates' => [[
				[-72.02, -30.52], [-71.98, -30.52], [-71.98, -30.48], [-72.02, -30.48], [-72.02, -30.52],
			]],
		]]);
		$this->place('Una', ['geometry' => self::at(-72.00, -30.50)]);
		$this->place('Otra', ['geometry' => self::at(-71.99, -30.51)]);

		self::assertSame(2, Server::get(RegistryService::class)->counts()['bySector'][$sector] ?? 0);
	}

	/**
	 * Reclassification is a redraw of the map without a redraw of the shape.
	 *
	 * Both directions go through `update()` with the geometry untouched, which is
	 * exactly the shape the sweep used to skip: it asked whether the geometry had
	 * moved, and a Subcategory change moves nothing. ADR-0008 says what falls
	 * inside a boundary is derived at the one door every write comes through, so
	 * becoming a boundary and ceasing to be one both have to derive.
	 *
	 * Far from La Florida on purpose: this runs against an instance the browser
	 * gate and other suites have written to, and a coordinate near the seed's own
	 * Sectores would let somebody else's boundary answer for ours.
	 */
	public function testReclassifyingIntoABoundarySubcategoryAssignsWhatItCovers(): void {
		// A Polygon in a Subcategory that is not a division: a shape, not a boundary.
		$zone = $this->place('Zona que asciende', [
			'subcategoryId' => $this->subcategoryIdBySlug('colegio'),
			'geometry' => self::REMOTE,
		]);
		$place = $this->place('Escuela dentro de la zona', ['geometry' => self::at(-71.00, -29.50)]);
		self::assertNotSame($zone, $this->stored($place)->getSectorId(), 'not a boundary yet');

		// The shape is re-sent unchanged, which is what the browser does on an edit.
		Server::get(FeatureService::class)->update($zone, [
			'name' => 'Zona que asciende',
			'subcategoryId' => $this->subcategoryIdBySlug('sector'),
			'geometry' => self::REMOTE,
		]);

		self::assertSame($zone, $this->stored($place)->getSectorId(), 'it is a Sector now');
	}

	public function testReclassifyingOutOfABoundarySubcategoryLetsGoOfWhatItHeld(): void {
		$sector = $this->sector('Sector que desciende', ['geometry' => self::REMOTE]);
		$place = $this->place('Escuela dentro del sector', ['geometry' => self::at(-71.00, -29.50)]);
		self::assertSame($sector, $this->stored($place)->getSectorId());

		Server::get(FeatureService::class)->update($sector, [
			'name' => 'Sector que desciende',
			// A Subcategory that is not a division.
			'subcategoryId' => $this->subcategoryIdBySlug('colegio'),
			'geometry' => self::REMOTE,
		]);

		// Not null: see the redraw test above — another boundary may legitimately
		// hold this point. What is promised is that it let go of THIS one, which
		// is no longer a boundary at all.
		self::assertNotSame($sector, $this->stored($place)->getSectorId(), 'no longer a boundary');
	}

	/**
	 * The third direction #84 missed: Sector to Unidad Vecinal with the shape
	 * unchanged. Both are boundaries, so "was it a boundary?" answers the same
	 * before and after and the sweep stayed skipped — the Place kept a sectorId
	 * that no Sector holds any more and never gained the Unidad Vecinal it is in.
	 */
	public function testReclassifyingBetweenDivisionsMovesWhatItHoldsAcross(): void {
		$sector = $this->sector('Sector que cambia de división', ['geometry' => self::REMOTE]);
		$place = $this->place('Escuela dentro', ['geometry' => self::at(-71.00, -29.50)]);
		self::assertSame($sector, $this->stored($place)->getSectorId());
		self::assertNotSame($sector, $this->stored($place)->getUnidadVecinalId());

		Server::get(FeatureService::class)->update($sector, [
			'name' => 'Sector que cambia de división',
			'subcategoryId' => $this->subcategoryIdBySlug('unidad_vecinal'),
			'geometry' => self::REMOTE,
		]);

		self::assertNotSame($sector, $this->stored($place)->getSectorId(), 'no longer its Sector');
		self::assertSame($sector, $this->stored($place)->getUnidadVecinalId(), 'now its Unidad Vecinal');
	}

	/** @return array<string, mixed> */
	private static function at(float $lon, float $lat): array {
		return ['type' => 'Point', 'coordinates' => [$lon, $lat]];
	}

	private function stored(int $id): \OCA\Territorio\Db\Feature {
		return Server::get(FeatureMapper::class)->find($id);
	}
}
