<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Service\BasemapConfig;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\TaxonomyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCA\Territorio\Service\Basemap;
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
#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
		private BasemapConfig $basemapConfig,
		private TaxonomyService $taxonomy,
		private FeatureService $features,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$basemap = $this->basemapConfig->get();

		$this->initialState->provideInitialState('basemap', $basemap->jsonSerialize());
		$this->initialState->provideInitialState('taxonomy', $this->taxonomy->tree());
		// The cursor is read BEFORE the Features, and the order matters. Read
		// after, a change landing between the two reads is missing from the list
		// but already counted in the cursor, so the client never asks for it and
		// stays stale forever. Read before, that same change is simply delivered
		// again by the first poll, and merging a record twice is harmless.
		$this->initialState->provideInitialState('revision', $this->features->currentRevision());
		$this->initialState->provideInitialState('features', array_map(
			static fn ($feature): array => $feature->toClient(),
			$this->features->listAll(),
		));

		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');

		$response = new TemplateResponse(Application::APP_ID, 'index');

		// The policy is derived from the same setting the basemap comes from, so
		// repointing it cannot leave the map blocked by a stale host (ADR-0004).
		//
		// WHICH directive follows from how the browser fetches it, and getting
		// that wrong fails silently in the worst way: the host is named, the
		// policy reads as though it allowed the basemap, and the map is blank.
		// Raster tiles are <img> loads — `img-src`. A PMTiles archive is read
		// with `fetch` and Range requests — `connect-src`.
		$csp = new ContentSecurityPolicy();
		if ($basemap->kind() === Basemap::KIND_PMTILES) {
			$csp->addAllowedConnectDomain($basemap->cspHost());
		} else {
			$csp->addAllowedImageDomain($basemap->cspHost());
		}
		$response->setContentSecurityPolicy($csp);

		return $response;
	}
}
