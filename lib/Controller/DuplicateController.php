<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use APS\Common\Refuses;
use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Service\DuplicateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IUserSession;

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
	use Refuses;

	public function __construct(
		IRequest $request,
		private DuplicateService $duplicates,
		private ?IUserSession $userSession = null,
		private ?ILogger $logger = null,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function logAuditEvent(string $action, int $pairId, ?int $survivorId): void {
		$actor = $this->userSession?->getUser()?->getUID() ?? 'system';
		$ip = $this->request->getRemoteAddress() ?? '127.0.0.1';
		$this->logger?->info(sprintf(
			'HIPAA_AUDIT: duplicate_review action=%s actor=%s ip=%s pair_id=%d survivor_id=%s',
			$action,
			$actor,
			$ip,
			$pairId,
			$survivorId !== null ? (string)$survivorId : 'none'
		));
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
	public function merge(string $id): JSONResponse {
		if (!ctype_digit($id)) {
			return $this->missing();
		}

		// Absent — not empty, absent — the merge is refused naming the field
		// (L1-03): the caller said nothing about which record survives, and the
		// old answer pretended they had named one that was not part of the pair.
		$survivorId = $this->request->getParam('survivorId');
		if ($survivorId === null) {
			return $this->refuse('Indique cuál registro sobrevive.');
		}

		return $this->attempt(function () use ($id, $survivorId): array {
			$res = $this->duplicates->merge((int)$id, (int)$survivorId)->toClient();
			$this->logAuditEvent('MERGE_DUPLICATE', (int)$id, (int)$survivorId);
			return $res;
		});
	}

	#[NoAdminRequired]
	public function dismiss(string $id): JSONResponse {
		if (!ctype_digit($id)) {
			return $this->missing();
		}

		return $this->attempt(function () use ($id): array {
			$res = $this->duplicates->dismiss((int)$id)->toClient();
			$this->logAuditEvent('DISMISS_DUPLICATE', (int)$id, null);
			return $res;
		});
	}

}
