<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\DuplicateMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Db\Subcategory;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Db\ImportBatchMapper;
use OCP\IUserSession;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Exception\InvalidGeometryException;
use OCA\Territorio\Service\AssignmentService;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\TaxonomyService;
use OCA\Territorio\Domain\Geometry;
use OCA\Territorio\Domain\Kind;
use OCA\Territorio\Domain\LocationQuality;
use OCA\Territorio\Domain\Provenance;
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
	private AssignmentService&MockObject $assignments;
	private ImportBatchMapper&MockObject $batches;
	private FeatureService $service;

	/** The Subcategory ids the mapper stub resolves — every test uses 7, and
	 *  the #109 reclassify case adds a second so one save can carry a record
	 *  out of its division (the mapper must accept where the save is going,
	 *  not only where the record has been). */
	private array $subcategoryIds = [7];

	protected function setUp(): void {
		$this->features = $this->createMock(FeatureMapper::class);
		$this->subcategories = $this->createMock(SubcategoryMapper::class);
		$this->revisions = $this->createMock(RevisionLog::class);
		$this->assignments = $this->createMock(AssignmentService::class);

		$subcategory = new Subcategory();
		$subcategory->setId(7);
		$this->subcategories->method('find')->willReturnCallback(
			function (int $id) use ($subcategory): Subcategory {
				if (!in_array($id, $this->subcategoryIds, true)) {
					throw new DoesNotExistException('no such subcategory');
				}
				return $subcategory;
			},
		);

		// insert()/update() hand back whatever they were given, so assertions can
		// read the entity the service built.
		$this->features->method('insert')->willReturnArgument(0);
		$this->features->method('update')->willReturnArgument(0);

		$this->batches = $this->createMock(ImportBatchMapper::class);

		// A real one would need the whole Registry to resolve against; what this
		// suite is about is what the service builds from what a person typed.
		$this->service = $this->serviceWith($this->assignments);
	}

	private function serviceWith(AssignmentService $assignments): FeatureService {
		return new FeatureService(
			$this->features,
			$this->subcategories,
			$this->revisions,
			$assignments,
			$this->createMock(DuplicateMapper::class),
			$this->batches,
			$this->createStub(IUserSession::class),
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

	/** The stored form of the ring above — what applyGeometry normalizes any
	 *  submission of it to, so both sides of the #109 compare read the same
	 *  bytes and a re-sent unchanged polygon is "unchanged". */
	private static function storedPolygon(): string {
		return Geometry::fromArray(self::polygon())->toJson();
	}

	/** The ring with a corner dragged elsewhere — a redraw, in the shape
	 *  geoman hands one over after a drag. */
	private static function redrawnPolygon(): array {
		return [
			'type' => 'Polygon',
			'coordinates' => [[[-70.7, -33.6], [-70.5, -33.5], [-70.5, -33.4], [-70.7, -33.6]]],
		];
	}

	/** A division record as the registry hands it over: Subcategory 7 resolves
	 *  to $division, with the stub answering the way the real service does —
	 *  off the record's own Subcategory — so a save that reclassifies the
	 *  record sees the answer change, which is what the #109 bypass case
	 *  proves on. The polygon is stored in its normalized form. Single-use:
	 *  a refused save still mutates this record in memory (the guard throws
	 *  after apply()), so each test builds its own. */
	private function boundary(string $division): Feature {
		$existing = new Feature();
		$existing->setId(3);
		$existing->setName('UV 12');
		$existing->setSubcategoryId(7);
		$existing->setGeometry(self::storedPolygon());
		$existing->setKind(Kind::Zone->value);
		$existing->setLocationQuality(LocationQuality::Exact->value);
		$this->features->method('find')->willReturn($existing);
		$this->assignments->method('divisionOf')->willReturnCallback(
			static fn (Feature $feature): ?string => $feature->getSubcategoryId() === 7 ? $division : null,
		);

		return $existing;
	}

	/** A real AssignmentService, where Subcategory 7 is «Unidad vecinal» under
	 *  «Organización social», a Category a clinic added (#205). The stub above
	 *  answers divisionOf() by fiat, and that answer is what #205 is about. */
	private function assignmentsOverASameNamedSubcategory(): AssignmentService {
		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->method('subcategoryIndex')->willReturn([
			7 => ['category' => 'organizacion_social', 'categoryLabel' => 'Organización social', 'subcategory' => 'unidad_vecinal', 'subcategoryLabel' => 'Unidad vecinal'],
		]);

		return new AssignmentService($this->features, $taxonomy);
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

	/** #203: the name column is VARCHAR 255, so a longer name was the
	 *  database's error through a 500; it is the app's sentence, before any
	 *  write. */
	public function testAnOverLongNameIsRefused(): void {
		$this->features->expects(self::never())->method('insert');

		$this->expectException(InvalidFeatureException::class);
		$this->expectExceptionMessage('El nombre no puede pasar de 255 caracteres.');

		$this->service->create($this->valid(['name' => str_repeat('a', 300)]));
	}

	public function testAnOverLongNameIsRefusedOnUpdate(): void {
		$existing = new Feature();
		$existing->setId(3);
		$existing->setName('Antiguo');
		$existing->setSubcategoryId(7);
		$this->features->method('find')->willReturn($existing);
		$this->features->expects(self::never())->method('update');

		$this->expectException(InvalidFeatureException::class);
		$this->expectExceptionMessage('El nombre no puede pasar de 255 caracteres.');

		$this->service->update(3, $this->valid(['name' => str_repeat('a', 300)]));
	}

	/** #207: `(string)` on an array gives the literal "Array" plus an
	 *  E_WARNING Nextcloud logs and then continues past, so a hand-crafted
	 *  `name[]=x` request used to store a Feature named "Array" through a
	 *  200. Refused here, at the same door as every other name problem,
	 *  before any write. */
	public function testANameThatArrivesAsAListIsRefused(): void {
		$this->features->expects(self::never())->method('insert');

		$this->expectException(InvalidFeatureException::class);
		$this->expectExceptionMessage('Eso es una lista, no un texto.');

		$this->service->create($this->valid(['name' => ['Plaza']]));
	}

	/** #207, the optional fields: an array `notes` used to be stored as the
	 *  text "Array" through an ordinary 200 edit, with a history entry
	 *  recording it as one. The name here is valid, so this is the text
	 *  policy refusing on its own — nothing is written, nothing logged, the
	 *  stored notes are left exactly as they were. */
	public function testAnOptionalFieldThatArrivesAsAListIsRefusedAndWritesNothing(): void {
		$existing = new Feature();
		$existing->setId(3);
		$existing->setName('Antiguo');
		$existing->setSubcategoryId(7);
		$existing->setNotes('Nota guardada');
		$this->features->method('find')->willReturn($existing);
		$this->features->expects(self::never())->method('update');
		$this->revisions->expects(self::never())->method(self::anything());

		$this->expectException(InvalidFeatureException::class);
		$this->expectExceptionMessage('Eso es una lista, no un texto.');

		$this->service->update(3, $this->valid(['notes' => ['x']]));
	}

	/** Characters, not bytes, and the cap itself is allowed: 255 two-byte
	 *  letters fill the column exactly. */
	public function testANameAtItsCapIsKept(): void {
		$name = str_repeat('á', 255);

		self::assertSame($name, $this->service->create($this->valid(['name' => $name]))->getName());
	}

	/** The import's pre-check (#203) hears the write door's own sentences, and
	 *  asking writes, reads and logs nothing. */
	public function testCheckNameAndTextRefusesWhatTheWriteRefusesAndWritesNothing(): void {
		foreach ([$this->features, $this->subcategories, $this->revisions, $this->assignments] as $collaborator) {
			$collaborator->expects(self::never())->method(self::anything());
		}

		self::assertSame(
			'El teléfono no puede pasar de 64 caracteres.',
			$this->refusalOf(['name' => 'Sede', 'phone' => str_repeat('9', 65)]),
		);
		self::assertSame(
			'El registro necesita un nombre.',
			$this->refusalOf(['name' => '  ', 'phone' => '961111111']),
		);
		// #207: a file row whose name arrives as a list hears the same sentence
		// a save would, before any of its rows is written.
		self::assertSame(
			'Eso es una lista, no un texto.',
			$this->refusalOf(['name' => 'Sede', 'notes' => ['x']]),
		);
		$this->service->checkNameAndText($this->valid(['phone' => str_repeat('9', 64)]));
	}

	private function refusalOf(array $input): string {
		try {
			$this->service->checkNameAndText($input);
		} catch (InvalidFeatureException $e) {
			return $e->getMessage();
		}
		self::fail('checkNameAndText() accepted ' . json_encode($input));
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
	 * The case from the report, in the words it was reported in, and the rule the
	 * service states for every optional field applied to the one that measures
	 * four columns at once. `applyGeometry` read the key with `?? null`, so an
	 * ABSENT geometry and an explicitly empty one were the same thing, and both
	 * wiped the shape, the bounding box, the Kind and the Location quality.
	 *
	 * A Unidad Vecinal's boundary comes from a Cadastral Dataset — the clinic
	 * reads it and never draws it — so a lost polygon cannot be redrawn by hand:
	 * the only way back is a re-import or the revision history. Found on
	 * 2026-08-12 by updating one with only a name; the save reported success.
	 *
	 * All four bounding-box columns are asserted because they move together or
	 * not at all, and for a Zone the box is what the viewport query reads.
	 */
	public function testAUnidadVecinalUpdatedWithOnlyANameKeepsItsPolygon(): void {
		$zone = json_encode(self::polygon());
		$existing = new Feature();
		$existing->setId(3);
		$existing->setName('UV 12');
		$existing->setSubcategoryId(7);
		$existing->setGeometry($zone);
		$existing->setMinLat(-33.5);
		$existing->setMinLon(-70.6);
		$existing->setMaxLat(-33.4);
		$existing->setMaxLon(-70.5);
		$existing->setKind(Kind::Zone->value);
		$existing->setLocationQuality(LocationQuality::Exact->value);
		$this->features->method('find')->willReturn($existing);

		$updated = $this->service->update(3, ['name' => 'UV 12 Norte', 'subcategoryId' => 7]);

		self::assertSame('UV 12 Norte', $updated->getName());
		self::assertSame($zone, $updated->getGeometry());
		self::assertEqualsWithDelta(-33.5, $updated->getMinLat(), 1e-9);
		self::assertEqualsWithDelta(-70.6, $updated->getMinLon(), 1e-9);
		self::assertEqualsWithDelta(-33.4, $updated->getMaxLat(), 1e-9);
		self::assertEqualsWithDelta(-70.5, $updated->getMaxLon(), 1e-9);
		self::assertSame(Kind::Zone->value, $updated->getKind());
		self::assertSame(LocationQuality::Exact->value, $updated->getLocationQuality());
	}

	/**
	 * #109: a Unidad Vecinal's shape comes from a cadastral Dataset and the
	 * clinic never draws it, so a person's save cannot redraw it either. The
	 * refusal is the whole transaction — no row, no Revision, no ADR-0008
	 * reassignment sweep — because the guard throws after apply() has built
	 * the record but before anything persists it: the in-memory entity
	 * apply() already touched is discarded with the request, and must stay
	 * discarded (reordering it "clean" would move the throw onto a path that
	 * has already written).
	 */
	public function testARedrawnUnidadVecinalIsRefusedRatherThanSaved(): void {
		$this->boundary('unidad_vecinal');
		$this->features->expects(self::never())->method('update');

		$this->expectException(InvalidFeatureException::class);
		$this->expectExceptionMessageMatches('/Unidad Vecinal/');

		$this->service->update(3, $this->valid([
			'name' => 'UV 12 Norte',
			'geometry' => self::redrawnPolygon(),
		]));
	}

	/**
	 * Clearing IS redrawing, to nothing — and it arrives in the two shapes a
	 * client sends for "no shape": `null` from the JSON door, `[]` from the
	 * form-encoded one. Either branch that clears the four geometry columns
	 * (#63's map) refuses; one test each, because a refused save still mutates
	 * the in-memory record it read, and a second update() through the same
	 * stub would see a record whose polygon is already gone.
	 */
	public function testClearingAUnidadVecinalWithANullGeometryIsRefused(): void {
		$this->boundary('unidad_vecinal');
		$this->features->expects(self::never())->method('update');

		$this->expectException(InvalidFeatureException::class);
		$this->expectExceptionMessageMatches('/Unidad Vecinal/');

		$this->service->update(3, $this->valid(['geometry' => null]));
	}

	public function testClearingAUnidadVecinalWithAnEmptyGeometryArrayIsRefused(): void {
		$this->boundary('unidad_vecinal');
		$this->features->expects(self::never())->method('update');

		$this->expectException(InvalidFeatureException::class);
		$this->expectExceptionMessageMatches('/Unidad Vecinal/');

		$this->service->update(3, $this->valid(['geometry' => []]));
	}

	/**
	 * The save that must keep flowing: payload() always sends the geometry key
	 * (src/draft.js), so every UV rename arrives carrying the polygon it was
	 * opened with. It re-normalizes to the identical stored bytes, the
	 * stored-form compare reads "unchanged", and the save lands — key-presence
	 * would have refused every one of them, which is #63's bug by another name.
	 */
	public function testAUnidadVecinalRenameCarryingThePolygonUnchangedFlows(): void {
		$this->boundary('unidad_vecinal');

		$updated = $this->service->update(3, $this->valid([
			'name' => 'UV 12 Norte',
			'geometry' => self::polygon(),
		]));

		self::assertSame('UV 12 Norte', $updated->getName());
		self::assertSame(self::storedPolygon(), $updated->getGeometry());
	}

	/** The other half of the same guarantee: a save that never mentions the
	 *  geometry at all — applyGeometry's absent branch — leaves it alone, so
	 *  the compare cannot see a change. */
	public function testAUnidadVecinalRenameThatOmitsTheGeometryFlows(): void {
		$this->boundary('unidad_vecinal');

		$updated = $this->service->update(3, ['name' => 'UV 12 Norte', 'subcategoryId' => 7]);

		self::assertSame('UV 12 Norte', $updated->getName());
		self::assertSame(self::storedPolygon(), $updated->getGeometry());
	}

	/**
	 * A revert is the undoing of a batch, not a person redrawing the line —
	 * it is the door the catastro's own earlier geometry comes back through,
	 * so it carries the old shape without ceremony.
	 */
	public function testARevertPutsAUnidadVecinalsShapeBackUnchallenged(): void {
		$this->boundary('unidad_vecinal');

		$reverted = $this->service->revertTo(3, $this->valid([
			'name' => 'UV 12 (catastro)',
			'geometry' => self::redrawnPolygon(),
		]));

		self::assertSame(
			Geometry::fromArray(self::redrawnPolygon())->toJson(),
			$reverted->getGeometry(),
		);
	}

	/**
	 * An import is the authority on its own shape: update() carrying a
	 * Provenance is the import door (markOverride stays false), so it may
	 * reshape a UV — and the write it performs is a provenance write, not the
	 * local override a person's edit would mark (ADR-0003).
	 */
	public function testAnImportReshapesAUnidadVecinalAndMarksNoOverride(): void {
		$this->boundary('unidad_vecinal');

		$imported = $this->service->update(
			3,
			$this->valid(['name' => 'UV 12 (catastro)', 'geometry' => self::redrawnPolygon()]),
			new Provenance(5, 'mch-uv-12', ['name' => 'UV 12']),
		);

		self::assertSame(
			Geometry::fromArray(self::redrawnPolygon())->toJson(),
			$imported->getGeometry(),
		);
		self::assertNotTrue($imported->getOverridden());
	}

	/**
	 * A Sector's shape is the clinic's own to draw (ADR-0007's whole
	 * subject); only the Unidad Vecinal comes from the catastro. The same
	 * redraw through the other division flows.
	 */
	public function testASectorRedrawnByHandFlows(): void {
		$this->boundary('sector');

		$updated = $this->service->update(3, $this->valid([
			'name' => 'Sector Norte',
			'geometry' => self::redrawnPolygon(),
		]));

		self::assertSame(
			Geometry::fromArray(self::redrawnPolygon())->toJson(),
			$updated->getGeometry(),
		);
	}

	/**
	 * The door's own bypass attempt: reclassify the record out of the UV
	 * Subcategory and redraw it in the same save. The guard reads the division
	 * the record WAS — captured before apply() reclassifies it — so this save
	 * is refused too; reading it after would let every reshape through by
	 * attaching a reclassify to it.
	 */
	public function testReclassifyingAUnidadVecinalAndReshapingInOneSaveIsRefused(): void {
		$this->boundary('unidad_vecinal');
		$this->subcategoryIds[] = 8;
		$this->features->expects(self::never())->method('update');

		$this->expectException(InvalidFeatureException::class);

		$this->service->update(3, $this->valid([
			'subcategoryId' => 8,
			'geometry' => self::redrawnPolygon(),
		]));
	}

	/**
	 * #205: the #109 refusal is for the cadastre's Unidades Vecinales. A pin
	 * filed under a same-named Subcategory elsewhere moves like any other.
	 */
	public function testAPointUnderASameNamedSubcategoryCanBeMoved(): void {
		$service = $this->serviceWith($this->assignmentsOverASameNamedSubcategory());
		$existing = new Feature();
		$existing->setId(3);
		$existing->setName('Junta de Vecinos de Prueba');
		$existing->setSubcategoryId(7);
		$existing->setGeometry(self::PLAZA);
		$existing->setBoundingBox(Geometry::fromJson(self::PLAZA)->boundingBox());
		$existing->setKind(Kind::Place->value);
		$existing->setLocationQuality(LocationQuality::Exact->value);
		$this->features->method('find')->willReturn($existing);

		$moved = $service->update(3, $this->valid([
			'geometry' => ['type' => 'Point', 'coordinates' => [-70.52, -33.48]],
		]));

		self::assertEqualsWithDelta(-70.52, $moved->getMinLon(), 1e-9);
		self::assertEqualsWithDelta(-33.48, $moved->getMinLat(), 1e-9);
	}

	/**
	 * #205, the batch half: a polygon filed there is no boundary, so saving it
	 * reassigns no Place and records no reassignment batch. Through create(),
	 * which sweeps on every new boundary; findAll() is the Registry right after
	 * the insert — the new Zone and a Place inside its ring.
	 */
	public function testAPolygonUnderASameNamedSubcategorySweepsNothing(): void {
		$service = $this->serviceWith($this->assignmentsOverASameNamedSubcategory());
		$zone = new Feature();
		$zone->setId(3);
		$zone->setSubcategoryId(7);
		$zone->setGeometry(self::storedPolygon());
		$zone->setBoundingBox(Geometry::fromJson(self::storedPolygon())->boundingBox());
		$zone->setKind(Kind::Zone->value);
		$place = new Feature();
		$place->setId(2);
		$place->setSubcategoryId(7);
		$place->setGeometry('{"type":"Point","coordinates":[-70.52,-33.48]}');
		$place->setBoundingBox(Geometry::fromJson($place->getGeometry())->boundingBox());
		$place->setKind(Kind::Place->value);
		$this->features->method('findAll')->willReturn([$zone, $place]);
		$this->batches->expects(self::never())->method('insert');

		$service->create($this->valid(['geometry' => self::polygon()]));

		self::assertNull($place->getUnidadVecinalId());
	}

	/**
	 * The other half of the same rule, and the branch that has no caller to
	 * remind it. A create states the WHOLE record, so an omitted geometry means
	 * the record has none — and a new Feature carries `kind = ''`, which is not a
	 * Kind. Without the distinction reaching `applyGeometry`, this record would
	 * be inserted with no Kind and no Location quality, and the map, the Capas
	 * filter and `terr_feat_kind_idx` all read that column.
	 */
	public function testCreatingWithNoGeometryKeyAtAllStillGivesThePlaceItsKind(): void {
		$feature = $this->service->create(['name' => 'Sede sin coordenada', 'subcategoryId' => 7]);

		self::assertNull($feature->getGeometry());
		self::assertNull($feature->getMinLat());
		self::assertSame(Kind::Place->value, $feature->getKind());
		self::assertSame(LocationQuality::Absent->value, $feature->getLocationQuality());
	}

	/**
	 * Clearing stays possible, it just has to be asked for — and asked for in
	 * either of the two shapes a client sends. `null` is one; an empty array is
	 * what a form-encoded or JSON client sends for an empty shape, and it takes a
	 * different branch (`$raw === []`, not `!is_array($raw)`).
	 */
	public function testSendingAnEmptyGeometryArrayClearsAllFourColumns(): void {
		$existing = new Feature();
		$existing->setId(3);
		$existing->setSubcategoryId(7);
		$existing->setGeometry(self::PLAZA);
		$existing->setMinLat(-33.5187671);
		$existing->setMinLon(-70.5553836);
		$existing->setMaxLat(-33.5187671);
		$existing->setMaxLon(-70.5553836);
		$existing->setKind(Kind::Place->value);
		$existing->setLocationQuality(LocationQuality::Exact->value);
		$this->features->method('find')->willReturn($existing);

		$updated = $this->service->update(3, $this->valid(['geometry' => []]));

		self::assertNull($updated->getGeometry());
		self::assertNull($updated->getMinLat());
		self::assertNull($updated->getMinLon());
		self::assertNull($updated->getMaxLat());
		self::assertNull($updated->getMaxLon());
		self::assertSame(Kind::Place->value, $updated->getKind());
		self::assertSame(LocationQuality::Absent->value, $updated->getLocationQuality());
	}

	/**
	 * Location quality had no door of its own: it was read only inside
	 * `applyGeometry`'s with-geometry branch, so correcting a judgement about a
	 * shape that is already stored meant re-sending that shape byte-for-byte
	 * (issue #83). It is a judgement about the coordinate, not part of it.
	 */
	public function testAQualitySentWithoutAGeometryIsAppliedToTheStoredShape(): void {
		$existing = new Feature();
		$existing->setId(3);
		$existing->setName('Sede');
		$existing->setSubcategoryId(7);
		$existing->setGeometry(self::PLAZA);
		$existing->setKind(Kind::Place->value);
		$existing->setLocationQuality(LocationQuality::Exact->value);
		$this->features->method('find')->willReturn($existing);

		$updated = $this->service->update(3, [
			'name' => 'Sede',
			'subcategoryId' => 7,
			'locationQuality' => 'aproximada',
		]);

		self::assertSame(LocationQuality::Approximate->value, $updated->getLocationQuality());
		self::assertSame(self::PLAZA, $updated->getGeometry());
	}

	/**
	 * Absent is derived from having no geometry, never chosen — so the new door
	 * refuses rather than letting a record claim a quality for a coordinate it
	 * does not have. Refusing, not ignoring: the message names the field so the
	 * caller learns which one it was.
	 */
	public function testAQualitySentForARecordWithNoStoredGeometryIsRefused(): void {
		$existing = new Feature();
		$existing->setId(3);
		$existing->setName('Sede sin coordenada');
		$existing->setSubcategoryId(7);
		$existing->setKind(Kind::Place->value);
		$existing->setLocationQuality(LocationQuality::Absent->value);
		$this->features->method('find')->willReturn($existing);

		$this->expectException(InvalidFeatureException::class);
		$this->expectExceptionMessageMatches('/Exactitud/');

		$this->service->update(3, [
			'name' => 'Sede sin coordenada',
			'subcategoryId' => 7,
			'locationQuality' => 'aproximada',
		]);
	}

	/**
	 * The door is in `apply()`, which creates share with updates, so a create
	 * that omits the `geometry` key entirely while naming a quality is refused
	 * too — where it used to store `sin_coordenada` in silence. That is the
	 * deliberate half of the change and the only behaviour it altered, so it
	 * gets a test rather than a paragraph: a create states the WHOLE record, and
	 * "there is no shape" plus "the shape is approximate" contradict each other.
	 * Creates that send `geometry` explicitly as `null` or `[]` are unchanged
	 * (see the two tests above), and the client always sends the key.
	 */
	public function testACreateWithNoGeometryKeyAtAllCannotNameAQuality(): void {
		$this->expectException(InvalidFeatureException::class);
		$this->expectExceptionMessageMatches('/Exactitud/');

		$this->service->create([
			'name' => 'Sede sin coordenada',
			'subcategoryId' => 7,
			'locationQuality' => 'aproximada',
		]);
	}

	/**
	 * The new door must not become the #63 overwrite by another name:
	 * `locationQuality()` reads an absent value as `exacta`, so applying it
	 * unconditionally would reset every stored `aproximada` on any update that
	 * never mentioned the field.
	 */
	public function testAnUpdateThatOmitsTheQualityLeavesItAlone(): void {
		$existing = new Feature();
		$existing->setId(3);
		$existing->setName('Sede');
		$existing->setSubcategoryId(7);
		$existing->setGeometry(self::PLAZA);
		$existing->setKind(Kind::Place->value);
		$existing->setLocationQuality(LocationQuality::Approximate->value);
		$this->features->method('find')->willReturn($existing);

		$updated = $this->service->update(3, ['name' => 'Sede nueva', 'subcategoryId' => 7]);

		self::assertSame(LocationQuality::Approximate->value, $updated->getLocationQuality());
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
