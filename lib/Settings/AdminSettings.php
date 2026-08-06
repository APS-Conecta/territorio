<?php

declare(strict_types=1);

namespace OCA\Territorio\Settings;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Service\BasemapConfig;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private BasemapConfig $basemapConfig,
	) {
	}

	public function getForm(): TemplateResponse {
		$basemap = $this->basemapConfig->get();

		$this->initialState->provideInitialState('basemap', [
			'url' => $basemap->url(),
			'attribution' => $basemap->attribution(),
			'isDefault' => $basemap->isDefault(),
		]);

		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');

		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
