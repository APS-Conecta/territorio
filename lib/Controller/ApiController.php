<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Service\RegistryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * The Registry over OCS — territorio's published surface (L0-05, ADR-0020:
 * REST is the common published surface for cross-app reads; injection stays
 * for in-instance transactional calls). farmacia's ApiController shape: the
 * OCS envelope, ApiRoute attributes, rate-limited reads, no admin required.
 *
 * Reads only: this surface exists so a consumer off the instance (or a
 * sibling app that prefers the wire) can ask what the Registry holds.
 * Writes stay on the app's own routes — a change is a person's act, made
 * through the screens that show what is changing.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class ApiController extends OCSController {
	/** Reads: a ceiling against a runaway consumer, not a quota — a page
	 *  spends one or two of these, not sixty. */
	private const READ_LIMIT = 60;

	private const PERIOD = 60;

	public function __construct(
		IRequest $request,
		private RegistryService $registry,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * How many records the Registry holds, and where they are filed
	 *
	 * @return DataResponse<Http::STATUS_OK, array{total: int, unknownSubcategory: int, categories: array<string, array{label: string, total: int, subcategories: array<string, int>}>, bySector: array<string, int>, byUnidadVecinal: array<string, int>}, array{}>
	 * 200: Counts by Category and Subcategory (slug-keyed), plus stored Sector/Unidad Vecinal assignments (boundary-id-keyed — JSON object keys are strings)
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/counts')]
	public function counts(): DataResponse {
		return new DataResponse($this->registry->counts());
	}

	/**
	 * One record as the Registry currently shows it
	 *
	 * @param int $id the record's id
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_NOT_FOUND, array<string, mixed>, array{}>
	 * 200: One record as a caller wants it: slugs, not ids
	 * 404: No such record in the Registry — retired records answer 404, the honest answer to "show me this"
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/features/{id}')]
	public function find(int $id): DataResponse {
		$feature = $this->registry->find($id);

		return $feature === null
			? new DataResponse([], Http::STATUS_NOT_FOUND)
			: new DataResponse($feature);
	}

	/**
	 * Every record filed under one Subcategory, named by the pair a caller
	 * would say: "organizacion_social", "taller". The whole comuna's worth,
	 * deliberately unpaged — one document, the same budget the list itself
	 * spends
	 *
	 * @param string $category a Category slug, the stable name
	 * @param string $subcategory a Subcategory slug within it
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_NOT_FOUND, list<array<string, mixed>>, array{}>
	 * 200: Every record filed under the pair ([] = understood, nothing filed under it)
	 * 404: No such pair — distinct from empty, per both siblings' unknown-address discipline
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/subcategories/{category}/{subcategory}/features')]
	public function inSubcategory(string $category, string $subcategory): DataResponse {
		$found = $this->registry->inSubcategory($category, $subcategory);

		return $found === null
			? new DataResponse([], Http::STATUS_NOT_FOUND)
			: new DataResponse($found);
	}
}
