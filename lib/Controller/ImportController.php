<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use APS\Common\Refuses;
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
		// The empty-dataset rule lives in ImportService now (L1-02): this door and
		// `occ territorio:import` both pass through the one refusal, with the same
		// sentence, and a third entry point inherits it for free.
		$dataset = $this->stringParam('dataset');

		// A path means the file is already in the caller's own Files tree (#104),
		// so there is nothing to upload a second time. It is the ONLY difference:
		// past this point the name picks the reader and the batch is recorded and
		// revertible (ADR-0009) exactly as an upload's is.
		$path = $this->stringParam('path');

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
				$this->stringParam('label'),
				$this->userSession->getUser()?->getUID(),
			)->toClient();
		});
	}

	/**
	 * is_string, not a cast (L1-12, #207): `(string)` on a `dataset[]=x`,
	 * `path[]=x` or `label[]=x` request turns garbage into "Array" plus an
	 * E_WARNING, against Refuses' own "never a silent 200". '' is what each of
	 * them already reads as not given: ImportService refuses an empty dataset
	 * with its own sentence, an empty label falls back to the Dataset's slug,
	 * and an empty path means an upload. trim normalises what a form sends as
	 * whitespace.
	 */
	private function stringParam(string $key): string {
		$value = $this->request->getParam($key, '');

		return is_string($value) ? trim($value) : '';
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
