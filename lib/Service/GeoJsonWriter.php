<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

/**
 * Writes rows back out as the FeatureCollection `GeoJsonReader` reads (issue #17).
 *
 * The lossless one of the three exports, and "lossless" is a promise about
 * re-import rather than about the file being pretty: every property name here is
 * one the reader looks for, and `uid` carries the external id so that importing
 * an export UPDATES the Registry instead of duplicating it (ADR-0003).
 *
 * That promise is checked by reading what this writes with the reader itself,
 * which was written for the real catastro and knows nothing about this class.
 *
 * Depends on no OCP class, so it tests in bare PHP — the same rule the reader
 * follows, for the same reason.
 */
class GeoJsonWriter {
	/**
	 * The properties that describe a person rather than a place (ADR-0006).
	 *
	 * `address` is not among them on purpose: where a junta meets is a fact about
	 * the territory, and it is on the sign. A name, a phone number and an email
	 * address are the presidenta's.
	 *
	 * @var array<string, string> row key → GeoJSON property name
	 */
	private const CONTACT = [
		'phone' => 'phone',
		'email' => 'email',
		'contactPerson' => 'contact_person',
		'contactRole' => 'contact_role',
	];

	/** Everything else, in the reader's own spelling. */
	private const PLAIN = [
		'categorySlug' => 'category',
		'subcategorySlug' => 'subcategory',
		'locationQuality' => 'ubicacion',
		'address' => 'address',
		'openingHours' => 'opening_hours',
		'notes' => 'notes',
	];

	/**
	 * @param list<array<string, mixed>> $rows as `GeoJsonReader` produces them
	 * @param bool $contacts whether the file may carry contact fields at all
	 */
	public function write(array $rows, bool $contacts): string {
		$features = array_map(
			fn (array $row): array => $this->feature($row, $contacts),
			$rows,
		);

		return (string)json_encode(
			// array_values, because a caller filtering its rows leaves gaps in the
			// keys and json_encode would then write an object where GeoJSON
			// requires an array.
			['type' => 'FeatureCollection', 'features' => array_values($features)],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
		);
	}

	/** @param array<string, mixed> $row */
	private function feature(array $row, bool $contacts): array {
		$properties = ['name' => $row['name']];

		// uid rather than the collection-level id: the reader looks at `uid` first
		// and it is the property the catastro this app inherits actually uses.
		if (($row['externalId'] ?? null) !== null) {
			$properties['uid'] = $row['externalId'];
		}

		$fields = $contacts ? self::PLAIN + self::CONTACT : self::PLAIN;
		foreach ($fields as $key => $property) {
			if (($row[$key] ?? null) !== null) {
				$properties[$property] = $row[$key];
			}
		}

		// Written whole into source_es and never split back into a label and a
		// URL. The reader joins those two with an em dash into one stored string,
		// and re-splitting on a dash a person may have typed would corrupt the
		// value it is meant to preserve.
		if (($row['sourceRef'] ?? null) !== null) {
			$properties['source_es'] = $row['sourceRef'];
		}

		return [
			'type' => 'Feature',
			'properties' => $properties,
			// Null, not omitted: a record with no coordinate is a real record, and
			// GeoJSON says a Feature's geometry member may be null.
			'geometry' => $row['geometry'] ?? null,
		];
	}
}
