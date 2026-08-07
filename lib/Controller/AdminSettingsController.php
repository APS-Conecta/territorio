<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Service\BasemapConfig;
use OCA\Territorio\Service\Comuna;
use OCA\Territorio\Service\ComunaConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
		private ComunaConfig $comunaConfig,
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

		return new JSONResponse(
			$basemap->jsonSerialize() + ['accepted' => !$basemap->wasRejected()],
		);
	}

	/**
	 * Which Comuna this install serves — exactly one, by CUT code.
	 *
	 * A bad code is refused rather than stored. Stored, it would make every
	 * Unidad Vecinal file fail later for a reason nobody could see from here.
	 */
	public function saveComuna(string $cut = '', string $name = ''): JSONResponse {
		try {
			$comuna = Comuna::of($cut, $name);
		} catch (InvalidFeatureException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->comunaConfig->set($comuna);

		return new JSONResponse($comuna->jsonSerialize());
	}
}
