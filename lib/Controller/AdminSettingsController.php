<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use APS\Common\Refuses;
use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Domain\Comuna;
use OCA\Territorio\Service\ComunaConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Saves the setting an administrator chooses: which Comuna this install serves.
 *
 * Carries no #[NoAdminRequired] and no #[NoCSRFRequired] on purpose: both of
 * those are how a route is opened up, so their absence is what makes this
 * administrator-only and CSRF-protected.
 */
class AdminSettingsController extends Controller {
	use Refuses;

	public function __construct(
		IRequest $request,
		private ComunaConfig $comunaConfig,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Which Comuna this install serves — exactly one, by CUT code.
	 *
	 * A bad code is refused rather than stored. Stored, it would make every
	 * Unidad Vecinal file fail later for a reason nobody could see from here.
	 */
	public function saveComuna(string $cut = '', string $name = ''): JSONResponse {
		// attempt(), not the manual catch (L1-03): Comuna::of() throws a
		// RefusedException, and the exception→response mapping lives in exactly
		// one place.
		return $this->attempt(function () use ($cut, $name): array {
			$comuna = Comuna::of($cut, $name);
			$this->comunaConfig->set($comuna);

			return $comuna->jsonSerialize();
		});
	}
}
