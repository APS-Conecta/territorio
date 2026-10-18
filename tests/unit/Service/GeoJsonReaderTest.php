<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Service\GeoJsonReader;
use PHPUnit\Framework\TestCase;

/**
 * Turning a GeoJSON file into rows an import can match on.
 *
 * The hard part is identity. The real La Florida catastro carries no `id` on its
 * Features and no `uid` in their properties — checked, not assumed — so the
 * external id has to be DERIVED, and derived the same way every time. Get that
 * wrong and a second import does not update 865 records, it creates 865 more.
 */
final class GeoJsonReaderTest extends TestCase {
	private const JDV = <<<'JSON'
	{"type":"FeatureCollection","features":[
	  {"type":"Feature",
	   "geometry":{"type":"Point","coordinates":[-70.5800318,-33.5159937]},
	   "properties":{"name":"Junta de Vecinos de Prueba","category":"organizacion_social",
	     "subcategory":"junta_de_vecinos","address":"Calle de Prueba 123","phone":"900000000",
	     "email":"jdv@example.org","contact_person":"Persona de Prueba","contact_role":"Secretario",
	     "ubicacion":"Exacta","source":"cesfam_de_prueba","source_url":"https://example.org/catastro"}}
	]}
	JSON;

	private function read(string $json, string $dataset = 'catastro'): array {
		return (new GeoJsonReader())->read($json, $dataset);
	}

	public function testItMapsThePriorArtsPropertyNames(): void {
		$row = $this->read(self::JDV)[0];

		self::assertSame('Junta de Vecinos de Prueba', $row['name']);
		self::assertSame('organizacion_social', $row['category']);
		self::assertSame('junta_de_vecinos', $row['subcategory']);
		self::assertSame('Calle de Prueba 123', $row['address']);
		self::assertSame('900000000', $row['phone']);
		self::assertSame('jdv@example.org', $row['email']);
		self::assertSame('Persona de Prueba', $row['contactPerson']);
		self::assertSame('Secretario', $row['contactRole']);
		self::assertSame(['type' => 'Point', 'coordinates' => [-70.5800318, -33.5159937]], $row['geometry']);
	}

	public function testUbicacionPassesThroughRawForPrepareToJudge(): void {
		// Raw since L1-07/L1-15: the reader carries the cell as the file spelled
		// it and ImportService::prepare() folds and judges it — the same
		// raw-label doctrine the classification cells got.
		self::assertSame('Exacta', $this->read(self::JDV)[0]['locationQuality']);
		self::assertSame(
			'Aproximada',
			$this->read(str_replace('"Exacta"', '"Aproximada"', self::JDV))[0]['locationQuality'],
		);
	}

	/** 309 of the real file's features have no `ubicacion` at all. */
	public function testAMissingUbicacionIsSimplyAbsent(): void {
		$row = $this->read(str_replace('"ubicacion":"Exacta",', '', self::JDV))[0];

		self::assertNull($row['locationQuality']);
	}

	public function testSourceAndItsLinkBecomeTheSourceReference(): void {
		$row = $this->read(self::JDV)[0];

		self::assertStringContainsString('cesfam_de_prueba', $row['sourceRef']);
		self::assertStringContainsString('https://example.org/catastro', $row['sourceRef']);
	}

	public function testAnExplicitUidIsPreferredOverAnythingDerived(): void {
		$json = str_replace('"name":"Junta', '"uid":"cesfam:892c3398ecef3f8c","name":"Junta', self::JDV);

		self::assertSame('cesfam:892c3398ecef3f8c', $this->read($json)[0]['externalId']);
	}

	public function testTheFeaturesOwnIdIsUsedWhenThereIsNoUid(): void {
		$json = str_replace('{"type":"Feature",', '{"type":"Feature","id":"osm/way/123",', self::JDV);

		self::assertSame('osm/way/123', $this->read($json)[0]['externalId']);
	}

	/**
	 * The one that decides whether a second import updates or duplicates.
	 */
	public function testADerivedIdIsIdenticalAcrossReads(): void {
		self::assertSame(
			$this->read(self::JDV)[0]['externalId'],
			$this->read(self::JDV)[0]['externalId'],
		);
	}

