<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use APS\Common\Refuses;
use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Service\TaxonomyService;
use OCP\AppFramework\Controller;
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
	use Refuses;

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
			$this->stringParam('label'),
			$this->request->getParam('glyph') ?? '',
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
			? $this->taxonomy->renameCategory($slug, $this->stringParam('label'))
			: $this->taxonomy->setCategoryGlyph($slug, $glyph));
	}

	#[NoAdminRequired]
	public function addSubcategory(string $slug): JSONResponse {
		return $this->attempt(fn (): array => $this->taxonomy->addSubcategory(
			$slug, $this->stringParam('label'),
		));
	}

	#[NoAdminRequired]
	public function renameSubcategory(string $slug, string $subSlug): JSONResponse {
		return $this->attempt(fn (): array => $this->taxonomy->renameSubcategory(
			$slug, $subSlug, $this->stringParam('label'),
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
	 * The label door, the is_string check the import's own parameters take
	 * (#207, #241): not a cast. A `label[]=x` was `(string)`'d to the text
	 * "Array" — a Category named "Array" on a route open to every user
	 * (ADR-0005). Anything that is not a string counts as not given, and the
	 * service's own empty-name refusal is what answers, so nothing is written
	 * and the sentence is one the panel already shows.
	 *
	 * The glyph does NOT go through here: its empty string is a real thing to
	 * send (the CLEAR), so a garbage mark must be refused rather than read as
	 * not given — it reaches the service raw and `TaxonomyPolicy::glyphName()`
	 * refuses it at the type.
	 */
	private function stringParam(string $key): string {
		$value = $this->request->getParam($key, '');

		return is_string($value) ? trim($value) : '';
	}
}
