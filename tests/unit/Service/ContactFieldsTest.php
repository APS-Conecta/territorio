<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Service\CsvWriter;
use OCA\Territorio\Service\GeoJsonWriter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * That the writers agree about which fields describe a person.
 *
 * The promise an unconfirmed export makes — that it carries nothing about
 * anybody (ADR-0006) — is only as good as the agreement between everything that
 * writes a file. Each writer decides for itself what to withhold, and they spell
 * the same field differently because their formats do, so nothing in the code
 * can make them agree. This is what does.
 *
 * Reflection because both maps are private and should stay so: this asserts an
 * agreement between two internals, and making them public to check it would be
 * changing the design to suit the test.
 */
final class ContactFieldsTest extends TestCase {
	/** GeoJSON maps row key → property name, so its keys are the row keys. */
	private static function geoJsonFields(): array {
		return self::sorted(array_keys(
			(new ReflectionClass(GeoJsonWriter::class))->getConstant('CONTACT'),
		));
	}

	/** The CSV maps heading → row key, so its values are the row keys. */
	private static function csvFields(): array {
		return self::sorted(array_values(
			(new ReflectionClass(CsvWriter::class))->getConstant('CONTACT'),
		));
	}

	/** @return list<string> */
	private static function sorted(array $keys): array {
		sort($keys);

		return $keys;
	}

	public function testBothWritersWithholdTheSameFields(): void {
		self::assertSame(
			self::geoJsonFields(),
			self::csvFields(),
			'one writer treats a field as somebody\'s detail and the other does not',
		);
	}

	/**
	 * `address` is deliberately absent from both. Where a junta meets is a fact
	 * about the territory and it is on the sign outside; a name, a telephone
	 * number and an email address are the presidenta's.
	 */
	public function testNeitherWriterTreatsAddressAsSomebodysDetail(): void {
		self::assertNotContains('address', self::geoJsonFields());
		self::assertNotContains('address', self::csvFields());
	}

	/** A set that emptied itself would make the assertions above vacuous. */
	public function testTheContactFieldsAreNotEmpty(): void {
		self::assertNotEmpty(self::geoJsonFields());
	}
}
