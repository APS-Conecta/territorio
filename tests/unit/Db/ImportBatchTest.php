<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Db;

use OCA\Territorio\Db\ImportBatch;
use PHPUnit\Framework\TestCase;

/**
 * The first import of a fresh instance reads the cursor at 0. Entity::setter returns
 * early when the new value === the current one, so a property initialised to 0 is never
 * marked updated and QBMapper::insert omits the column — and territorio_import
 * .revision_from is NOT NULL with no default, unlike its six sibling counters, so the
 * INSERT fails and no retry can ever succeed: only a write elsewhere moves the cursor.
 *
 * The integration suite cannot catch this — it truncates territorio_feature and
 * territorio_import but never the monotonic territorio_cursor, so it has never run at 0.
 */
final class ImportBatchTest extends TestCase {
	private string $zone;

	/** Nextcloud forces the process to UTC (base.php), and so does this test. */
	protected function setUp(): void {
		$this->zone = date_default_timezone_get();
		date_default_timezone_set('UTC');
	}

	protected function tearDown(): void {
		date_default_timezone_set($this->zone);
	}

	public function testTheRevisionRangeIsWrittenEvenWhenTheCursorIsStillZero(): void {
		$batch = new ImportBatch();
		$batch->setRevisionFrom(0);
		$batch->setRevisionTo(0);

		$updated = $batch->getUpdatedFields();
		self::assertArrayHasKey('revisionFrom', $updated);
		self::assertArrayHasKey('revisionTo', $updated);
	}

	/**
	 * `started_at` holds UTC wall-clock time with no zone, so the browser needs
	 * the offset spelt out or it reads the time as its own (#211).
	 */
	public function testTheStartTimeLeavesWithAnExplicitUtcOffset(): void {
		$batch = ImportBatch::fromRow(['started_at' => '2026-10-27 17:30:00']);

		self::assertSame('2026-10-27T17:30:00+00:00', $batch->toClient()['startedAt']);
	}
}
