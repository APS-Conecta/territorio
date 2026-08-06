<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Controller;

use OCA\Territorio\Controller\FeatureController;
use OCA\Territorio\Db\Feature;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Exception\InvalidGeometryException;
use OCA\Territorio\Service\FeatureService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The controller holds one decision: which status code a refusal becomes. Get it
 * wrong and a form that could tell someone what to fix instead reports a server
 * error, or a missing record looks like a validation problem.
 */
final class FeatureControllerTest extends TestCase {
	private FeatureService&MockObject $features;
	private FeatureController $controller;

	protected function setUp(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getParams')->willReturn(['name' => 'Plaza', 'subcategoryId' => 7]);

		$this->features = $this->createMock(FeatureService::class);
		$this->controller = new FeatureController($request, $this->features);
	}

	public function testACreatedFeatureComesBackToTheClient(): void {
		$feature = new Feature();
		$feature->setId(12);
		$feature->setName('Plaza de Prueba');
		$feature->setKind('place');
		$this->features->method('create')->willReturn($feature);

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('Plaza de Prueba', $response->getData()['name']);
		self::assertSame(12, $response->getData()['id']);
	}

	public function testARefusedSubmissionIsUnprocessableNotAServerError(): void {
		$this->features->method('create')
			->willThrowException(new InvalidFeatureException('a Feature needs a name'));

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('a Feature needs a name', $response->getData()['message']);
	}

	public function testABadGeometryIsAlsoUnprocessable(): void {
		$this->features->method('create')
			->willThrowException(new InvalidGeometryException('coordinates must be numbers'));

		self::assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$this->controller->create()->getStatus(),
		);
	}

	public function testEditingSomethingThatIsGoneIsNotFound(): void {
		$this->features->method('update')->willThrowException(new DoesNotExistException('gone'));

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->update(404)->getStatus());
	}

	public function testAnUpdateReturnsTheStoredFeature(): void {
		$feature = new Feature();
		$feature->setId(3);
		$feature->setName('Nuevo nombre');
		$this->features->method('update')->willReturn($feature);

		$response = $this->controller->update(3);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('Nuevo nombre', $response->getData()['name']);
	}
}
