<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Controller;

use OCA\Territorio\Controller\CategoryController;
use OCA\Territorio\Exception\InvalidTaxonomyException;
use OCA\Territorio\Service\TaxonomyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The controller does two things: hand the service the path segments and the
 * label read out of the body, in the order the service declares them, and turn
 * a refusal into a status code.
 *
 * Both are pinned — the arguments with `->with(...)`, as BoundaryControllerTest
 * pins the ids it hands BoundaryService — because nothing else exercises the
 * controller: the integration suite calls TaxonomyService directly, so a
 * swapped `$slug`/`$subSlug` or a body key renamed away from `label` would stay
 * green through every suite and only surface in the browser.
 *
 * What a rename DOES — that the slug survives it, that the seed does not put
 * the old label back, that a blank label is refused before anything is written
 * — stays in the integration suite, the only place the unique indexes and the
 * served tree are real (issue #40, Testing Decisions).
 */
final class CategoryControllerTest extends TestCase {
	private TaxonomyService&MockObject $taxonomy;
	private CategoryController $controller;

	protected function setUp(): void {
		$this->body();
	}

	/**
	 * Rebuild the controller over a body holding exactly these keys.
	 *
	 * A map and not a fixed return, because the PUT now carries two keys that are
	 * never sent together (#46): a name is typed into the row's field and a mark
	 * is clicked out of a grid. Which key is ABSENT is the whole of the routing
	 * decision, so a stub that answered every `getParam()` with the same string
	 * could not express the cases being asserted — and a key this controller has
	 * no business reading comes back null, which is how a body key renamed away
	 * from `label` or `glyph` is caught here rather than in the browser.
	 *
	 * @param array<string, mixed> $params
	 */
	private function body(array $params = ['label' => 'Salud comunitaria']): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default,
		);

		$this->taxonomy = $this->createMock(TaxonomyService::class);
		$this->controller = new CategoryController($request, $this->taxonomy);
	}

	public function testARenamedCategoryComesBackAsTheWholeTree(): void {
		$tree = [['id' => 1, 'slug' => 'salud', 'label' => 'Salud comunitaria',
			'color' => '#99564e', 'subcategories' => []]];
		$this->taxonomy->expects(self::once())
			->method('renameCategory')
			->with('salud', 'Salud comunitaria')
			->willReturn($tree);

		$response = $this->controller->update('salud');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($tree, $response->getData());
	}

	/**
	 * Two path segments, and `renameSubcategory(string $categorySlug, string
	 * $slug, string $label)` reads them in that order — the Category first, the
	 * Subcategory second. Swapping them renames nothing and refuses nothing: it
	 * looks for a Category called «cesfam», so the pin is on the order, not just
	 * on the values.
	 */
	public function testARenamedSubcategoryComesBackAsTheWholeTree(): void {
		$tree = [['id' => 1, 'slug' => 'salud', 'label' => 'Salud', 'color' => '#99564e',
			'subcategories' => [['id' => 2, 'slug' => 'cesfam', 'label' => 'CESFAM Norte']]]];
		$this->taxonomy->expects(self::once())
			->method('renameSubcategory')
			->with('salud', 'cesfam', 'Salud comunitaria')
			->willReturn($tree);

		$response = $this->controller->renameSubcategory('salud', 'cesfam');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($tree, $response->getData());
	}

	/**
	 * One path segment and the label out of the body, in the order
	 * `addSubcategory(string $categorySlug, string $label)` declares them.
	 *
	 * 200 and not 201: the answer is not the Subcategory that was created, it is
	 * the whole served tree — the same body a rename answers with, because it is
	 * the thing the browser swaps in.
	 */
	public function testAnAddedSubcategoryComesBackAsTheWholeTree(): void {
		$tree = [['id' => 1, 'slug' => 'salud', 'label' => 'Salud', 'color' => '#99564e',
			'subcategories' => [['id' => 9, 'slug' => 'salud_comunitaria', 'label' => 'Salud comunitaria']]]];
		$this->taxonomy->expects(self::once())
			->method('addSubcategory')
			->with('salud', 'Salud comunitaria')
			->willReturn($tree);

		$response = $this->controller->addSubcategory('salud');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($tree, $response->getData());
	}

	/**
	 * Adding a Category takes the label out of the body and nothing else (#45).
	 *
	 * No slug and no colour go up: the app derives the one and assigns the other
	 * (ADR-0017), so a body key for either is a path by which a person could
	 * choose a colour no gate ever measured. The `->with()` below pins that no
	 * slug or colour key is read; the mark beside it is pinned in
	 * `testAnAddedCategoryCarriesTheMarkPickedWithIt()`.
	 *
	 * 200 and the whole served tree, like every other write on this controller —
	 * it is what the browser swaps in.
	 */
	public function testAnAddedCategoryComesBackAsTheWholeTree(): void {
		$tree = [['id' => 12, 'slug' => 'salud_comunitaria', 'label' => 'Salud comunitaria',
			'color' => '#c27b72', 'subcategories' => []]];
		$this->taxonomy->expects(self::once())
			->method('addCategory')
			->with('Salud comunitaria')
			->willReturn($tree);

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($tree, $response->getData());
	}

	/**
	 * A Category whose name is already taken is refused with a sentence, not a
	 * 500. The rule itself is `terr_cat_slug_idx` and only the integration suite
	 * can exercise it; this pins that its refusal reaches the panel as a 422.
	 */
	public function testARefusalFromTheCreatePathIsUnprocessableNotAServerError(): void {
		$this->taxonomy->method('addCategory')
			->willThrowException(new InvalidTaxonomyException('Ya existe una categoría con ese nombre.'));

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertStringContainsString('Ya existe', $response->getData()['message']);
	}

	/**
	 * #241: `label[]=x` was `(string)`'d to "Array", so an open route could name
	 * a Category "Array". A label that is not text now counts as not given —
	 * the is_string move the import's own parameters made (#207) — so the
	 * service hears '' and answers with its own empty-name sentence as a 422,
	 * before any write.
	 */
	public function testAListForALabelIsReadAsNotGivenAndRefusedByTheService(): void {
		$this->body(['label' => ['x']]);
		$this->taxonomy->expects(self::once())
			->method('addCategory')
			->with('', '')
			->willThrowException(new InvalidTaxonomyException('El nombre no puede quedar vacío.'));

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('El nombre no puede quedar vacío.', $response->getData()['message']);
	}

	/**
	 * #241: the mark goes to the service RAW — `(string)` here turned `false`
	 * into '' (which the service reads as "clear the choice") and a list into
	 * "Array" (which its shape check then accepted). glyphName() refuses what
	 * is not text, pinned in TaxonomyGlyphTest; this pins the passing and the
	 * 422, and that a garbage mark is not misread as no mark and answered with
	 * a refusal about a name nobody was typing.
	 */
	public function testABoolForTheGlyphReachesTheServiceRawAndItsRefusalIsA422(): void {
		$this->body(['glyph' => false]);
		$this->taxonomy->expects(self::once())
			->method('setCategoryGlyph')
			->with('salud', false)
			->willThrowException(new InvalidTaxonomyException('Un campo de texto recibió algo que no es texto.'));
		$this->taxonomy->expects(self::never())->method('renameCategory');

		$response = $this->controller->update('salud');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('Un campo de texto recibió algo que no es texto.', $response->getData()['message']);
	}

	/** #241: the Subcategory labels read through the same door as the
	 *  Category's own. */
	public function testAListForASubcategoryLabelIsReadAsNotGiven(): void {
		$this->body(['label' => ['x']]);
		$this->taxonomy->expects(self::once())
			->method('addSubcategory')
			->with('salud', '')
			->willThrowException(new InvalidTaxonomyException('El nombre no puede quedar vacío.'));

		$response = $this->controller->addSubcategory('salud');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	/**
	 * The add path has to go through the same `attempt()` as the renames: a
	 * refusal is something a person can act on, and a 500 says nothing.
	 *
	 * This pins the mapping only — the service is stubbed, so nothing here
	 * exercises the duplicate rule itself. That rule is the `(category_id, slug)`
	 * index becoming an `InvalidTaxonomyException`, and its only test is the
	 * integration one (`CategoryCrudTest::testASecondSubcategoryWithTheSameName…`),
	 * which needs a real database to mean anything.
	 */
	public function testARefusalFromTheAddPathIsUnprocessableNotAServerError(): void {
		$this->taxonomy->method('addSubcategory')
			->willThrowException(new InvalidTaxonomyException('Ya existe una subcategoría con ese nombre en esta categoría.'));

		$response = $this->controller->addSubcategory('salud');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertStringContainsString('Ya existe', $response->getData()['message']);
	}

	public function testABlankLabelIsUnprocessableNotAServerError(): void {
		$this->taxonomy->method('renameCategory')
			->willThrowException(new InvalidTaxonomyException('El nombre no puede quedar vacío.'));

		$response = $this->controller->update('salud');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('El nombre no puede quedar vacío.', $response->getData()['message']);
	}

	/**
	 * Removing a Category is a DELETE on the row, and it answers with the whole
	 * served tree like every other write here — it is what the browser swaps in,
	 * and it is how the removed Category leaves the Capas panel, the Subcategoría
	 * picker and Análisis at once with no reload (#47).
	 *
	 * The `->with()` is the pin: nothing else in any suite exercises this
	 * controller, so a slug read from the wrong place would stay green everywhere
	 * and remove the wrong Category in the browser.
	 */
	public function testARemovedCategoryComesBackAsTheWholeTree(): void {
		$tree = [['id' => 1, 'slug' => 'salud', 'label' => 'Salud', 'color' => '#99564e',
			'subcategories' => []]];
		$this->taxonomy->expects(self::once())
			->method('removeCategory')
			->with('comite_de_vivienda')
			->willReturn($tree);

		$response = $this->controller->destroy('comite_de_vivienda');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($tree, $response->getData());
	}

	/**
	 * Two path segments, the Category first — the order
	 * `removeSubcategory(string $categorySlug, string $slug)` declares them.
	 * Swapping them removes nothing and refuses nothing: it looks for a Category
	 * called «cesfam», so the pin is on the order and not only on the values.
	 */
	public function testARemovedSubcategoryComesBackAsTheWholeTree(): void {
		$tree = [['id' => 1, 'slug' => 'salud', 'label' => 'Salud', 'color' => '#99564e',
			'subcategories' => []]];
		$this->taxonomy->expects(self::once())
			->method('removeSubcategory')
			->with('salud', 'cesfam')
			->willReturn($tree);

		$response = $this->controller->destroySubcategory('salud', 'cesfam');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($tree, $response->getData());
	}

	/**
	 * ADR-0016's refusal has to reach the panel as a sentence a person can act
	 * on. 422 and not 409: no controller in this app answers 409, and the panel
	 * reads `message` off the body of a 422 exactly as it does for a rename.
	 */
	public function testARefusalToRemoveIsUnprocessableNotAServerError(): void {
		$this->taxonomy->method('removeCategory')->willThrowException(new InvalidTaxonomyException(
			'No se puede quitar «Salud»: hay 12 registros clasificados ahí, incluidos los retirados.',
		));

		$response = $this->controller->destroy('salud');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertStringContainsString('12', $response->getData()['message']);
	}

	/** Same mapping for a Subcategory: one rule, one `attempt()`. */
	public function testARefusalToRemoveASubcategoryIsUnprocessableToo(): void {
		$this->taxonomy->method('removeSubcategory')->willThrowException(
			new InvalidTaxonomyException('No se puede quitar «Sector»: la división territorial…'),
		);

		$response = $this->controller->destroySubcategory('division_territorial', 'sector');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertStringContainsString('Sector', $response->getData()['message']);
	}

	public function testRemovingSomethingThatIsGoneIsNotFound(): void {
		$this->taxonomy->method('removeCategory')
			->willThrowException(new DoesNotExistException('gone'));

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->destroy('no-existe')->getStatus(),
		);
	}

	public function testRenamingSomethingThatIsGoneIsNotFound(): void {
		$this->taxonomy->method('renameCategory')
			->willThrowException(new DoesNotExistException('gone'));

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->update('no-existe')->getStatus(),
		);
	}

	public function testRenamingASubcategoryOfSomethingThatIsGoneIsAlsoNotFound(): void {
		$this->taxonomy->method('renameSubcategory')
			->willThrowException(new DoesNotExistException('gone'));

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->renameSubcategory('salud', 'no-existe')->getStatus(),
		);
	}

	/**
	 * Issue #46: the pictogram goes up on the same PUT the rename uses, and the
	 * controller acts on the key that is actually in the body.
	 *
	 * The `->with()` is the pin that matters. A body carrying `glyph` and no
	 * `label` used to read the missing label as '' and refuse the whole request
	 * for being blank — so choosing a mark would have answered «El nombre no
	 * puede quedar vacío.» about a name nobody was typing.
	 */
	public function testChoosingAPictogramSetsItAndComesBackAsTheWholeTree(): void {
		$this->body(['glyph' => 'bakery']);
		$tree = [['id' => 12, 'slug' => 'comite_de_vivienda', 'label' => 'Comité de vivienda',
			'color' => '#c27b72', 'glyph' => 'bakery', 'subcategories' => []]];
		$this->taxonomy->expects(self::once())
			->method('setCategoryGlyph')
			->with('comite_de_vivienda', 'bakery')
			->willReturn($tree);
		$this->taxonomy->expects(self::never())->method('renameCategory');

		$response = $this->controller->update('comite_de_vivienda');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($tree, $response->getData());
	}

	/**
	 * Clearing the choice is an empty `glyph`, and it must still reach
	 * `setCategoryGlyph()` — read as "no glyph key", it would fall through to the
	 * rename and refuse a blank label instead of putting the mark back.
	 */
	public function testClearingThePictogramIsStillAPictogramWrite(): void {
		$this->body(['glyph' => '']);
		$this->taxonomy->expects(self::once())
			->method('setCategoryGlyph')
			->with('comite_de_vivienda', '')
			->willReturn([]);

		self::assertSame(
			Http::STATUS_OK,
			$this->controller->update('comite_de_vivienda')->getStatus(),
		);
	}

	/**
	 * Both keys at once: the pictogram is written and the rename is discarded.
	 *
	 * Neither screen sends both — the field and the grid are never open at the
	 * same moment — so this pins a rule rather than a behaviour anybody relies
	 * on. It is pinned because `/categories/{slug}` is open to every user
	 * (ADR-0005) and the answer is a 200: a caller that sent both would be told
	 * the rename succeeded when nothing read `label` at all. The rule is stated
	 * in appinfo/routes.php as well, which is where a caller looks before the
	 * controller's branch.
	 */
	public function testABodyCarryingBothKeysWritesTheGlyphAndLeavesTheLabelAlone(): void {
		$this->body(['label' => 'Nombre que se descarta', 'glyph' => 'bakery']);
		$this->taxonomy->expects(self::once())
			->method('setCategoryGlyph')
			->with('comite_de_vivienda', 'bakery')
			->willReturn([]);
		$this->taxonomy->expects(self::never())->method('renameCategory');

		self::assertSame(
			Http::STATUS_OK,
			$this->controller->update('comite_de_vivienda')->getStatus(),
		);
	}

	/** A body with only a label is still a rename, exactly as #41 left it. */
	public function testABodyWithOnlyALabelIsStillARename(): void {
		$this->taxonomy->expects(self::once())
			->method('renameCategory')
			->with('salud', 'Salud comunitaria')
			->willReturn([]);
		$this->taxonomy->expects(self::never())->method('setCategoryGlyph');

		self::assertSame(Http::STATUS_OK, $this->controller->update('salud')->getStatus());
	}

	/**
	 * A mark is picked when the Category is created too, so it goes up on the
	 * POST beside the label — in the order `addCategory(string $label, string
	 * $glyph)` declares them. Swapping the two would file «Comité de vivienda» as
	 * a pictogram name and be refused for a shape nobody typed.
	 */
	public function testAnAddedCategoryCarriesTheMarkPickedWithIt(): void {
		$this->body(['label' => 'Comité de vivienda', 'glyph' => 'town']);
		$this->taxonomy->expects(self::once())
			->method('addCategory')
			->with('Comité de vivienda', 'town')
			->willReturn([]);

		self::assertSame(Http::STATUS_OK, $this->controller->create()->getStatus());
	}

	/** Nothing picked is not a refusal: the Category draws the neutral (ADR-0017). */
	public function testACategoryCanStillBeAddedWithNoMarkAtAll(): void {
		$this->taxonomy->expects(self::once())
			->method('addCategory')
			->with('Salud comunitaria', '')
			->willReturn([]);

		self::assertSame(Http::STATUS_OK, $this->controller->create()->getStatus());
	}

	/** A shape this app will not store is a 422 with a sentence, like every other refusal. */
	public function testAPictogramThisAppWillNotStoreIsUnprocessable(): void {
		$this->body(['glyph' => 'Park']);
		$this->taxonomy->method('setCategoryGlyph')
			->willThrowException(new InvalidTaxonomyException('Ese pictograma no existe en esta aplicación.'));

		$response = $this->controller->update('salud');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertStringContainsString('pictograma', $response->getData()['message']);
	}
}
