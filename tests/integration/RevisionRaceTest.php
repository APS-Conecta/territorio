<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use BadFunctionCallException;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Service\Action;
use OCA\Territorio\Service\FeatureService;
use OCP\Server;

/**
 * The properties that make the cursor safe under concurrency.
 *
 * Two writers cannot be run from one PHPUnit process, so the row lock that
 * serialises them is not directly demonstrable here — and it is worth being
 * honest about that rather than implying otherwise. What IS demonstrable is
 * everything the lock depends on:
 *
 *   - a Revision cannot be taken outside the transaction that writes the row,
 *     so the dangerous interleaving cannot be constructed at all;
 *   - a completed write leaves no gap between the cursor and the row, so no
 *     client can bank a cursor past a change it was not given;
 *   - Revisions rise strictly, so `revision > cursor` never skips one.
 *
 * These three failed before the fix. The suite that passed alongside them tested
 * only the happy path, which is why it noticed nothing.
 */
final class RevisionRaceTest extends IntegrationTestCase {
	private FeatureService $service;
	private FeatureMapper $features;
	private RevisionLog $revisions;

	protected function setUp(): void {
		$this->service = Server::get(FeatureService::class);
		$this->features = Server::get(FeatureMapper::class);
		$this->revisions = Server::get(RevisionLog::class);
	}

	/**
	 * The interleaving that loses a change starts with a Revision becoming
	 * visible before its row. This makes that first step impossible.
	 */
	public function testARevisionCannotBeTakenOutsideAWriteTransaction(): void {
		$this->expectException(BadFunctionCallException::class);
		$this->revisions->allocate(1, Action::Updated);
	}

	/**
	 * After a write, the cursor and the row agree exactly. A cursor ahead of the
	 * row is precisely the state in which a client banks a number for a change it
	 * was never handed, and then filters that change out forever.
	 */
	public function testACompletedWriteLeavesNoGapBetweenCursorAndRow(): void {
		$id = $this->place('Sin hueco');

		self::assertSame(
			$this->revisions->current(),
			$this->features->find($id)->getRevision(),
			'the cursor must not run ahead of the row it describes',
		);
	}

	public function testRevisionsRiseStrictlyAcrossWrites(): void {
		$first = $this->place('Primera');
		$firstRevision = $this->features->find($first)->getRevision();

		$second = $this->place('Segunda');
		$secondRevision = $this->features->find($second)->getRevision();

		self::assertGreaterThan($firstRevision, $secondRevision);
	}

	/**
	 * A client that polled before either write is owed both, in order — nothing
	 * below the cursor is silently skipped.
	 */
	public function testAClientFromBeforeBothWritesReceivesBoth(): void {
		$cursor = $this->revisions->current();

		$this->place('Una');
		$this->place('Otra');

		$names = array_column($this->service->changesSince($cursor)['features'], 'name');

		self::assertContains('Una', $names);
		self::assertContains('Otra', $names);
	}

	/**
	 * A failed write must leave the cursor where it was. Otherwise every client
	 * skips past a Revision that describes nothing, and the next real change
	 * lands below their cursor.
	 */
	public function testARejectedWriteDoesNotBurnARevision(): void {
		$before = $this->revisions->current();

		try {
			$this->service->create([
				'name' => 'Inválida',
				'subcategoryId' => 999999,
				'geometry' => null,
			]);
			self::fail('an unknown subcategory should have been refused');
		} catch (\Throwable) {
			// Expected — what matters is what it left behind.
		}

		self::assertSame($before, $this->revisions->current());
	}
}
