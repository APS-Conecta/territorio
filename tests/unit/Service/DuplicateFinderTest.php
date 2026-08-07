<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Service\DuplicateFinder;
use PHPUnit\Framework\TestCase;

/**
 * Which pairs are worth a person's attention, and which are not (issue #16).
 *
 * The rule is deliberately generous in one direction and strict in the other:
 * this finds CANDIDATES and never merges anything, so a false positive costs one
 * glance at a queue while a false negative leaves two records for the same place
 * in the Registry forever. The one thing it must not do is offer so much that
 * nobody reads it.
 *
 * Two shapes appear in the real data and both are pinned here: the same place at
 * the same coordinate under two names, and the same institution named slightly
 * differently a block apart.
 */
final class DuplicateFinderTest extends TestCase {
	/**
	 * @param array{0: int, 1: string, 2: ?float, 3: ?float} ...$records
	 * @return list<array{0: int, 1: int, 2: string}>
	 */
	private function pairs(array ...$records): array {
		return array_map(
			static fn ($pair): array => [$pair->firstId, $pair->secondId, $pair->reason],
			DuplicateFinder::pairs(array_map(
				static fn (array $r): array => [
					'id' => $r[0], 'name' => $r[1], 'lat' => $r[2], 'lon' => $r[3],
				],
				$records,
			)),
		);
	}

	public function testTheSamePlaceUnderTwoNamesIsACandidate(): void {
		self::assertSame(
			[[1, 2, 'coordenada']],
			$this->pairs(
				[1, 'Balneario Municipal', -33.52, -70.58],
				[2, 'Piscina Municipal La Florida', -33.52, -70.58],
			),
		);
	}

	/** A GPS reading and a hand-placed pin for one building differ by metres. */
	public function testCoordinatesAFewMetresApartAreTheSameCoordinate(): void {
		// ~8 m north.
		self::assertCount(1, $this->pairs(
			[1, 'Balneario Municipal', -33.52, -70.58],
			[2, 'Piscina Municipal', -33.520072, -70.58],
		));
	}

	/**
	 * Two real places on the same block are not one place.
	 *
	 * Deliberately inside the distance rule — ~100 m — so that the names are what
	 * has to reject this. Put them far enough apart and the test would pass
	 * whatever the name rule did.
	 */
	public function testTwoDifferentPlacesNearbyAreNotCandidates(): void {
		self::assertSame([], $this->pairs(
			[1, 'Farmacia Cruz Verde', -33.52, -70.58],
			[2, 'Jardín Infantil Los Cerezos', -33.5209, -70.58],
		));
	}

	/**
	 * The case the threshold is actually set for.
	 *
	 * "Escuela Bellavista" and "Liceo Bellavista" share most of their letters and
	 * are two different schools — a merge here loses a real record, so the rule
	 * has to leave them alone even though they are next door to each other.
	 */
	public function testTwoInstitutionsSharingAWordAreNotOne(): void {
		self::assertSame([], $this->pairs(
			[1, 'Escuela Bellavista', -33.52, -70.58],
			[2, 'Liceo Bellavista', -33.5209, -70.58],
		));
	}

	/** The other real shape: one institution, spelled differently by two sources. */
	public function testASimilarNameAShortDistanceAwayIsACandidate(): void {
		self::assertSame(
			[[1, 2, 'nombre']],
			// ~120 m apart: one source geocoded the street, the other the entrance.
			$this->pairs(
				[1, 'CESFAM Bellavista', -33.52, -70.58],
				[2, 'Cesfam  Bellavista', -33.5211, -70.58],
			),
		);
	}

	/** Accents are a spelling difference between sources, not a different place. */
	public function testAccentsAndCaseDoNotMakeTwoNamesDifferent(): void {
		self::assertCount(1, $this->pairs(
			[1, 'Jardín Infantil Rayún', -33.52, -70.58],
			[2, 'JARDIN INFANTIL RAYUN', -33.5205, -70.58],
		));
	}

	/**
	 * The same name across the comuna is the case that makes a queue useless.
	 *
	 * "Sede Junta de Vecinos" is thirty different buildings in La Florida, and
	 * offering every combination of them is 435 pairs nobody will ever read.
	 */
	public function testTheSameNameFarAwayIsNotACandidate(): void {
		// ~3 km apart.
		self::assertSame([], $this->pairs(
			[1, 'Sede Junta de Vecinos', -33.52, -70.58],
			[2, 'Sede Junta de Vecinos', -33.547, -70.58],
		));
	}

	/**
	 * A pair inside the rule is found wherever on the map it happens to sit.
	 *
	 * Swept rather than sampled, and east-west on purpose. Whatever this bins
	 * records into to avoid comparing a comuna against itself, the bins have
	 * edges — and a pair that straddles one is exactly the pair a person would
	 * never know had been missed. A degree of longitude in Santiago is about 93 km
	 * against latitude's 111, so an east-west pair covers more of a bin than a
	 * north-south one and is the first to fall through.
	 */
	public function testAPairInsideTheRuleIsFoundWhereverItSits(): void {
		// ~140 m apart, inside the rule and close enough to its edge to straddle
		// anything the size of the rule.
		$apart = 140 / (111_320 * cos(deg2rad(33.5)));

		for ($step = 0; $step < 40; $step++) {
			$lon = -70.58 + $step * $apart / 7;
			self::assertCount(
				1,
				$this->pairs(
					[1, 'CESFAM Bellavista', -33.52, $lon],
					[2, 'Cesfam Bellavista', -33.52, $lon + $apart],
				),
				sprintf('missed the pair at longitude %.6f', $lon),
			);
		}
	}

	/** A record with no coordinate cannot be near anything. */
	public function testARecordWithoutACoordinateIsNeverPaired(): void {
		self::assertSame([], $this->pairs(
			[1, 'Balneario Municipal', null, null],
			[2, 'Balneario Municipal', -33.52, -70.58],
		));
	}

	/** Each pair once, lowest id first, however the records arrive. */
	public function testAPairIsOfferedOnceAndInAStableOrder(): void {
		self::assertSame(
			[[1, 2, 'coordenada']],
			$this->pairs(
				[2, 'Piscina Municipal', -33.52, -70.58],
				[1, 'Balneario Municipal', -33.52, -70.58],
			),
		);
	}

	/**
	 * Three records at one coordinate are three pairs, not one group.
	 *
	 * Grouping would mean deciding they are all the same place, which is the
	 * decision this refuses to make on anyone's behalf.
	 */
	public function testThreeAtOneCoordinateAreThreePairs(): void {
		self::assertCount(3, $this->pairs(
			[1, 'Balneario Municipal', -33.52, -70.58],
			[2, 'Piscina Municipal', -33.52, -70.58],
			[3, 'Complejo Deportivo', -33.52, -70.58],
		));
	}
}
