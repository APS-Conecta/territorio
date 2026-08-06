<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Exception\InvalidGeometryException;
use OCA\Territorio\Service\FeatureService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Creating and editing Features.
 *
 * #[NoAdminRequired] is deliberate: every user who can open Territorio may edit
 * the Registry (ADR-0005). The safety net is history and retirement, not
 * permissions — and both arrive in later slices, so nothing here may hard-delete
 * anything.
 *
 * Thin on purpose. All the judgement is in FeatureService, which is what makes
 * an OCS controller later a new file rather than a rewrite.
 */
class FeatureController extends Controller {
	public function __construct(
		IRequest $request,
		private FeatureService $features,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->attempt(fn (): array
			=> $this->features->create($this->request->getParams())->toClient());
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse {
		return $this->attempt(fn (): array
			=> $this->features->update($id, $this->request->getParams())->toClient());
	}

	/**
	 * One place to turn the service's refusals into status codes, so neither
	 * action repeats the mapping and neither can quietly forget a case.
	 *
	 * @param callable(): array<string, mixed> $action
	 */
	private function attempt(callable $action): JSONResponse {
		try {
			return new JSONResponse($action());
		} catch (InvalidFeatureException|InvalidGeometryException $e) {
			// The submission was understood and refused — the message names what
			// to fix, and the form shows it.
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (DoesNotExistException) {
			return new JSONResponse(['message' => 'no existe'], Http::STATUS_NOT_FOUND);
		}
	}
}
