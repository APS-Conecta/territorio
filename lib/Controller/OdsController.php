<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use APS\Common\Refuses;
use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Service\ImportFiles;
use OCA\Territorio\Service\OdsImportService;
use OCA\Territorio\Service\OdsWorkbook;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;

/** Session-bound previews prevent committing a different file from the one reviewed. */
class OdsController extends Controller {
	use Refuses;
	private const PREVIEW = 'territorio.ods.preview';

	public function __construct(
		IRequest $request,
		private OdsImportService $ods,
		private ImportFiles $files,
		private ISession $session,
		private IUserSession $user,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function template(): DataDownloadResponse {
		return new DataDownloadResponse($this->ods->template(), 'plantilla-activos-comunitarios.ods', OdsWorkbook::MIME);
	}

	#[NoAdminRequired]
	public function preview(): JSONResponse {
		return $this->attempt(function (): array {
			$dataset = $this->textParam('dataset');
			$path = $this->textParam('path');
			[$bytes] = $path === '' ? $this->files->uploadedFile() : $this->files->fileInNextcloud($path);
			$preview = $this->ods->preview($bytes, $dataset);
			return $this->savePreview(['bytes' => $bytes, 'dataset' => $dataset, 'corrections' => []], $preview);
		});
	}

	#[NoAdminRequired]
	public function revise(): JSONResponse {
		return $this->attempt(function (): array {
			$stored = $this->storedPreview();
			$row = $this->request->getParam('row');
			$subcategory = $this->request->getParam('subcategoryId');
			if (!is_int($row) || !is_int($subcategory)) {
				throw new InvalidImportException('Elija una clasificación para la fila indicada.');
			}
			$stored['corrections'][$row] = $subcategory;
			$preview = $this->ods->preview($stored['bytes'], $stored['dataset'], $stored['corrections']);
			return $this->savePreview($stored, $preview);
		});
	}

	#[NoAdminRequired]
	public function commit(): JSONResponse {
		return $this->attempt(function (): array {
			$preview = $this->storedPreview();
			$decisions = $this->request->getParam('decisions');
			if (!is_array($decisions)) {
				throw new InvalidImportException('Falta la selección de filas a importar.');
			}
			$result = $this->ods->commit($preview['bytes'], $preview['dataset'], $preview['revision'], $decisions,
				$this->user->getUser()?->getUID(), $preview['taxonomySignature'], $preview['corrections']);
			$this->session->remove(self::PREVIEW);
			return $result->toClient();
		});
	}

	private function storedPreview(): array {
		$preview = $this->session->get(self::PREVIEW);
		if (!is_array($preview) || !hash_equals($preview['token'], $this->textParam('token')) || $preview['expires'] < time()) {
			throw new InvalidImportException('La revisión expiró o cambió. Vuelva a revisar la planilla.');
		}
		return $preview;
	}

	private function savePreview(array $stored, array $preview): array {
		$stored['token'] = bin2hex(random_bytes(24));
		$stored['revision'] = $preview['revision'];
		$stored['taxonomySignature'] = $preview['taxonomySignature'];
		$stored['expires'] = time() + 1800;
		$this->session->set(self::PREVIEW, $stored);
		return $preview + ['token' => $stored['token']];
	}

	private function textParam(string $key): string {
		$value = $this->request->getParam($key, '');
		return is_string($value) ? trim($value) : '';
	}
}
