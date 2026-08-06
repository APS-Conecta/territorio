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
