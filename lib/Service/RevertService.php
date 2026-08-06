<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use DateTime;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\ImportBatch;
use OCA\Territorio\Db\ImportBatchMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Exception\InvalidImportException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Undoes one batch (ADR-0009).
 *
 * There is no snapshot and no backup feature; this is the answer to "restore
 * from backup", and catastrophic loss belongs to the Postgres backup in the
 * `gestion` deployment.
 *
 * A revert is the inverse of each action the batch recorded, applied newest
 * first. Expressing it that way — rather than as a special "undo import"
 * routine — is what makes a revert revertible: the revert records its own batch,
 * its Revisions are stamped with it, and undoing THAT is the same code walking a
 * different batch.
 *
 * Nothing is undone blindly. A record touched since the batch is left exactly as
 * it is and reported, because the alternative is silently discarding whatever
 * someone did in the meantime — which is the failure a revert exists to prevent,
 * not to cause.
 */
class RevertService {
	public function __construct(
		private ImportBatchMapper $batches,
		private RevisionLog $revisions,
		private FeatureMapper $features,
		private FeatureService $featureService,
	) {
	}

	/**
	 * @throws DoesNotExistException when there is no such batch
	 * @throws InvalidImportException when it has already been undone
	 */
	public function revert(int $batchId, ?string $actor): ImportBatch {
		$original = $this->batches->find($batchId);

		if ($this->batches->isUndone($batchId)) {
			throw new InvalidImportException('Ese lote ya está deshecho.');
		}

		// Read BEFORE the new batch exists. Once it is inserted it becomes the
		// newest revert of this one, so walking the chain from here would find
		// only itself and lose every earlier undo — leaving the guard below
		// convinced that a colleague had touched every record.
		$lineage = $this->lineage($batchId);

		$revert = new ImportBatch();
		$revert->setDatasetId($original->getDatasetId());
		$revert->setRevertsId($batchId);
		$revert->setActor($actor);
		$revert->setStartedAt(new DateTime());
		$revert->setRevisionFrom($this->revisions->current());
		$revert->setRevisionTo($this->revisions->current());
		$revert = $this->batches->insert($revert);

		// ponytail: two queries per entry, so undoing a 500-row import is about a
		// thousand round trips. Fine against one comuna; batch the newestFor()
		// lookup into a single query if a real catastro ever makes it drag.
		$counts = ['updated' => 0, 'skipped' => 0];

		try {
			$this->revisions->duringImport(
				(int)$revert->getId(),
				function () use ($batchId, $lineage, &$counts): void {
					$this->undo($batchId, $lineage, $counts);
				},
			);
		} finally {
			$revert->setUpdated($counts['updated']);
			$revert->setSkipped($counts['skipped']);
			$revert->setRevisionTo($this->revisions->current());
			$this->batches->update($revert);
		}

		return $revert;
	}

	/**
	 * ponytail: the "touched since" check reads outside the transaction that then
	 * writes, so a save committing in between is not seen. The window is the few
	 * milliseconds between the two, and reverts are rare; closing it means taking
	 * the cursor lock before the check, which would hold every other writer in the
	 * app for the length of the run.
	 *
	 * @param list<int> $lineage this batch and every undo of it, read before this
	 *                           revert was recorded
	 * @param array<string, int> $counts
	 */
	private function undo(int $batchId, array $lineage, array &$counts): void {
		foreach ($this->revisions->forImport($batchId) as $entry) {
			$newest = $this->revisions->newestFor($entry['featureId']);

			// If anything newer touched this record, the batch is no longer the
			// last word on it and undoing would discard that newer change — unless
			// the thing that touched it was this batch's own undo, or the undo of
			// that undo. Those are the same person pressing the same button, not a
			// colleague's work to protect.
			$mine = $newest['revision'] === $entry['revision']
				|| in_array($newest['importId'], $lineage, true);

			if (!$mine) {
				$counts['skipped']++;
				continue;
			}

			try {
				$undone = $this->inverse($entry);
			} catch (DoesNotExistException|InvalidFeatureException) {
				// One record this revert cannot put back — a Subcategory deleted
				// since, say. Left as it is and counted, rather than aborting: a
				// half-undone batch is worse than one that says what it could not
				// reach, and aborting used to leave the batch marked spent.
				$counts['skipped']++;
				continue;
			}

			$counts[$undone ? 'updated' : 'skipped']++;
		}
	}

	/**
	 * Every batch in this one's undo chain: itself, whatever undid it, whatever
	 * undid that, and so on.
	 *
	 * @return list<int>
	 */
	private function lineage(int $batchId): array {
		$chain = [$batchId];
		$next = $this->batches->findRevertOf($batchId);

		// Terminates because a revert always carries a higher id than what it
		// reverts, so the walk is strictly upwards.
		while ($next !== null) {
			$chain[] = (int)$next->getId();
			$next = $this->batches->findRevertOf((int)$next->getId());
		}

		return $chain;
	}

	/**
	 * @param array{revision: int, featureId: int, action: string, changes: ?array} $entry
	 * @return bool whether anything actually changed
	 */
	private function inverse(array $entry): bool {
		$feature = $this->features->find($entry['featureId']);

		return match (Action::from($entry['action'])) {
			// A record the batch created leaves the map, but is not destroyed —
			// ADR-0005 has no hard delete, and this is why undo can be undone.
			Action::Created, Action::Restored => $this->setRetired($feature, true),
			Action::Retired => $this->setRetired($feature, false),
			Action::Updated => $this->restoreValues($feature, $entry['changes'] ?? []),
		};
	}

	/**
	 * Retire or restore, but only if that is not already the case.
	 *
	 * FeatureService refuses to retire what is already retired precisely so
	 * history does not fill with entries describing nothing, and history is
	 * append-only (ADR-0005) so such an entry could never be tidied away. Going
	 * through the mapper directly would have skipped that reasoning.
	 */
	private function setRetired(\OCA\Territorio\Db\Feature $feature, bool $retired): bool {
		if ((bool)$feature->getRetired() === $retired) {
			return false;
		}

		$retired ? $this->features->retire($feature) : $this->features->restore($feature);

		return true;
	}

	/**
	 * Put back the values the batch overwrote.
	 *
	 * Built from the record as it stands now and then overlaid with the "from"
	 * side of the diff, so fields the batch never touched keep whatever they hold
	 * — a revert restores what the batch overwrote, and nothing else.
	 *
	 * @param array<string, array{from: mixed, to: mixed}> $changes
	 */
	private function restoreValues(\OCA\Territorio\Db\Feature $feature, array $changes): bool {
		if ($changes === []) {
			// An update that changed nothing visible — a refreshed official value,
			// say. There is nothing to put back, and counting it as undone would
			// overstate what the revert did.
			return false;
		}

		$values = $feature->comparable();
		foreach ($changes as $field => $change) {
			$values[$field] = $change['from'];
		}

		$this->featureService->revertTo((int)$feature->getId(), [
			'name' => $values['name'],
			'subcategoryId' => $values['subcategoryId'],
			// Stored as GeoJSON text; the service takes it decoded.
			'geometry' => $values['geometry'] === null
				? null
				: json_decode((string)$values['geometry'], true),
			'locationQuality' => $values['locationQuality'] === LocationQuality::Absent->value
				? null
				: $values['locationQuality'],
			'address' => $values['address'],
			'phone' => $values['phone'],
			'email' => $values['email'],
			'contactPerson' => $values['contactPerson'],
			'contactRole' => $values['contactRole'],
			'openingHours' => $values['openingHours'],
			'notes' => $values['notes'],
			'sourceRef' => $values['sourceRef'],
		]);

		return true;
	}
}
