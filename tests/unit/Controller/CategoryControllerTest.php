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
		$request = $this->createStub(IRequest::class);
		// Pinned to 'label': asking the body for any other key is the failure
		// this stub exists to catch, since the panel sends `label` and nothing
		// else in the suite reads the body.
		$request->method('getParam')->with('label')->willReturn('Salud comunitaria');

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

		$response = $this->controller->rename('salud');

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
	 * choose a colour no gate ever measured. The `->with()` below is what says
	 * that only `label` is read.
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

		$response = $this->controller->rename('salud');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('El nombre no puede quedar vacío.', $response->getData()['message']);
	}

	public function testRenamingSomethingThatIsGoneIsNotFound(): void {
		$this->taxonomy->method('renameCategory')
			->willThrowException(new DoesNotExistException('gone'));

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->rename('no-existe')->getStatus(),
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
}
