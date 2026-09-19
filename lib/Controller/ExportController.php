<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Service\ExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Taking the filtered set out of the app (issue #17, ADR-0006).
 *
 * TWO endpoints for one operation, and that is the design rather than an
 * oversight. `#[PasswordConfirmationRequired]` is an attribute on a method:
 * there is no way to apply it to some requests and not others, and a single
 * endpoint that decided for itself would put the whole protection behind an `if`
 * that a later edit can quietly invert.
 *
 * So the confirmed route is the one that may carry contact details, and the
 * plain route is the one that structurally cannot. The prompt then appears
 * exactly when it is earned — which matters, because a prompt that appears every
 * time teaches people to type their password without reading why.
 */
class ExportController extends Controller {
	use Refuses;

	public function __construct(
		IRequest $request,
		private ExportService $exports,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** No contact fields, so nothing to confirm and nothing to record. */
	#[NoAdminRequired]
	public function plain(): DataDownloadResponse|JSONResponse {
		return $this->write(false);
	}

	/**
	 * strict: true, so a confirmation from twenty minutes ago does not count.
	 * ADR-0006 asks the person to confirm THIS export, not to have confirmed
	 * something once this session.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired(strict: true)]
	public function withContacts(): DataDownloadResponse|JSONResponse {
		return $this->write(true);
	}

	/**
	 * The report (ADR-0006: "An administrator can read that list as a report").
	 *
	 * No #[NoAdminRequired], deliberately: it is that attribute which opens a
	 * route to every user, and this is the one route in the app that should not
	 * be. The log says who took personal data out of the building.
	 */
	public function history(): JSONResponse {
		return new JSONResponse($this->exports->history());
	}

	private function write(bool $contacts): DataDownloadResponse|JSONResponse {
		$ids = $this->request->getParam('ids');
		if (!is_array($ids)) {
			return $this->refuse('Falta la lista de registros a exportar.');
		}

		try {
			$file = $this->exports->export(
				$ids,
				(string)$this->request->getParam('format'),
				$contacts,
				(string)$this->request->getParam('scope', ''),
			);
		} catch (InvalidFeatureException $e) {
			return $this->refuse($e->getMessage());
		}

		return new DataDownloadResponse($file['body'], $file['filename'], $file['mime']);
	}
}
