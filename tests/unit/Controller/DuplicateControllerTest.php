<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Controller;

use OCA\Territorio\Controller\DuplicateController;
use OCA\Territorio\Db\Feature;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Service\DuplicateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Nothing else exercises this controller: the integration suite drives
 * DuplicateService directly (DuplicateQueueTest), so a swapped pair id, a body
 * key renamed away from `survivorId`, or a guard that stopped answering the
 * not-found path would stay green through every suite and only surface in the
 * browser — or as a TypeError a person reading the screen cannot see.
 */
final class DuplicateControllerTest extends TestCase {
	/** @var array<string, string> */
	private array $params = [];
	private DuplicateService&MockObject $duplicates;
	private DuplicateController $controller;

	protected function setUp(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => $this->params[$key] ?? $default,
		);

		$this->duplicates = $this->createMock(DuplicateService::class);
		$this->controller = new DuplicateController($request, $this->duplicates);
	}

	public function testAMergeWithoutASurvivorIsRefusedNamingTheField(): void {
		$this->params = [];
		$this->duplicates->expects(self::never())->method('merge');

		$response = $this->controller->merge('5');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('Indique cuál registro sobrevive.', $response->getData()['message']);
	}

	/**
	 * The `->with()` is the pin: the pair id arrives as a route string and the
	 * survivor as a body string, and both reach the service as ints in the
	 * order `merge(int $pairId, int $survivorId)` declares them.
	 */
	public function testTheSurvivorTheCallerNamesIsTheOneMerged(): void {
		$this->params = ['survivorId' => '7'];
		$merged = new Feature();
		$merged->setId(7);
		$this->duplicates->expects(self::once())
			->method('merge')
			->with(5, 7)
			->willReturn($merged);

		$response = $this->controller->merge('5');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAMalformedPairIdIsTheSameNotFound(): void {
		$this->params = ['survivorId' => '7'];
		$this->duplicates->expects(self::never())->method('merge');

		$response = $this->controller->merge('retired');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('no existe', $response->getData()['message']);
	}

	public function testADismissWithAMalformedIdIsAlsoNotFound(): void {
		$this->duplicates->expects(self::never())->method('dismiss');

		$response = $this->controller->dismiss('abc');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('no existe', $response->getData()['message']);
	}

	public function testAMergeRefusalIsUnprocessableNotAServerError(): void {
		$this->params = ['survivorId' => '7'];
		$this->duplicates->method('merge')
			->willThrowException(new InvalidFeatureException('El registro que se conserva no es parte de este par.'));

		$response = $this->controller->merge('5');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertStringContainsString('no es parte de este par', $response->getData()['message']);
	}

	public function testAPairNobodyHasIsNotFound(): void {
		$this->params = ['survivorId' => '7'];
		$this->duplicates->method('merge')->willThrowException(new DoesNotExistException('gone'));

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->merge('404')->getStatus());
	}
}
