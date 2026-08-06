<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Service\BasemapConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

/**
 * The single page the map lives on.
 *
 * The basemap arrives as initial state rather than through a fetch: it is two
 * strings known before the document is written, and a request for them would
 * add a spinner, an error path and a route to no purpose. The Registry's own
 * data will need a route; this does not.
 *
 * #[NoAdminRequired] is deliberate — the map is for everyone who can open the
 * app, and most clinic staff are not administrators.
 */
class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
		private BasemapConfig $basemapConfig,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$basemap = $this->basemapConfig->get();

		$this->initialState->provideInitialState('basemap', [
			'url' => $basemap->url(),
			'attribution' => $basemap->attribution(),
			'isDefault' => $basemap->isDefault(),
		]);

		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');

		$response = new TemplateResponse(Application::APP_ID, 'index');

		// The policy is derived from the same setting the tiles come from, so
		// repointing the basemap cannot leave the map blocked by a stale host
		// (ADR-0004). Tiles are <img> loads, so img-src is the whole story.
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedImageDomain($basemap->cspHost());
		$response->setContentSecurityPolicy($csp);

		return $response;
	}
}
