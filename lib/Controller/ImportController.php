<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Db\ImportBatchMapper;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Service\ImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
	/**
	 * A comuna's catastro is well under a megabyte — La Florida's 865 features
	 * are 567 KB. The limit is here so a mistaken upload fails immediately and
	 * cheaply, rather than after PHP has read it all into memory.
	 */
	private const MAX_BYTES = 8 * 1024 * 1024;

	public function __construct(
		IRequest $request,
		private ImportService $import,
		private ImportBatchMapper $batches,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function upload(): JSONResponse {
		$file = $this->request->getUploadedFile('file');
		$dataset = trim((string)$this->request->getParam('dataset', ''));

		if ($dataset === '') {
			return $this->refuse('Indique a qué conjunto de datos importar.');
		}
		if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return $this->refuse('No llegó ningún archivo.');
		}
		if (($file['size'] ?? 0) > self::MAX_BYTES) {
			return $this->refuse('El archivo supera los 8 MB.');
		}

		$contents = file_get_contents((string)$file['tmp_name']);
		if ($contents === false) {
			return $this->refuse('No se pudo leer el archivo subido.');
		}

		try {
			$batch = $this->import->importGeoJson(
				$contents,
				$dataset,
				trim((string)$this->request->getParam('label', '')) ?: null,
				$this->userSession->getUser()?->getUID(),
			);
		} catch (InvalidImportException|InvalidFeatureException $e) {
			// Nothing was written: the service checks the whole file first.
			return $this->refuse($e->getMessage());
		}

		return new JSONResponse($batch->toClient());
	}

	#[NoAdminRequired]
	public function batches(): JSONResponse {
		return new JSONResponse(array_map(
			static fn ($batch): array => $batch->toClient(),
			$this->batches->findAll(),
		));
	}

	private function refuse(string $message): JSONResponse {
		return new JSONResponse(['message' => $message], Http::STATUS_UNPROCESSABLE_ENTITY);
	}
}
