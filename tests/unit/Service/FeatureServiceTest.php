<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\DuplicateMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Db\Subcategory;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Exception\InvalidGeometryException;
use OCA\Territorio\Service\AssignmentService;
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
	private RevisionLog&MockObject $revisions;
	private FeatureService $service;

	protected function setUp(): void {
		$this->features = $this->createMock(FeatureMapper::class);
		$this->subcategories = $this->createMock(SubcategoryMapper::class);
		$this->revisions = $this->createMock(RevisionLog::class);

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

		// A real one would need the whole Registry to resolve against; what this
		// suite is about is what the service builds from what a person typed.
		$this->service = new FeatureService(
			$this->features,
			$this->subcategories,
			$this->revisions,
			$this->createMock(AssignmentService::class),
			$this->createMock(DuplicateMapper::class),
		);
	}

	public function testNothingChangedCostsOneQueryAndReturnsNoFeatures(): void {
		$this->revisions->method('current')->willReturn(12);
		// The Feature table must not be touched at all on the common poll — this
		// is what makes a five-second interval from every open map affordable.
		$this->features->expects(self::never())->method('findChangedSince');

		self::assertSame(
			['revision' => 12, 'features' => []],
			$this->service->changesSince(12),
		);
	}

	public function testAClientBehindTheCursorIsGivenWhatItMissed(): void {
		$changed = new Feature();
		$changed->setId(5);
		$changed->setName('Plaza nueva');
		$changed->setRevision(13);

		$this->revisions->method('current')->willReturn(13);
		$this->features->expects(self::once())
			->method('findChangedSince')
			->with(12)
			->willReturn([$changed]);

		$result = $this->service->changesSince(12);

		self::assertSame(13, $result['revision']);
		self::assertCount(1, $result['features']);
		self::assertSame('Plaza nueva', $result['features'][0]['name']);
	}

	/**
	 * A cursor ahead of the log means the client knows something the server does
	 * not — a restored database, say. Answering with the real current Revision
	 * lets it fall back into step instead of never polling successfully again.
	 */
	public function testACursorAheadOfTheLogIsBroughtBackIntoStep(): void {
		$this->revisions->method('current')->willReturn(3);

		self::assertSame(['revision' => 3, 'features' => []], $this->service->changesSince(99));
	}

	private function valid(array $overrides = []): array {
		return array_merge([
			'name' => 'Junta de Vecinos de Prueba',
			'subcategoryId' => 7,
			'geometry' => json_decode(self::PLAZA, true),
		], $overrides);
	}

	/** A closed ring, as geoman hands one over. */
	private static function polygon(): array {
		return [
			'type' => 'Polygon',
			'coordinates' => [[[-70.6, -33.5], [-70.5, -33.5], [-70.5, -33.4], [-70.6, -33.5]]],
		];
	}

	public function testItCreatesAPlaceFromAPoint(): void {
		$feature = $this->service->create($this->valid());

		self::assertSame('Junta de Vecinos de Prueba', $feature->getName());
		self::assertSame(7, $feature->getSubcategoryId());
		self::assertSame(Kind::Place->value, $feature->getKind());
		self::assertEqualsWithDelta(-33.5187671, $feature->getMinLat(), 1e-9);
		self::assertEqualsWithDelta(-70.5553836, $feature->getMaxLon(), 1e-9);
	}

	public function testKindIsDerivedFromTheGeometryNotAcceptedFromTheClient(): void {
		$feature = $this->service->create($this->valid(['kind' => 'route']));

		self::assertSame(Kind::Place->value, $feature->getKind());
	}

	/** A drawn Sector: a Polygon, measured across its ring like any other Zone. */
	public function testAPolygonIsStoredAsAZoneAndMeasured(): void {
		$feature = $this->service->create($this->valid(['geometry' => self::polygon()]));

		self::assertSame(Kind::Zone->value, $feature->getKind());
		self::assertEqualsWithDelta(-70.6, $feature->getMinLon(), 1e-9);
		self::assertEqualsWithDelta(-33.4, $feature->getMaxLat(), 1e-9);
	}

	public function testALineStringIsStoredAsARoute(): void {
		$feature = $this->service->create($this->valid([
			'geometry' => ['type' => 'LineString', 'coordinates' => [[-70.6, -33.5], [-70.5, -33.4]]],
		]));

		self::assertSame(Kind::Route->value, $feature->getKind());
	}

	/**
	 * A Sector's colour belongs to the Sector, not to its Subcategory: a clinic
	 * gives each one its own so teams can tell them apart at a glance.
	 */
	public function testAColourIsKeptOnTheRecord(): void {
		$feature = $this->service->create($this->valid([
			'geometry' => self::polygon(),
			'colour' => '#A1B2C3',
		]));

		self::assertSame('#a1b2c3', $feature->getColour());
	}

	public function testAColourIsOptionalAndSendingItEmptyClearsIt(): void {
		self::assertNull($this->service->create($this->valid())->getColour());

		$feature = new Feature();
		$feature->setId(4);
		$feature->setColour('#a1b2c3');
		$this->features->method('find')->willReturn($feature);

		self::assertNull($this->service->update(4, $this->valid(['colour' => '  ']))->getColour());
	}

	/**
	 * The colour reaches the browser inside a style attribute. Anything that is
	 * not a hex colour is refused here rather than escaped downstream, so there is
	 * one place to be right about it instead of one per template.
	 */
	public function testAColourThatIsNotAHexColourIsRefused(): void {
		$this->expectException(InvalidFeatureException::class);
		$this->service->create($this->valid(['colour' => 'red; background: url(x)']));
	}

	/**
	 * Naming is clinic-specific and stays unconstrained (issue #10): a CESFAM may
	 * call its sectores "3", "Rojo" or "Sector Sur", and Territorio does not know
	 * which — so nothing here may reserve, reformat or reject any of them.
	 */
	public function testASectorNameCanBeABareNumberOrAColourWord(): void {
		foreach (['3', 'Rojo', 'Sector Sur', '2 Norte'] as $name) {
			$feature = $this->service->create($this->valid([
				'name' => $name,
				'geometry' => self::polygon(),
			]));

			self::assertSame($name, $feature->getName());
		}
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
			'name' => "  Escuela Peñaflor de Prueba \n",
			'address' => '  Calle de Prueba 123  ',
			'phone' => '   ',
			'email' => '',
		]));

		self::assertSame('Escuela Peñaflor de Prueba', $feature->getName());
		self::assertSame('Calle de Prueba 123', $feature->getAddress());
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

	/**
	 * A request that does not mention a field must leave it alone. History is
	 * what would make such a loss recoverable, and it does not exist until issue
	 * #4 — so until then an omission silently blanking an address would be
	 * unrecoverable, on a route every user can reach.
	 */
	public function testAnUpdateThatOmitsAFieldLeavesItAlone(): void {
		$existing = new Feature();
		$existing->setId(3);
		$existing->setSubcategoryId(7);
		$existing->setAddress('Calle de Prueba 123');
		$existing->setPhone('900000000');
		$this->features->method('find')->willReturn($existing);

		$updated = $this->service->update(3, [
			'name' => 'Solo cambio el nombre',
			'subcategoryId' => 7,
			'geometry' => null,
		]);

		self::assertSame('Calle de Prueba 123', $updated->getAddress());
		self::assertSame('900000000', $updated->getPhone());
	}

	public function testSendingAFieldEmptyStillClearsIt(): void {
		$existing = new Feature();
		$existing->setId(3);
		$existing->setSubcategoryId(7);
		$existing->setAddress('Calle de Prueba 123');
		$this->features->method('find')->willReturn($existing);

		$updated = $this->service->update(3, [
			'name' => 'X', 'subcategoryId' => 7, 'geometry' => null, 'address' => '',
		]);

		self::assertNull($updated->getAddress());
	}

	public function testUpdatingSomethingThatDoesNotExistIsNotSwallowed(): void {
		$this->features->method('find')->willThrowException(new DoesNotExistException('gone'));

		$this->expectException(DoesNotExistException::class);
		$this->service->update(404, $this->valid());
	}
}
