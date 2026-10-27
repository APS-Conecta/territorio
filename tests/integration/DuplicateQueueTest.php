<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\Duplicate;
use OCA\Territorio\Db\DuplicateMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Domain\Action;
use OCA\Territorio\Service\DuplicateService;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\ImportService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The review queue end to end (issue #16).
 *
 * Every assertion here is scoped to the pairs this test made. The dev instance
 * holds a comuna's worth of imported records with real candidate pairs among
 * them, so a count of everything outstanding measures that catastro and not this
 * test — the trap that has cost three runs in this repo already.
 */
final class DuplicateQueueTest extends IntegrationTestCase {
	private DuplicateService $service;
	private DuplicateMapper $duplicates;
	private FeatureService $features;

	/** @var int[] pairs this test made, to be removed afterwards */
	private array $pairs = [];

	protected function setUp(): void {
		$this->service = Server::get(DuplicateService::class);
		$this->duplicates = Server::get(DuplicateMapper::class);
		$this->features = Server::get(FeatureService::class);
	}

	protected function tearDown(): void {
		if ($this->pairs !== []) {
			$db = Server::get(IDBConnection::class);
			$qb = $db->getQueryBuilder();
			$qb->delete('territorio_duplicate')
				->where($qb->expr()->in('id', $qb->createNamedParameter(
					$this->pairs, IQueryBuilder::PARAM_INT_ARRAY,
				)))
				->executeStatement();
			$this->pairs = [];
		}

		parent::tearDown();
	}

	/** Two records at one coordinate under two names, scanned, and the pair found. */
	private function twinsAt(string $one, string $other, array $extra = []): array
	{
		$first = $this->place($one);
		$second = $this->place($other, $extra);

		$this->service->scan();
		$pair = $this->pairOf($first, $second);
		self::assertNotNull($pair, 'the scan should have offered these two');
		$this->pairs[] = (int)$pair->getId();

		return [$first, $second, $pair];
	}

	/**
	 * Three records at one coordinate, and the three pairs among them.
	 *
	 * (a,b) is recorded first, so each record also sits on a newer pair: the
	 * shape in which a restore that took the newest Merged pair reopened the
	 * wrong one (#206). The two later pairs are looked up right after their scan,
	 * while `pairOf()` can still see them; once resolved, read them by id.
	 *
	 * @return array{int, int, int, Duplicate, Duplicate, Duplicate} a, b, c, (a,b), (a,c), (b,c)
	 */
	private function tripletsAt(string $one, string $other, string $third): array {
		[$a, $b, $ab] = $this->twinsAt($one, $other);
		$c = $this->place($third);

		$this->service->scan();
		$ac = $this->pairOf($a, $c);
		$bc = $this->pairOf($b, $c);
		self::assertNotNull($ac, 'the scan should have offered a and c');
		self::assertNotNull($bc, 'the scan should have offered b and c');
		$this->pairs[] = (int)$ac->getId();
		$this->pairs[] = (int)$bc->getId();

		return [$a, $b, $c, $ab, $ac, $bc];
	}

	/** The pair naming exactly these two, whatever else the instance holds. */
	private function pairOf(int $one, int $other): ?Duplicate {
		foreach ($this->duplicates->findPending() as $pair) {
			if ($pair->getFirstId() === min($one, $other) && $pair->getSecondId() === max($one, $other)) {
				return $pair;
			}
		}

		return null;
	}

	public function testImportFlagsAPairAndMergesNothing(): void {
		[$first, $second] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');

		// The criterion that matters most: BOTH are still there.
		$visible = array_map(static fn ($f): int => (int)$f->getId(), $this->features->listAll());
		self::assertContains($first, $visible);
		self::assertContains($second, $visible);
	}

	public function testThePairSaysWhyItWasOffered(): void {
		[,, $pair] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');
		self::assertSame('coordenada', $pair->getReason());
	}

	/**
	 * The id sequence, which moves on every insert the database is ASKED for —
	 * whether or not the row survives. `nextval` is not transactional, so a
	 * refused insert burns a value just as a successful one does, which makes this
	 * the one thing that can tell "the scan proposed nothing" from "the scan
	 * proposed everything and the index refused it".
	 */
	private function sequenceValue(): int {
		$db = Server::get(IDBConnection::class);
		$result = $db->executeQuery(
			"select pg_sequence_last_value(pg_get_serial_sequence('*PREFIX*territorio_duplicate', 'id'))",
		);
		$value = (int)$result->fetchOne();
		$result->closeCursor();

		return $value;
	}

	/**
	 * A second scan must not offer the database a pair it already holds.
	 *
	 * `testScanningTwiceDoesNotOfferTheSamePairTwice` above pins the OUTCOME, and
	 * the outcome was always right — the unique index saw to that. What it cannot
	 * see is the cost: every known pair was re-proposed on every scan and refused,
	 * and a refused insert in PostgreSQL still writes a row version for autovacuum
	 * to collect. Measured on the dev instance before this: 1,778,856 insert
	 * attempts behind 8,852 live pairs.
	 *
	 * The index still decides — two scans at once are still safe (ADR-0011). It is
	 * simply no longer asked two hundred times per row for an answer already held.
	 */
	public function testASecondScanDoesNotProposePairsTheTableAlreadyHolds(): void {
		$this->twinsAt('Balneario Municipal', 'Piscina Municipal');

		$before = $this->sequenceValue();
		// Non-zero, or this passes by comparing nothing: if the sequence could not be
		// found both reads cast to 0 and the check goes green for ever.
		self::assertGreaterThan(0, $before, 'the id sequence was not found, so nothing was measured');

		$this->service->scan();

		// Read once. `sprintf` evaluates its arguments whether or not the assertion
		// fails, so calling it again in there ran a third query on every green run
		// and reported a delta measured after the assertion's own read.
		$after = $this->sequenceValue();
		self::assertSame($before, $after, sprintf(
			'the second scan asked for %d inserts the table already held; each refusal '
			. 'is a dead tuple, and the sequence moves whether the row survives or not',
			$after - $before,
		));
	}

	public function testScanningTwiceDoesNotOfferTheSamePairTwice(): void {
		[$first, $second] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');

		$this->service->scan();

		$offered = array_filter(
			$this->duplicates->findPending(),
			static fn (Duplicate $p): bool => $p->getFirstId() === min($first, $second),
		);
		self::assertCount(1, $offered);
	}

	public function testMergingRetiresTheLoserAndPointsItAtTheSurvivor(): void {
		[$first, $second, $pair] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');

		$this->service->merge((int)$pair->getId(), $second);

		$loser = Server::get(FeatureMapper::class)->find($first);
		self::assertTrue($loser->getRetired());
		self::assertSame($second, $loser->getMergedInto());

		$survivor = Server::get(FeatureMapper::class)->find($second);
		self::assertFalse($survivor->getRetired());
		self::assertNull($survivor->getMergedInto());
	}

	/** A merge is a fact of its own in history, not a retirement with no reason. */
	public function testAMergeIsRecordedAsAMerge(): void {
		[$first, $second, $pair] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');

		$this->service->merge((int)$pair->getId(), $second);

		$actions = array_column($this->features->history($first), 'action');
		self::assertSame(Action::Merged->value, $actions[0]);
	}

	/** Reversible for free under ADR-0005: restoring is the undo. */
	public function testRestoringTheLoserUndoesTheMergeAndReopensThePair(): void {
		[$first, $second, $pair] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');

		$this->service->merge((int)$pair->getId(), $second);
		self::assertSame(Duplicate::MERGED, $this->duplicates->find((int)$pair->getId())->getStatus());

		$restored = $this->features->restore($first);

		self::assertFalse($restored->getRetired());
		// The pointer goes with the retirement: a record on the map that still
		// claimed to have been merged into another would be describing itself as
		// gone while everyone can see it.
		self::assertNull($restored->getMergedInto());
		self::assertSame(Duplicate::PENDING, $this->duplicates->find((int)$pair->getId())->getStatus());
	}

	/**
	 * The criterion says IMPORT flags them, and both formats reach the same code.
	 *
	 * Through KML rather than GeoJSON on purpose: both readers funnel into the
	 * same importRows(), and a test of the format that is already covered
	 * everywhere else would not notice if one of them stopped.
	 */
	public function testImportingAKmlFlagsThePairsInIt(): void {
		$twins = <<<'XML'
			<kml xmlns="http://www.opengis.net/kml/2.2"><Document>
			  <Folder><name>Salud</name>
			    <Placemark><name>Balneario Municipal KML</name>
			      <Point><coordinates>-70.5811,-33.5211</coordinates></Point>
			    </Placemark>
			    <Placemark><name>Piscina Municipal KML</name>
			      <Point><coordinates>-70.5811,-33.5211</coordinates></Point>
			    </Placemark>
			  </Folder>
			</Document></kml>
			XML;

		Server::get(ImportService::class)->importFile($twins, 'datos.kml', 'gemelos-kml-' . uniqid());

		$made = [];
		foreach ($this->features->listAll() as $feature) {
			if (str_ends_with($feature->getName(), 'Municipal KML')) {
				$made[] = (int)$feature->getId();
				$this->created[] = (int)$feature->getId();
			}
		}

		self::assertCount(2, $made, 'the import should have merged nothing');

		$pair = $this->pairOf($made[0], $made[1]);
		self::assertNotNull($pair, 'importing two records at one coordinate offered no pair');
		$this->pairs[] = (int)$pair->getId();
	}

	/**
	 * Restoring the SURVIVOR of a merge does not reopen the pair.
	 *
	 * A survivor can be retired later for a reason of its own, and restoring it
	 * must not put back a question whose other half is still gone — the queue
	 * would then hold a pair with one visible member, which renders as a card
	 * with one side and counts toward the number on the pane.
	 */
	public function testRestoringTheSurvivorDoesNotReopenThePair(): void {
		[, $second, $pair] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');

		$this->service->merge((int)$pair->getId(), $second);
		$this->features->retire($second);
		$this->features->restore($second);

		self::assertSame(Duplicate::MERGED, $this->duplicates->find((int)$pair->getId())->getStatus());
	}

	/**
	 * A record that won one merge and lost a later one is on two Merged pairs, and
	 * restoring it undoes only the merge it lost (#206).
	 */
	public function testRestoringALoserReopensThePairItLostNotOneItWon(): void {
		[$a, $b, , $ab, $ac] = $this->tripletsAt('Balneario Municipal', 'Piscina Municipal', 'Gimnasio Municipal');

		$this->service->merge((int)$ac->getId(), $a);
		$this->service->merge((int)$ab->getId(), $b);
		$this->features->restore($a);

		self::assertSame(Duplicate::PENDING, $this->duplicates->find((int)$ab->getId())->getStatus());
		self::assertContains((int)$ab->getId(), array_column($this->service->outstanding()['pairs'], 'id'));
		self::assertSame(Duplicate::MERGED, $this->duplicates->find((int)$ac->getId())->getStatus());
	}

	/** The same when the restored loser holds the higher id, the second side of its pair. */
	public function testRestoringTheHigherIdLoserReopensThePairItLost(): void {
		[$a, $b, , $ab, , $bc] = $this->tripletsAt('Balneario Municipal', 'Piscina Municipal', 'Gimnasio Municipal');

		$this->service->merge((int)$bc->getId(), $b);
		$this->service->merge((int)$ab->getId(), $a);
		$this->features->restore($b);

		self::assertSame(Duplicate::PENDING, $this->duplicates->find((int)$ab->getId())->getStatus());
		self::assertContains((int)$ab->getId(), array_column($this->service->outstanding()['pairs'], 'id'));
		self::assertSame(Duplicate::MERGED, $this->duplicates->find((int)$bc->getId())->getStatus());
	}

	/** A dismissal is final (ADR-0011): a restore whose pair is not Merged reopens nothing. */
	public function testRestoringALoserWhosePairIsNotMergedChangesNoPair(): void {
		[$a, $b, , $ab, $ac] = $this->tripletsAt('Balneario Municipal', 'Piscina Municipal', 'Gimnasio Municipal');

		$this->service->merge((int)$ac->getId(), $a);
		$this->service->dismiss((int)$ab->getId());
		// Builds a mergedInto whose pair is not Merged, as the #208 race leaves it.
		$this->features->merge($a, $b);
		$this->features->restore($a);

		self::assertSame(Duplicate::DISMISSED, $this->duplicates->find((int)$ab->getId())->getStatus());
		self::assertSame(Duplicate::MERGED, $this->duplicates->find((int)$ac->getId())->getStatus());
	}

	/** A pair whose records are no longer visible is not a question anybody can answer. */
	public function testAPairWithARetiredMemberIsNotOffered(): void {
		[$first,, $pair] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');

		$offered = static fn (array $out): bool => in_array(
			(int)$pair->getId(),
			array_column($out['pairs'], 'id'),
			true,
		);
		self::assertTrue($offered($this->service->outstanding()));

		// Retired on its own, not merged: this is what reverting the import that
		// created them does to both of them (ADR-0009).
		$this->features->retire($first);
		self::assertFalse($offered($this->service->outstanding()));

		// And it comes back with the record, still pending and still the same
		// question.
		$this->features->restore($first);
		self::assertTrue($offered($this->service->outstanding()));
	}

	public function testADismissedPairStopsBeingOffered(): void {
		[$first, $second, $pair] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');

		$this->service->dismiss((int)$pair->getId());
		self::assertNull($this->pairOf($first, $second), 'a dismissed pair is not outstanding');

		// And the next import does not resurrect it — the question was answered.
		$this->service->scan();
		self::assertNull($this->pairOf($first, $second));
	}

	public function testAPairIsResolvedOnceAndOnlyOnce(): void {
		[, $second, $pair] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');

		$this->service->dismiss((int)$pair->getId());

		$this->expectException(InvalidFeatureException::class);
		$this->service->merge((int)$pair->getId(), $second);
	}

	/**
	 * A service whose every read of the pair returns this snapshot: the PENDING
	 * row a second request read before the first one committed (#208). Two truly
	 * concurrent requests are not a deterministic seam; a stale read is.
	 */
	private function serviceReading(Duplicate $snapshot): DuplicateService {
		$duplicates = $this->getMockBuilder(DuplicateMapper::class)
			->setConstructorArgs([Server::get(IDBConnection::class)])
			->onlyMethods(['find'])
			->getMock();
		$duplicates->method('find')->willReturn($snapshot);

		return new DuplicateService(
			$duplicates,
			Server::get(FeatureMapper::class),
			Server::get(FeatureService::class),
			Server::get(IUserSession::class),
			Server::get(IDBConnection::class),
		);
	}

	public function testAMergeAfterACommittedDismissIsRefusedAndRetiresNothing(): void {
		[$first, $second, $pair] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');
		$this->service->dismiss((int)$pair->getId());

		$this->expectException(InvalidFeatureException::class);
		$this->expectExceptionMessage('Alguien ya resolvió este par.');
		try {
			$this->serviceReading($pair)->merge((int)$pair->getId(), $second);
		} finally {
			foreach ([$first, $second] as $id) {
				$record = Server::get(FeatureMapper::class)->find($id);
				self::assertFalse($record->getRetired());
				self::assertNull($record->getMergedInto());
			}
			self::assertSame(Duplicate::DISMISSED, $this->duplicates->find((int)$pair->getId())->getStatus());
			self::assertNotContains(Action::Merged->value, array_column($this->features->history($first), 'action'));
		}
	}

	public function testADismissAfterACommittedMergeIsRefusedAndThePairStaysMerged(): void {
		[$first, $second, $pair] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');
		$this->service->merge((int)$pair->getId(), $second);

		$this->expectException(InvalidFeatureException::class);
		$this->expectExceptionMessage('Alguien ya resolvió este par.');
		try {
			$this->serviceReading($pair)->dismiss((int)$pair->getId());
		} finally {
			self::assertSame(Duplicate::MERGED, $this->duplicates->find((int)$pair->getId())->getStatus());
			$loser = Server::get(FeatureMapper::class)->find($first);
			self::assertTrue($loser->getRetired());
			self::assertSame($second, $loser->getMergedInto());
		}
	}

	/** @return array<string, array{string}> */
	public static function survivors(): array {
		return [
			'same' => ['same'],
			'other' => ['other'],
		];
	}

	/** Keeping the other record would retire the first merge's survivor: both gone. */
	#[DataProvider('survivors')]
	public function testASecondMergeOfAMergedPairIsRefusedWhicheverSurvivor(string $survivor): void {
		[$first, $second, $pair] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');
		$this->service->merge((int)$pair->getId(), $second);

		$this->expectException(InvalidFeatureException::class);
		$this->expectExceptionMessage('Alguien ya resolvió este par.');
		try {
			$this->serviceReading($pair)->merge((int)$pair->getId(), $survivor === 'same' ? $second : $first);
		} finally {
			$loser = Server::get(FeatureMapper::class)->find($first);
			self::assertTrue($loser->getRetired());
			self::assertSame($second, $loser->getMergedInto());
			self::assertFalse(Server::get(FeatureMapper::class)->find($second)->getRetired());
			self::assertSame(Duplicate::MERGED, $this->duplicates->find((int)$pair->getId())->getStatus());
			$merges = array_filter(
				array_column($this->features->history($first), 'action'),
				static fn (string $action): bool => $action === Action::Merged->value,
			);
			self::assertCount(1, $merges);
		}
	}

	/**
	 * The pair is claimed before the records are touched, so a merge the records
	 * refuse has to give the claim back: this pins the rollback that does.
	 */
	public function testAMergeTheRecordsRefuseLeavesThePairPending(): void {
		[, $second, $pair] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');
		$this->features->retire($second);

		$this->expectException(InvalidFeatureException::class);
		$this->expectExceptionMessage('El registro que se conserva está retirado; restáurelo antes de fusionar.');
		try {
			$this->service->merge((int)$pair->getId(), $second);
		} finally {
			self::assertSame(Duplicate::PENDING, $this->duplicates->find((int)$pair->getId())->getStatus());
		}
	}

	/** The survivor has to be one of the two, or nobody compared it with anything. */
	public function testAStrangerCannotBeTheSurvivor(): void {
		[,, $pair] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');
		$stranger = $this->place('Otro lugar del todo');

		$this->expectException(InvalidFeatureException::class);
		$this->service->merge((int)$pair->getId(), $stranger);
	}

	/** The count is what the queue shows, and it moves when a pair is resolved. */
	public function testResolvingAPairTakesItOutOfTheCount(): void {
		[,, $pair] = $this->twinsAt('Balneario Municipal', 'Piscina Municipal');

		$before = $this->service->outstanding()['total'];
		$this->service->dismiss((int)$pair->getId());

		self::assertSame($before - 1, $this->service->outstanding()['total']);
	}
}
