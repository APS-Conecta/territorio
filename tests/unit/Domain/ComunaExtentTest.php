<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Domain;

use InvalidArgumentException;
use OCA\Territorio\Domain\ComunaExtent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Comuna extent is the box the map opens on (ADR-0021). It is checked
 * when built and refused rather than repaired: a box with its corners swapped
 * or its axes crossed would open the map on the wrong ocean, and nothing on
 * screen would say why.
 */
final class ComunaExtentTest extends TestCase {
	/**
	 * The table stores west, south, east, north; BoundingBox::fromBounds takes
	 * (minLat, minLon, maxLat, maxLon). Every number here differs from every
	 * other, and longitude and latitude are far apart, so crossing any two of
	 * them in the constructor changes a value this test reads back.
	 */
	public function testEachCornerLandsOnTheAxisItBelongsTo(): void {
		[[$south, $west], [$north, $east]] = ComunaExtent::of(-70.692, -33.479, -70.625, -33.426)->jsonSerialize();

		self::assertSame(-70.692, $west);
		self::assertSame(-33.479, $south);
		self::assertSame(-70.625, $east);
		self::assertSame(-33.426, $north);
	}

	/** Leaflet's fitBounds order: [[south, west], [north, east]]. */
	public function testItSerialisesInLeafletOrder(): void {
		$extent = ComunaExtent::of(-70.692, -33.479, -70.625, -33.426);

		self::assertSame([[-33.479, -70.692], [-33.426, -70.625]], $extent->jsonSerialize());
		self::assertSame('[[-33.479,-70.692],[-33.426,-70.625]]', json_encode($extent));
	}

	public function testItAcceptsTheLargestSpanAllowed(): void {
		$span = ComunaExtent::MAX_SPAN_DEGREES;

		self::assertSame(
			[[-50.0, -70.0 - $span], [-50.0 + $span, -70.0]],
			ComunaExtent::of(-70.0 - $span, -50.0, -70.0, -50.0 + $span)->jsonSerialize(),
		);
	}

	/** @return array<string, array{float, float, float, float}> */
	public static function refused(): array {
		return [
			'NaN' => [NAN, -33.5, -70.6, -33.4],
			'infinite' => [-70.7, -33.5, INF, -33.4],
			'west of the antimeridian' => [-180.5, -33.5, -179.9, -33.4],
			'east of the antimeridian' => [179.9, -33.5, 180.5, -33.4],
			'south of the pole' => [-70.7, -90.5, -70.6, -90.0],
			'north of the pole' => [-70.7, 89.9, -70.6, 90.5],
			'west and east swapped' => [-70.6, -33.5, -70.7, -33.4],
			'south and north swapped' => [-70.7, -33.4, -70.6, -33.5],
			'zero width' => [-70.7, -33.5, -70.7, -33.4],
			'zero height' => [-70.7, -33.5, -70.6, -33.5],
			'wider than the cap' => [-70.0 - ComunaExtent::MAX_SPAN_DEGREES - 0.1, -50.0, -70.0, -49.0],
			'taller than the cap' => [-70.7, -50.0, -70.6, -50.0 + ComunaExtent::MAX_SPAN_DEGREES + 0.1],
		];
	}

	#[DataProvider('refused')]
	public function testItRefusesWhatIsNotABox(float $west, float $south, float $east, float $north): void {
		$this->expectException(InvalidArgumentException::class);

		ComunaExtent::of($west, $south, $east, $north);
	}

	public function testAnUnknownCutHasNoExtent(): void {
		self::assertNull(ComunaExtent::forCut('99999'));
		self::assertNull(ComunaExtent::forCut(''));
		self::assertNull(ComunaExtent::forCut('not a cut'));
	}

	/**
	 * Reads the generated table through forCut(), where the column order is
	 * decided once. Santiago is one part, so the box does not depend on which
	 * part the generator picks. West is -70.693, not -70.692: the raw edge is
	 * -70.69204 and the table rounds outward.
	 */
	public function testAKnownCutReadsBackWithItsAxesTheRightWayRound(): void {
		$extent = ComunaExtent::forCut('13101');

		self::assertNotNull($extent);
		self::assertSame([[-33.479, -70.693], [-33.426, -70.625]], $extent->jsonSerialize());
	}
}
