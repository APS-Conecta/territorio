<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use DateTime;
use OCA\Territorio\Db\Duplicate;
use OCA\Territorio\Db\DuplicateMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Domain\Action;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Service\DuplicateService;
use OCA\Territorio\Service\FeatureService;
use OCP\IDBConnection;
use OCP\Server;

/**
 * What a merge, a retirement or a resolution does when the world it was decided
 * against has changed under it (#208).
 *
 * Two writers cannot be run from one PHPUnit process, so the interleaving
 * itself is not directly demonstrable here — the same honesty RevisionRaceTest
 * applies to the cursor. What IS demonstrable is the property the fix rests on:
 * every write that leaves or resolves something re-reads what it decided
 * against under a lock, so a decision made on yesterday's row is refused
 * rather than written over the first one.
 *
 * The interleaving is replayed by hand: load an entity, commit a conflicting
 * write as the "first" request, then hand the stale entity to the write the
 * "second" request would have made. A plain read cannot be made stale that way
 * in one process — but the pre-lock reads in the services were never the
 * problem; the writes were, because nothing re-checked after them.
 */
final class MergeRaceTest extends IntegrationTestCase {
	private FeatureMapper $features;
	private FeatureService $featureService;
	private DuplicateMapper $duplicates;
	private DuplicateService $service;
	private IDBConnection $db;
	private RevisionLog $revisions;

	/** @var int[] pair rows this test made, to be removed afterwards */
	private array $pairs = [];

	protected function setUp(): void {
		$this->features = Server::get(FeatureMapper::class);
		$this->featureService = Server::get(FeatureService::class);
		$this->duplicates = Server::get(DuplicateMapper::class);
		$this->service = Server::get(DuplicateService::class);
		$this->db = Server::get(IDBConnection::class);
		$this->revisions = Server::get(RevisionLog::class);
	}

	protected function tearDown(): void {
		if ($this->pairs !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('territorio_duplicate')
				->where($qb->expr()->in('id', $qb->createNamedParameter(
					$this->pairs, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY,
				)))
				->executeStatement();
			$this->pairs = [];
		}

		parent::tearDown();
	}

	/**
	 * Two live records and a PENDING pair naming them — what «Posibles
	 * duplicados» shows.
	 *
	 * The pair is recorded directly rather than found by a scan: this suite is
	 * about what happens to a decision made about a pair, and a scan would walk
	 * the whole Registry to produce a row this test can name exactly.
	 *
	 * @return array{0: int, 1: int, 2: Duplicate} the two record ids, and the pair
	 */
	private function pairOn(string $one, string $other): array {
		$first = $this->place($one);
		$second = $this->place($other);

		$pair = new Duplicate();
		$pair->setFirstId(min($first, $second));
		$pair->setSecondId(max($first, $second));
		$pair->setReason('coordenada');
		$pair->setStatus(Duplicate::PENDING);
		$pair->setFoundAt(new DateTime());
		$this->duplicates->insert($pair);
		$this->pairs[] = (int)$pair->getId();

		return [$first, $second, $pair];
	}

	/**
	 * A retirement whose record was retired under it is refused, not repeated.
	 *
	 * This is the same-direction half of the #208 race — and the case a plain
	 * re-read after the cursor lock cannot catch, because under REPEATABLE READ
	 * it would still see the old snapshot. The write itself must look.
	 */
	public function testARetireRefusesARecordRetiredUnderIt(): void {
		$id = $this->place('Estadio Municipal');
		$cursor = $this->revisions->current();

		// What the second request read before the first one committed: live.
		$stale = $this->features->find($id);

		// The first request retires it, and commits.
		$this->featureService->retire($id);

		try {
			$this->features->retire($stale);
			self::fail('retiring a record that was retired under this decision should have been refused');
		} catch (InvalidFeatureException $e) {
			self::assertSame('El registro ya estaba retirado.', $e->getMessage());
		}

		// The refusal took its Revision with it — no client may bank a cursor past
		// a change that was never written, refused or otherwise.
		self::assertSame($cursor + 1, $this->revisions->current());

		// And it left nothing behind: history is append-only, so a second
		// "Retirado" describing nothing could never be tidied away.
		self::assertSame(
			[Action::Retired->value, Action::Created->value],
			array_column($this->featureService->history($id), 'action'),
			'the refused retirement must not have been recorded',
		);
	}

