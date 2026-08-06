<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use BadFunctionCallException;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\Kind;
use OCA\Territorio\Service\LocationQuality;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A Feature written to the real database and read back.
 *
 * The unit suite proves what the service decides; this proves the decision
 * survives the round trip — that camelCase Entity properties reach the
 * snake_case columns the migration made, that the bounding-box floats come back
 * as floats and not strings, and that a null geometry stays null rather than
 * becoming an empty string. None of that is visible to a mocked mapper.
 */
final class FeatureRoundTripTest extends TestCase {
	private FeatureService $service;
	private FeatureMapper $features;
	/** @var int[] */
	private array $created = [];

	protected function setUp(): void {
		$this->features = Server::get(FeatureMapper::class);
		$this->service = Server::get(FeatureService::class);
	}

	/**
	 * Rows this test made, removed with raw SQL.
	 *
	 * Not `FeatureMapper::delete()`: that now refuses outright, because ADR-0005
	 * forbids hard-deleting a Feature anywhere in the app and the mapper is where
	 * that has to be enforced. Cleaning up after a test is the one job that
	 * legitimately sits outside the app, so it goes around the mapper rather than
	 * weakening it.
	 */
	protected function tearDown(): void {
		if ($this->created === []) {
			return;
		}

		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete('territorio_feature')
			->where($qb->expr()->in('id', $qb->createNamedParameter(
				$this->created, IQueryBuilder::PARAM_INT_ARRAY,
			)))
			->executeStatement();

		$this->created = [];
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

	private function anySubcategoryId(): int {
		$subcategories = Server::get(SubcategoryMapper::class)->findAll();
		self::assertNotEmpty($subcategories, 'the migration should have seeded the taxonomy');

		return (int)$subcategories[0]->getId();
	}

	public function testAPlaceSurvivesTheRoundTripWithItsBoundingBox(): void {
		$created = $this->service->create([
			'name' => 'CESFAM Los Castaños',
			'subcategoryId' => $this->anySubcategoryId(),
			'address' => 'Los Bambúes 2001',
			'phone' => '961111133',
			'geometry' => ['type' => 'Point', 'coordinates' => [-70.5553836, -33.5187671]],
		]);
		$this->created[] = (int)$created->getId();

		$read = $this->features->find((int)$created->getId());

		self::assertSame('CESFAM Los Castaños', $read->getName());
		self::assertSame('Los Bambúes 2001', $read->getAddress());
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
			'name' => 'Coordinadora Territorial',
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
}
