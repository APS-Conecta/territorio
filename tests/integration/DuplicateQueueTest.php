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
use OCA\Territorio\Service\ImportService;
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

	/**
	 * The criterion says IMPORT flags them, and both formats reach the same code.
	 *
	 * Through KML rather than GeoJSON on purpose: importKml() and importGeoJson()
	 * both funnel into importRows(), and a test of the format that is already
	 * covered everywhere else would not notice if one of them stopped.
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

		Server::get(ImportService::class)->importKml($twins, 'gemelos-kml-' . uniqid());

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
