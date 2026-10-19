<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Domain\Comuna;
use OCA\Territorio\Domain\ComunaExtent;
use OCA\Territorio\Service\ComunaConfig;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\TaxonomyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\FeaturePolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

/**
 * The single page the map lives on.
 *
 * The Comuna arrives as initial state rather than through a fetch: it is a few
 * values known before the document is written, and a request for them would add
 * a spinner, an error path and a route to no purpose. The Registry's own data
 * will need a route; this does not.
 *
 * #[NoAdminRequired] is deliberate — the map is for everyone who can open the
 * app, and most clinic staff are not administrators.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
		private ComunaConfig $comunaConfig,
		private TaxonomyService $taxonomy,
		private FeatureService $features,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$this->initialState->provideInitialState('comuna', self::comunaState($this->comunaConfig->get()));
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
		$response->setContentSecurityPolicy(self::contentSecurityPolicy());

		// Its sibling, attached the way core attaches the two together: the page
		// grants geolocation to itself, or «¿Dónde estoy?» is refused before the
		// browser ever asks. See geolocationPolicy().
		$response->setFeaturePolicy(self::geolocationPolicy());

		return $response;
	}

	/**
	 * The one origin the basemap's tiles come from (ADR-0021). The JavaScript names
	 * the same host in `OSM_TILE_URL` (`src/basemap.js`); the two languages cannot
	 * share a constant, so PageControllerTest compares them.
	 */
	public const BASEMAP_HOST = 'https://tile.openstreetmap.org';

	/**
	 * The page's Content-Security-Policy: Nextcloud's own, plus the one host the
	 * basemap's images come from.
	 *
	 * Tiles are <img> loads, so the host goes in `img-src` and nowhere else. A
	 * host named in the wrong directive reads as though it allowed the map and
	 * leaves it blank, and nothing is fetched cross-origin any more, so there is
	 * no `connect-src` domain to add.
	 *
	 * Public and static for the same reason as geolocationPolicy().
	 */
	public static function contentSecurityPolicy(): ContentSecurityPolicy {
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedImageDomain(self::BASEMAP_HOST);

		return $policy;
	}

	/**
	 * The policy that lets «¿Dónde estoy?» ask the browser at all.
	 *
	 * Nextcloud attaches a bare FeaturePolicy to every TemplateResponse, and a
	 * bare one builds `geolocation 'none'`: measured on NC 34, every click on
	 * the control failed with code 1 — permission granted or not — before the
	 * browser's own permission was consulted, and three green suites never saw
	 * it. This page grants geolocation to itself, and only to itself.
	 *
	 * `FeaturePolicy`, not `EmptyFeaturePolicy`. Core's FeaturePolicyMiddleware
	 * merges the response's policy into its defaults UNLESS the class is exactly
	 * EmptyFeaturePolicy, which it returns untouched — so an empty one here would
	 * read as this grant and grant nothing. The idea is nextcloud/maps'
	 * (maps@0cc19b0:lib/Listener/AddFeaturePolicyListener.php:27-28), which
	 * contributes the same domain through an event; a listener is the wrong size
	 * for one page, so it lives here, beside the CSP it belongs with.
	 *
	 * Public and static so `make test` can assert it without rendering the
	 * page: index() calls Util::addScript, which wants a running Nextcloud.
	 */
	public static function geolocationPolicy(): FeaturePolicy {
		$policy = new FeaturePolicy();
		$policy->addAllowedGeoLocationDomain("'self'");

		return $policy;
	}

	/**
	 * The `comuna` initial state: which Comuna this install serves, and the box
	 * the map opens on (ADR-0021).
	 *
	 * Only the chosen Comuna's own box is looked up and sent, never the table:
	 * it is a few hundred rows the browser has no use for. `extent` is null when
	 * none is chosen or the table has no box for the CUT, and the client then
	 * opens on the next source. It is read from the Comuna passed in, not from
	 * ComunaConfig again, so the name and the box cannot come from two reads of
	 * a setting that changed between them.
	 *
	 * Public and static for the same reason as geolocationPolicy().
	 *
	 * @return array{cut: string, name: string, chosen: bool, extent: ?array{array{float, float}, array{float, float}}}
	 */
	public static function comunaState(Comuna $comuna): array {
		return array_merge($comuna->jsonSerialize(), [
			'extent' => ComunaExtent::forCut($comuna->cut)?->jsonSerialize(),
		]);
	}
}
