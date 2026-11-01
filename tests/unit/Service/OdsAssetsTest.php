<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Service\OdsAssets;
use PHPUnit\Framework\TestCase;

final class OdsAssetsTest extends TestCase {
	public function testExportRetainsIdentityAndOptionalFieldsForReview(): void {
		$rows = [['id' => 'instance:42', 'revision' => 8, 'name' => 'Plaza', 'categorySlug' => 'social',
			'subcategorySlug' => 'otro', 'geometry' => ['type' => 'Point', 'coordinates' => [-70.58, -33.52]],
			'phone' => '+56912345678', 'notes' => 'Abierta', 'locationQuality' => 'aproximada']];
		$read = OdsAssets::read(OdsAssets::write($rows, [], true));
		self::assertSame('instance:42', $read[0]['values']['id']);
		self::assertSame('8', $read[0]['values']['revision']);
		self::assertSame('-33.52', $read[0]['values']['lat']);
		self::assertSame('+56912345678', $read[0]['values']['phone']);
		self::assertSame('Abierta', $read[0]['values']['notes']);
	}
}
