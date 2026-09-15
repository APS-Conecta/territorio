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

	/**
	 * Add a Category (#45), with the pictogram picked for it (#46).
	 *
	 * The label and the mark, and nothing else: the app derives the slug and
	 * ASSIGNS the colour (ADR-0017), so there is no body key by which either
	 * could be chosen. An empty `glyph` is the ordinary case — a Category added
	 * without one draws the neutral fallback.
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->attempt(fn (): array => $this->taxonomy->addCategory(
			(string)($this->request->getParam('label') ?? ''),
			(string)($this->request->getParam('glyph') ?? ''),
		));
	}

	/**
	 * Rename a Category (#41), or choose the pictogram it is drawn with (#46).
	 *
	 * One route, because both edit the same Category, and the body says which:
	 * a name is typed into the row's field and a mark is clicked out of a grid,
	 * and neither screen sends the other's key. Branching on PRESENCE and not on
	 * emptiness — an empty `glyph` is how the choice is cleared, and reading it
	 * as "no glyph sent" would fall through to the rename and refuse a blank
	 * label about a name nobody was typing.
	 *
	 * A body carrying both keys therefore writes the pictogram and discards the
	 * rename. Neither screen sends both, but this route is open to every user
	 * (ADR-0005) and a silent discard answered 200 is the worst way to say so —
	 * `testABodyCarryingBothKeysWritesTheGlyphAndLeavesTheLabelAlone()` pins it,
	 * and appinfo/routes.php says it where a caller looks first.
	 */
	#[NoAdminRequired]
	public function update(string $slug): JSONResponse {
		$glyph = $this->request->getParam('glyph');

		return $this->attempt(fn (): array => $glyph === null
			? $this->taxonomy->renameCategory($slug, (string)($this->request->getParam('label') ?? ''))
			: $this->taxonomy->setCategoryGlyph($slug, (string)$glyph));
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
	 * Remove a Category the clinic does not use (#47).
	 *
	 * No body and no query parameter — in particular no `?force`. ADR-0016
	 * decided that a Category holding records is refused rather than emptied or
	 * retired alongside, so there is nothing here for a caller to choose.
	 */
	#[NoAdminRequired]
	public function destroy(string $slug): JSONResponse {
		return $this->attempt(fn (): array => $this->taxonomy->removeCategory($slug));
	}

	#[NoAdminRequired]
	public function destroySubcategory(string $slug, string $subSlug): JSONResponse {
		return $this->attempt(fn (): array => $this->taxonomy->removeSubcategory($slug, $subSlug));
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
