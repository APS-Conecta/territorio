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
 * The controller holds one decision: which status code a refusal becomes.
 *
 * Status codes only, deliberately. What a rename DOES — that the slug survives
 * it, that the seed does not put the old label back, that a blank label is
 * refused before anything is written — is asserted in the integration suite,
 * which is the only place the unique indexes and the served tree are real
 * (issue #40, Testing Decisions).
 */
final class CategoryControllerTest extends TestCase {
	private TaxonomyService&MockObject $taxonomy;
	private CategoryController $controller;

	protected function setUp(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturn('Salud comunitaria');

		$this->taxonomy = $this->createMock(TaxonomyService::class);
		$this->controller = new CategoryController($request, $this->taxonomy);
	}

	public function testARenamedCategoryComesBackAsTheWholeTree(): void {
		$tree = [['id' => 1, 'slug' => 'salud', 'label' => 'Salud comunitaria',
			'color' => '#99564e', 'subcategories' => []]];
		$this->taxonomy->method('renameCategory')->willReturn($tree);

		$response = $this->controller->rename('salud');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($tree, $response->getData());
	}

	public function testARenamedSubcategoryComesBackAsTheWholeTree(): void {
		$tree = [['id' => 1, 'slug' => 'salud', 'label' => 'Salud', 'color' => '#99564e',
			'subcategories' => [['id' => 2, 'slug' => 'cesfam', 'label' => 'CESFAM Norte']]]];
		$this->taxonomy->method('renameSubcategory')->willReturn($tree);

		$response = $this->controller->renameSubcategory('salud', 'cesfam');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($tree, $response->getData());
	}

	public function testABlankLabelIsUnprocessableNotAServerError(): void {
		$this->taxonomy->method('renameCategory')
			->willThrowException(new InvalidTaxonomyException('La capa necesita un nombre.'));

		$response = $this->controller->rename('salud');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('La capa necesita un nombre.', $response->getData()['message']);
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
