<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use APS\Common\Csv;
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

	/**
	 * The two columns whose CELL is a classification and is folded like a
	 * heading, not passed through like a phone number.
	 */
	private const CLASSIFICATION = ['category', 'subcategory'];

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
	 * The file as UTF-8 — the shared rule, stated once in aps-common's Csv.
	 * See Csv::utf8() for the 1252-fallback reasoning and the no-BOM-strip
	 * doctrine; CsvReaderTest covers the BOM through the real path.
	 */
	private static function utf8(string $csv): string {
		return Csv::utf8($csv);
	}

	/**
	 * Every line of the file, parsed — blank ones included (PHP answers a
	 * blank line with [null]); `feature()` is what decides that a row holds
	 * no record. The RFC 4180 stream dialect is aps-common's Csv::rows.
	 *
	 * @return list<list<?string>>
	 */
	private static function rows(string $csv): array {
		return Csv::rows($csv);
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
	 * The «Categoría» and «Subcategoría» cells are folded with the same
	 * `slugFor()` the headings are matched on, because they are the one pair a
	 * person TYPES: they copy what the Capas panel shows them — «Salud»,
	 * «Educación», «Organización social» — while `ImportService::classify()`
	 * looks the pair up in an index keyed by slug. Unfolded, every such row lands
	 * in Sin clasificar silently, with no count that could explain it. Slugified,
	 * not matched, for the reason `KmlReader::folderOf()` gives about the Folder
	 * name it reads: which Categories exist is the import's business, not the
	 * reader's.
	 *
	 * ponytail: folding only. `slugFor('Deporte y recreación')` is
	 * `deporte_y_recreacion` while the seed's slug is `deporte_recreacion` — the
	 * seed drops short words — so that one row still falls to Sin clasificar,
	 * which is exactly where it fell before. Matching on the Category's LABEL is
	 * the fix for the residue, it belongs in `ImportService` beside the other two
	 * matching heuristics, and it is its own issue.
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
				$values[$key] = in_array($key, self::CLASSIFICATION, true)
					? TaxonomyService::slugFor($value)
					: $value;
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
	 *
	 * An unreadable cell — «s/i», «-33°27'», a stray unit — is deliberately the
	 * same as an empty one: null, so the record imports with no geometry. No
	 * count is owed for it because the record says so itself, and says it in the
	 * place somebody looking for missing coordinates already looks: it sits at
	 * Location quality Absent in the Registry, alongside every record whose
	 * address never resolved. That is unlike GeoJSON's «geometría no soportada»,
	 * which IS counted, because a shape this app cannot store leaves nothing
	 * behind to find.
	 *
	 * ponytail: no count distinguishes «filled but unreadable» from «never
	 * supplied». Carry one through `GeoJsonReader` to the ImportBatch when a
	 * clinic's file turns out to be full of them and the Registry filter is not
	 * enough to find them.
	 */
	private static function number(?string $cell): ?float {
		$plain = str_replace(',', '.', (string)$cell);

		return is_numeric($plain) ? (float)$plain : null;
	}
}
