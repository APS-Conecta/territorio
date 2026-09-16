<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Service\ImportService;
use OCA\Territorio\Service\RevertService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\GenericFileException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Lock\LockedException;

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
	/**
	 * A comuna's catastro is well under a megabyte — La Florida's 865 features
	 * are 567 KB. The limit is here so a mistaken upload fails immediately and
	 * cheaply, rather than after PHP has read it all into memory.
	 */
	private const MAX_BYTES = 8 * 1024 * 1024;

	/**
	 * One sentence for both ways in (#104). The cap is about this process's
	 * memory, so a file already in Files earns exactly the same refusal as one
	 * being uploaded — and a person who hits it twice reads the same thing.
	 */
	private const TOO_BIG = 'El archivo supera los 8 MB.';

	public function __construct(
		IRequest $request,
		private ImportService $import,
		private RevertService $revertService,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
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

		try {
			[$contents, $filename] = $path === ''
				? $this->uploadedFile()
				: $this->fileInNextcloud($path);

			$batch = $this->import->importFile(
				$contents,
				$filename,
				$dataset,
				trim((string)$this->request->getParam('label', '')) ?: null,
				$this->userSession->getUser()?->getUID(),
			);
		} catch (InvalidImportException|InvalidFeatureException $e) {
			// A file rejected during reading wrote nothing at all. One that failed
			// part-way through writing left a batch behind that can be reverted —
			// which is why the message matters and the batch is recorded first.
			return $this->refuse($e->getMessage());
		}

		return new JSONResponse($batch->toClient());
	}

	/**
	 * The bytes of a multipart upload, and the browser's own name for the file,
	 * which is used only to pick a reader.
	 *
	 * @return array{string, string}
	 */
	private function uploadedFile(): array {
		$file = $this->request->getUploadedFile('file');

		if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			throw new InvalidImportException('No llegó ningún archivo.');
		}
		if (($file['size'] ?? 0) > self::MAX_BYTES) {
			throw new InvalidImportException(self::TOO_BIG);
		}

		$contents = file_get_contents((string)$file['tmp_name']);
		if ($contents === false) {
			throw new InvalidImportException('No se pudo leer el archivo subido.');
		}

		return [$contents, (string)($file['name'] ?? '')];
	}

	/**
	 * The bytes of a file the caller already has in Nextcloud Files (#104), and
	 * the name Files holds it under — the server's own name, so the suffix that
	 * picks the reader is the file's rather than anything the browser typed.
	 *
	 * Resolved against the caller's OWN user folder, which is the entire access
	 * rule. A file somebody shared WITH them is mounted there, so it is
	 * importable by design; a path that is not theirs simply does not resolve,
	 * and `Folder::get()` normalises `..` away rather than climbing out.
	 *
	 * Every refusal below is a sentence rather than an exception let through: the
	 * dialog shows a 422's message, and a 500 is a stack trace in a log nobody
	 * reading the screen can see. (NoUserException is not among them — it lives
	 * outside OCP, and a route this app serves only to a signed-in session cannot
	 * raise it.)
	 *
	 * @return array{string, string}
	 */
	private function fileInNextcloud(string $path): array {
		try {
			$node = $this->rootFolder
				->getUserFolder((string)$this->userSession->getUser()?->getUID())
				->get($path);
		} catch (NotFoundException|NotPermittedException) {
			throw new InvalidImportException('No se encontró ese archivo en sus archivos de Nextcloud.');
		}

		if (!$node instanceof File) {
			throw new InvalidImportException('Eso es una carpeta, no un archivo.');
		}
		// Before reading, not after: `getContent()` loads the node whole into
		// memory, which is the thing the cap exists to prevent.
		if ($node->getSize() > self::MAX_BYTES) {
			throw new InvalidImportException(self::TOO_BIG);
		}

		try {
			$contents = $node->getContent();
		} catch (NotPermittedException|LockedException|GenericFileException) {
			throw new InvalidImportException('No se pudo leer ese archivo de Nextcloud.');
		}

		return [$contents, $node->getName()];
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
	public function revert(int $id): JSONResponse {
		try {
			$revert = $this->revertService->revert($id, $this->userSession->getUser()?->getUID());
		} catch (DoesNotExistException) {
			return new JSONResponse(['message' => 'Ese lote no existe.'], Http::STATUS_NOT_FOUND);
		} catch (InvalidImportException $e) {
			return $this->refuse($e->getMessage());
		}

		return new JSONResponse($revert->toClient());
	}

	private function refuse(string $message): JSONResponse {
		return new JSONResponse(['message' => $message], Http::STATUS_UNPROCESSABLE_ENTITY);
	}
}
