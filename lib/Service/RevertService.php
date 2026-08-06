<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use DateTime;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\ImportBatch;
use OCA\Territorio\Db\ImportBatchMapper;
use OCA\Territorio\Db\RevisionLog;
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

		if ($this->batches->findRevertOf($batchId) !== null) {
			// Undoing twice would take the second pass down the "touched since"
			// path for every record and report a confusing pile of skips.
			throw new InvalidImportException('Ese lote ya fue revertido.');
		}

		$revert = new ImportBatch();
		$revert->setDatasetId($original->getDatasetId());
		$revert->setRevertsId($batchId);
		$revert->setActor($actor);
		$revert->setStartedAt(new DateTime());
		$revert->setRevisionFrom($this->revisions->current());
		$revert->setRevisionTo($this->revisions->current());
		$revert = $this->batches->insert($revert);

		$counts = ['updated' => 0, 'skipped' => 0];

		try {
			$this->revisions->duringImport(
				(int)$revert->getId(),
				function () use ($batchId, &$counts): void {
					$this->undo($batchId, $counts);
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

	/** @param array<string, int> $counts */
	private function undo(int $batchId, array &$counts): void {
		foreach ($this->revisions->forImport($batchId) as $entry) {
			// If anything newer touched this record, the batch is no longer the
			// last word on it and undoing would discard that newer change.
			if ($this->revisions->newestFor($entry['featureId']) !== $entry['revision']) {
				$counts['skipped']++;
				continue;
			}

			try {
				$this->inverse($entry);
			} catch (DoesNotExistException) {
				// The record is gone in a way retirement cannot explain. Nothing to
				// undo, and refusing the whole revert over it would help nobody.
				$counts['skipped']++;
				continue;
			}

			$counts['updated']++;
		}
	}

	/** @param array{revision: int, featureId: int, action: string, changes: ?array} $entry */
	private function inverse(array $entry): void {
		$feature = $this->features->find($entry['featureId']);

		match (Action::from($entry['action'])) {
			// A record the batch created leaves the map, but is not destroyed —
			// ADR-0005 has no hard delete, and this is why undo can be undone.
			Action::Created, Action::Restored => $this->features->retire($feature),
			Action::Retired => $this->features->restore($feature),
			Action::Updated => $this->restoreValues($feature, $entry['changes'] ?? []),
		};
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
	private function restoreValues(\OCA\Territorio\Db\Feature $feature, array $changes): void {
		if ($changes === []) {
			// An update that changed nothing visible — a refreshed official value,
			// say. There is nothing to put back.
			return;
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
	}
}
