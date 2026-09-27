<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Exception\ServerFaultException;
use ZipArchive;

/**
 * A KMZ is a KML inside a ZIP — one home for the bytes-to-archive mechanics
 * both directions share (L6-01).
 *
 * `ZipArchive` is PHP's own and cannot read or write from memory: `open()`
 * takes a path, `close()` writes to one. Both the browser upload and
 * `occ territorio:import` read the bytes before calling the service, so both
 * directions go through a temporary file — and the `finally` in each is what
 * makes that file the call's own rather than the request's: nothing else
 * removes it, and a refused call is the path where it would otherwise pile up.
 *
 * Each operation keeps its own refusal sentences and its own exception, so
 * the caller's catch stays the narrow one it is: reading throws
 * `InvalidImportException` (the CLI catches the concrete type; the import
 * controller rides the refusal mapping), writing throws
 * `ServerFaultException` (L1-08 — the server-fault half of the
 * ExportController catch).
 */
final class Kmz {
	/**
	 * The KML inside a KMZ is inflated into a string in memory, and a few KB
	 * of archive can declare gigabytes of it — a zip bomb (#129). A comuna's
	 * real KML is well under this: La Florida's 865 features are 567 KB, so
	 * the cap is ~14× the real thing. The const is the knob.
	 */
	private const MAX_KML_BYTES = 8 * 1024 * 1024;

	/**
	 * One sentence for the refusal, named so the guard and the test that pins
	 * it agree on what the person reads.
	 */
	private const KML_TOO_BIG = 'El archivo .kml dentro del KMZ supera los 8 MB al descomprimir.';

	/**
	 * The KML held by an archive's first `.kml` entry.
	 *
	 * **The document is the FIRST `.kml` entry, not `doc.kml`.** That name is
	 * Google Earth's convention and the format's rule is the position, which QGIS
	 * and ArcGIS take at their word and fill with `<capa>.kml`. Asking libzip for
	 * `doc.kml` by name returns false for those files, and the person would be
	 * told their perfectly valid KMZ held no map. The entries are walked in
	 * order, and anything ahead of the KML — an icon, an overlay image, a
	 * directory entry — is simply not a `.kml`.
	 *
	 * The entry's declared size is checked BEFORE it is inflated (#129): a zip
	 * bomb refuses for the price of one `statIndex` call instead of the memory
	 * its inflation would claim. The declared size is trusted — an archive
	 * lying low in its central directory is not covered, and every real bomb,
	 * written by a real zipper, declares its true size.
	 *
	 * @throws InvalidImportException
	 */
	public static function extractFirstKml(string $kmz): string {
		$path = tempnam(sys_get_temp_dir(), 'territorio-kmz-');
		if ($path === false) {
			throw new InvalidImportException('No se pudo preparar la lectura del archivo KMZ.');
		}

		// Everything after the file EXISTS happens inside the try, including
		// writing to it: a full disk is a failure like any other and must not be
		// the one path that leaves the file behind.
		try {
			if (file_put_contents($path, $kmz) === false) {
				throw new InvalidImportException('No se pudo preparar la lectura del archivo KMZ.');
			}

			$zip = new ZipArchive();
			if ($zip->open($path) !== true) {
				throw new InvalidImportException(
					'El archivo no es un KMZ: no se pudo abrir como archivo comprimido.',
				);
			}

			try {
				for ($entry = 0; $entry < $zip->numFiles; $entry++) {
					$name = (string)$zip->getNameIndex($entry);
					if (!str_ends_with(strtolower($name), '.kml')) {
						continue;
					}

					// #129: read the central directory's declared size and refuse
					// BEFORE inflating — and a stat that fails is not permission to
					// inflate either.
					$stat = $zip->statIndex($entry);
					if ($stat === false) {
						throw new InvalidImportException(sprintf(
							'No se pudo leer «%s» dentro del KMZ.',
							$name,
						));
					}
					if ($stat['size'] > self::MAX_KML_BYTES) {
						throw new InvalidImportException(self::KML_TOO_BIG);
					}

					$kml = $zip->getFromIndex($entry);
					if ($kml === false) {
						throw new InvalidImportException(sprintf(
							'No se pudo leer «%s» dentro del KMZ.',
							$name,
						));
					}

					return $kml;
				}
			} finally {
				$zip->close();
			}

			throw new InvalidImportException('El archivo KMZ no contiene ningún archivo .kml.');
		} finally {
			unlink($path);
		}
	}

	/**
	 * The KML a callable renders, written into an archive as its `doc.kml` and
	 * read back as bytes.
	 *
	 * The KML is rendered INTO the open archive — `$render` runs after the
	 * archive opens, inside the try — so a writer that throws halfway leaves a
	 * file behind unless something removes it, which is exactly the failure
	 * `ExportServiceTest` injects and the `finally` covers.
	 *
	 * The three failures below are the server's, not the caller's, and now they
	 * say so (L1-08): `ServerFaultException` is the app's way to say "our
	 * fault" in the person's language, and ExportController answers it 500 —
	 * still with the Spanish sentence, which a bare RuntimeException through
	 * the framework would lose, and no longer mis-filed under the caller in
	 * every 4xx monitor. The sentences themselves do not move.
	 *
	 * @param callable(): string $render writes the KML into the open archive
	 * @throws ServerFaultException
	 */
	public static function archive(callable $render): string {
		$path = tempnam(sys_get_temp_dir(), 'territorio-kmz-');
		if ($path === false) {
			throw new ServerFaultException('No se pudo preparar el archivo KMZ.');
		}

		try {
			$zip = new ZipArchive();
			if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
				throw new ServerFaultException('No se pudo preparar el archivo KMZ.');
			}

			// Closed only if the entry went in: `close()` on an archive with
			// nothing in it DELETES the file it was going to write (libzip), and
			// reading it back afterwards would be a warning about a missing file
			// instead of the refusal below.
			$written = $zip->addFromString('doc.kml', $render());
			$body = $written && $zip->close() ? file_get_contents($path) : false;
			if ($body === false) {
				throw new ServerFaultException('No se pudo escribir el archivo KMZ.');
			}

			return $body;
		} finally {
			unlink($path);
		}
	}
}
