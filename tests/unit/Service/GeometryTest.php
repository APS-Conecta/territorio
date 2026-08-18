<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Exception\InvalidGeometryException;
use OCA\Territorio\Service\Geometry;
use OCA\Territorio\Service\Kind;
use PHPUnit\Framework\TestCase;

/**
 * Geometry is stored as GeoJSON text with a bounding box alongside it, because
 * the database has no PostGIS and no spatial index. The bounding box is what
 * every later query filters on, so getting it wrong is not a display bug — it
 * silently hides Features from the map.
 *
 * Coordinates here are real ones from the La Florida catastro. GeoJSON orders
 * them [longitude, latitude], which is the reverse of how everyone says them
 * out loud, and is the single most likely thing to be wrong.
 */
final class GeometryTest extends TestCase {
	private const JDV_35 = '{"type":"Point","coordinates":[-70.5553836,-33.5187671]}';

	public function testAPointKeepsGeoJsonLongitudeLatitudeOrder(): void {
		$geometry = Geometry::fromJson(self::JDV_35);

		self::assertSame('Point', $geometry->type());
		$box = $geometry->boundingBox();
		self::assertEqualsWithDelta(-33.5187671, $box->minLat(), 1e-9);
		self::assertEqualsWithDelta(-70.5553836, $box->minLon(), 1e-9);
	}

	public function testAPointsBoundingBoxIsThePointItself(): void {
		$box = Geometry::fromJson(self::JDV_35)->boundingBox();

		self::assertSame($box->minLat(), $box->maxLat());
		self::assertSame($box->minLon(), $box->maxLon());
	}

	public function testALineStringSpansAllItsVertices(): void {
		$box = Geometry::fromJson(
			'{"type":"LineString","coordinates":[[-70.60,-33.55],[-70.50,-33.49],[-70.58,-33.52]]}',
		)->boundingBox();

		self::assertEqualsWithDelta(-70.60, $box->minLon(), 1e-9);
		self::assertEqualsWithDelta(-70.50, $box->maxLon(), 1e-9);
		self::assertEqualsWithDelta(-33.55, $box->minLat(), 1e-9);
		self::assertEqualsWithDelta(-33.49, $box->maxLat(), 1e-9);
	}

	/**
	 * A polygon nests one level deeper than a line, and a polygon with a hole
	 * deeper still. The box must not depend on how deep the nesting goes.
	 */
	public function testAPolygonWithAHoleIsMeasuredAcrossEveryRing(): void {
		$box = Geometry::fromJson('{"type":"Polygon","coordinates":['
			. '[[-70.62,-33.56],[-70.48,-33.56],[-70.48,-33.48],[-70.62,-33.48],[-70.62,-33.56]],'
			. '[[-70.55,-33.53],[-70.53,-33.53],[-70.53,-33.51],[-70.55,-33.51],[-70.55,-33.53]]'
			. ']}')->boundingBox();

		self::assertEqualsWithDelta(-70.62, $box->minLon(), 1e-9);
		self::assertEqualsWithDelta(-70.48, $box->maxLon(), 1e-9);
		self::assertEqualsWithDelta(-33.56, $box->minLat(), 1e-9);
		self::assertEqualsWithDelta(-33.48, $box->maxLat(), 1e-9);
	}

	/**
	 * The rings as they would be STORED, read back out of the stored text rather
	 * than off the object. What matters is what the column ends up holding: that
	 * is what containment reads and what an export re-emits.
	 *
	 * @return array<int, array<int, array{float, float}>>
	 */
	private static function ringsOf(string $json): array {
		return json_decode(Geometry::fromJson($json)->toJson(), true)['coordinates'];
	}

