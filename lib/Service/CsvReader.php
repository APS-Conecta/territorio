<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Exception\InvalidImportException;

/**
 * Reads a spreadsheet — in practice, the one a clinic sends (#101).
 *
 * It does not build rows itself. It turns the file into a GeoJSON
 * FeatureCollection and hands that to `GeoJsonReader`, exactly as `KmlReader`
 * does, so a CSV import IS a GeoJSON import: the same derived external ids, the
 * same refusal of a nameless row, the same row shape. Two readers building rows
 * separately is how the formats would quietly start importing differently.
 *
 * **It does not read this app's own CSV export back in.** `CsvWriter` says the
 * export is "lossy on purpose and in one direction only" (issue #17) and that
 * stands: it carries no id, its «Clasificación» is one label rather than the two
 * slugs, and its contact cells carry the apostrophe that stops a spreadsheet
 * evaluating them. GeoJSON is the format that comes back in, and the import
 * dialog says so. What this reads is somebody else's spreadsheet.
 *
 * Parsed with `fgetcsv`, which is in PHP already. A CSV a person edited has
 * commas inside quoted cells and line breaks inside quoted notes, and a library
 * would be a dependency to do what the standard library does.
 *
 * Depends on no OCP class, so it tests in bare PHP.
 */
class CsvReader {
	/**
	 * Heading → the property `GeoJsonReader` reads it under.
	 *
	 * Keyed by the heading's slug, so «Teléfono», «telefono» and «TELEFONO » are
	 * one column: the file is typed by hand in a spreadsheet, not generated.
	 *
	 * The headings are the Spanish ones `CsvWriter` emits, because they are the
	 * words the people using this app already see. Fixed, not configurable — a
	 * clinic whose columns are named something else can ask for a mapping in its
	 * own issue, and until somebody does, a configurable mapping is a screen
	 * nobody has needed.
	 *
	 * «Fuente» lands on `source_es` and not on `source`, which looks like the
	 * closer name and is not: `source` also prefixes the derived external id, so
	 * a file whose Fuente column varies row by row would derive ids that no
	 * longer belong to one Dataset (ADR-0003). `source_es` is the label, which is
	 * what the cell holds.
	 */
	private const COLUMNS = [
		'nombre' => 'name',
		'categoria' => 'category',
		'subcategoria' => 'subcategory',
		'direccion' => 'address',
		'telefono' => 'phone',
		'correo' => 'email',
		'persona_de_contacto' => 'contact_person',
		'cargo' => 'contact_role',
		'horario' => 'opening_hours',
		'notas' => 'notes',
		'fuente' => 'source_es',
		'ubicacion' => 'ubicacion',
	];

	/** The two columns that are a position rather than a property. */
	private const LATITUDE = 'latitud';
	private const LONGITUDE = 'longitud';

	public function __construct(
		private GeoJsonReader $geoJson,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 * @throws InvalidImportException
	 */
	public function read(string $csv, string $datasetSlug): array {
		return $this->geoJson->fromCollection($this->asCollection($csv), $datasetSlug);
	}

	/**
	 * @return array<string, mixed>
	 * @throws InvalidImportException
	 */
	private function asCollection(string $csv): array {
		$rows = self::rows(self::utf8($csv));
		$headings = array_shift($rows);

		if ($headings === null) {
			throw new InvalidImportException('El archivo está vacío.');
		}

		$columns = self::columns($headings);
		if (!in_array('name', $columns, true)) {
			// The whole file turns on this one column, so the refusal names it and
			// names the rest rather than saying the file is invalid.
			throw new InvalidImportException(
				'La primera fila debe ser el encabezado y no tiene una columna «Nombre». '
				. 'Las columnas que se leen son: Nombre, Categoría, Subcategoría, Latitud, '
				. 'Longitud, Dirección, Teléfono, Correo, Persona de contacto, Cargo, '
				. 'Horario, Notas, Fuente y Ubicación. Las demás se ignoran.',
			);
		}

		$features = [];
		foreach ($rows as $cells) {
			$feature = self::feature($columns, $cells);
			if ($feature !== null) {
				$features[] = $feature;
			}
		}

		// A header with no rows under it is not refused: it is an empty file in
		// the same sense an empty FeatureCollection is, and GeoJSON imports that
		// as a run that created nothing. A CSV with no header at all IS refused,
		// above, because there is no reading of it that could work.
		return ['type' => 'FeatureCollection', 'features' => $features];
	}

	/**
	 * The file as UTF-8, decided rather than guessed.
	 *
	 * Bytes that are not valid UTF-8 are read as Windows-1252, which is what
	 * Excel writes when it is not asked for UTF-8. That is a rule, not a
	 * heuristic — a 1252 file with any accent in it is invalid UTF-8, and a valid
	 * UTF-8 file is never touched. `mb_detect_encoding` would answer the same
	 * question by guessing, differently on different files.
	 *
	 * There is deliberately no BOM strip here. Excel writes one in front of a
	 * UTF-8 CSV and so does `CsvWriter`, and it ends up on the first heading —
	 * but headings are matched on their slug, and the marker is neither a letter
	 * nor a digit, so `TaxonomyService::slugFor()` folds it out with the spaces
	 * and the accents. A strip here was written first and then removed: nothing
	 * could be made to fail without it, and a line no test can hold is a line
	 * nobody can trust. `CsvReaderTest` covers the BOM through the real path.
	 */
	private static function utf8(string $csv): string {
		return mb_check_encoding($csv, 'UTF-8')
			? $csv
			: (string)mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
	}

	/**
	 * Every non-empty line, parsed.
	 *
	 * `fgetcsv` over a stream rather than `str_getcsv` per line, because a Notas
	 * cell somebody typed into a spreadsheet can contain a line break and only
	 * the stream reader knows that the quoted field has not ended.
	 *
	 * @return list<list<?string>>
	 */
	private static function rows(string $csv): array {
		$handle = fopen('php://temp', 'r+');
		fwrite($handle, $csv);
		rewind($handle);

		$delimiter = self::delimiterOf($csv);
		$rows = [];
		// The empty `$escape` is what `CsvWriter` passes too: PHP's historical
		// backslash escape is not in RFC 4180, is deprecated from 8.4, and would
		// eat a backslash somebody typed in an address.
		//
		// Blank lines are NOT filtered here. `feature()` already drops a row with
		// nothing in the columns this reader reads, which is what a blank line is,
		// and a second filter for the same case is one no test can fail on.
		while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
			$rows[] = $cells;
		}
		fclose($handle);

		return $rows;
	}

