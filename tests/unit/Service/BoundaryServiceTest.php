<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Domain\BoundingBox;
use OCA\Territorio\Service\BoundaryService;
use PHPUnit\Framework\TestCase;

/**
 * The street search's index: every name the cadastre holds, once per place.
 *
 * ADR-0007 on the search side: nothing here picks one «Pasaje Dos» — it offers
 * each one it can tell apart, and the person chooses. What tells them apart is
 * a distance (`BoundaryService::SAME_PLACE_M`), so most fixtures here are laid
 * out north-south, where a degree is 111,320 m anywhere: 0.001° is 111 m.
 */
final class BoundaryServiceTest extends TestCase {
	private const LAT = -33.52;
	private const LON = -70.59;

	/**
	 * An east-west way `$length` degrees long, `$north` degrees north of LAT.
	 * A length of 0 is a point, which makes the gaps below exact.
	 *
	 * @return array{name: string, box: BoundingBox}
	 */
	private static function way(string $name, float $north = 0.0, float $length = 0.001): array {
		return [
			'name' => $name,
			'box' => BoundingBox::around([
				[self::LON, self::LAT + $north],
				[self::LON + $length, self::LAT + $north],
			]),
		];
	}

	/**
	 * What the search is offered for these ways, as the mapper would hand them
	 * over (in id order).
	 *
	 * @param list<array{name: string, box: BoundingBox}> $ways
	 * @return list<array<string, mixed>>
	 */
	private function namesOf(array $ways): array {
		$features = $this->createMock(FeatureMapper::class);
		$features->expects(self::once())->method('boundaryNames')->willReturn($ways);

		return (new BoundaryService($features))->names();
	}

	/** «LOS TOROS» and «Los Toros» side by side are one street, spelt two ways. */
	public function testSpellingsOfOneNameNextToEachOtherAreOneRow(): void {
		$rows = $this->namesOf([self::way('LOS TOROS'), self::way('Los Toros', 0.0005)]);

		self::assertCount(1, $rows);
		self::assertSame('LOS TOROS', $rows[0]['name'], 'labelled with the first spelling filed');
		self::assertSame(2, $rows[0]['count']);
	}

	/** The criterion: two Pasaje Dos two kilometres apart are two choices, each with its own box. */
	public function testOneNameInTwoPlacesIsTwoRows(): void {
		$rows = $this->namesOf([self::way('Pasaje Dos'), self::way('Pasaje Dos', 0.02)]);

		self::assertCount(2, $rows);
		self::assertSame(['Pasaje Dos', 'Pasaje Dos'], array_column($rows, 'name'));
		self::assertSame([1, 1], array_column($rows, 'count'));
		self::assertEqualsWithDelta(self::LAT, $rows[0]['south'], 1e-9);
		self::assertEqualsWithDelta(self::LAT + 0.02, $rows[1]['south'], 1e-9);
	}

	/**
	 * An avenue is held as many ways laid end to end — «Avenida La Florida» is
	 * 128 — and its first and last are kilometres apart. Each touches the next,
	 * so it is one row whose box covers all of it.
	 */
	public function testALongStreetHeldAsManyWaysIsOneRow(): void {
		$ways = array_map(
			static fn (int $i): array => [
				'name' => 'Avenida Larga',
				'box' => BoundingBox::around([
					[self::LON + $i * 0.004, self::LAT],
					[self::LON + ($i + 1) * 0.004, self::LAT],
				]),
			],
			range(0, 9),
		);

		$rows = $this->namesOf($ways);

		self::assertCount(1, $rows);
		self::assertSame(10, $rows[0]['count']);
		self::assertEqualsWithDelta(self::LON, $rows[0]['west'], 1e-9);
		self::assertEqualsWithDelta(self::LON + 0.04, $rows[0]['east'], 1e-9);
	}

	/**
	 * Single linkage: a way joins through any way already in the place, not only
	 * through the first. 0 → 0.004° is 445 m and 0.004° → 0.008° is 445 m, but
	 * 0 → 0.008° is 890 m, and the middle one is filed LAST.
	 */
	public function testAWayJoinsThroughAnyWayAlreadyInThePlace(): void {
		$rows = $this->namesOf([
			self::way('Calle Cadena', 0.0, 0.0),
			self::way('Calle Cadena', 0.008, 0.0),
			self::way('Calle Cadena', 0.004, 0.0),
		]);

		self::assertCount(1, $rows);
		self::assertSame(3, $rows[0]['count']);
	}

	/** SAME_PLACE_M is the line: 401 m apart is one street, 601 m apart is two. */
	public function testTheGapDecides(): void {
		self::assertCount(1, $this->namesOf([
			self::way('Pasaje Uno', 0.0, 0.0),
			self::way('Pasaje Uno', 0.0036, 0.0),
		]));
		self::assertCount(2, $this->namesOf([
			self::way('Pasaje Uno', 0.0, 0.0),
			self::way('Pasaje Uno', 0.0054, 0.0),
		]));
	}

	/** Issue #12: names and boxes, never a shape — these six keys and no others. */
	public function testNoRowCarriesGeometry(): void {
		$rows = $this->namesOf([self::way('Pasaje Dos'), self::way('Los Toros')]);

		foreach ($rows as $row) {
			self::assertSame(['name', 'south', 'west', 'north', 'east', 'count'], array_keys($row));
		}
	}

	/** Production today: no Cadastral Dataset, so an empty list — not an error. */
	public function testNoCadastreIsAnEmptyList(): void {
		self::assertSame([], $this->namesOf([]));
	}

	/** A name of spaces is no name: nothing anybody types would reach it. */
	public function testANameOfSpacesIsLeftOut(): void {
		self::assertSame([], $this->namesOf([self::way('   ')]));
	}

	/** By folded name, so the browser's stable sort keeps same-name rows in this order. */
	public function testRowsComeOrderedByFoldedName(): void {
		$rows = $this->namesOf([self::way('Zapallar'), self::way('Álamos', 0.02)]);

		self::assertSame(['Álamos', 'Zapallar'], array_column($rows, 'name'));
	}
}
