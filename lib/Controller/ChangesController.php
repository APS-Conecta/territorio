<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Service\FeatureService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The change cursor every open map polls (ADR-0002).
 *
 * This route is hit far more often than any other in the app — once every five
 * seconds per open map — so it does as little as possible: when nothing has
 * changed it answers from a single indexed read and returns a few bytes.
 *
 * Deliberately not SSE or a WebSocket. This instance runs `nextcloud:34-apache`,
 * where a held-open connection pins a PHP worker, and a handful of people
 * watching the map would exhaust the pool. The interval is the design, not a
 * shortcoming to fix later.
 */
class ChangesController extends Controller {
	use Refuses;
	public function __construct(
		IRequest $request,
		private FeatureService $features,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @param string $since the Revision the client last applied — refused, not
	 *                      TypeError'd, when it is not a number (L1-01)
	 */
	#[NoAdminRequired]
	public function since(string $since = '0'): JSONResponse {
		if (!ctype_digit($since)) {
			return $this->missing('La revisión desde la que preguntar tiene que ser un número.');
		}

		return new JSONResponse($this->features->changesSince(max(0, (int)$since)));
	}
}
