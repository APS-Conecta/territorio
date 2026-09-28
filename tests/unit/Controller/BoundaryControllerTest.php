<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Controller;

use OCA\Territorio\Controller\BoundaryController;
use OCA\Territorio\Service\BoundaryService;
use OCA\Territorio\Service\FeatureService;
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

	/**
	 * A Limit holds at most LIMIT_PARTS parts, so no honest client sends more.
	 *
	 * The head is asserted as well as the count, because *which* ids survive the
	 * cut is the part that matters: a draft cites its parts in order, so cutting
	 * from the wrong end would answer about the last 500 of a list whose first
	 * 500 are the ones the Limit actually reads from.
	 */
	public function testMoreIdsThanALimitCanHoldAreCutOffFromTheEnd(): void {
		$cap = FeatureService::LIMIT_PARTS;

		$this->boundaries->expects(self::once())
			->method('inView')
			->with(
				self::anything(), self::anything(), self::anything(), self::anything(),
				self::callback(static fn (array $ids): bool => count($ids) === $cap
					&& $ids[0] === 1
					&& $ids[$cap - 1] === $cap),
			)
			->willReturn(['features' => [], 'gone' => []]);

		$this->controller->inView(
			'-33.54', '-70.61', '-33.51', '-70.57',
			implode(',', range(1, $cap + 100)),
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

	/** The street search's read passes the service's answer through as it is. */
	public function testNamesIsTheServicesAnswer(): void {
		$places = [
			['name' => 'Pasaje Dos', 'south' => -33.52, 'west' => -70.59, 'north' => -33.52, 'east' => -70.589, 'count' => 1],
		];
		$this->boundaries->expects(self::once())->method('names')->willReturn($places);

		$response = $this->controller->names();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($places, $response->getData());
	}

	/** No Cadastral Dataset — production today — is an empty list, not a refusal. */
	public function testNoCadastreIsAnEmptyListNotAnError(): void {
		$this->boundaries->expects(self::once())->method('names')->willReturn([]);

		$response = $this->controller->names();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());
	}
}
