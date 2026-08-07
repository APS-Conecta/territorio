<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Service\BoundaryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The Boundary features on screen, for the Sector editor.
 *
 * Thin over the service, like every other controller here, so an OCS version
 * later is a new file rather than a rewrite (issue #18).
 */
class BoundaryController extends Controller {
	public function __construct(
		IRequest $request,
		private BoundaryService $boundaries,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Everything a Sector's Limit could follow, within the box asked for.
	 *
	 * Open to every user who can open the app, like every other read (ADR-0005).
	 */
	#[NoAdminRequired]
	public function inView(
		string $south = '',
		string $west = '',
		string $north = '',
		string $east = '',
	): JSONResponse {
		foreach ([$south, $west, $north, $east] as $ordinate) {
			if (!is_numeric($ordinate)) {
				return new JSONResponse(
					['message' => 'Indique el área con south, west, north y east.'],
					Http::STATUS_UNPROCESSABLE_ENTITY,
				);
			}
		}

		return new JSONResponse($this->boundaries->inView(
			(float)$south,
			(float)$west,
			(float)$north,
			(float)$east,
		));
	}
}