	public function testADerivedIdIsPrefixedWithItsSource(): void {
		self::assertStringStartsWith('cesfam_de_prueba:', $this->read(self::JDV)[0]['externalId']);
	}

	/** Without a source in the file, the Dataset it is being imported into names it. */
	public function testADerivedIdFallsBackToTheDatasetName(): void {
		$json = str_replace(',"source":"cesfam_de_prueba"', '', self::JDV);

		self::assertStringStartsWith('catastro:', $this->read($json)[0]['externalId']);
	}

	/**
	 * Two plazas called "Plaza" on opposite sides of the comuna are two plazas.
	 * Deriving from the name alone would merge them on every import.
	 */
	public function testTwoFeaturesWithTheSameNameElsewhereGetDifferentIds(): void {
		$json = '{"type":"FeatureCollection","features":['
			. '{"type":"Feature","geometry":{"type":"Point","coordinates":[-70.60,-33.50]},"properties":{"name":"Plaza"}},'
			. '{"type":"Feature","geometry":{"type":"Point","coordinates":[-70.50,-33.40]},"properties":{"name":"Plaza"}}'
			. ']}';
		$rows = $this->read($json);

		self::assertNotSame($rows[0]['externalId'], $rows[1]['externalId']);
	}

	/**
	 * The one that would have caught a silent, dataset-wide duplication.
	 *
	 * `json_encode` of a float obeys serialize_precision, and CLI and FPM
	 * routinely load different php.ini files — so the occ command and the browser
	 * upload could derive different ids for the same file, and the second import
	 * would create every record again instead of matching.
	 */
	public function testADerivedIdDoesNotDependOnSerializePrecision(): void {
		$original = ini_get('serialize_precision');

		try {
			ini_set('serialize_precision', '-1');
			$loose = $this->read(self::JDV)[0]['externalId'];
			ini_set('serialize_precision', '17');
			$strict = $this->read(self::JDV)[0]['externalId'];
		} finally {
			ini_set('serialize_precision', (string)$original);
		}

		self::assertSame($loose, $strict);
	}

	public function testAFeatureWithNoGeometryIsKept(): void {
		$json = str_replace(
			'"geometry":{"type":"Point","coordinates":[-70.5800318,-33.5159937]}',
			'"geometry":null',
			self::JDV,
		);
		$row = $this->read($json)[0];

		self::assertNull($row['geometry']);
		self::assertSame('Junta de Vecinos de Prueba', $row['name']);
	}

	/** Kinds this slice cannot draw are still read; the service decides. */
	public function testNonPointGeometryIsReadAndReported(): void {
		$json = '{"type":"FeatureCollection","features":[{"type":"Feature","geometry":'
			. '{"type":"Polygon","coordinates":[[[-70.6,-33.5],[-70.5,-33.5],[-70.5,-33.4],[-70.6,-33.5]]]},'
			. '"properties":{"name":"Sector"}}]}';

		self::assertSame('Polygon', $this->read($json)[0]['geometry']['type']);
	}

	public function testMalformedJsonIsRefusedWithSomethingUsable(): void {
		$this->expectException(InvalidImportException::class);
		$this->expectExceptionMessageMatches('/JSON/i');
		$this->read('{not json');
	}

	public function testSomethingThatIsNotAFeatureCollectionIsRefused(): void {
		$this->expectException(InvalidImportException::class);
		$this->read('{"type":"Point","coordinates":[0,0]}');
	}

	public function testAFeatureWithNoNameIsRefusedRatherThanImportedBlank(): void {
		$json = '{"type":"FeatureCollection","features":[{"type":"Feature","geometry":null,"properties":{}}]}';

		$this->expectException(InvalidImportException::class);
		$this->read($json);
	}

	public function testAnEmptyCollectionIsValidAndImportsNothing(): void {
		self::assertSame([], $this->read('{"type":"FeatureCollection","features":[]}'));
	}
}
