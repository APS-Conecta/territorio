<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Service\ExportService;
use OCA\Territorio\Service\FeatureService;
use OCP\Server;

/**
 * A Sector's Limit, stored (issue #15).
 *
 * The distinction every test here turns on: **the Limit records intent and the
 * geometry records the agreed line.** They agree when a Sector is generated from
 * picked lines and stop agreeing the moment somebody drags a vertex — which is
 * expected, and is why the Limit is a column of its own rather than something
 * derived from the shape.
 */
final class LimitTest extends IntegrationTestCase {
	private const WEST = [[[-70.60, -33.54], [-70.56, -33.54], [-70.56, -33.50], [-70.60, -33.50], [-70.60, -33.54]]];
	private const MOVED = [[[-70.59, -33.53], [-70.55, -33.53], [-70.55, -33.49], [-70.59, -33.49], [-70.59, -33.53]]];

	private const LIMIT = [
		['featureId' => 4321, 'name' => 'Avenida Vicuña Mackenna'],
		['featureId' => 4322, 'name' => 'Canal San Carlos'],
		// A stretch no dataset holds, which is half of what a real limit runs
		// along and the reason a Limit is not just a list of feature ids.
		['name' => 'por detrás del colegio'],
	];

	private FeatureService $features;

	protected function setUp(): void {
		$this->features = Server::get(FeatureService::class);
	}

	/** A Sector with a Limit, registered for cleanup. */
	private function sector(string $name, array $limit, array $rings = self::WEST): int {
		$id = (int)$this->features->create([
			'name' => $name,
			'subcategoryId' => $this->sectorSubcategoryId(),
			'geometry' => ['type' => 'Polygon', 'coordinates' => $rings],
			'limit' => $limit,
		])->getId();
		$this->created[] = $id;

		return $id;
	}

	private function sectorSubcategoryId(): int {
		foreach (Server::get(\OCA\Territorio\Db\SubcategoryMapper::class)->findAll() as $subcategory) {
			if ($subcategory->getSlug() === 'sector') {
				return (int)$subcategory->getId();
			}
		}

		self::fail('the seed should have created the Sector subcategory');
	}

	private function stored(int $id): array {
		return Server::get(FeatureMapper::class)->find($id)->toClient();
	}

	public function testPickingRecordsWhatTheSectorRunsAlong(): void {
		$id = $this->sector('Sector con límite', self::LIMIT);

		self::assertSame(self::LIMIT, $this->stored($id)['limit']);
	}

	/**
	 * The criterion the whole slice exists for.
	 *
	 * Adjusting by hand sends the geometry and says nothing about the Limit, so
	 * the Limit must be left alone. Anything else would mean a person loses what
	 * they wrote down the first time they nudge a corner.
	 */
	public function testTheLimitSurvivesHandAdjustmentOfTheGeometry(): void {
		$id = $this->sector('Sector que se ajusta', self::LIMIT);

		$this->features->update($id, [
			'name' => 'Sector que se ajusta',
			'subcategoryId' => $this->sectorSubcategoryId(),
			'geometry' => ['type' => 'Polygon', 'coordinates' => self::MOVED],
		]);

		$after = $this->stored($id);
		self::assertSame(self::LIMIT, $after['limit'], 'the Limit is intent, not a copy of the shape');
		// And the shape really did move, or this would pass by nothing happening.
		self::assertSame(self::MOVED, $after['geometry']['coordinates']);
	}

	/** ADR-0007: a Sector drawn by hand is just as valid, and has no Limit. */
	public function testAHandDrawnSectorHasAnEmptyLimit(): void {
		$id = $this->sector('Sector a mano', []);

		self::assertNull($this->stored($id)['limit']);
	}

	/** Sending a Limit is how it is changed — including to nothing. */
	public function testTheLimitCanBeChangedAndCleared(): void {
		$id = $this->sector('Sector que cambia', self::LIMIT);

		$this->features->update($id, [
			'name' => 'Sector que cambia',
			'subcategoryId' => $this->sectorSubcategoryId(),
			'limit' => [['name' => 'solo el canal']],
		]);
		self::assertSame([['name' => 'solo el canal']], $this->stored($id)['limit']);

		$this->features->update($id, [
			'name' => 'Sector que cambia',
			'subcategoryId' => $this->sectorSubcategoryId(),
			'limit' => [],
		]);
		self::assertNull($this->stored($id)['limit']);
	}

