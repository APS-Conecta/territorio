<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Controller;

use OCA\Territorio\Controller\ApiController;
use OCA\Territorio\Service\RegistryService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The OCS door's own decisions: which status each of RegistryService's
 * answers becomes — farmacia's ApiControllerTest discipline, restated for
 * the read-only surface. The service is doubled; the null/[] split that
 * inSubcategory() now promises (L0-05) is what these pins hold: null is 404,
 * [] is 200, and the two may never be confused by a shared door.
 */
final class ApiControllerTest extends TestCase {
	private RegistryService&\PHPUnit\Framework\MockObject\MockObject $registry;
	private ApiController $controller;

	protected function setUp(): void {
		$this->registry = $this->createMock(RegistryService::class);
		$this->controller = new ApiController($this->createStub(IRequest::class), $this->registry);
	}

	/** @return array<string, mixed> the payload the envelope carried */
	private function answered(mixed $response): array {
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		return $response->getData();
	}

	public function testCountsPassesTheRegistryAnswerThrough(): void {
		$counts = ['total' => 3, 'unknownSubcategory' => 0, 'categories' => [], 'bySector' => [], 'byUnidadVecinal' => []];
		$this->registry->method('counts')->willReturn($counts);

		self::assertSame($counts, $this->answered($this->controller->counts()));
	}

	public function testARecordTheRegistryDoesNotShowIs404(): void {
		$this->registry->method('find')->willReturn(null);

		$response = $this->controller->find(99);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testARecordTheRegistryShowsArrivesWhole(): void {
		$feature = ['id' => 7, 'name' => 'CESFAM de Prueba', 'category' => 'salud', 'subcategory' => 'cesfam'];
		$this->registry->method('find')->willReturn($feature);

		self::assertSame($feature, $this->answered($this->controller->find(7)));
	}

	public function testAnUnknownPairIs404EvenThoughTheListWouldBeEmpty(): void {
		$this->registry->method('inSubcategory')->willReturn(null);

		$response = $this->controller->inSubcategory('salud', 'no_existe');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testARealPairWithNothingUnderItIs200AndEmpty(): void {
		$this->registry->method('inSubcategory')->willReturn([]);

		$response = $this->controller->inSubcategory('salud', 'cesfam');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());
	}

	public function testARealPairAnswersWithItsRecords(): void {
		$found = [['id' => 1, 'name' => 'Taller de Prueba']];
		$this->registry->method('inSubcategory')->willReturn($found);

		self::assertSame($found, $this->answered($this->controller->inSubcategory('organizacion_social', 'taller')));
	}
}