	/**
	 * A GeoJSON ring must repeat its first position last (RFC 7946 §3.1.6), and
	 * real files do not always do it. KML rings arrive through GeoJsonReader the
	 * same way, so the app has two doors for it.
	 *
	 * Closed here, on the way in, rather than tolerated later. Left open, three
	 * separate things went wrong and none of them said so: PointInPolygon needs
	 * four positions to have an inside, so an unclosed TRIANGLE contained nothing
	 * for ever and every Place inside it was assigned to nobody — while Leaflet
	 * drew the filled shape and it looked correct. An unclosed QUAD has four
	 * positions, so it worked, which made closure silently required at three
	 * vertices and silently optional at four. And the app re-exported what it
	 * stored, so an invalid ring went back out as invalid GeoJSON.
	 */
	public function testAnUnclosedRingIsClosedOnTheWayIn(): void {
		$triangle = '{"type":"Polygon","coordinates":[[[-70.59,-33.53],[-70.57,-33.53],[-70.57,-33.51]]]}';

		$rings = self::ringsOf($triangle);

		self::assertCount(4, $rings[0], 'a ring needs its first position repeated last to close');
		self::assertSame($rings[0][0], $rings[0][3], 'the closing position must equal the first');
	}

	public function testAlreadyClosedRingsAreLeftAlone(): void {
		$square = '{"type":"Polygon","coordinates":[[[-70.59,-33.53],[-70.57,-33.53],'
			. '[-70.57,-33.51],[-70.59,-33.51],[-70.59,-33.53]]]}';

		$rings = self::ringsOf($square);

		self::assertCount(5, $rings[0], 'nothing should have been appended to a closed ring');
	}

	/** A hole is a ring like any other, and bounds the polygon just as much. */
	public function testHolesAreClosedToo(): void {
		$withHole = '{"type":"Polygon","coordinates":['
			. '[[-70.60,-33.54],[-70.56,-33.54],[-70.56,-33.50],[-70.60,-33.50],[-70.60,-33.54]],'
			. '[[-70.59,-33.53],[-70.58,-33.53],[-70.58,-33.52]]]}';

		$rings = self::ringsOf($withHole);

		self::assertCount(4, $rings[1]);
		self::assertSame($rings[1][0], $rings[1][3]);
	}

	/**
	 * Two positions cannot bound an area however they are closed, and letting one
	 * through only moves the silence downstream — it would store, draw as a line,
	 * and contain nothing.
	 */
	public function testARingThatCannotBoundAnAreaIsRejected(): void {
		$this->expectException(InvalidGeometryException::class);

		Geometry::fromJson('{"type":"Polygon","coordinates":[[[-70.59,-33.53],[-70.57,-33.51]]]}');
	}

	public function testKindFollowsTheShapeOnTheMap(): void {
		self::assertSame(Kind::Place, Geometry::fromJson(self::JDV_35)->kind());
		self::assertSame(
			Kind::Route,
			Geometry::fromJson('{"type":"LineString","coordinates":[[-70.6,-33.5],[-70.5,-33.4]]}')->kind(),
		);
		self::assertSame(
			Kind::Zone,
			Geometry::fromJson('{"type":"Polygon","coordinates":'
				. '[[[-70.6,-33.5],[-70.5,-33.5],[-70.5,-33.4],[-70.6,-33.5]]]}')->kind(),
		);
	}

	public function testItRoundTripsThroughJson(): void {
		$geometry = Geometry::fromJson(self::JDV_35);

		self::assertSame(self::JDV_35, Geometry::fromJson($geometry->toJson())->toJson());
	}

	public function testMalformedJsonIsRejected(): void {
		$this->expectException(InvalidGeometryException::class);
		Geometry::fromJson('{not json');
	}

	public function testJsonThatIsNotAnObjectIsRejected(): void {
		$this->expectException(InvalidGeometryException::class);
		Geometry::fromJson('[1,2]');
	}

	public function testAMissingTypeIsRejected(): void {
		$this->expectException(InvalidGeometryException::class);
		Geometry::fromJson('{"coordinates":[-70.5,-33.5]}');
	}

