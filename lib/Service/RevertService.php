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
				// This revert belongs in its own lineage. It could not be read into
				// it above — it did not exist yet — but from here on it is writing
				// Revisions, and a batch that touched one record twice would
				// otherwise undo the newer entry and then find its OWN write sitting
				// on the record, decide a colleague had been there, and skip the
				// older one. The record stayed on the map, counted as "omitido",
				// which reads as deliberately protected rather than missed.
				function () use ($batchId, $lineage, $revert, &$counts): void {
					$this->undo($batchId, [...$lineage, (int)$revert->getId()], $counts);
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
			// Merged is retired-with-a-pointer, so its undo is a restore — and
			// FeatureService::restore() is what reopens the pair, which is the
			// other half of undoing a merge (issue #16).
			//
			// Unreachable today: a merge is resolved by a person, outside
			// duringImport(), so no Revision carries both this action and an import
			// id. It is here because `match` has no default and the omission was a
			// 500 mid-revert — the one moment the app must not fall over.
			Action::Retired, Action::Merged => $this->setRetired($feature, false),
			Action::Updated => $this->restoreValues($feature, $entry['changes'] ?? []),
		};
	}

	/**
	 * Retire or restore, but only if that is not already the case.
	 *
	 * FeatureService refuses to retire what is already retired precisely so
	 * history does not fill with entries describing nothing, and history is
	 * append-only (ADR-0005) so such an entry could never be tidied away. The
	 * guard above is what lets this call the service rather than duck it.
	 *
	 * Through FeatureService and NOT the mapper. The mapper writes the column;
	 * the service also reassigns everything the record covered (ADR-0008). Going
	 * straight to the mapper meant reverting an import that created a Sector or a
	 * Unidad Vecinal retired the boundary and left every Place still holding its
	 * id — pointing at something no read will resolve, so the sidebar says "sin
	 * asignar" about a record the database says is assigned. Registry-wide,
	 * silent, and the revert counted those records as successfully updated.
	 */
	private function setRetired(\OCA\Territorio\Db\Feature $feature, bool $retired): bool {
		if ((bool)$feature->getRetired() === $retired) {
			return false;
		}

		$id = (int)$feature->getId();
		$retired ? $this->featureService->retire($id) : $this->featureService->restore($id);

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

		// Shorter than comparable() by four keys, and deliberately so on both counts:
		// sectorId/unidadVecinalId are DERIVED from geometry at the write door (ADR-0008), so
		// restoring geometry restores them, and writing them here would fight that; colour and
		// limit are never carried by ImportService::input(), so no import can have changed them
		// and a revert has nothing of its own to put back.
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
