<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use APS\Common\Csv;
use OCA\Territorio\Domain\OsmAttribution;

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
		// The assignment, immediately after what a record IS — the same order
		// the Índice reads its columns in.
		'Sector' => 'sectorName',
		'Unidad Vecinal' => 'unidadVecinalName',
		'Dirección' => 'address',
		'Horario' => 'openingHours',
		'Notas' => 'notes',
		'Fuente' => 'sourceRef',
		'Límite' => 'limit',
		'Ubicación' => 'locationQuality',
	];

	/** The columns that describe a person rather than a place (ADR-0006).
	 *  `ContactFieldsTest` holds this to the GeoJSON writer's set. */
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

		// A COLUMN, because RFC 4180 has no comment syntax (#28): a line above the
		// header breaks header detection and a trailing line of a different width
		// breaks a strict parser, so a cell is the only place a CSV can hold the
		// licence without breaking whoever reads the file. Present only when the
		// set needs it, so a file of the clinic's own records does not grow an
		// empty column nobody can explain.
		$licence = OsmAttribution::required($rows);
		if ($licence) {
			$headings[] = 'Licencia';
		}

		// fputcsv rather than implode: a Chilean address has commas in it and a
		// note can have quotes in it, and one unquoted comma shifts every column
		// after it for the rest of the file — silently, and only for the rows a
		// person actually wrote something in.
		$handle = fopen('php://temp', 'r+');
		fputcsv($handle, $headings, ',', '"', '');

		foreach ($rows as $row) {
			[$lat, $lon] = self::coordinate($row['geometry'] ?? null);
			$cells = [
				...array_map(
					static fn (string $key): string => self::text($row[$key] ?? ''),
					array_values($columns),
				),
				$lat,
				$lon,
			];
			if ($licence) {
				// Only on the rows it is about: a mixed set is the normal case, and
				// the same line on every row would say the municipality's own
				// catastro belongs to OpenStreetMap.
				$cells[] = OsmAttribution::isFromOsm($row) ? OsmAttribution::NOTICE : '';
			}
			fputcsv($handle, $cells, ',', '"', '');
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		// A BOM, so that Excel opens a Spanish name as "Ñuñoa" rather than
		// "Ã'uÃ±oa". LibreOffice asks; Excel does not, it just guesses Latin-1.
		return Csv::BOM . $csv;
	}

	/**
	 * One cell, defused — the shared rule (aps-common's Csv::text, where the
	 * formula-threat reasoning and the apostrophe trade live). Kept as a
	 * private forward so the writer's own docblock story stays local.
	 */
	private static function text(mixed $value): string {
		return Csv::text($value);
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
