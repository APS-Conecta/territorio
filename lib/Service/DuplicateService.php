<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use DateTime;
use OCA\Territorio\Db\Duplicate;
use OCA\Territorio\Db\DuplicateMapper;
use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Domain\DuplicateFinder;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserSession;
use Throwable;

/**
 * The review queue: which records might be the same place, and what was decided
 * (issue #16).
 *
 * Import matches on the exact external id and nothing else. Everything that
 * merely looks similar comes here, both records stay visible and usable, and a
 * person decides. That is the whole design, and it is a decision about failure
 * modes rather than about effort: a wrong merge fails SILENTLY — nobody
 * discovers it until they phone the wrong place — while a pair left in a queue
 * is a visible, harmless nuisance.
 *
 * The rule lives in DuplicateFinder, which knows nothing about the database.
 * This knows about the database and nothing about the rule.
 */
class DuplicateService {
	private const ALREADY_RESOLVED = 'Alguien ya resolvió este par.';

	public function __construct(
		private DuplicateMapper $duplicates,
		private FeatureMapper $features,
		private FeatureService $featureService,
		private IUserSession $session,
		private IDBConnection $db,
	) {
	}

	/**
	 * Look for candidate pairs and record the new ones.
	 *
	 * Run after an import, over the whole Registry rather than only what the file
	 * touched: a record typed in by hand and a record imported last month are as
	 * much a candidate pair as two rows of one file, and scoping this to the
	 * import would find only the second kind.
	 *
	 * Pairs already known — including ones somebody dismissed — are left alone.
	 *
	 * @return int how many pairs were new
	 */
	public function scan(): int {
		// Read once, so the scan does not offer the database pairs it already holds.
		// The outcome was always right — the unique index refused them — but a
		// refused insert in PostgreSQL still writes a row version for autovacuum to
		// collect, and still burns a sequence value. Measured before this: 704 of them in
		// a single re-scan of the dev instance.
		//
		// The index remains the authority: two scans at once still cannot both
		// record the same pair (ADR-0011), because this only skips what was already
		// there when the scan began. It is a filter, not a lock.
		$known = $this->duplicates->knownPairs();

		$found = 0;
		foreach (DuplicateFinder::pairs($this->candidates()) as $pair) {
			if (isset($known[$pair->firstId][$pair->secondId])) {
				continue;
			}

			$row = new Duplicate();
			$row->setFirstId($pair->firstId);
			$row->setSecondId($pair->secondId);
			$row->setReason($pair->reason);
			$row->setStatus(Duplicate::PENDING);
			$row->setFoundAt(new DateTime());

			if ($this->duplicates->record($row)) {
				$found++;
			}
		}

		return $found;
	}

	/**
	 * The outstanding pairs and how many there are.
	 *
	 * Only ids go out. The client already holds every visible record for the list
	 * and the map, so sending names and addresses again would be a second copy
	 * that can disagree with the first.
	 *
	 * Pairs whose members are not BOTH visible are left out. A pair can outlive
	 * its records: retire one by hand, or revert the import that created them
	 * (ADR-0009 retires rather than deletes, so nothing removes the row), and the
	 * pair is still pending with nothing to show. The client would render a card
	 * with one side, or none, and a live "no son duplicados" button on a question
	 * that no longer exists — and it would count toward the number on the pane.
	 *
	 * Left pending rather than resolved, deliberately: restoring the records
	 * brings the question back, and it is the same question it always was.
	 *
	 * @return array{pairs: list<array<string, mixed>>, total: int}
	 */
	public function outstanding(): array {
		// The ids, not the Registry: this ran once per queue poll and walked every
		// live record into a map just to ask "does this id exist" (L1-17).
		$visible = array_fill_keys($this->features->findLiveIds(), true);

		$pairs = [];
		foreach ($this->duplicates->findPending() as $pair) {
			if (isset($visible[$pair->getFirstId()], $visible[$pair->getSecondId()])) {
				$pairs[] = $pair->toClient();
			}
		}

		return ['pairs' => $pairs, 'total' => count($pairs)];
	}