	/**
	 * GeometryCollection is valid GeoJSON that this app has no use for, and its
	 * coordinates live under a different key entirely — accepting it would give a
	 * bounding box of nothing.
	 */
	public function testAnUnsupportedGeometryTypeIsRejected(): void {
		$this->expectException(InvalidGeometryException::class);
		Geometry::fromJson('{"type":"GeometryCollection","geometries":[]}');
	}

	public function testAFeatureWrapperIsNotAGeometry(): void {
		$this->expectException(InvalidGeometryException::class);
		Geometry::fromJson('{"type":"Feature","geometry":{"type":"Point","coordinates":[0,0]},"properties":{}}');
	}

	public function testNonNumericCoordinatesAreRejected(): void {
		$this->expectException(InvalidGeometryException::class);
		Geometry::fromJson('{"type":"Point","coordinates":["-70.5","-33.5"]}');
	}

	public function testEmptyCoordinatesAreRejected(): void {
		$this->expectException(InvalidGeometryException::class);
		Geometry::fromJson('{"type":"Point","coordinates":[]}');
	}

	public function testAPointNeedsBothOrdinates(): void {
		$this->expectException(InvalidGeometryException::class);
		Geometry::fromJson('{"type":"Point","coordinates":[-70.5]}');
	}

	public function testALatitudeOutsideTheWorldIsRejected(): void {
		$this->expectException(InvalidGeometryException::class);
		Geometry::fromJson('{"type":"Point","coordinates":[-70.5,-95.0]}');
	}

	/**
	 * A known limitation, pinned so nobody later assumes otherwise: swapping
	 * latitude and longitude is the classic GeoJSON mistake, and for Chile the
	 * range check cannot catch it. Both -33.5 and -70.5 are valid latitudes AND
	 * valid longitudes, so a swapped Chilean coordinate is accepted here and
	 * lands in the South Atlantic.
	 *
	 * Catching it needs to know where the install actually is, which arrives with
	 * the Comuna setting (issue #9). Until then, importers must get the order
	 * right themselves.
	 */
	public function testASwappedChileanCoordinateIsNotCaughtByRangeAlone(): void {
		$box = Geometry::fromJson('{"type":"Point","coordinates":[-33.5187671,-70.5553836]}')
			->boundingBox();

		self::assertEqualsWithDelta(-70.5553836, $box->minLat(), 1e-9);
		self::assertEqualsWithDelta(-33.5187671, $box->minLon(), 1e-9);
	}

	public function testALongitudeOutsideTheWorldIsRejected(): void {
		$this->expectException(InvalidGeometryException::class);
		Geometry::fromJson('{"type":"Point","coordinates":[-200.0,-33.5]}');
	}

	/**
	 * The only message assertion in this suite, and it is here because this
	 * message is not a diagnostic — `ImportService::prepare()` splices it into the
	 * refusal a clinician reads when a file will not import, so it is UI text and
	 * the language rule applies to it.
	 *
	 * Pinned on one representative site rather than all ten: the defect this
	 * guards is a whole file drifting back to English, which one site catches as
	 * well as ten and costs nothing to keep.
	 */
	public function testTheMessageAClinicianReadsIsSpanish(): void {
		$this->expectExceptionMessage('las coordenadas deben ser números');
		Geometry::fromJson('{"type":"Point","coordinates":["-70.5","-33.5"]}');
	}

	public function testTheAntimeridianAndPolesAreInsideTheWorld(): void {
		$box = Geometry::fromJson('{"type":"LineString","coordinates":[[-180,-90],[180,90]]}')
			->boundingBox();

		self::assertSame(-180.0, $box->minLon());
		self::assertSame(90.0, $box->maxLat());
	}

	public function testIntegerCoordinatesAreAccepted(): void {
		$box = Geometry::fromJson('{"type":"Point","coordinates":[-70,-33]}')->boundingBox();

		self::assertSame(-33.0, $box->minLat());
		self::assertSame(-70.0, $box->minLon());
	}
}
