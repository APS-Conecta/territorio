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

	/** A FeatureCollection of rows with no geometry and no id, one per set of properties. */
	private function withoutCoordinates(array ...$properties): string {
		return json_encode([
			'type' => 'FeatureCollection',
			'features' => array_map(
				static fn (array $one): array => ['type' => 'Feature', 'geometry' => null, 'properties' => $one],
				$properties,
			),
		], JSON_THROW_ON_ERROR);
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

	/**
	 * Without a coordinate the address is the "where" (ADR-0022): fifteen
	 * «Multicancha» rows at fifteen addresses are fifteen places, not one row
	 * and fourteen «duplicados en el archivo» (#202).
	 */
	public function testTheSameNameWithoutACoordinateAtAnotherAddressIsAnotherPlace(): void {
		$rows = $this->read($this->withoutCoordinates(
			['name' => 'Multicancha', 'address' => 'Pasaje Uno 100'],
			['name' => 'Multicancha', 'address' => 'Pasaje Dos 200'],
		));

		self::assertNotSame($rows[0]['externalId'], $rows[1]['externalId']);
	}

	/**
	 * Only the "where" counts: a new phone, or new notes beside an address, is
	 * the same place updated, and its Local override stays attached (ADR-0003).
	 */
	public function testTheSameNameAndAddressIsOnePlaceWhateverThePhoneOrNotes(): void {
		$rows = $this->read($this->withoutCoordinates(
			['name' => 'Multicancha', 'address' => 'Pasaje Uno 100', 'phone' => '911111111', 'notes' => 'Abierta'],
			['name' => 'Multicancha', 'address' => 'Pasaje Uno 100', 'phone' => '922222222'],
			['name' => 'Multicancha', 'address' => 'Pasaje Uno 100', 'notes' => 'Cerrada'],
		));

		self::assertSame($rows[0]['externalId'], $rows[1]['externalId']);
		self::assertSame($rows[0]['externalId'], $rows[2]['externalId']);
	}

	/** KML has no address: a placemark's description is its notes, and the only "where" left. */
	public function testWithoutAnAddressTheNotesTellTwoPlacesApart(): void {
		$rows = $this->read($this->withoutCoordinates(
			['name' => 'Sede social', 'notes' => 'Villa Los Aromos'],
			['name' => 'Sede social', 'notes' => 'Villa El Bosque'],
		));

		self::assertNotSame($rows[0]['externalId'], $rows[1]['externalId']);
	}

	public function testACoordinateLessIdIsIdenticalAcrossReads(): void {
		$file = $this->withoutCoordinates(['name' => 'Multicancha', 'address' => 'Pasaje Uno 100']);

		self::assertSame($this->read($file)[0]['externalId'], $this->read($file)[0]['externalId']);
	}

	/**
	 * Pinned to the id main derived at c2b163e. A coordinate-bearing id that
	 * moved would turn every re-import of the 865-feature catastro into 865 new
	 * records (ADR-0022, decision 2).
	 */
	public function testACoordinateBearingIdKeepsItsBytes(): void {
		self::assertSame('cesfam_de_prueba:25365630c19448b3', $this->read(self::JDV)[0]['externalId']);
	}

	/**
	 * Pinned to main at c2b163e: with no address and no notes there is nothing
	 * new to hash, so such a record is not re-keyed and old exports still match.
	 */
	public function testACoordinateLessIdWithNoAddressOrNotesKeepsItsBytes(): void {
		self::assertSame(
			'catastro:316a1b63cabc9a41',
			$this->read($this->withoutCoordinates(['name' => 'Multicancha', 'phone' => '911111111']))[0]['externalId'],
		);
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

	/**
	 * #241: a `name` that is a list or an object was `(string)`'d to the text
	 * "Array" — a row imported under a name nobody typed. Read through the
	 * same text() as every other property, a list is no name at all, so the
	 * file is refused by the nameless-row sentence below it.
	 */
	public function testANameThatIsAListIsRefusedRatherThanImportedAsArray(): void {
		$this->expectException(InvalidImportException::class);
		$this->expectExceptionMessage('El feature 1 no tiene nombre.');

		$this->read($this->withoutCoordinates(['name' => ['x']]));
	}

	/**
	 * #241: a `source` that is a list derived ids from the text "Array".
	 * Through text() it is simply not given, so the Dataset's own name says
	 * where the rows came from — the same fallback an absent source gets.
	 */
	public function testASourceThatIsAListFallsBackToTheDatasetNotArray(): void {
		$rows = $this->read($this->withoutCoordinates(['name' => 'Sede', 'source' => ['x']]));

		self::assertStringStartsWith('catastro:', $rows[0]['externalId']);
	}

	/**
	 * #241: a classification cell that is a list imported as the label "Array"
	 * for classify() to fail on. Through text() it is absent, which is what a
	 * row without that cell already means: nothing to classify on, and no
	 * Category named "Array" invented by the reader.
	 */
	public function testAClassificationCellThatIsAListIsAbsentNotArray(): void {
		$rows = $this->read($this->withoutCoordinates(
			['name' => 'Sede', 'category' => ['x'], 'subcategory' => ['y']],
		));

		self::assertNull($rows[0]['category']);
		self::assertNull($rows[0]['subcategory']);
	}

	public function testAnEmptyCollectionIsValidAndImportsNothing(): void {
		self::assertSame([], $this->read('{"type":"FeatureCollection","features":[]}'));
	}
}