	/**
	 * The same refusal for a merge whose loser was retired under it — the
	 * same-direction double merge, where nothing about the survivor is wrong and
	 * only the loser's row can say no.
	 */
	public function testAMergeRefusesALoserRetiredUnderIt(): void {
		[$first, $second] = $this->pairOn('Cine Municipal', 'Cinema Municipal');

		// What the second request read: both live.
		$stale = $this->features->find($second);

		// The first request retires the same record for a reason of its own.
		$this->featureService->retire($second);

		try {
			$this->features->merge($stale, $first);
			self::fail('merging a record that was retired under this decision should have been refused');
		} catch (InvalidFeatureException $e) {
			self::assertSame('El registro ya estaba retirado.', $e->getMessage());
		}

		// The refused merge must not have stamped a pointer onto a retirement it
		// did not make: mergedInto is a column precisely so the map can answer
		// "where did this go" from the record itself.
		$row = $this->features->find($second);
		self::assertNull($row->getMergedInto(), 'the refused merge must not have set the pointer');
		self::assertNotContains(Action::Merged->value, array_column($this->featureService->history($second), 'action'));
	}

	/**
	 * A merge whose survivor was retired under it is refused — the pointer would
	 * aim at a record nobody can see, the exact harm FeatureService::merge()
	 * documents for its own pre-lock read.
	 *
	 * This is also the guard that catches the ticket's interleaving itself: two
	 * opposite merges of one pair, where each request's survivor is the other's
	 * loser.
	 */
	public function testAMergeRefusesASurvivorRetiredUnderIt(): void {
		[$first, $second] = $this->pairOn('Gimnasio Municipal', 'Gym Municipal');

		$stale = $this->features->find($first);

		// The first request retires the SURVIVOR and commits.
		$this->featureService->retire($second);

		try {
			$this->features->merge($stale, $second);
			self::fail('merging into a survivor that was retired under this decision should have been refused');
		} catch (InvalidFeatureException $e) {
			self::assertSame(
				'El registro que se conserva está retirado; restáurelo antes de fusionar.',
				$e->getMessage(),
			);
		}

		// The loser stayed on the map, pointing nowhere.
		$row = $this->features->find($first);
		self::assertFalse($row->getRetired());
		self::assertNull($row->getMergedInto());
	}

	/**
	 * Resolving a pair that was resolved under it is refused — the queue's own
	 * decision row, decided twice (#208).
	 *
	 * A merge and a dismissal racing each other is the same race with a kinder
	 * outcome: without the refusal, the second write would overwrite the first
	 * resolution (or retire a record over a pair somebody had already said was
	 * two places).
	 */
	public function testResolvingAPairRefusesOneAlreadyResolvedUnderIt(): void {
		[, $second, $pair] = $this->pairOn('Biblioteca Municipal', 'Biblioteca Comunal');
		$pairId = (int)$pair->getId();

		// What the second request read: a pending pair.
		$stalePair = $this->duplicates->find($pairId);

		// The first request resolves it — a dismissal, the resolution that does
		// not retire anything, so only the pair row can refuse.
		$this->service->dismiss($pairId);

		$stalePair->setStatus(Duplicate::MERGED);
		$stalePair->setResolvedBy('segunda-persona');
		try {
			$this->duplicates->resolve($stalePair);
			self::fail('resolving a pair that was resolved under this decision should have been refused');
		} catch (InvalidFeatureException $e) {
			self::assertSame('Alguien ya resolvió este par.', $e->getMessage());
		}

		// The first decision stands, exactly as it was recorded.
		$row = $this->duplicates->find($pairId);
		self::assertSame(Duplicate::DISMISSED, $row->getStatus());
		self::assertNotSame('segunda-persona', $row->getResolvedBy());

		// And the pair's records are both still on the map — nothing was retired
		// over a question already answered.
		self::assertFalse($this->features->find($second)->getRetired());
	}

	/**
	 * The inverse transition, for completeness of the rule: a restore whose
	 * record was brought back under it is refused — two «Restaurar» clicks on
	 * one retired record would otherwise both log, and history is append-only.
	 */
	public function testARestoreRefusesARecordRestoredUnderIt(): void {
		$id = $this->place('Bodega Comunitaria');
		$this->featureService->retire($id);

		// What the second request read: a retired record.
		$stale = $this->features->find($id);

		// The first request brings it back, and commits.
		$this->featureService->restore($id);

		try {
			$this->features->restore($stale);
			self::fail('restoring a record that was restored under this decision should have been refused');
		} catch (InvalidFeatureException $e) {
			self::assertSame('El registro no está retirado.', $e->getMessage());
		}

		$actions = array_column($this->featureService->history($id), 'action');
		self::assertSame(
			1,
			count(array_keys($actions, Action::Restored->value, true)),
			'the refused restore must not have been recorded as well',
		);
	}

