<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use APS\Common\Refuses;
use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Service\FeatureService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IUserSession;

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
	use Refuses;

	public function __construct(
		IRequest $request,
		private FeatureService $features,
		private ?IUserSession $userSession = null,
		private ?ILogger $logger = null,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function logAuditEvent(string $action, ?int $featureId, ?string $name): void {
		$actor = $this->userSession?->getUser()?->getUID() ?? 'system';
		$ip = $this->request->getRemoteAddress() ?? '127.0.0.1';
		$this->logger?->info(sprintf(
			'HIPAA_AUDIT: feature_mutation action=%s actor=%s ip=%s feature_id=%s name=%s',
			$action,
			$actor,
			$ip,
			$featureId !== null ? (string)$featureId : 'unknown',
			$name !== null ? $name : 'unknown'
		));
	}

	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->attempt(function (): array {
			$res = $this->features->create($this->request->getParams())->toClient();
			$this->logAuditEvent('CREATE_FEATURE', $res['id'] ?? null, $res['name'] ?? null);
			return $res;
		});
	}

	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		// Route ids arrive as strings; anything not a whole number is the same
		// not-found as a record nobody has (L0-03) — a TypeError before the
		// method body even ran answered 500 to a request about nothing.
		if (!ctype_digit($id)) {
			return $this->missing();
		}

		return $this->attempt(function () use ($id): array {
			$res = $this->features->update((int)$id, $this->request->getParams())->toClient();
			$this->logAuditEvent('UPDATE_FEATURE', (int)$id, $res['name'] ?? null);
			return $res;
		});
	}

	/**
	 * DELETE retires; it does not destroy (ADR-0005). The verb is the one a
	 * client naturally reaches for, and what it means here is documented rather
	 * than renamed — calling the route `/retire` would only move the surprise.
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		if (!ctype_digit($id)) {
			return $this->missing();
		}

		return $this->attempt(function () use ($id): array {
			$res = $this->features->retire((int)$id)->toClient();
			$this->logAuditEvent('RETIRE_FEATURE', (int)$id, $res['name'] ?? null);
			return $res;
		});
	}

	#[NoAdminRequired]
	public function restore(string $id): JSONResponse {
		if (!ctype_digit($id)) {
			return $this->missing();
		}

		return $this->attempt(function () use ($id): array {
			$res = $this->features->restore((int)$id)->toClient();
			$this->logAuditEvent('RESTORE_FEATURE', (int)$id, $res['name'] ?? null);
			return $res;
		});
	}

	#[NoAdminRequired]
	public function retired(): JSONResponse {
		return $this->attempt(fn (): array => array_map(
			static fn ($feature): array => $feature->toClient(),
			$this->features->listRetired(),
		));
	}

	#[NoAdminRequired]
	public function history(string $id): JSONResponse {
		if (!ctype_digit($id)) {
			return $this->missing();
		}

		return $this->attempt(fn (): array => $this->features->history((int)$id));
	}

}
