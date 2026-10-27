<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use BadFunctionCallException;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Domain\Kind;
use OCA\Territorio\Domain\LocationQuality;
use OCP\Server;

/**
 * A Feature written to the real database and read back.
 *
 * The unit suite proves what the service decides; this proves the decision
 * survives the round trip — that camelCase Entity properties reach the
 * snake_case columns the migration made, that the bounding-box floats come back
 * as floats and not strings, and that a null geometry stays null rather than
 * becoming an empty string. None of that is visible to a mocked mapper.
 */
final class FeatureRoundTripTest extends IntegrationTestCase {
	private FeatureService $service;
	private FeatureMapper $features;

	protected function setUp(): void {
		$this->features = Server::get(FeatureMapper::class);
		$this->service = Server::get(FeatureService::class);
	}

	public function testTheMapperRefusesToHardDeleteAFeature(): void {
		$created = $this->service->create([
			'name' => 'No se borra',
			'subcategoryId' => $this->anySubcategoryId(),
			'geometry' => null,
		]);
		$this->created[] = (int)$created->getId();

		$this->expectException(BadFunctionCallException::class);
		$this->features->delete($created);
	}

	public function testAPlaceSurvivesTheRoundTripWithItsBoundingBox(): void {
		$created = $this->service->create([
			'name' => 'CESFAM de Prueba',
			'subcategoryId' => $this->anySubcategoryId(),
			'address' => 'Calle de Prueba 123',
			'phone' => '900000000',
			'geometry' => ['type' => 'Point', 'coordinates' => [-70.5553836, -33.5187671]],
		]);
		$this->created[] = (int)$created->getId();

		$read = $this->features->find((int)$created->getId());

		self::assertSame('CESFAM de Prueba', $read->getName());
		self::assertSame('Calle de Prueba 123', $read->getAddress());
		self::assertSame(Kind::Place->value, $read->getKind());
		self::assertSame(LocationQuality::Exact->value, $read->getLocationQuality());

		// Floats, not numeric strings: a string here would sort and compare wrong
		// in every viewport query built on these columns.
		self::assertIsFloat($read->getMinLat());
		self::assertIsFloat($read->getMaxLon());
		self::assertEqualsWithDelta(-33.5187671, $read->getMinLat(), 1e-7);
		self::assertEqualsWithDelta(-70.5553836, $read->getMaxLon(), 1e-7);

		// The geometry column round-trips as GeoJSON the client can render.
		self::assertSame(
			['type' => 'Point', 'coordinates' => [-70.5553836, -33.5187671]],
			json_decode((string)$read->getGeometry(), true),
		);
	}

	public function testARecordWithNoCoordinateStoresNullsNotEmptyStrings(): void {
		$created = $this->service->create([
			'name' => 'Coordinadora de Prueba',
			'subcategoryId' => $this->anySubcategoryId(),
			'address' => 'Dependencias de La Municipalidad',
			'geometry' => null,
		]);
		$this->created[] = (int)$created->getId();

		$read = $this->features->find((int)$created->getId());

		self::assertNull($read->getGeometry());
		self::assertNull($read->getMinLat());
		self::assertNull($read->getMaxLon());
		self::assertNull($read->getPhone());
		self::assertSame(LocationQuality::Absent->value, $read->getLocationQuality());
	}

	public function testAnUpdatePersistsAndClearsTheBoundingBox(): void {
		$created = $this->service->create([
			'name' => 'Antes',
			'subcategoryId' => $this->anySubcategoryId(),
			'geometry' => ['type' => 'Point', 'coordinates' => [-70.60, -33.55]],
		]);
		$id = (int)$created->getId();
		$this->created[] = $id;

		$this->service->update($id, [
			'name' => 'Después',
			'subcategoryId' => $this->anySubcategoryId(),
			'geometry' => null,
		]);

		$read = $this->features->find($id);
		self::assertSame('Después', $read->getName());
		self::assertNull($read->getGeometry());
		self::assertNull($read->getMinLat());
	}

	/**
	 * The seeding the report asks for (issue #83): a stored record, an update
	 * that names only the quality, and the new value read back out of the
	 * database rather than off the object the service returned. A mocked mapper
	 * cannot tell an applied quality from one that was set and never written.
	 */
	public function testAQualityCorrectedWithoutResendingTheGeometryReachesTheDatabase(): void {
		$created = $this->service->create([
			'name' => 'Sede de Prueba',
			'subcategoryId' => $this->anySubcategoryId(),
			'geometry' => ['type' => 'Point', 'coordinates' => [-70.60, -33.55]],
		]);
		$id = (int)$created->getId();
		$this->created[] = $id;
		self::assertSame(LocationQuality::Exact->value, $created->getLocationQuality());

		$this->service->update($id, [
			'name' => 'Sede de Prueba',
			'subcategoryId' => $this->anySubcategoryId(),
			'locationQuality' => LocationQuality::Approximate->value,
		]);

		$read = $this->features->find($id);
		self::assertSame(LocationQuality::Approximate->value, $read->getLocationQuality());
		// The shape it describes is untouched: the point of the fix is that
		// correcting the judgement does not mean re-sending the geometry.
		self::assertSame(
			['type' => 'Point', 'coordinates' => [-70.60, -33.55]],
			json_decode((string)$read->getGeometry(), true),
		);
	}

	public function testFindAllReturnsWhatWasStored(): void {
		$created = $this->service->create([
			'name' => 'Plaza de prueba',
			'subcategoryId' => $this->anySubcategoryId(),
			'geometry' => ['type' => 'Point', 'coordinates' => [-70.58, -33.52]],
		]);
		$this->created[] = (int)$created->getId();

		$names = array_map(
			static fn ($feature): string => $feature->getName(),
			$this->service->listAll(),
		);

		self::assertContains('Plaza de prueba', $names);
	}

	/**
	 * #207: `notes[]=x` was a 200 that stored the text "Array". It is refused
	 * before the write, so the stored notes and the history stay as they were.
	 */
	public function testAListInNotesIsRefusedAndTheStoredNotesAndHistoryAreUntouched(): void {
		$id = $this->place('Plaza con notas', ['notes' => 'Abre a las 9']);
		$entries = count($this->service->history($id));

		try {
			$this->service->update($id, [
				'name' => 'Plaza con notas',
				'subcategoryId' => $this->anySubcategoryId(),
				'notes' => ['x'],
			]);
			self::fail('a list in notes was accepted');
		} catch (InvalidFeatureException $e) {
			self::assertSame('Un campo de texto recibió algo que no es texto.', $e->getMessage());
		}

		self::assertSame('Abre a las 9', $this->features->find($id)->getNotes());
		self::assertCount($entries, $this->service->history($id), 'the refused update added a history entry');
	}
}
