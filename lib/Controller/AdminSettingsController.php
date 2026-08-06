<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Service\BasemapConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Saves the basemap setting.
 *
 * Carries no #[NoAdminRequired] and no #[NoCSRFRequired] on purpose: both of
 * those are how a route is opened up, so their absence is what makes this
 * administrator-only and CSRF-protected.
 */
class AdminSettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private BasemapConfig $basemapConfig,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The response reports what the setting actually resolves to, not what was
	 * sent. An administrator who types a URL Territorio will not put in a policy
	 * sees the map fall back to OpenStreetMap immediately, instead of finding out
	 * later from a blank map.
	 */
	public function save(string $url = '', string $attribution = ''): JSONResponse {
		$basemap = $this->basemapConfig->set($url, $attribution);

		return new JSONResponse([
			'url' => $basemap->url(),
			'attribution' => $basemap->attribution(),
			'isDefault' => $basemap->isDefault(),
			'accepted' => $basemap->url() === trim($url) || trim($url) === '',
		]);
	}
}