	/**
	 * Changing what a Sector runs along is a decision about the territory, and
	 * "quién cambió el límite" is the question history exists to answer.
	 */
	public function testChangingTheLimitIsInHistory(): void {
		$id = $this->sector('Sector con historial', self::LIMIT);

		$this->features->update($id, [
			'name' => 'Sector con historial',
			'subcategoryId' => $this->sectorSubcategoryId(),
			'limit' => [['name' => 'solo el canal']],
		]);

		// The UPDATE entry, not the union of every entry. Flattened, this passed on
		// the Created row — which `insert()` builds from `comparable()` directly and
		// which therefore always carries `limit` — while the update recorded nothing.
		// A test that cannot fail is worse than the bug it was meant to hold.
		$history = $this->features->history($id);
		$update = $history[0] ?? null;

		self::assertNotNull($update, 'the update left no history entry at all');
		self::assertSame('updated', $update['action']);
		self::assertArrayHasKey(
			'limit',
			$update['changes'] ?? [],
			'changing what a Sector runs along was not recorded',
		);
	}

	/** A part with no readable name is not a part; it would render as a gap. */
	public function testAPartWithNoNameIsRefusedRatherThanStored(): void {
		$id = $this->sector('Sector con basura', [
			['featureId' => 1, 'name' => '  '],
			['name' => 'Canal San Carlos'],
			'no soy un objeto',
		]);

		self::assertSame([['name' => 'Canal San Carlos']], $this->stored($id)['limit']);
	}

	/**
	 * A Limit is read aloud, so there is a point past which it is not one.
	 *
	 * Nothing here is a security boundary — every user may already write every
	 * field (ADR-0005) — but this is the one field the browser sends as a
	 * structure, into a column with no length of its own.
	 */
	public function testAnAbsurdLimitIsBounded(): void {
		$id = $this->sector('Sector con límite absurdo', [
			...array_map(
				static fn (int $n): array => ['featureId' => $n, 'name' => 'Calle ' . $n],
				range(1, 900),
			),
			['name' => str_repeat('a', 500)],
		]);

		$stored = $this->stored($id)['limit'];
		self::assertLessThanOrEqual(500, count($stored));
		foreach ($stored as $part) {
			self::assertLessThanOrEqual(200, mb_strlen($part['name']));
		}
	}

	/** The export carries it as a sentence, because a person reads the export. */
	public function testTheLimitAppearsInExports(): void {
		$id = $this->sector('Sector que se exporta', self::LIMIT);

		$geojson = json_decode(
			Server::get(ExportService::class)->export([$id], 'geojson', false)['body'],
			true,
		);
		self::assertSame(
			'Avenida Vicuña Mackenna, Canal San Carlos y por detrás del colegio',
			$geojson['features'][0]['properties']['limite'],
		);

		$csv = Server::get(ExportService::class)->export([$id], 'csv', false)['body'];
		self::assertStringContainsString('Límite', $csv);
		self::assertStringContainsString('Avenida Vicuña Mackenna, Canal San Carlos', $csv);
	}

	/**
	 * A comuna holds one street as dozens of ways, so picking a name records
	 * dozens of parts. "Vicuña Mackenna, Vicuña Mackenna, Vicuña Mackenna…" is
	 * not what anybody said.
	 */
	public function testARepeatedNameIsSaidOnceInAnExport(): void {
		$id = $this->sector('Sector con calle repetida', [
			['featureId' => 1, 'name' => 'Avenida Vicuña Mackenna'],
			['featureId' => 2, 'name' => 'Avenida Vicuña Mackenna'],
			['featureId' => 3, 'name' => 'Canal San Carlos'],
		]);

		$geojson = json_decode(
			Server::get(ExportService::class)->export([$id], 'geojson', false)['body'],
			true,
		);

		self::assertSame(
			'Avenida Vicuña Mackenna y Canal San Carlos',
			$geojson['features'][0]['properties']['limite'],
		);
	}
}
