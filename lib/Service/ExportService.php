<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use DateTime;
use OCA\Territorio\Db\ExportRecord;
use OCA\Territorio\Db\ExportRecordMapper;
use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCP\IUserSession;

/**
 * Taking the filtered set out of the app (issue #17, ADR-0006).
 *
 * Four formats, one set. The format decides only which writer runs, exactly as
 * it decides only which reader runs on the way in — so "the current filtered
 * set" means the same thing in all of them and cannot drift between them. KMZ is
 * not a fourth writer: it is the KML one, zipped (issue #102).
 *
 * The contact fields are the whole reason this is not a plain download. The
 * Registry holds the names, mobile numbers and email addresses of identifiable
 * people, and an export is where a coordination tool turns into a portable
 * contact list. So an export carrying them is confirmed by the person's own
 * password — Nextcloud's, never a credential invented here — and recorded. One
 * that carries none is an ordinary download and is neither, because a prompt
 * that appears every time teaches people to type their password without reading
 * why.
 *
 * The confirmation itself is not enforced here. It is an attribute on the route,
 * which is what makes "contacts" and "no contacts" two different endpoints
 * rather than one endpoint with a flag somebody can forget to check.
 */
class ExportService {
	/** What each format is called, what it writes, and what it is served as. */
	private const FORMATS = ['geojson', 'csv', 'kml', 'kmz'];


	private const MIME = [
		'geojson' => 'application/geo+json',
		'csv' => 'text/csv; charset=utf-8',
		'kml' => 'application/vnd.google-earth.kml+xml',
		'kmz' => 'application/vnd.google-earth.kmz',
	];

	public function __construct(
		private FeatureMapper $features,
		private TaxonomyService $taxonomy,
		private ExportRecordMapper $log,
		private GeoJsonWriter $geoJson,
		private CsvWriter $csv,
		private KmlWriter $kml,
		private IUserSession $session,
	) {
	}

	/**
	 * Write the given records out, and record it if it carried anybody's details.
	 *
	 * The ids come from the browser because the filter lives in the browser: the
	 * View is personal (CONTEXT.md), the Capas panel and the search are client
	 * state, and asking the server to reconstruct them would mean two
	 * implementations of "the current filtered set" that can disagree. What the
	 * caller sees is what the caller gets.
	 *
	 * @param int[] $ids
	 * @return array{filename: string, mime: string, body: string}
	 * @throws InvalidFeatureException
	 */
	public function export(array $ids, string $format, bool $contacts, string $scope = ''): array {
		if (!in_array($format, self::FORMATS, true)) {
			throw new InvalidFeatureException('Formato desconocido: ' . $format);
		}

		// A KML carries no contact details whatever is asked for, so a KML on the
		// confirmed route is a password prompt for a file with nothing personal in
		// it — the unearned prompt ADR-0006 exists to avoid. Refused rather than
		// quietly downgraded, because a request nobody can mean is a bug in the
		// caller and silently doing something else hides it.
		//
		// A KMZ is that same KML in an archive, so it is refused by the same rule:
		// letting it through would prompt for a password AND write an entry to the
		// export log claiming contact data left the building when none did.
		if (($format === 'kml' || $format === 'kmz') && $contacts) {
			throw new InvalidFeatureException(
				'Un archivo KML no lleva datos de contacto; expórtelo sin marcarlos.',
			);
		}

		$rows = $this->rows($ids);

		$body = match ($format) {
			'geojson' => $this->geoJson->write($rows, $contacts),
			'csv' => $this->csv->write($rows, $contacts),
			'kml' => $this->kml->write($rows),
			// A KMZ is that KML in an archive (issue #102): rendered into the
			// open archive by `Kmz`, the one home of the temp-file dance.
			'kmz' => Kmz::archive(fn (): string => $this->kml->write($rows)),
		};

		if ($contacts) {
			$this->record($format, count($rows), $scope);
		}

		return [
			'filename' => 'territorio-' . (new DateTime())->format('Y-m-d') . '.' . $format,
			'mime' => self::MIME[$format],
			'body' => $body,
		];
	}

	/**
	 * The log, for the administrator's report (ADR-0006).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function history(): array {
		return array_map(
			static fn (ExportRecord $entry): array => $entry->toClient(),
			$this->log->findRecent(),
		);
	}

	private function record(string $format, int $records, string $scope): void {
		$entry = new ExportRecord();
		// An export always has a person behind it — the route requires a confirmed
		// password — so an empty actor here would mean something has gone wrong
		// rather than that this was a background job.
		$entry->setActor($this->session->getUser()?->getUID() ?? 'desconocido');
		$entry->setExportedAt(new DateTime());
		$entry->setFormat($format);
		$entry->setRecords($records);
		// What the person had on screen, in their own words — "búsqueda: plaza"
		// rather than a filter object. ADR-0006 asks for the scope, and a count
		// alone says how much left the building without saying what.
		$entry->setScope(mb_substr($scope, 0, 255));

		$this->log->insert($entry);
	}

	/**
	 * The requested records, in the shape the writers take.
	 *
	 * Read from the database rather than trusted from the request: the browser
	 * sends ids and nothing else, so nothing a caller invents can end up in a
	 * file that claims to be the Registry.
	 *
	 * Retired records are left out. They are absent from every default read for
	 * the same reason (ADR-0005), and a person exporting what is on their screen
	 * is not asking for what used to be there.
	 *
	 * the same threshold `findAll()` itself is waiting on.
	 *
	 * @param int[] $ids
	 * @return list<array<string, mixed>>
	 */
	private function rows(array $ids): array {
		$labels = $this->labelledSubcategories();

		$features = $this->features->findByIds($ids);
		// Once per export, not per row: every record inside one Sector repeats
		// the same id, and a comuna's export is hundreds of records over a
		// handful of divisions.
		$divisions = $this->divisionNames($features);

		$rows = [];
		foreach ($features as $feature) {
			$rows[] = $this->row($feature, $labels, $divisions);
		}

		return $rows;
	}

