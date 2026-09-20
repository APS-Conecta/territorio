<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Service\ImportFiles;
use OCA\Territorio\Service\ImportService;
use OCA\Territorio\Service\RevertService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Importing from the browser.
 *
 * #[NoAdminRequired] is deliberate and consistent with ADR-0005: every user who
 * can open Territorio may change the Registry, and the safety net is that the
 * work is recorded and undoable rather than that it is restricted. An import is
 * a large change, which is exactly why it is recorded as a batch that issue #7
 * can revert in one action.
 *
 * The same service backs `occ territorio:import`, so the two cannot drift.
 */
class ImportController extends Controller {
	use Refuses;

	public function __construct(
		IRequest $request,
		private ImportService $import,
		private RevertService $revertService,
		private IUserSession $userSession,
		private ImportFiles $files,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function upload(): JSONResponse {
		$dataset = trim((string)$this->request->getParam('dataset', ''));
		if ($dataset === '') {
			return $this->refuse('Indique a qué conjunto de datos importar.');
		}

		// A path means the file is already in the caller's own Files tree (#104),
		// so there is nothing to upload a second time. It is the ONLY difference:
		// past this point the name picks the reader and the batch is recorded and
		// revertible (ADR-0009) exactly as an upload's is.
		$path = trim((string)$this->request->getParam('path', ''));

		// A file rejected during reading wrote nothing at all. One that failed
		// part-way through writing left a batch behind that can be reverted —
		// which is why the message matters and the batch is recorded first.
		return $this->attempt(function () use ($path, $dataset): array {
			[$contents, $filename] = $path === ''
				? $this->files->uploadedFile()
				: $this->files->fileInNextcloud($path);

			return $this->import->importFile(
				$contents,
				$filename,
				$dataset,
				// Cast, not normalised: a label the request sent as an array must
				// not reach a ?string parameter as a TypeError 500 — and
				// ImportService owns the ""-means-null rule now, so this is the
				// raw pass.
				(string)$this->request->getParam('label', ''),
				$this->userSession->getUser()?->getUID(),
			)->toClient();
		});
	}

	#[NoAdminRequired]
	public function batches(): JSONResponse {
		return new JSONResponse($this->import->recentBatches());
	}

	/**
	 * ADR-0009's answer to "restore from backup". Open to every user for the same
	 * reason importing is: the safety net is that the undo is itself recorded and
	 * undoable, not that fewer people may reach it.
	 */
	#[NoAdminRequired]
	public function revert(string $id): JSONResponse {
		if (!ctype_digit($id)) {
			return $this->missing('Ese lote no existe.');
		}

		return $this->attempt(
			fn (): array => $this->revertService
				->revert((int)$id, $this->userSession->getUser()?->getUID())
				->toClient(),
			'Ese lote no existe.',
		);
	}
}
