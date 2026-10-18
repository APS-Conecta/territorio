<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Controller;

use OCA\Territorio\Controller\AdminSettingsController;
use OCA\Territorio\Service\BasemapConfig;
use OCA\Territorio\Service\ComunaConfig;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The settings screen's comuna write: the sentence an administrator reads on a
 * bad CUT code is the contract (AdminSettings.vue shows it), and the move to
 * the refusal family (L7-01) must not change a word of it — or the status it
 * arrives on.
 */
final class AdminSettingsControllerTest extends TestCase {
	private ComunaConfig&MockObject $comunaConfig;
	private AdminSettingsController $controller;

	protected function setUp(): void {
		$this->comunaConfig = $this->createMock(ComunaConfig::class);
		$this->controller = new AdminSettingsController(
			$this->createStub(IRequest::class),
			$this->createStub(BasemapConfig::class),
			$this->comunaConfig,
		);
	}

	public function testABadCutCodeIsRefusedWithTheSameSentence(): void {
		$this->comunaConfig->expects(self::never())->method('set');

		$response = $this->controller->saveComuna('131');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertStringContainsString('no es un código CUT', $response->getData()['message']);
	}

	public function testTheChosenComunaComesBackStored(): void {
		$this->comunaConfig->expects(self::once())->method('set');

		$response = $this->controller->saveComuna('13110', 'Comuna de Prueba');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['cut' => '13110', 'name' => 'Comuna de Prueba', 'chosen' => true], $response->getData());
	}
}
