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
		// The form shows what was typed, not what it resolved to — a rejected URL
		// has to stay visible in the box that produced it, together with the
		// warning saying why the map is still on OpenStreetMap.
		$stored = $this->basemapConfig->stored();

		$this->initialState->provideInitialState('basemap', $stored + [
			'rejected' => $this->basemapConfig->get()->wasRejected(),
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
