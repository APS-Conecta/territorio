<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use DateTime;
use OCA\Territorio\Db\CategoryMapper;
use OCA\Territorio\Db\ExportRecord;
use OCA\Territorio\Db\ExportRecordMapper;
use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCP\IUserSession;

/**
 * Taking the filtered set out of the app (issue #17, ADR-0006).
 *
 * Three formats, one set. The format decides only which writer runs, exactly as
 * it decides only which reader runs on the way in — so "the current filtered
 * set" means the same thing in all three and cannot drift between them.
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
	private const FORMATS = ['geojson', 'csv', 'kml'];

	private const MIME = [
		'geojson' => 'application/geo+json',
		'csv' => 'text/csv; charset=utf-8',
		'kml' => 'application/vnd.google-earth.kml+xml',
	];

	public function __construct(
		private FeatureMapper $features,
		private CategoryMapper $categories,
		private SubcategoryMapper $subcategories,
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
	public function export(array $ids, string $format, bool $contacts): array {
		if (!in_array($format, self::FORMATS, true)) {
			throw new InvalidFeatureException('Formato desconocido: ' . $format);
		}

		$rows = $this->rows($ids);

		// KML carries no contact details in any case, so asking for them changes
		// nothing about the file. It still counts as a contact-bearing export for
		// the confirmation, because the person asked for one — but there is no
		// point recording that a KML took contacts out of the building when it
		// demonstrably did not.
		$body = match ($format) {
			'geojson' => $this->geoJson->write($rows, $contacts),
			'csv' => $this->csv->write($rows, $contacts),
			'kml' => $this->kml->write($rows),
		};

		if ($contacts && $format !== 'kml') {
			$this->record($format, count($rows));
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

	private function record(string $format, int $records): void {
		$entry = new ExportRecord();
		// An export always has a person behind it — the route requires a confirmed
		// password — so an empty actor here would mean something has gone wrong
		// rather than that this was a background job.
		$entry->setActor($this->session->getUser()?->getUID() ?? 'desconocido');
		$entry->setExportedAt(new DateTime());
		$entry->setFormat($format);
		$entry->setRecords($records);
		$entry->setContacts(true);

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
	 * @param int[] $ids
	 * @return list<array<string, mixed>>
	 */
	private function rows(array $ids): array {
		$wanted = array_flip(array_map('intval', $ids));
		$taxonomy = $this->taxonomy();

		$rows = [];
		foreach ($this->features->findAll() as $feature) {
			if (!isset($wanted[(int)$feature->getId()])) {
				continue;
			}

			$rows[] = $this->row($feature, $taxonomy);
		}

		return $rows;
	}

	/**
	 * @param array<int, array{slug: string, label: string}> $taxonomy
	 * @return array<string, mixed>
	 */
	private function row(Feature $feature, array $taxonomy): array {
		$classification = $taxonomy[$feature->getSubcategoryId()] ?? null;

		return [
			'externalId' => $feature->getExternalId(),
			'name' => $feature->getName(),
			'categorySlug' => $classification === null ? null : explode('/', $classification['slug'])[0],
			'subcategorySlug' => $classification === null ? null : explode('/', $classification['slug'])[1],
			'categoryLabel' => $classification['label'] ?? null,
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
		];
	}

	/**
	 * Subcategory id → its slug pair and the label a person reads.
	 *
	 * Built once per export rather than joined per row: a comuna is one taxonomy
	 * and hundreds of records, and this is two reads instead of hundreds.
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	private function taxonomy(): array {
		$categories = [];
		foreach ($this->categories->findAll() as $category) {
			$categories[(int)$category->getId()] = $category;
		}

		$index = [];
		foreach ($this->subcategories->findAll() as $subcategory) {
			$category = $categories[$subcategory->getCategoryId()] ?? null;
			if ($category === null) {
				continue;
			}

			$index[(int)$subcategory->getId()] = [
				'slug' => $category->getSlug() . '/' . $subcategory->getSlug(),
				// The same "Category · Subcategory" the list and the search show,
				// so a CSV column reads as the screen does.
				'label' => $category->getLabel() . ' · ' . $subcategory->getLabel(),
			];
		}

		return $index;
	}
}
