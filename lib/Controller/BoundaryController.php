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
	 * How much of a Limit a re-import may not hold any more.
	 *
	 * The same cap as `FeatureService::LIMIT_PARTS`, for the same reason: a Limit
	 * cannot hold more parts than that, so no honest client asks about more ids.
	 */
	private const ASKED_ABOUT = 500;

	/**
	 * Everything a Sector's Limit could follow, within the box asked for — and,
	 * of the ids the open draft already cites, which are no longer there.
	 *
	 * Open to every user who can open the app, like every other read (ADR-0005).
	 *
	 * `ids` is a comma-separated list, the same string shape as the ordinates,
	 * because the editor sends it on the query string of a GET it already makes.
	 */
	#[NoAdminRequired]
	public function inView(
		string $south = '',
		string $west = '',
		string $north = '',
		string $east = '',
		string $ids = '',
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
			$this->cited($ids),
		));
	}

	/**
	 * The ids a draft says it cites, as ids.
	 *
	 * Whole numbers only, the same rule `FeatureService::limit()` applies when it
	 * stores one: `is_numeric()` would take "12.9", " 41" and "1e3" and cast them
	 * to 12, 41 and 1000 — and here that would answer a question about a
	 * different feature from the one asked about. Anything else is dropped rather
	 * than refused: this is one extra fact on a read the editor makes constantly,
	 * and failing the whole view because one id was malformed would black out the
	 * picker over a detail.
	 *
	 * @return list<int>
	 */
	private function cited(string $ids): array {
		$whole = array_filter(
			explode(',', $ids),
			static fn (string $id): bool => ctype_digit($id),
		);

		return array_map('intval', array_slice(array_values($whole), 0, self::ASKED_ABOUT));
	}
}
