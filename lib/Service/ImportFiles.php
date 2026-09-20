<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Exception\InvalidImportException;
use OCP\Files\File;
use OCP\Files\GenericFileException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Lock\LockedException;

/**
 * The bytes an import reads, however they arrive (L1-04).
 *
 * Two doors into one import: a multipart upload, and a file the caller
 * already has in their own Nextcloud Files (#104). Both hand back the same
 * pair — the bytes and the name that picks the reader — so the controller is
 * wiring only, and the acquisition rules live here where the two paths cannot
 * drift apart.
 */
class ImportFiles {
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
		private IRequest $request,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * The bytes of a multipart upload, and the browser's own name for the file,
	 * which is used only to pick a reader.
	 *
	 * @return array{string, string}
	 */
	public function uploadedFile(): array {
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
	public function fileInNextcloud(string $path): array {
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
}