	/**
	 * Which character separates the cells, read off the header line.
	 *
	 * Excel in es-CL writes `;`, because the comma is already the decimal
	 * separator in this locale — a comma-only reader mis-parses every file a
	 * Chilean clinic saves. Decided by counting on the FIRST line only: that line
	 * is headings, so a comma in it is a separator and not part of an address.
	 *
	 * ponytail: two candidates, not sniffing. A tab-separated file would be read
	 * as one column and refused for having no «Nombre»; add the tab here when
	 * somebody brings one.
	 */
	private static function delimiterOf(string $csv): string {
		$heading = strstr($csv, "\n", true);
		$heading = $heading === false ? $csv : $heading;

		return substr_count($heading, ';') > substr_count($heading, ',') ? ';' : ',';
	}

	/**
	 * Column index → what that column is, for the columns this reader knows.
	 *
	 * Unknown columns are simply absent from the map and so are ignored: a
	 * spreadsheet always carries a code, a total, or a note to whoever filled it
	 * in, and refusing the file for them would make this unusable on a real one.
	 * There is no `website` field on a Feature, so a column named that is
	 * ignored like any other.
	 *
	 * An allow-list rather than "pass everything through and let the row builder
	 * pick": a spreadsheet heading that happened to slug to `uid` or `source`
	 * would then reach into the DERIVED external id, and the same file saved with
	 * one more column would import as a second set of records (ADR-0003).
	 *
	 * Matched on `TaxonomyService::slugFor()`, the repo's own folding: it takes
	 * the accents off, the case down and the punctuation out, so «Teléfono» and
	 * «Telefono» are the same column and a heading nobody accented still matches.
	 *
	 * @param list<?string> $headings
	 * @return array<int, string>
	 */
	private static function columns(array $headings): array {
		$columns = [];
		foreach ($headings as $index => $heading) {
			$slug = TaxonomyService::slugFor((string)$heading);
			$known = self::COLUMNS[$slug]
				?? (in_array($slug, [self::LATITUDE, self::LONGITUDE], true) ? $slug : null);
			if ($known !== null) {
				$columns[$index] = $known;
			}
		}

		return $columns;
	}

	/**
	 * One row as a GeoJSON Feature, or null where the row holds nothing this
	 * reader can see.
	 *
	 * A row whose known columns are all empty is a spreadsheet's leftover rather
	 * than a record. A row that HAS a value and no name is passed on and refused
	 * downstream, which is the same rule GeoJSON and KML rows meet: a nameless
	 * record is unfindable in the list and unidentifiable in the duplicate queue.
	 *
	 * @param array<int, string> $columns
	 * @param list<?string> $cells
	 * @return ?array<string, mixed>
	 */
	private static function feature(array $columns, array $cells): ?array {
		$values = [];
		foreach ($columns as $index => $key) {
			$value = trim((string)($cells[$index] ?? ''));
			if ($value !== '') {
				$values[$key] = $value;
			}
		}

		if ($values === []) {
			return null;
		}

		$geometry = self::point($values[self::LATITUDE] ?? null, $values[self::LONGITUDE] ?? null);
		unset($values[self::LATITUDE], $values[self::LONGITUDE]);

		return ['type' => 'Feature', 'properties' => $values, 'geometry' => $geometry];
	}

	/**
	 * The position two cells describe, or null where they do not describe one.
	 *
	 * Half a coordinate is no coordinate, and neither is a cell holding a word.
	 * Both leave the record with no geometry rather than refusing the file: the
	 * record is real even when the cell is not — the catastro is full of
	 * organisations whose address never resolved — and refusing would cost the
	 * person every other row in the file.
	 *
	 * @return ?array<string, mixed>
	 */
	private static function point(?string $latitude, ?string $longitude): ?array {
		$lat = self::number($latitude);
		$lon = self::number($longitude);

		// GeoJSON stores longitude first; everybody else writes latitude first,
		// including this app's own form and the spreadsheet's own columns.
		return $lat === null || $lon === null
			? null
			: ['type' => 'Point', 'coordinates' => [$lon, $lat]];
	}

	/**
	 * A coordinate cell as a number, accepting the decimal comma.
	 *
	 * `-33,5211` is what Excel writes in es-CL, where the comma IS the decimal
	 * separator. There is no ambiguity to resolve: a coordinate has no thousands
	 * group, so a comma in one of these two cells is a decimal point and nothing
	 * else.
	 */
	private static function number(?string $cell): ?float {
		$plain = str_replace(',', '.', (string)$cell);

		return is_numeric($plain) ? (float)$plain : null;
	}
}