	/**
	 * @param array<int, array{slug: string, label: string}> $labels
	 * @param array<int, string> $divisions
	 * @return array<string, mixed>
	 */
	private function row(Feature $feature, array $labels, array $divisions): array {
		$classification = $labels[$feature->getSubcategoryId()] ?? null;

		return [
			'externalId' => $feature->getExternalId(),
			'name' => $feature->getName(),
			'categorySlug' => $classification === null ? null : explode('/', $classification['slug'])[0],
			'subcategorySlug' => $classification === null ? null : explode('/', $classification['slug'])[1],
			'categoryLabel' => $classification['label'] ?? null,
			// The assignment as the person reads it, not the ids it is stored as
			// (ADR-0008): the CSV's «Sector» and «Unidad Vecinal» cells, and empty
			// wherever the record points at nothing — or at a division nobody can
			// name any more.
			'sectorName' => $divisions[$feature->getSectorId()] ?? null,
			'unidadVecinalName' => $divisions[$feature->getUnidadVecinalId()] ?? null,
			'geometry' => $feature->getGeometry() === null
				? null
				: json_decode($feature->getGeometry(), true),
			'locationQuality' => $feature->getLocationQuality(),
			'address' => $feature->getAddress(),
			'phone' => $feature->getPhone(),
			'email' => $feature->getEmail(),
			'contactPerson' => $feature->getContactPerson(),
			'contactRole' => $feature->getContactRole(),
			'openingHours' => $feature->getOpeningHours(),
			'notes' => $feature->getNotes(),
			'sourceRef' => $feature->getSourceRef(),
			// Never written to a file — every writer takes only the keys it maps,
			// the writer tests hold all three to that, contact fields included. It
			// rides along so the licence decision has something a person cannot
			// edit: `sourceRef` is the Fuente box, `official` is what the authority
			// published and only an import writes it (ADR-0003, #28).
			'official' => $feature->getOfficial(),
			// As a sentence, not as the stored parts. An export is read by a
			// person or opened in a spreadsheet, and "Vicuña Mackenna y el canal"
			// is the answer; a list of ids into a Boundary Dataset the reader does
			// not have is not (issue #15).
			'limit' => self::readableLimit($feature->getBoundaryLimit()),
		];
	}

	/**
	 * The names of the divisions this export's records point at.
	 *
	 * The same shape as `labelledSubcategories()` — one map read per export —
	 * because the ids repeat and the names change never.
	 *
	 * @param Feature[] $features
	 * @return array<int, string>
	 */
	private function divisionNames(array $features): array {
		$ids = [];
		foreach ($features as $feature) {
			foreach ([$feature->getSectorId(), $feature->getUnidadVecinalId()] as $id) {
				if ($id !== null) {
					$ids[$id] = true;
				}
			}
		}

		return $this->features->namesAmong(array_keys($ids));
	}

	/**
	 * A Sector's Limit as somebody would say it.
	 *
	 * The same rule as the browser's `readable()` in `src/limit.js`, and the same
	 * reason for the deduplication: a comuna holds one street as dozens of ways,
	 * so a Limit records thirty parts named "Vicuña Mackenna" and saying it thirty
	 * times is not what anybody said.
	 */
	private static function readableLimit(?string $stored): ?string {
		if ($stored === null) {
			return null;
		}

		$parts = json_decode($stored, true);
		if (!is_array($parts)) {
			return null;
		}

		$names = [];
		foreach ($parts as $part) {
			$name = is_array($part) ? (string)($part['name'] ?? '') : '';
			if ($name !== '' && !in_array($name, $names, true)) {
				$names[] = $name;
			}
		}

		if ($names === []) {
			return null;
		}

		$last = array_pop($names);

		// "A, B y C" — Spanish takes no comma before the final "y".
		return $names === [] ? $last : implode(', ', $names) . ' y ' . $last;
	}

	/**
	 * Subcategory id → its slug pair and the label a person reads.
	 *
	 * Built once per export rather than joined per row: a comuna is one taxonomy
	 * and hundreds of records, and this is two reads instead of hundreds.
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	private function labelledSubcategories(): array {
		$labels = [];
		// The shared flat index (L1-20) — the third tree-walk gone.
		foreach ($this->taxonomy->subcategoryIndex() as $id => $pair) {
			$labels[$id] = [
				'slug' => $pair['category'] . '/' . $pair['subcategory'],
				// The same "Category · Subcategory" the list and the search show,
				// so a CSV column reads as the screen does.
				'label' => $pair['categoryLabel'] . ' · ' . $pair['subcategoryLabel'],
			];
		}

		return $labels;
	}
}
