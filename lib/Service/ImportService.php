<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use APS\Common\Text;
use OCA\Territorio\Domain\Geometry;
use OCA\Territorio\Domain\LocationQuality;
use OCA\Territorio\Domain\Provenance;

use DateTime;
use OCA\Territorio\Db\Dataset;
use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\ImportBatch;
use OCA\Territorio\Db\ImportBatchMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Exception\InvalidGeometryException;
use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Migration\EnsureSeedData;

/**
 * Brings a file into a Dataset, once or repeatedly.
 *
 * GeoJSON, KML, KMZ or CSV — the format decides only which reader runs, and a
 * KMZ is a KML in an archive, so there are three readers for four formats. All
 * of them produce the same rows, so the batch, the matching, the local-override
 * rule and the revert are one implementation and cannot drift apart between them.
 *
 * Three rules do most of the work here, and all three are ADRs rather than
 * preferences:
 *
 *  - Matching is on `(Dataset, external id)`, so importing the same file twice
 *    updates rather than duplicates. Anything that merely LOOKS the same is
 *    flagged for a person and never merged (issue #16) — a wrong merge is the
 *    one failure nobody notices until they phone the wrong place.
 *  - A record a person has edited is never overwritten (ADR-0003). The incoming
 *    official value is stored beside the override so the two stay comparable and
 *    the override can be undone.
 *  - Every Revision a run takes is stamped with its batch (ADR-0009), because
 *    that is what makes the run revertible and it cannot be worked out
 *    afterwards. A range of Revision numbers would not do: writes are serialised
 *    behind one cursor, so a long import is easily long enough for a colleague's
 *    edit to fall inside the window and be reverted with it.
 *
 * The file is read, resolved against the taxonomy and checked in full before a
 * single row is written, so a file that is wrong anywhere imports nothing. Once
 * writing starts each row commits separately, so a failure part-way through does
 * leave rows behind — which is exactly why the batch is recorded first, and why
 * its counts are written even when the run throws.
 */
class ImportService {


	public function __construct(
		private GeoJsonReader $reader,
		private KmlReader $kml,
		private CsvReader $csv,
		private DatasetMapper $datasets,
		private FeatureMapper $features,
		private FeatureService $featureService,
		private TaxonomyService $taxonomy,
		private RevisionLog $revisions,
		private ImportBatchMapper $batches,
		private ComunaConfig $comuna,
		private DuplicateService $duplicates,
	) {
	}

	/**
	 * Import a file, reading it according to what it is. The one way in.
	 *
	 * The name is used only to choose the reader — everything downstream is the
	 * same batch, the same matching and the same revert, whichever format it
	 * arrived in (issue #8).
	 *
	 * FORMAT SELECTION is by filename suffix and is deliberately not a refusal:
	 * `.kml` in any case is KML, `.kmz` is that KML in an archive, `.csv` is a
	 * spreadsheet, and EVERYTHING ELSE — including an unrecognised suffix and an
	 * empty filename — is read as GeoJSON. A caller that cannot name its input
	 * still gets a parse error from the reader rather than a rejection here,
	 * which is why `occ` can pipe a file with any name.
	 *
	 * ORDER, because a failure part-way leaves different debris at each step:
	 *   1. the comuna claim is refused first, before a single row is written
	 *      (a file cut for another comuna is a mistake, not a partial import);
	 *   2. rows the file repeats verbatim collapse, and the count is reported;
	 *   3. the Dataset is found or created — the first write of the run;
	 *   4. the ImportBatch is written BEFORE the first row, per ADR-0009: each
	 *      row commits its own transaction, so a file that fails at row 400
	 *      leaves 399 behind, and the batch is what makes those revertible;
	 *   5. rows apply — name and «ubicación» are checked with the rest, in
	 *      `prepare()`, before any of them is (L1-15);
	 *   6. counts are recorded in a `finally`, so a run that threw still says
	 *      what it did;
	 *   7. the duplicate scan runs last, after the batch has closed.
	 *
	 * SIZE is NOT capped here. `ImportFiles::MAX_BYTES` caps what the browser
	 * sends — an upload or a Files path alike — at 8 MiB; `occ territorio:import`
	 * has no cap at all, deliberately — a comuna's cadastre arrives on the
	 * command line.
	 *
	 * @param string      $contents   the file itself
	 * @param string      $filename   used ONLY to pick the reader; see above
	 * @param string      $datasetSlug
	 * @param string|null $label      null means "no label", and the Dataset takes
	 *                                the slug. "" and whitespace mean the same,
	 *                                normalised here — callers pass what they
	 *                                were given, raw.
	 * @param string|null $actor      null where nobody was behind it
	 * @param bool|null   $boundary   null means "not saying", which leaves an
	 *                                existing Dataset's flag alone
	 *
	 * @throws InvalidImportException
	 */
	public function importFile(
		string $contents,
		string $filename,
		string $datasetSlug,
		?string $label = null,
		?string $actor = null,
		?bool $boundary = null,
	): ImportBatch {
		// The one rule both doors used to carry a copy of (L1-02). The browser
		// route and `occ` pass what they were given; this is the one place an
		// empty slug is refused, with the sentence the browser door always used —
		// and the L0-05 OCS route, when it exists, inherits it for free.
		if (trim($datasetSlug) === '') {
			throw new InvalidImportException('Indique a qué conjunto de datos importar.');
		}

		// `?:` and not `??`: an empty label is a label the caller did not give,
		// and `$label ?? $datasetSlug` below only catches null. Letting '' through
		// put it into Dataset::setLabel(''), where Entity::setter early-returns on
		// the '' initialiser and the NOT NULL `label` column is omitted from the
		// INSERT. Both callers — the browser route and `occ` — pass what they
		// were given; this is the one place it is normalised.
		$label = trim((string)$label) ?: null;

		return $this->importRows(
			match (self::suffixOf($filename)) {
				'.kml' => $this->kml->read($contents, $datasetSlug),
				'.kmz' => $this->kml->readArchive($contents, $datasetSlug),
				'.csv' => $this->csv->read($contents, $datasetSlug),
				default => $this->reader->read($contents, $datasetSlug),
			},
			$datasetSlug,
			$label,
			$actor,
			$boundary,
		);
	}

