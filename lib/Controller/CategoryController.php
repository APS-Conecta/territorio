<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Exception\InvalidTaxonomyException;
use OCA\Territorio\Service\TaxonomyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Editing the Category tree.
 *
 * #[NoAdminRequired] for the same reason the Registry is open (ADR-0005): the
 * taxonomy is the clinic's own vocabulary, and whoever keeps the Registry is who
 * notices that a name is wrong. The admin settings are about the install, not
 * about the words.
 *
 * Thin over TaxonomyService, like FeatureController is over FeatureService: the
 * judgement lives in the service, and the one decision here is which status code
 * a refusal becomes.
 */
class CategoryController extends Controller {
	public function __construct(
		IRequest $request,
		private TaxonomyService $taxonomy,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function rename(string $slug): JSONResponse {
		return $this->attempt(fn (): array => $this->taxonomy->renameCategory(
			$slug, (string)($this->request->getParam('label') ?? ''),
		));
	}

	#[NoAdminRequired]
	public function addSubcategory(string $slug): JSONResponse {
		return $this->attempt(fn (): array => $this->taxonomy->addSubcategory(
			$slug, (string)($this->request->getParam('label') ?? ''),
		));
	}

	#[NoAdminRequired]
	public function renameSubcategory(string $slug, string $subSlug): JSONResponse {
		return $this->attempt(fn (): array => $this->taxonomy->renameSubcategory(
			$slug, $subSlug, (string)($this->request->getParam('label') ?? ''),
		));
	}

	/**
	 * A refusal is 422 and carries the reason; a slug nobody has is 404.
	 *
	 * @param callable(): array<int|string, mixed> $action
	 */
	private function attempt(callable $action): JSONResponse {
		try {
			return new JSONResponse($action());
		} catch (InvalidTaxonomyException $e) {
			// Understood and refused — the message names what to fix, and the
			// panel shows it where the person typed.
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (DoesNotExistException) {
			return new JSONResponse(['message' => 'no existe'], Http::STATUS_NOT_FOUND);
		}
	}
}
