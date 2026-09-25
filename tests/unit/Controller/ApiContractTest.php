<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Territorio\Tests\Unit\Controller;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Service\RegistryService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The published-surface fence (org ADR 0005: REST is the one published
 * surface; farmacia's ApiContractTest is the pattern). Three families:
 *
 * 1. Route inventory — openapi.json carries exactly the Registry's three
 *    reads. A route added or removed without the spec moving breaks here.
 * 2. counts is the one fully-typed route: the spec's 200 names exactly the
 *    keys RegistryService::counts() serves — the farmacia L2-10 family.
 * 3. The slugged routes (find/inSubcategory) are deliberately
 *    array<string,mixed> in the docblock, so the spec cannot name their
 *    keys; the fence holds the SERVED shape instead — describe()'s key set,
 *    read from the code, stable so a consumer's destructure never breaks
 *    silently.
 */
final class ApiContractTest extends TestCase {
	private const RUTAS = [
		'/ocs/v2.php/apps/territorio/api/v1/counts',
		'/ocs/v2.php/apps/territorio/api/v1/features/{id}',
		'/ocs/v2.php/apps/territorio/api/v1/subcategories/{category}/{subcategory}/features',
	];

	/** What counts() serves, read from RegistryService::counts()'s return. */
	private const COUNTS = [
		'total', 'unknownSubcategory', 'categories', 'label', 'subcategories',
		'bySector', 'byUnidadVecinal',
	];

	/** What describe() serves on the slugged routes: slugs, not ids. */
	private const SLUGGED = [
		'id', 'name', 'kind', 'category', 'subcategory', 'address', 'geometry',
	];

	private function spec(): array {
		$spec = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/openapi.json'),
			true,
		);
		self::assertIsArray($spec, 'openapi.json is missing or unparseable');
		return $spec;
	}

	public function testThePublishedRoutesAreExactlyTheRegistryReads(): void {
		self::assertSame(
			self::RUTAS,
			array_keys($this->spec()['paths']),
			'the published surface moved — a route was added or removed without openapi.json (org ADR 0005)',
		);
	}

	public function testTheSpecNamesExactlyTheServedCountsKeys(): void {
		$schema = $this->spec()['paths'][self::RUTAS[0]]['get']['responses']['200']
			?? null;
		self::assertNotNull($schema, 'the counts GET 200 response is gone from the spec');

		$json = json_encode($schema['content']['application/json']['schema']);
		self::assertIsString($json);
		preg_match_all('/"([a-zA-Z]+)":\s*\{/', $json, $matches);
		$named = array_values(array_unique($matches[1]));

		self::assertSame(
			[],
			array_values(array_diff(self::COUNTS, $named)),
			'the spec no longer names these served counts keys',
		);
		self::assertSame(
			[],
			array_values(array_diff($named, self::COUNTS, ['type', 'properties', 'required', 'items', 'description', 'ocs', 'meta', 'data', 'additionalProperties'])),
			'the spec names keys the counts route does not serve',
		);
	}

	public function testDescribeServesTheSluggedShapeStably(): void {
		$feature = new Feature();
		$feature->setName('CESFAM Demo');
		$feature->setKind('establecimiento');

		// describe() is private by design — it is the Registry's own
		// projection. The published payload is its return, so the fence reads
		// it without the service's mappers: no constructor, empty taxonomy.
		$registry = (new ReflectionClass(RegistryService::class))
			->newInstanceWithoutConstructor();
		$describe = new ReflectionMethod(RegistryService::class, 'describe');
		$describe->setAccessible(true);

		$payload = $describe->invoke($registry, $feature, []);

		self::assertSame(
			self::SLUGGED,
			array_keys($payload),
			'the slugged routes\' served shape moved — update every consumer and the ADR 0005 fence',
		);
	}
}
