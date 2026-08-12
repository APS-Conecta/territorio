<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Db;

use OCA\Territorio\Db\Feature;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * That every field history compares can be found on the entity.
 *
 * `comparable()` is keyed as the client knows a Feature; `Entity::getUpdatedFields()`
 * is keyed by property. `FeatureMapper::diff()` gates one on the other, so a key
 * that exists in only one of the two vocabularies is skipped — the edit is recorded
 * as having changed nothing, and the record's own history omits it. `limit` did
 * exactly that (issue #65).
 *
 * The point of this test is the next one. Any column whose client name diverges
 * from its property — the usual cause being a reserved word, as `limit` was — makes
 * the same silent hole, and `PROPERTY_OF` is where it is closed.
 */
final class FeatureTest extends TestCase {
	public function testEveryComparedFieldResolvesToAPropertyOfTheEntity(): void {
		$properties = array_map(
			static fn ($property) => $property->getName(),
			(new ReflectionClass(Feature::class))->getProperties(),
		);

		$unresolved = array_values(array_filter(
			array_keys((new Feature())->comparable()),
			static fn ($field) => !in_array(Feature::PROPERTY_OF[$field] ?? $field, $properties, true),
		));

		self::assertSame([], $unresolved, sprintf(
			'Feature::comparable() carries %s, which is not a property of Feature — '
			. 'FeatureMapper::diff() will skip it and the change will never reach history. '
			. 'Add it to Feature::PROPERTY_OF.',
			implode(', ', $unresolved),
		));
	}

	/**
	 * An alias for a field nobody compares is dead, and an alias pointing at a
	 * property that no longer exists makes the check above pass by resolving to
	 * nothing real.
	 */
	public function testEveryAliasIsForAComparedFieldAndAnExistingProperty(): void {
		$comparable = (new Feature())->comparable();
		$reflection = new ReflectionClass(Feature::class);

		foreach (Feature::PROPERTY_OF as $field => $property) {
			self::assertArrayHasKey($field, $comparable, "PROPERTY_OF aliases $field, which comparable() does not carry");
			self::assertTrue($reflection->hasProperty($property), "PROPERTY_OF points $field at $property, which Feature does not have");
		}
	}
}
