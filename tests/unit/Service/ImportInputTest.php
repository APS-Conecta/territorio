<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Service\LocationQuality;
use OCA\Territorio\Service\ImportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * That every field an import carries is a field a re-import compares.
 *
 * `differs()` decides whether a re-import writes at all. It derives the fields
 * it compares from `input()` — but it can only compare what `comparable()` also
 * holds, so a field present in one and absent from the other is compared against
 * nothing and the re-import reports the record unchanged. Silently, which
 * defeats the point of running it again.
 *
 * Reflection because both are private and should stay so: this asserts an
 * agreement between two internals, and making them public to check it would be
 * changing the design to suit the test.
 */
final class ImportInputTest extends TestCase {
	/** One of every field a reader produces, so `input()` has something to pick from. */
	private const ROW = [
		'name' => 'Plaza Uno',
		'subcategoryId' => 7,
		'geometry' => ['type' => 'Point', 'coordinates' => [-70.58, -33.52]],
		'locationQuality' => LocationQuality::Exact->value,
		'address' => 'Calle Uno 1',
		'phone' => '961111111',
		'email' => 'uno@ejemplo.cl',
		'contactPerson' => 'Marcela Ríos',
		'contactRole' => 'Presidenta',
		'openingHours' => 'Lunes a viernes',
		'notes' => 'Nada',
		'sourceRef' => 'prueba',
	];

	/** @return array<string, mixed> */
	private static function input(): array {
		$method = (new ReflectionClass(ImportService::class))->getMethod('input');

		return $method->invoke(
			(new ReflectionClass(ImportService::class))->newInstanceWithoutConstructor(),
			self::ROW,
		);
	}

	public function testEveryImportedFieldIsOneAReImportCanCompare(): void {
		$missing = array_diff(
			array_keys(self::input()),
			array_keys((new Feature())->comparable()),
		);

		self::assertSame([], array_values($missing), sprintf(
			'ImportService::input() carries %s, which Feature::comparable() does not hold — '
			. 'a re-import would report it unchanged and never write it',
			implode(', ', $missing),
		));
	}

	/**
	 * The row this test hands `input()` has to stay a superset of what `input()`
	 * asks for, or the check above passes by comparing nothing.
	 */
	public function testTheFixtureCoversEveryFieldInputAsksFor(): void {
		self::assertSame([], array_values(array_diff(
			array_keys(self::input()),
			array_keys(self::ROW),
		)));
	}
}