	/**
	 * Decide that these two are one place, and which one survives.
	 *
	 * @throws DoesNotExistException when the pair or either record is gone
	 * @throws InvalidFeatureException when the pair is already resolved or the survivor is not one of the two
	 */
	public function merge(int $pairId, int $survivorId): Feature {
		$pair = $this->duplicates->find($pairId);
		$this->refuseResolved($pair);

		$ids = [$pair->getFirstId(), $pair->getSecondId()];
		if (!in_array($survivorId, $ids, true)) {
			// A survivor from outside the pair would retire a record on the strength
			// of a comparison nobody made.
			throw new InvalidFeatureException('El registro que se conserva no es parte de este par.');
		}

		$loserId = $survivorId === $ids[0] ? $ids[1] : $ids[0];

		// One transaction (L1-16): the loser's retirement and the pair's
		// resolution commit together or not at all. The failure this closes is the
		// gap between them — a loser retired while the pair sat PENDING, invisible
		// in outstanding() and never asked again, so the queue forgot a decision it
		// never finished making. The reads and refusals above stay outside: they
		// take no locks and write nothing — the writes re-verify under their own
		// locks (#208), so a pair resolved between the read here and the write
		// below refuses, and the rollback takes the retirement with it.
		return $this->transactionally(function () use ($pair, $loserId, $survivorId): Feature {
			$merged = $this->featureService->merge($loserId, $survivorId);

			$pair->setStatus(Duplicate::MERGED);
			$pair->setResolvedBy($this->actor());
			$this->duplicates->resolve($pair);

			return $this->featureService->merge($loserId, $survivorId);
		});
	}

	/** @param callable(): T $work @template T */
	private function transactionally(callable $work): mixed {
		$this->db->beginTransaction();
		try {
			$written = $work();
			$this->db->commit();

			return $written;
		} catch (Throwable $e) {
			$this->db->rollBack();

			throw $e;
		}
	}

	/**
	 * Decide that these two are different places.
	 *
	 * Recorded rather than deleted: the next import will find the same pair, and
	 * a queue that asks the same question every week is a queue nobody opens.
	 *
	 * @throws DoesNotExistException
	 * @throws InvalidFeatureException when the pair is already resolved
	 */
	public function dismiss(int $pairId): Duplicate {
		$pair = $this->duplicates->find($pairId);
		$this->refuseResolved($pair);
		$this->claim($pair, Duplicate::DISMISSED);

		return $pair;
	}

		$this->duplicates->resolve($pair);

		return $pair;
	}

	/**
	 * Two people opening the queue at once both see the pair; the second to act
	 * would otherwise retire a record on the strength of a decision the first had
	 * already made differently.
	 *
	 * This read is the fast answer, given before a transaction is opened — not
	 * the rule. The rule is in resolve(), which refuses a pair that was resolved
	 * under the decision, in these same words (#208).
	 */
	private function refuseResolved(Duplicate $pair): void {
		if ($pair->getStatus() !== Duplicate::PENDING) {
			throw new InvalidFeatureException(self::ALREADY_RESOLVED);
		}
	}

	/**
	 * What the finder compares: everything visible that is a single location.
	 *
	 * Places only. A Sector and a Unidad Vecinal have an extent rather than a
	 * coordinate, so "the same place" would have to mean overlapping areas — a
	 * different question, and one that would offer every Sector against the
	 * Unidad Vecinal it covers. Boundaries and retired records are already absent
	 * from this read.
	 *
	 * @return list<array{id: int, name: string, lat: ?float, lon: ?float}>
	 */
	private function candidates(): array {
		$candidates = [];
		foreach ($this->features->findPlaces() as $feature) {
			$anchor = $feature->anchor();

			$candidates[] = [
				'id' => (int)$feature->getId(),
				'name' => $feature->getName(),
				// A Place's bounding box IS its coordinate — the anchor rule,
				// stated once on the record (L1-20).
				'lat' => $anchor === null ? null : $anchor[1],
				'lon' => $anchor === null ? null : $anchor[0],
			];
		}

		return $candidates;
	}

	private function actor(): ?string {
		return $this->session->getUser()?->getUID();
	}
}
