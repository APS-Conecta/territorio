<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\ImportBatch;
use OCA\Territorio\Db\ImportBatchMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\RevertService;
use OCP\DB\Exception as DbException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A revert sweeps its boundaries once, at the end of its walk and inside its own
 * batch stamp, the way an import does (L1-13, #200).
 *
 * Swept per boundary instead, each sweep stamped a «reasignación de límite»
 * batch outside the revert's lineage. The walk then found that write on a Place
 * the undone batch had created, took it for a colleague's, and left the Place on
 * the map. RevertAssignmentTest proves the outcome against a real instance; this
 * proves the nesting, and it is the one of them CI runs.
 */
final class RevertServiceTest extends TestCase {
	private const BATCH = 10;
	private const REVERT = 11;

	/** @return array<string, array{bool}> */
	public static function walks(): array {
		return [
			'walk completes' => [false],
			// A database error, not DoesNotExistException or InvalidFeatureException:
			// undo() counts those as skipped, so they never reach the finally.
			'walk throws' => [true],
		];
	}

	#[DataProvider('walks')]
	public function testTheWalkSweepsOnceInsideItsOwnStampAndRecordsItsCounts(bool $throws): void {
		$entries = [['revision' => 100, 'featureId' => 5, 'action' => 'created', 'changes' => null]];
		if ($throws) {
			$entries[] = ['revision' => 99, 'featureId' => 6, 'action' => 'created', 'changes' => null];
		}

		$original = new ImportBatch();
		$original->setId(self::BATCH);
		$original->setDatasetId(3);

		$batches = $this->createMock(ImportBatchMapper::class);
		$batches->method('find')->willReturn($original);
		$batches->method('isUndone')->willReturn(false);
		$batches->method('findRevertOf')->willReturn(null);
		$batches->method('insert')->willReturnCallback(static function (ImportBatch $batch): ImportBatch {
			$batch->setId(self::REVERT);

			return $batch;
		});
		$recorded = null;
		$batches->expects(self::once())->method('update')->willReturnCallback(
			static function (ImportBatch $batch) use (&$recorded): ImportBatch {
				$recorded = ['updated' => $batch->getUpdated(), 'skipped' => $batch->getSkipped()];

				return $batch;
			},
		);

		// Which batch the Revisions taken right now are stamped with, and whether
		// boundary reassignment is being collected rather than swept.
		$stamp = null;
		$deferring = false;

		$revisions = $this->createMock(RevisionLog::class);
		$revisions->method('current')->willReturn(100);
		$revisions->method('forImport')->with(self::BATCH)->willReturn($entries);
		// Every entry is still the newest word on its record.
		$revisions->method('newestFor')->willReturnCallback(
			static function (int $featureId) use ($entries): array {
				foreach ($entries as $entry) {
					if ($entry['featureId'] === $featureId) {
						return ['revision' => $entry['revision'], 'importId' => self::BATCH];
					}
				}
				self::fail("no entry for feature $featureId");
			},
		);
		$revisions->method('duringImport')->willReturnCallback(
			static function (int $importId, callable $work) use (&$stamp): mixed {
				$previous = $stamp;
				$stamp = $importId;
				try {
					return $work();
				} finally {
					$stamp = $previous;
				}
			},
		);

		$live = new Feature();
		$live->setId(5);
		$features = $this->createMock(FeatureMapper::class);
		$features->method('find')->willReturnCallback(static function (int $id) use ($live): Feature {
			if ($id === 6) {
				throw new DbException('connection lost');
			}

			return $live;
		});

		$featureService = $this->createMock(FeatureService::class);
		$featureService->expects(self::once())->method('retire')->with(5)->willReturn($live);
		$featureService->method('deferringReassignments')->willReturnCallback(
			static function (callable $work) use (&$deferring): mixed {
				$previous = $deferring;
				$deferring = true;
				try {
					return $work();
				} finally {
					$deferring = $previous;
				}
			},
		);
		$flushes = [];
		$featureService->method('flushDeferredReassignments')->willReturnCallback(
			static function () use (&$stamp, &$deferring, &$flushes): void {
				$flushes[] = ['stamp' => $stamp, 'deferring' => $deferring];
			},
		);

		$service = new RevertService($batches, $revisions, $features, $featureService);

		if ($throws) {
			$this->expectException(DbException::class);
		}

		try {
			$service->revert(self::BATCH, 'tester');
		} finally {
			self::assertSame(
				[['stamp' => self::REVERT, 'deferring' => true]],
				$flushes,
				'one sweep, inside the revert\'s own stamp, with reassignment still deferred',
			);
			self::assertSame(['updated' => 1, 'skipped' => 0], $recorded, 'the counts the walk reached');
		}
	}
}
