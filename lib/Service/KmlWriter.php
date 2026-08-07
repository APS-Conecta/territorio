<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

/**
 * Geometry and name, for looking at somewhere else (issue #17).
 *
 * The lossiest of the three and the one whose criterion is about honesty rather
 * than fidelity: it must STATE which fields it cannot carry. The statement goes
 * in the file, not only in the dialog, because the file is what gets emailed to
 * somebody who never saw the dialog and who will otherwise conclude the Registry
 * holds nothing but names.
 *
 * It carries no contact details at all, whatever the caller asks for. A KML is
 * opened in Google Earth and passed around; that is the wrong shape of thing to
 * put a presidenta's mobile number in, and ADR-0006's confirmation is about
 * deliberate exports rather than about making every format capable of one.
 *
 * Depends on no OCP class, so it tests in bare PHP.
 */
class KmlWriter {
	/** Said in the file, where whoever opens it will see it. */
	private const CANNOT_CARRY = 'Este archivo KML lleva solamente el nombre y la ubicación de cada '
		. 'registro. No lleva teléfono, correo, persona de contacto, cargo, dirección, horario, '
		. 'notas, fuente ni categoría. Para un archivo completo, exporte en formato GeoJSON.';

	/** @param list<array<string, mixed>> $rows */
	public function write(array $rows): string {
		$placemarks = '';
		foreach ($rows as $row) {
			$placemarks .= $this->placemark($row);
		}

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<kml xmlns="http://www.opengis.net/kml/2.2"><Document>' . "\n"
			. '<name>Territorio</name>' . "\n"
			. '<description>' . self::escape(self::CANNOT_CARRY) . '</description>' . "\n"
			. $placemarks
			. '</Document></kml>' . "\n";
	}

	/** @param array<string, mixed> $row */
	private function placemark(array $row): string {
		$geometry = $row['geometry'] ?? null;
		$shape = is_array($geometry) ? $this->shape($geometry) : null;

		// A record with no coordinate is left out rather than written without one.
		// KML is a map format; a Placemark with nowhere to be is not something
		// Google Earth can show, and a silent empty pin at the null island is the
		// worst of the available answers.
		if ($shape === null) {
			return '';
		}

		return '<Placemark><name>' . self::escape((string)($row['name'] ?? '')) . '</name>'
			. $shape . '</Placemark>' . "\n";
	}

	/** @param array<string, mixed> $geometry */
	private function shape(array $geometry): ?string {
		$coordinates = $geometry['coordinates'] ?? null;
		if (!is_array($coordinates)) {
			return null;
		}

		return match ($geometry['type'] ?? null) {
			'Point' => '<Point><coordinates>' . self::positions([$coordinates]) . '</coordinates></Point>',
			'LineString' => '<LineString><coordinates>' . self::positions($coordinates) . '</coordinates></LineString>',
			'Polygon' => $this->polygon($coordinates),
			// Everything else — the multi-part geometries — is left out rather
			// than half-written. The notice already says this file is partial.
			default => null,
		};
	}

	/** @param array<int, mixed> $rings */
	private function polygon(array $rings): ?string {
		$outer = $rings[0] ?? null;
		if (!is_array($outer)) {
			return null;
		}

		// The outer ring only. KML expresses a hole as an innerBoundaryIs and a
		// Sector drawn in this app has none, so carrying them would be code for a
		// case the Registry cannot produce.
		return '<Polygon><outerBoundaryIs><LinearRing><coordinates>'
			. self::positions($outer)
			. '</coordinates></LinearRing></outerBoundaryIs></Polygon>';
	}

	/**
	 * KML writes "lon,lat" separated by spaces — the same order as GeoJSON, which
	 * is the one piece of luck in this format.
	 *
	 * @param array<int, mixed> $positions
	 */
	private static function positions(array $positions): string {
		$written = [];
		foreach ($positions as $position) {
			if (is_array($position) && count($position) >= 2) {
				$written[] = $position[0] . ',' . $position[1];
			}
		}

		return implode(' ', $written);
	}

	private static function escape(string $value): string {
		return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}
