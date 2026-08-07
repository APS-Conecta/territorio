<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\Duplicate;
use OCA\Territorio\Db\DuplicateMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Service\Action;
use OCA\Territorio\Service\DuplicateService;
use OCA\Territorio\Service\FeatureService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;

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
