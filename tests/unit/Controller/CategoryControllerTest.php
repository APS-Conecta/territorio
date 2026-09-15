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
