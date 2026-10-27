<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A repair step that is not registered never runs.
 *
 * RemoveRetiredTileSettings is the only thing that deletes a clinic's stale tile
 * settings, and RekeyCoordinateLessRecords the only thing that carries a record
 * #202 collapsed to its ADR-0022 id, so deleting either line from
 * `appinfo/info.xml` would leave every unit test green and the old data in
 * place. `install` and `post-migration` are both needed, for the reason
 * EnsureSeedData's registration gives.
 */
final class RepairStepsRegisteredTest extends TestCase {
	/** @return list<string> the step classes registered under one repair-steps event */
	private static function steps(string $event): array {
		$info = simplexml_load_file(dirname(__DIR__, 2) . '/appinfo/info.xml');
		self::assertNotFalse($info);

		$steps = [];
		foreach ($info->{'repair-steps'}->{$event}->step ?? [] as $step) {
			$steps[] = trim((string)$step);
		}

		return $steps;
	}

	/** @return array<string, array{string}> */
	public static function events(): array {
		return ['install' => ['install'], 'post-migration' => ['post-migration']];
	}

	/** @dataProvider events */
	public function testTheTileSettingsStepRunsOnBothEvents(string $event): void {
		self::assertContains('OCA\\Territorio\\Migration\\RemoveRetiredTileSettings', self::steps($event));
	}

	/** @dataProvider events */
	public function testTheReKeyStepRunsOnBothEvents(string $event): void {
		self::assertContains('OCA\\Territorio\\Migration\\RekeyCoordinateLessRecords', self::steps($event));
	}

	/** @dataProvider events */
	public function testTheSeedStepIsStillRegistered(string $event): void {
		self::assertContains('OCA\\Territorio\\Migration\\EnsureSeedData', self::steps($event));
	}
}
