<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Domain\Kind;
use OCA\Territorio\Service\FeatureService;
use OCP\Server;

final class CustomAreaTest extends IntegrationTestCase {
	public function testOverlappingCustomAreasKeepTheirGeometryAndDoNotReplaceSectorAssignments(): void {
		$subcategoryId = $this->subcategoryIdBySlug('custom_area');
		$service = Server::get(FeatureService::class);
		$sectorId = $this->sector('Official clinical Sector');
		$placeId = $this->place('Community asset within both areas');
		$areaIds = [];
		foreach (['Walking distance area', 'Community network area'] as $name) {
			$area = $service->create([
				'name' => $name,
				'subcategoryId' => $subcategoryId,
				'geometry' => self::BOUNDARY,
			]);
			$this->created[] = (int)$area->getId();
			$areaIds[] = (int)$area->getId();
			self::assertSame(Kind::Zone->value, $area->getKind());
			self::assertNull($area->getSectorId());
		}

		$read = static function (int $id) use ($service): Feature {
			foreach ($service->listAll() as $feature) {
				if ((int)$feature->getId() === $id) return $feature;
			}
			self::fail('Created record must be visible in the Registry.');
		};
		self::assertSame($sectorId, $read($placeId)->getSectorId());
		self::assertSame(self::BOUNDARY, json_decode($read($areaIds[0])->getGeometry(), true));
		self::assertSame(self::BOUNDARY, json_decode($read($areaIds[1])->getGeometry(), true));

		$service->update($areaIds[0], ['name' => 'Renamed area', 'subcategoryId' => $subcategoryId]);
		self::assertSame('Renamed area', $read($areaIds[0])->getName());
		self::assertCount(2, $service->history($areaIds[0]));
		$service->retire($areaIds[0]);
		self::assertNotContains($areaIds[0], array_map(static fn (Feature $feature): int => (int)$feature->getId(), $service->listAll()));
		$service->restore($areaIds[0]);
		self::assertSame('Renamed area', $read($areaIds[0])->getName());
		self::assertSame($sectorId, $read($placeId)->getSectorId());
	}
}