	/** The filename's extension, lowercased, or '' where it has none. */
	private static function suffixOf(string $filename): string {
		$name = strtolower(trim($filename));
		$dot = strrpos($name, '.');

		return $dot === false ? '' : substr($name, $dot);
	}

	/**
	 * Everything after the file has been read, which is everything both formats
	 * share.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @throws InvalidImportException
	 */
	private function importRows(
		array $rows,
		string $datasetSlug,
		?string $label,
		?string $actor,
		?bool $boundary,
	): ImportBatch {
		$this->refuseAnotherComuna($rows);

		[$rows, $duplicates] = $this->collapseDuplicates($rows);
		[$prepared, $unsupported] = $this->prepare($rows);

		// The Dataset is created only once the whole file has been read and
		// checked. Creating it first left a Dataset behind after a refused
		// import, which made "imports nothing" not quite true.
		$dataset = $this->datasets->findOrCreate(
			$datasetSlug,
			$label ?? $datasetSlug,
			$boundary,
		);

		return $this->apply($dataset, $prepared, $duplicates, $unsupported, $actor);
	}

	/**
	 * The recent runs, as the import dialog lists them.
	 *
	 * The Dataset's name is joined on here rather than shipped by the batch: a
	 * batch knows an id, and "Importación #3 en 7" tells a person nothing.
	 *
	 * This lives in the service rather than the controller so that an OCS
	 * controller later is a new file rather than a second copy of the join
	 * (issue #18).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function recentBatches(): array {
		$datasets = $this->datasets->allById();

		return array_map(
			function (ImportBatch $batch) use ($datasets): array {
				// ?? not ?->: a missing key is an undefined-index warning before
				// the nullsafe operator ever gets a chance.
				$dataset = $datasets[$batch->getDatasetId()] ?? null;

				return $batch->toClient() + [
					// A null dataset is a reassignment sweep's batch (L1-16) — not a
				// deleted Dataset, which is what 'conjunto eliminado' says.
				'dataset' => $dataset?->getLabel()
					?? ($batch->getDatasetId() === null ? 'reasignación de límite' : 'conjunto eliminado'),
					'undone' => $this->batches->isUndone((int)$batch->getId()),
				];
			},
			$this->batches->findRecent(),
		);
	}

	/**
	 * Refuse a file that belongs to a different comuna.
	 *
	 * An install serves exactly one Comuna and a Territorio never reaches into a
	 * second (CONTEXT.md), so a Unidad Vecinal file cut for somewhere else is a
	 * mistake worth catching at the door — not after 300 boundaries from another
	 * municipality are in the Registry and someone has to work out which.
	 *
	 * Only the UV cadastre says which comuna it is for. Everything else stays
	 * silent and is imported as before: this is a check on a claim the file makes,
	 * never a demand that every file make one.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @throws InvalidImportException
	 */
	private function refuseAnotherComuna(array $rows): void {
		$comuna = $this->comuna->get();
		if (!$comuna->isChosen()) {
			return;
		}

		foreach ($rows as $row) {
			$says = (string)($row['comuna'] ?? '');
			if ($says !== '' && $says !== $comuna->cut) {
				throw new InvalidImportException(sprintf(
					'El archivo es de la comuna %s y esta instalación es la %s%s. '
					. 'Corte el archivo para su comuna antes de importarlo.',
					$says,
					$comuna->cut,
					$comuna->name === '' ? '' : ' (' . $comuna->name . ')',
				));
			}
		}
	}

