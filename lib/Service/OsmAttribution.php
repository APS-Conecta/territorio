<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

/**
 * The line an export has to carry when it holds OpenStreetMap's data (issue #28).
 *
 * ODbL asks for the LICENCE to be named and not only the source, and it asks it
 * of whoever passes the data on — which is what a clinic does the day it hands a
 * Sector file to the municipality, the normal reason the app offers three export
 * formats at all. Crediting OSM on the map (`Basemap`) covers the tiles a browser
 * fetched and says nothing about the records inside a file.
 *
 * Here rather than in each writer because "this export holds OSM data" is one
 * fact about the set: the three formats disagree only about where to put the
 * sentence, and three copies of the sentence would drift into three licences.
 *
 * Depends on no OCP class, so it tests in bare PHP — the same rule the writers
 * follow.
 */
final class OsmAttribution {
	/**
	 * Spanish, because the person who opens the file works at a municipality,
	 * with the credit left in OSM's own words: `© OpenStreetMap contributors` is
	 * the attribution OSM asks for verbatim, and this app already renders it
	 * unchanged on the map.
	 *
	 * Deliberately not `Basemap::DEFAULT_ATTRIBUTION`, which is the same text
	 * today. That one is about tiles, and an administrator repointing the app at
	 * their own tile server changes what it should say; this one is about the
	 * data in the file, and nobody may change it.
	 */
	public const NOTICE = '© OpenStreetMap contributors, licencia ODbL — '
		. 'https://www.openstreetmap.org/copyright';

	/**
	 * Whether a set of export rows holds anything of OpenStreetMap's.
	 *
	 * @param list<array<string, mixed>> $rows as `ExportService::row()` builds them
	 */
	public static function required(array $rows): bool {
		foreach ($rows as $row) {
			if (self::isFromOsm($row)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Keyed on the row's own source, which is all an exported row knows about
	 * where it came from: OSM-derived Datasets arrive as GeoJSON stamped
	 * `source_es: OpenStreetMap` with a `source_url` (ADR-0004,
	 * `tools/boundary-features.php:160`), and `GeoJsonReader::sourceRef()` joins
	 * the two into this field.
	 *
	 * Matched loosely and on purpose. A hand-typed source that happens to mention
	 * OSM makes an export carry one line it did not strictly owe; a stricter
	 * match that misses one makes the export a licence breach. The two costs are
	 * not comparable.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function isFromOsm(array $row): bool {
		return stripos((string)($row['sourceRef'] ?? ''), 'OpenStreetMap') !== false;
	}
}
