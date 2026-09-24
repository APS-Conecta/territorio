<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Domain;

use OCA\Territorio\Domain\OsmAttribution;
use PHPUnit\Framework\TestCase;

/**
 * ODbL is the licence at stake (issue #28): an export carrying OpenStreetMap
 * geometry without the line is a licence breach, so the LOOSE match is the
 * design — and the edge cases here are the ones that decide whether the line
 * appears. Pinned directly because until now only the three writers' tests
 * caught a regression here, and only by accident of their fixtures.
 */
final class OsmAttributionTest extends TestCase {
	public function testASourceRefThatNamesOsmRequiresTheLine(): void {
		self::assertTrue(OsmAttribution::isFromOsm([
			'sourceRef' => 'OpenStreetMap — https://openstreetmap.org',
		]));
	}

	/** The loose match is case-insensitive and substring — «osm» alone is NOT a hit. */
	public function testTheMatchIsLooseButNotGenerous(): void {
		self::assertTrue(OsmAttribution::isFromOsm(['sourceRef' => 'openstreetmap contributors']));
		self::assertFalse(OsmAttribution::isFromOsm(['sourceRef' => 'OSM Chile']));
		self::assertFalse(OsmAttribution::isFromOsm(['sourceRef' => null]));
	}

	public function testTheOfficialCopySpeaksEvenWhenTheFuenteBoxWasCleared(): void {
		// ADR-0003/#28: a person may retype Fuente on an imported street; the
		// stored copy of what the authority published still obliges the file.
		self::assertTrue(OsmAttribution::isFromOsm([
			'sourceRef' => null,
			'official' => (string)json_encode(['sourceRef' => 'OpenStreetMap, licencia ODbL']),
		]));
	}

	/** A copy that is not JSON is not a claim about anything — even this one. */
	public function testANonJsonOfficialCopySaysNothing(): void {
		self::assertFalse(OsmAttribution::isFromOsm([
			'official' => 'OpenStreetMap (texto suelto, no JSON)',
		]));
	}

	/** JSON that is not an object is not a claim either. */
	public function testAScalarOfficialCopySaysNothing(): void {
		self::assertFalse(OsmAttribution::isFromOsm([
			'official' => (string)json_encode('OpenStreetMap'),
		]));
	}

	public function testARetypedSourceRefIsStillAString(): void {
		// names() casts before it looks, so an int the row grew does not warn.
		self::assertTrue(OsmAttribution::isFromOsm(['sourceRef' => 42]) === false);
	}

	public function testRequiredScansTheWholeSet(): void {
		$rows = [
			['sourceRef' => 'FONASA'],
			['sourceRef' => 'OpenStreetMap'],
		];

		self::assertTrue(OsmAttribution::required($rows));
		self::assertFalse(OsmAttribution::required([['sourceRef' => 'FONASA']]));
	}
}