	/**
	 * The ticket's interleaving, replayed whole: two opposite merges of one
	 * pair, each holding reads from before the other committed.
	 *
	 * Request one keeps the second record and commits. Request two then replays
	 * its own write path in DuplicateService's order — retire its loser, resolve
	 * the pair — against the stale rows it had read. Without the guards, both
	 * records end up retired into each other, the pair says MERGED, and both
	 * places vanish from the map, the list and the queue (#208).
	 */
	public function testTwoOppositeMergesLeaveOneDecisionStanding(): void {
		[$a, $b, $pair] = $this->pairOn('Correos', 'Chilexpress');
		$pairId = (int)$pair->getId();

		// Request one keeps b: retires a, resolves the pair, commits.
		$this->service->merge($pairId, $b);

		// Request two keeps a — so its loser is b, its survivor is a, and a was
		// retired under the decision. Its pair, as it read it, was PENDING.
		$stalePair = clone $this->duplicates->find($pairId);
		$stalePair->setStatus(Duplicate::PENDING);
		$stalePair->setResolvedBy('segunda-persona');

		$this->db->beginTransaction();
		try {
			$this->features->merge($this->features->find($b), $a);
			$this->duplicates->resolve($stalePair);
			$this->db->commit();
			self::fail('the second, opposite merge of the pair should have been refused');
		} catch (InvalidFeatureException) {
			$this->db->rollBack();
		}

		// One decision stands, whole: a is retired into b, and only a.
		$storedA = $this->features->find($a);
		self::assertTrue($storedA->getRetired());
		self::assertSame($b, $storedA->getMergedInto());

		$storedB = $this->features->find($b);
		self::assertFalse($storedB->getRetired(), 'the second merge must not have retired the other record too');
		self::assertNull($storedB->getMergedInto());

		$row = $this->duplicates->find($pairId);
		self::assertSame(Duplicate::MERGED, $row->getStatus());
		self::assertNotSame('segunda-persona', $row->getResolvedBy());
	}

	/**
	 * The other half of the pair race: a merge whose records are both live —
	 * every feature-level guard passes — refused by the pair itself, because the
	 * question was answered differently before the write landed.
	 *
	 * This is the path that proves the transaction unwinds: the loser's
	 * retirement is written first, in DuplicateService's order, and the pair's
	 * refusal must take it back (L1-16).
	 */
	public function testAMergeRefusedByThePairRetiresNothing(): void {
		[$a, $b, $pair] = $this->pairOn('Ferretería El Clavo', 'Ferretería La Tuerca');
		$pairId = (int)$pair->getId();

		// Request one dismisses: same race, kinder records — nothing retired.
		$this->service->dismiss($pairId);

		$stalePair = clone $this->duplicates->find($pairId);
		$stalePair->setStatus(Duplicate::MERGED);
		$stalePair->setResolvedBy('segunda-persona');

		$this->db->beginTransaction();
		try {
			$this->features->merge($this->features->find($b), $a);
			$this->duplicates->resolve($stalePair);
			$this->db->commit();
			self::fail('a merge over an already-dismissed pair should have been refused');
		} catch (InvalidFeatureException) {
			$this->db->rollBack();
		}

		// The refusal took the retirement with it, and left nothing behind.
		$storedB = $this->features->find($b);
		self::assertFalse($storedB->getRetired());
		self::assertNull($storedB->getMergedInto());
		self::assertNotContains(Action::Merged->value, array_column($this->featureService->history($b), 'action'));
		self::assertSame(Duplicate::DISMISSED, $this->duplicates->find($pairId)->getStatus());
	}

	/**
	 * The services refuse before any transaction is opened, in the same words the
	 * writes refuse with — pinned here so the two doors cannot drift apart, the
	 * queue's screen showing one sentence while the race leaves another.
	 */
	public function testBothDoorsRefuseInIdenticalWords(): void {
		// The retirement of a record that is already retired.
		$id = $this->place('Sede Vecinal');
		$this->featureService->retire($id);
		$fromTheService = $this->refusalOf(fn () => $this->featureService->retire($id));
		$fromTheWrite = $this->refusalOf(fn () => $this->features->retire($this->features->find($id)));
		self::assertSame($fromTheService, $fromTheWrite);

		// A merge into a survivor that is retired.
		[$one, $other] = $this->pairOn('Sala de Arte', 'Sala De Arte');
		$this->featureService->retire($other);
		$fromTheService = $this->refusalOf(fn () => $this->featureService->merge($one, $other));
		$fromTheWrite = $this->refusalOf(fn () => $this->features->merge($this->features->find($one), $other));
		self::assertSame($fromTheService, $fromTheWrite);

		// A pair that somebody already resolved.
		[, , $pair] = $this->pairOn('Casa de la Cultura', 'Casa Cultural');
		$this->service->dismiss((int)$pair->getId());
		$fromTheService = $this->refusalOf(fn () => $this->service->dismiss((int)$pair->getId()));
		$fromTheWrite = $this->refusalOf(function () use ($pair): void {
			$again = clone $pair;
			$again->setStatus(Duplicate::DISMISSED);
			$this->duplicates->resolve($again);
		});
		self::assertSame($fromTheService, $fromTheWrite);
	}

	/** The message of the refusal the call ends in, or what went wrong instead. */
	private function refusalOf(callable $call): string {
		try {
			$call();
		} catch (InvalidFeatureException $e) {
			return $e->getMessage();
		}

		self::fail('the call should have been refused, not accepted');
	}
}
