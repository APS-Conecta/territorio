<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Service\DuplicateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The review queue (issue #16).
 *
 * Open to every user, like every other change (ADR-0005). Merging retires a
 * record and points it at another, and both of those are undoable by restoring
 * it — so the safety net here is the same one the rest of the app relies on.
 *
 * Thin, like FeatureController: everything that decides anything is in
 * DuplicateService.
 */
class DuplicateController extends Controller {
	public function __construct(
		IRequest $request,
		private DuplicateService $duplicates,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->attempt(fn (): array => $this->duplicates->outstanding());
	}

	/**
	 * The survivor is named by the caller and never guessed. Which of two records
	 * is the better one is a judgement about the place, not about the data — the
	 * newer row is not the truer one.
	 */
	#[NoAdminRequired]
	public function merge(int $id): JSONResponse {
		return $this->attempt(fn (): array => $this->duplicates->merge(
			$id,
			(int)$this->request->getParam('survivorId'),
		)->toClient());
	}

	#[NoAdminRequired]
	public function dismiss(int $id): JSONResponse {
		return $this->attempt(fn (): array => $this->duplicates->dismiss($id)->toClient());
	}

	/** @param callable(): array<string, mixed> $action */
	private function attempt(callable $action): JSONResponse {
		try {
			return new JSONResponse($action());
		} catch (InvalidFeatureException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (DoesNotExistException) {
			return new JSONResponse(['message' => 'no existe'], Http::STATUS_NOT_FOUND);
		}
	}
}
