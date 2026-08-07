<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

/**
 * The Datos view as a spreadsheet (issue #17).
 *
 * Lossy on purpose and in one direction only: a CSV is for reading, sorting and
 * printing, not for coming back in. Nothing here tries to be re-importable —
 * that is what the GeoJSON export is for, and pretending a spreadsheet could do
 * both would produce a file that is bad at each.
 *
 * Columns are in Spanish because the file is opened by the people who use the
 * app, in the spreadsheet on their own machine, with no key to look anything up
 * in.
 *
 * Depends on no OCP class, so it tests in bare PHP.
 */
class CsvWriter {
	/** @var array<string, string> column heading → row key */
	private const PLAIN = [
		'Nombre' => 'name',
		'Clasificación' => 'categoryLabel',
		'Dirección' => 'address',
		'Horario' => 'openingHours',
		'Notas' => 'notes',
		'Fuente' => 'sourceRef',
		'Límite' => 'limit',
		'Ubicación' => 'locationQuality',
	];

	/** The columns that describe a person rather than a place (ADR-0006). */
	private const CONTACT = [
		'Teléfono' => 'phone',
		'Correo' => 'email',
		'Persona de contacto' => 'contactPerson',
		'Cargo' => 'contactRole',
	];

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param bool $contacts whether the file may carry contact columns at all
	 */
	public function write(array $rows, bool $contacts): string {
		$columns = $contacts ? self::PLAIN + self::CONTACT : self::PLAIN;
		// Last, because a person reading this scans names first and coordinates
		// only when they need them.
		$headings = [...array_keys($columns), 'Latitud', 'Longitud'];

		// fputcsv rather than implode: a Chilean address has commas in it and a
		// note can have quotes in it, and one unquoted comma shifts every column
		// after it for the rest of the file — silently, and only for the rows a
		// person actually wrote something in.
		$handle = fopen('php://temp', 'r+');
		fputcsv($handle, $headings, ',', '"', '');

		foreach ($rows as $row) {
			[$lat, $lon] = self::coordinate($row['geometry'] ?? null);
			fputcsv($handle, [
				...array_map(
					static fn (string $key): string => self::text($row[$key] ?? ''),
					array_values($columns),
				),
				$lat,
				$lon,
			], ',', '"', '');
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		// A BOM, so that Excel opens a Spanish name as "Ñuñoa" rather than
		// "Ã'uÃ±oa". LibreOffice asks; Excel does not, it just guesses Latin-1.
		return "\u{FEFF}" . $csv;
	}

	/**
	 * One cell, with anything a spreadsheet would run defused.
	 *
	 * Excel and LibreOffice treat a cell beginning with `=`, `+`, `-` or `@` as a
	 * formula and evaluate it the moment the file is opened — `=HYPERLINK(...)`
	 * and worse. Every user of this instance can edit every record (ADR-0005), so
	 * "a colleague typed it" is not a defence: the formula runs on the machine of
	 * whoever opens the export, which is usually somebody else.
	 *
	 * A leading apostrophe is the spreadsheet's own "this is text" marker: Excel
	 * and LibreOffice do not display it, so the cell reads exactly as it was
	 * typed. It IS in the bytes, so a script reading this file sees it — which is
	 * the trade this file is for. A CSV here is for a person with a spreadsheet;
	 * the machine-readable export is the GeoJSON, and it needs none of this
	 * because nothing evaluates JSON on open.
	 *
	 * A phone number beginning with `+56` gets the marker too. That is the price
	 * of not guessing which leading `+` is arithmetic.
	 */
	private static function text(mixed $value): string {
		$text = (string)$value;

		return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'" . $text : $text;
	}

	/**
	 * Latitude and longitude, but only where the record IS a single point.
	 *
	 * A Sector is a polygon and its centre is not where the Sector is. A column
	 * quietly holding a made-up point is worse than an empty one: somebody sorts
	 * by it, or drives to it.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function coordinate(mixed $geometry): array {
		if (!is_array($geometry) || ($geometry['type'] ?? null) !== 'Point') {
			return ['', ''];
		}

		$position = $geometry['coordinates'] ?? null;
		if (!is_array($position) || count($position) < 2) {
			return ['', ''];
		}

		// GeoJSON stores longitude first; everybody else writes latitude first,
		// including this app's own form.
		return [(string)$position[1], (string)$position[0]];
	}
}
