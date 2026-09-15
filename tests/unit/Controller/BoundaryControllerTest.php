<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Controller;

use OCA\Territorio\Controller\BoundaryController;
use OCA\Territorio\Service\BoundaryService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The editor asks which of the ids its draft already holds are still live
 * Boundary features (issue #51), and those ids arrive as a string off the query
 * string.
 *
 * That is a trust boundary, so it is tested like one. The rule is the same one
 * `FeatureService::limit()` applies to a Limit part's `featureId` — whole
 * numbers only, because `is_numeric()` would take "12.9" and cast it to 12, an
 * id pointing at a different feature from the one that was asked about — and the
 * cap is `FeatureService::LIMIT_PARTS`, because a Limit cannot hold more parts
 * than that in the first place.
 */
final class BoundaryControllerTest extends TestCase {
	private BoundaryService&MockObject $boundaries;
	private BoundaryController $controller;

	protected function setUp(): void {
		$this->boundaries = $this->createMock(BoundaryService::class);
		$this->controller = new BoundaryController(
			$this->createStub(IRequest::class),
			$this->boundaries,
		);
	}

	public function testOnlyWholeNumbersReachTheService(): void {
		$this->boundaries->expects(self::once())
			->method('inView')
			->with(-33.54, -70.61, -33.51, -70.57, [101, 102])
			->willReturn(['features' => [], 'gone' => []]);

		$response = $this->controller->inView('-33.54', '-70.61', '-33.51', '-70.57', 'abc,12.9,101,,102');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/** A Limit holds at most LIMIT_PARTS parts, so no honest client sends more. */
	public function testMoreIdsThanALimitCanHoldAreCutOff(): void {
		$this->boundaries->expects(self::once())
			->method('inView')
			->with(
				self::anything(), self::anything(), self::anything(), self::anything(),
				self::callback(static fn (array $ids): bool => count($ids) === 500),
			)
			->willReturn(['features' => [], 'gone' => []]);

		$this->controller->inView(
			'-33.54', '-70.61', '-33.51', '-70.57',
			implode(',', range(1, 600)),
		);
	}

	/** Asking about nothing is the ordinary case: the editor opens with no draft ids. */
	public function testNoIdsAtAllIsNotAnError(): void {
		$this->boundaries->expects(self::once())
			->method('inView')
			->with(-33.54, -70.61, -33.51, -70.57, [])
			->willReturn(['features' => [], 'gone' => []]);

		$response = $this->controller->inView('-33.54', '-70.61', '-33.51', '-70.57');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/** The ordinate check that was already here still refuses a box it cannot read. */
	public function testAnUnreadableBoxIsStillRefused(): void {
		$this->boundaries->expects(self::never())->method('inView');

		$response = $this->controller->inView('south', '-70.61', '-33.51', '-70.57');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}
}
