<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Db;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * What a write records in history, decided by a pure function of two Features.
 *
 * `FeatureMapper::diff()` touches no database — it reads `getUpdatedFields()` and `comparable()`
 * and nothing else — but it lives on a mapper that needs a connection to construct, so nothing
 * has ever exercised it below the integration suite. Reflection rather than making it public:
 * this asserts an internal agreement, and widening the interface to test it would be changing
 * the design to suit the test.
 */
final class FeatureDiffTest extends TestCase {
	/** @return array<string, array{from: mixed, to: mixed}> */
	private static function diff(Feature $before, Feature $after): array {
		$class = new ReflectionClass(FeatureMapper::class);

		return $class->getMethod('diff')->invoke($class->newInstanceWithoutConstructor(), $before, $after);
	}

	/**
	 * The one field whose client key is not its property name: history is keyed `limit`, the
	 * entity property is `boundaryLimit`, and `Feature::PROPERTY_OF` is the bridge. Drop that
	 * lookup and `getUpdatedFields()` — keyed by property — never matches the `limit` coming out
	 * of `comparable()`, so redrawing what a Sector runs along would vanish from history while
	 * every other field kept working. ADR-0009 and issue #15 both say that must be visible.
	 */
	public function testItRecordsAFieldWhoseClientKeyDiffersFromItsProperty(): void {
		$before = new Feature();
		$before->setBoundaryLimit('la línea vieja');

		$after = new Feature();
		$after->setBoundaryLimit('la línea nueva');

		$changes = self::diff($before, $after);

		self::assertArrayHasKey('limit', $changes, 'a change to the Sector limit left no history');
		self::assertSame(['from' => 'la línea vieja', 'to' => 'la línea nueva'], $changes['limit']);
	}

	/**
	 * The reason diff() consults `getUpdatedFields()` at all, quoted from its own docblock:
	 * "comparing every field against a freshly read row would report a colleague's untouched edit
	 * as a change this save made."
	 */
	public function testItIgnoresAFieldThisWriteNeverTouched(): void {
		$before = new Feature();
		$before->setName('Plaza Uno');

		// $after never has setName() called, so the write is not writing it.
		$after = new Feature();
		$after->setBoundaryLimit('cualquiera');

		self::assertArrayNotHasKey('name', self::diff($before, $after));
	}
}