	/**
	 * Rows the file repeated verbatim collapse to one.
	 *
	 * The La Florida catastro contains 18 of these across 865 features —
	 * "Balneario Municipal" three times with the same address and coordinate,
	 * inherited from FONASA. Collapsing them is right; doing it silently is not,
	 * so the count is reported on the batch.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return array{0: array<string, array<string, mixed>>, 1: int}
	 */
	private function collapseDuplicates(array $rows): array {
		$byId = [];
		$duplicates = 0;
		foreach ($rows as $row) {
			if (isset($byId[$row['externalId']])) {
				$duplicates++;
			}
			$byId[$row['externalId']] = $row;
		}

		return [$byId, $duplicates];
	}

	/**
	 * Resolve every row against the taxonomy and check its geometry, before
	 * anything is written.
	 *
	 * @param array<string, array<string, mixed>> $rows
	 * @return array{0: array<string, array<string, mixed>>, 1: int}
	 */
	private function prepare(array $rows): array {
		// The shared flat index (L1-20), inverted into the matching shape — one
		// taxonomy derivation per request instead of Import's private copy.
		$subcategories = [];
		foreach ($this->taxonomy->subcategoryIndex() as $id => $pair) {
			$subcategories[$pair['category'] . '/' . $pair['subcategory']] = $id;
		}
		$byLabel = $this->subcategoryLabels();
		$fallback = $subcategories[implode('/', EnsureSeedData::FALLBACK)] ?? null;
		if ($fallback === null) {
			throw new InvalidImportException(
				'Falta la subcategoría "Sin clasificar · Otro"; no hay dónde poner lo no reconocido.',
			);
		}

		$prepared = [];
		$unsupported = 0;
		$problems = [];

		foreach ($rows as $externalId => $row) {
			if ($row['geometry'] !== null && !Geometry::supports($row['geometry']['type'] ?? null)) {
				// Read, understood, and counted rather than imported. Counting is
				// the point: one MultiPolygon in a cadastre of thousands must not
				// make the file an all-or-nothing problem, and the batch says how
				// many rows were left behind.
				$unsupported++;
				continue;
			}

			try {
				// Parsed here so a bad coordinate is caught while nothing has been
				// written, rather than half-way through the file.
				if ($row['geometry'] !== null) {
					Geometry::fromArray($row['geometry']);
				}
			} catch (InvalidGeometryException $e) {
				$problems[] = sprintf('%s: %s', $row['name'], $e->getMessage());
				continue;
			}

			// L1-15: name and location quality are checked HERE, pre-write, so
			// the class docblock's "checked in full before a single row is
			// written" is literally true. Today's readers refuse nameless rows
			// themselves — this is the one-way-in check that stays true when a
			// reader changes.
			$name = trim((string)($row['name'] ?? ''));
			if ($name === '') {
				$problems[] = sprintf('%s: el registro no tiene nombre.', $externalId);
				continue;
			}

			// The raw «ubicación» cell (L1-07/L1-15), judged in this one place:
			//  - null is the DEFAULT and stays — most files say nothing, and a
			//    coordinate-bearing row takes Exact, documented here rather than
			//    silently rewritten;
			//  - «sin_coordenada» on a row that HAS a coordinate, or any quality
			//    at all on a row that has none, is a file contradicting itself —
			//    the UI path's own 422 posture, applied to imports;
			//  - anything else the enum does not know is refused by name.
			// Case-folded first, so «EXACTA» behaves as it always did.
			$quality = $row['locationQuality'] ?? null;
			if ($quality !== null) {
				$quality = mb_strtolower(trim($quality));
				$row['locationQuality'] = $quality !== '' ? $quality : null;
			}
			if (($row['locationQuality'] ?? null) !== null) {
				$case = LocationQuality::tryFrom($row['locationQuality']);
				if ($case === null) {
					$problems[] = sprintf(
						'%s: exactitud desconocida «%s».', $name, $row['locationQuality'],
					);
					continue;
				}
				if ($row['geometry'] === null xor $case === LocationQuality::Absent) {
					$problems[] = $row['geometry'] === null
						? sprintf('%s: la exactitud describe una coordenada que el registro no tiene.', $name)
						: sprintf('%s: dice «sin_coordenada» y trae una coordenada.', $name);
					continue;
				}
			}

			$row['subcategoryId'] = $this->classify($row, $subcategories, $byLabel, $fallback);
			$prepared[$externalId] = $row;
		}

		if ($problems !== []) {
			throw new InvalidImportException(sprintf(
				'%d registro(s) del archivo no se pueden importar y no se importó nada: %s',
				count($problems),
				implode('; ', array_slice($problems, 0, 3)) . (count($problems) > 3 ? '; …' : ''),
			));
		}

		return [$prepared, $unsupported];
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 */
	private function apply(
		Dataset $dataset,
		array $rows,
		int $duplicates,
		int $unsupported,
		?string $actor,
	): ImportBatch {
		// The batch is written BEFORE a single row is, and that ordering is the
		// point. Each row commits its own transaction, so a file that fails at row
		// 400 leaves 399 rows behind — and if the batch were only written at the
		// end, ADR-0009's one recovery path would not exist for precisely the
		// failure ADR-0009 was written about.
		$batch = new ImportBatch();
		$batch->setDatasetId((int)$dataset->getId());
		$batch->setActor($actor);
		$batch->setStartedAt(new DateTime());
		$batch->setRevisionFrom($this->revisions->current());
		$batch->setRevisionTo($this->revisions->current());
		$batch->setDuplicates($duplicates);
		$batch->setUnsupported($unsupported);
		$batch = $this->batches->insert($batch);

		$counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'unchanged' => 0];

		try {
			// Every Revision taken in here is stamped with this batch, so a revert
			// acts on exactly the rows the import touched and leaves a colleague's
			// concurrent edit alone — including the batch-end sweep's, which is
			// why the flush runs INSIDE the stamp (L1-13).
			$this->featureService->deferringReassignments(
				function () use ($batch, $dataset, $rows, &$counts): void {
					$this->revisions->duringImport(
						(int)$batch->getId(),
						// A long closure, not fn(): an arrow function captures by value, so
						// the counts applyRows() writes by reference would never come back.
						function () use ($dataset, $rows, &$counts): void {
							try {
								$this->applyRows($dataset, $rows, $counts);
							} finally {
								// Also on a run that threw: the rows it did write include
								// boundaries, and their Places hold assignments from
								// before the batch.
								$this->featureService->flushDeferredReassignments();
							}
						},
					);
				},
			);
		} finally {
			// Recorded even when the run threw, or the rows it did write would be
			// unrevertible.
			$batch->setCreated($counts['created']);
			$batch->setUpdated($counts['updated']);
			$batch->setSkipped($counts['skipped']);
			$batch->setUnchanged($counts['unchanged']);
			$batch->setRevisionTo($this->revisions->current());
			$this->batches->update($batch);
		}

		// Candidate pairs, flagged and never merged (issue #16). Deliberately
		// AFTER the batch is closed and outside duringImport(): this writes no
		// Revisions, so stamping it with the batch would be a lie, and a revert
		// that undid the flagging would take a person's decisions with it.
		$this->duplicates->scan();

		return $batch;
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @param array<string, int> $counts mutated as rows are applied
	 */
	private function applyRows(Dataset $dataset, array $rows, array &$counts): void {
		$existing = $this->features->findByDataset((int)$dataset->getId());

		foreach ($rows as $externalId => $row) {
			$provenance = new Provenance((int)$dataset->getId(), (string)$externalId, $this->official($row));
			$current = $existing[$externalId] ?? null;

			if ($current === null) {
				$this->featureService->create($this->input($row), $provenance);
				$counts['created']++;
				continue;
			}

			// ADR-0003, and retirement counts too: both are local decisions about
			// a record, and an import must not undo either. The official value is
			// still refreshed, so "what does the authority say now" stays current
			// and the override remains undoable.
			if ($current->getOverridden() || $current->getRetired()) {
				$this->refreshOfficial($current, $provenance);
				$counts['skipped']++;
				continue;
			}

			if (!$this->differs($current, $row, $provenance)) {
				$counts['unchanged']++;
				continue;
			}

			$this->featureService->update((int)$current->getId(), $this->input($row), $provenance);
			$counts['updated']++;
		}
	}

	/**
	 * Whether importing this row would change anything a person can see.
	 *
	 * Checked before writing so that re-importing an unchanged file produces no
	 * Revisions at all — otherwise every re-import would flood the change cursor
	 * with hundreds of "updates" that changed nothing, and history with them.
	 *
	 * The official blob answers first, and it is the whole row — so on an
	 * ordinary re-import nothing below it is reached. What the fields decide is
	 * the record a **revert** put back: that writes old values without marking an
	 * override (ADR-0009), so the record disagrees with an official blob that is
	 * still current. Getting the list wrong loses exactly that case, silently.
	 *
	 * So it is derived from `input()` rather than listed a second time by hand.
	 * `ImportInputTest` guards the other half — a field `comparable()` lacks
	 * would be compared against nothing.
	 *
	 * @param array<string, mixed> $row
	 */
	private function differs(Feature $current, array $row, Provenance $provenance): bool {
		if ($current->getOfficial() !== $provenance->officialAsJson()) {
			return true;
		}

		// Two of them are stored in a shape they do not arrive in. Normalised
		// here and not in `input()`, which feeds the write path and must keep
		// handing FeatureService the raw row.
		$incoming = $this->input($row);
		$incoming['geometry'] = $row['geometry'] === null
			? null
			: Geometry::fromArray($row['geometry'])->toJson();
		$incoming['locationQuality'] = $this->expectedQuality($row);

		// Sorted because the two are built in different orders and `!==` on
		// arrays is order-sensitive; strict so a stored '' never reads as null.
		$stored = array_intersect_key($current->comparable(), $incoming);
		ksort($stored);
		ksort($incoming);

		return $stored !== $incoming;
	}

	/**
	 * Keep the authority's latest values beside an override, without touching
	 * what the person wrote.
	 *
	 * Written only when it actually changed, and written WITHOUT taking a
	 * Revision. Nothing a person sees changes — the record still shows the local
	 * edit — so pushing it through the normal write path would spend a Revision,
	 * make every polling client refetch, and leave an "Actualizado" entry in
	 * history whose diff is empty.
	 */
	private function refreshOfficial(Feature $current, Provenance $provenance): void {
		if ($current->getOfficial() === $provenance->officialAsJson()) {
			return;
		}

		$this->features->storeOfficial($current, $provenance->officialAsJson());
	}

	/**
	 * The Location quality this row will end up with, so `differs()` compares
	 * like with like — FeatureService derives it rather than storing what the
	 * file said.
	 *
	 * @param array<string, mixed> $row
	 */
	private function expectedQuality(array $row): string {
		if ($row['geometry'] === null) {
			return LocationQuality::Absent->value;
		}

		return $row['locationQuality'] ?? LocationQuality::Exact->value;
	}

	/**
	 * What the authority published, kept verbatim for comparison against a local
	 * edit (ADR-0003).
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function official(array $row): array {
		$official = $row;
		unset($official['externalId'], $official['subcategoryId']);

		return $official;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function input(array $row): array {
		return [
			'name' => $row['name'],
			'subcategoryId' => $row['subcategoryId'],
			'geometry' => $row['geometry'],
			'locationQuality' => $row['locationQuality'],
			'address' => $row['address'],
			'phone' => $row['phone'],
			'email' => $row['email'],
			'contactPerson' => $row['contactPerson'],
			'contactRole' => $row['contactRole'],
			'openingHours' => $row['openingHours'],
			'notes' => $row['notes'],
			'sourceRef' => $row['sourceRef'],
		];
	}

	/**
	 * One classification-matching home (L1-06): the label the file typed,
	 * then two slug vocabularies — the plain slug and the seed's own
	 * short-word-dropped slug («movilidad y transporte» → movilidad_transporte,
	 * how EnsureSeedData names its Categories) — applied to the CATEGORY,
	 * where the seed's dropped-word slugs live.
	 *
	 * The label is matched first because it is the one vocabulary that cannot
	 * drift from the seed's slugs: a file names what the Capas panel shows
	 * («Deporte y recreación»), and folding it never loses the short words the
	 * seed drops. Only reachable with both halves named.
	 *
	 * Deliberately not applied when the file DID name a Subcategory and this
	 * install has no such thing. Falling to that Category's Otro would be a
	 * guess about what was meant, and it would also change where existing
	 * records live: `differs()` compares the Subcategory, so re-importing an
	 * unchanged GeoJSON would quietly move rows out of Sin clasificar and
	 * spend a Revision doing it. Sin clasificar · Otro says the honest
	 * thing — the file named something this install does not have.
	 *
	 * @param array<string, mixed> $row
	 * @param array<string, int> $subcategories
	 * @param array<string, int> $byLabel
	 */
	private function classify(array $row, array $subcategories, array $byLabel, int $fallback): int {
		$category = $row['category'];
		if ($category === null || $category === '') {
			return $fallback;
		}
		$subcategory = $row['subcategory'] ?? null;
		$subcategorySlug = $subcategory === null || $subcategory === ''
			? null
			: TaxonomyService::slugFor($subcategory);

		// Label-first: a file names what the Capas panel shows («Deporte y
		// recreación»), and the label is the one vocabulary that cannot drift
		// from the seed's slugs. Only reachable with both halves named.
		if ($subcategorySlug !== null) {
			$label = Text::fold($category) . '·' . Text::fold($subcategory);
			if (isset($byLabel[$label])) {
				return $byLabel[$label];
			}
		}

		// Both slug vocabularies, on the category — the seed's dropped-word
		// slugs (deporte_recreacion, movilidad_transporte) are Category slugs.
		foreach ([TaxonomyService::slugFor($category), self::seedSlug($category)] as $categorySlug) {
			if ($subcategorySlug === null) {
				$hit = $subcategories[$categorySlug . '/otro']
				?? $this->asSubcategory($categorySlug, $subcategories);
			} else {
				$hit = $subcategories[$categorySlug . '/' . $subcategorySlug]
				?? $subcategories[$categorySlug . '/' . self::seedSlug($subcategory)];
			}
			if ($hit !== null) {
				return $hit;
			}
		}

		return $fallback;
	}

	/**
	 * The seed's slug vocabulary: short words dropped, the way EnsureSeedData
	 * names its Categories («deporte_recreacion»). KmlReader's folder
	 * heuristic, moved to the one matching home (L1-06) — checked against
	 * the twelve seeded slugs, not assumed.
	 */
	private static function seedSlug(string $label): string {
		$folded = Text::fold($label);
		$words = array_filter(
			preg_split('/[^a-z0-9]+/', $folded) ?: [],
			static fn (string $word): bool => $word !== '' && !in_array($word, ['y', 'de', 'la', 'el'], true),
		);

		return implode('_', $words);
	}

	/** @return array<string, int> "foldedCategory·foldedSubcategory" → Subcategory id */
	private function subcategoryLabels(): array {
		$index = [];
		foreach ($this->taxonomy->tree() as $category) {
			foreach ($category['subcategories'] as $subcategory) {
				$index[Text::fold($category['label']) . '·' . Text::fold($subcategory['label'])] = $subcategory['id'];
			}
		}

		return $index;
	}

	/**
	 * The name of a Subcategory, where a file named one and called it a Category.
	 *
	 * Not every MyMaps folder is a Category. Two of the thirteen in the real La
	 * Florida export are Subcategory-level — "Ciclovías" over 39 placemarks and
	 * "Plazas y parques" over 270 — so matching Categories alone left 309 of 865
	 * records, 36% of the file, in Sin clasificar while `ciclovia` and `plaza`
	 * sat in the seed.
	 *
	 * ponytail: exact match first, then each word with a Spanish plural taken off
	 * — enough for "ciclovias" → `ciclovia` and "plazas_parques" → `plaza`, and
	 * checked against those two real folder names. It is a heuristic and it will
	 * miss something eventually; a miss costs Sin clasificar, which is where the
	 * row was going anyway. Match on the Subcategory's label when that is not
	 * good enough.
	 *
	 * @param array<string, int> $subcategories keyed "categorySlug/subcategorySlug"
	 */
	private function asSubcategory(string $named, array $subcategories): ?int {
		$bySlug = [];
		foreach ($subcategories as $key => $id) {
			$bySlug[substr($key, (int)strpos($key, '/') + 1)] ??= $id;
		}

		foreach ([$named, ...explode('_', $named)] as $word) {
			foreach ([$word, preg_replace('/(es|s)$/', '', $word)] as $candidate) {
				if ($candidate !== '' && isset($bySlug[$candidate])) {
					return $bySlug[$candidate];
				}
			}
		}

		return null;
	}

}
