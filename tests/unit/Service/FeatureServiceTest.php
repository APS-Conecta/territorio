<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\Subcategory;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Exception\InvalidGeometryException;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\Kind;
use OCA\Territorio\Service\LocationQuality;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What the service is for: turning what a person typed into a Feature that is
 * safe to store. Kind and the bounding box are derived here and never accepted
 * from the client, and neither is Sector or Unidad Vecinal (ADR-0008).
 */
final class FeatureServiceTest extends TestCase {
	private const PLAZA = '{"type":"Point","coordinates":[-70.5553836,-33.5187671]}';

	private FeatureMapper&MockObject $features;
	private SubcategoryMapper&MockObject $subcategories;
	private FeatureService $service;

	protected function setUp(): void {
		$this->features = $this->createMock(FeatureMapper::class);
		$this->subcategories = $this->createMock(SubcategoryMapper::class);

		$subcategory = new Subcategory();
		$subcategory->setId(7);
		$this->subcategories->method('find')->willReturnCallback(
			static function (int $id) use ($subcategory): Subcategory {
				if ($id !== 7) {
					throw new DoesNotExistException('no such subcategory');
				}
				return $subcategory;
			},
		);

		// insert()/update() hand back whatever they were given, so assertions can
		// read the entity the service built.
		$this->features->method('insert')->willReturnArgument(0);
		$this->features->method('update')->willReturnArgument(0);

		$this->service = new FeatureService($this->features, $this->subcategories);
	}

	private function valid(array $overrides = []): array {
		return array_merge([
			'name' => 'Junta de Vecinos 35',
			'subcategoryId' => 7,
			'geometry' => json_decode(self::PLAZA, true),
		], $overrides);
	}

	public function testItCreatesAPlaceFromAPoint(): void {
		$feature = $this->service->create($this->valid());

		self::assertSame('Junta de Vecinos 35', $feature->getName());
		self::assertSame(7, $feature->getSubcategoryId());
		self::assertSame(Kind::Place->value, $feature->getKind());
		self::assertEqualsWithDelta(-33.5187671, $feature->getMinLat(), 1e-9);
		self::assertEqualsWithDelta(-70.5553836, $feature->getMaxLon(), 1e-9);
	}

	public function testKindIsDerivedFromTheGeometryNotAcceptedFromTheClient(): void {
		$feature = $this->service->create($this->valid([
			'kind' => 'route',
			'geometry' => json_decode(
				'{"type":"Polygon","coordinates":[[[-70.6,-33.5],[-70.5,-33.5],[-70.5,-33.4],[-70.6,-33.5]]]}',
				true,
			),
		]));

		self::assertSame(Kind::Zone->value, $feature->getKind());
	}

	/**
	 * The catastro this app inherits holds real organisations whose address never
	 * resolved to a coordinate. They are valid records.
	 */
	public function testAPlaceWithNoCoordinateIsValidAndHasNoBoundingBox(): void {
		$feature = $this->service->create($this->valid(['geometry' => null]));

		self::assertNull($feature->getGeometry());
		self::assertNull($feature->getMinLat());
		self::assertNull($feature->getMaxLon());
		self::assertSame(Kind::Place->value, $feature->getKind());
		self::assertSame(LocationQuality::Absent->value, $feature->getLocationQuality());
	}

	public function testLocationQualityIsForcedToAbsentWithoutAGeometry(): void {
		$feature = $this->service->create($this->valid([
			'geometry' => null,
			'locationQuality' => 'exacta',
		]));

		self::assertSame(LocationQuality::Absent->value, $feature->getLocationQuality());
	}

	public function testLocationQualityIsTheUsersJudgementWhenThereIsAGeometry(): void {
		$feature = $this->service->create($this->valid(['locationQuality' => 'aproximada']));

		self::assertSame(LocationQuality::Approximate->value, $feature->getLocationQuality());
	}

	public function testLocationQualityDefaultsToExactWhenAGeometryIsGiven(): void {
		self::assertSame(
			LocationQuality::Exact->value,
			$this->service->create($this->valid())->getLocationQuality(),
		);
	}

	public function testAnUnknownLocationQualityIsRejected(): void {
		$this->expectException(InvalidFeatureException::class);
		$this->service->create($this->valid(['locationQuality' => 'más o menos']));
	}

	public function testANameIsRequired(): void {
		$this->expectException(InvalidFeatureException::class);
		$this->service->create($this->valid(['name' => '   ']));
	}

	public function testTheSubcategoryMustExist(): void {
		$this->expectException(InvalidFeatureException::class);
		$this->service->create($this->valid(['subcategoryId' => 999]));
	}

	public function testAnUnparseableGeometryIsRejected(): void {
		$this->expectException(InvalidGeometryException::class);
		$this->service->create($this->valid(['geometry' => ['type' => 'Point', 'coordinates' => 'nope']]));
	}

	public function testWhitespaceIsTrimmedAndBlankOptionalFieldsBecomeNull(): void {
		$feature = $this->service->create($this->valid([
			'name' => "  Escuela Los Castaños \n",
			'address' => '  Los Bambúes 2001  ',
			'phone' => '   ',
			'email' => '',
		]));

		self::assertSame('Escuela Los Castaños', $feature->getName());
		self::assertSame('Los Bambúes 2001', $feature->getAddress());
		self::assertNull($feature->getPhone());
		self::assertNull($feature->getEmail());
	}

	public function testUpdateChangesFieldsAndRederivesTheBoundingBox(): void {
		$existing = new Feature();
		$existing->setId(3);
		$existing->setName('Antiguo');
		$existing->setSubcategoryId(7);
		$existing->setKind(Kind::Place->value);
		$this->features->method('find')->willReturn($existing);

		$updated = $this->service->update(3, $this->valid([
			'name' => 'Nuevo nombre',
			'geometry' => json_decode('{"type":"Point","coordinates":[-70.60,-33.55]}', true),
		]));

		self::assertSame('Nuevo nombre', $updated->getName());
		self::assertEqualsWithDelta(-33.55, $updated->getMinLat(), 1e-9);
		self::assertEqualsWithDelta(-70.60, $updated->getMinLon(), 1e-9);
	}

	public function testClearingTheGeometryOnUpdateClearsTheBoundingBoxToo(): void {
		$existing = new Feature();
		$existing->setId(3);
		$existing->setSubcategoryId(7);
		$existing->setGeometry(self::PLAZA);
		$existing->setMinLat(-33.5187671);
		$existing->setMinLon(-70.5553836);
		$existing->setMaxLat(-33.5187671);
		$existing->setMaxLon(-70.5553836);
		$this->features->method('find')->willReturn($existing);

		$updated = $this->service->update(3, $this->valid(['geometry' => null]));

		self::assertNull($updated->getGeometry());
		self::assertNull($updated->getMinLat());
		self::assertNull($updated->getMinLon());
		self::assertNull($updated->getMaxLat());
		self::assertNull($updated->getMaxLon());
	}

	public function testUpdatingSomethingThatDoesNotExistIsNotSwallowed(): void {
		$this->features->method('find')->willThrowException(new DoesNotExistException('gone'));

		$this->expectException(DoesNotExistException::class);
		$this->service->update(404, $this->valid());
	}
}
