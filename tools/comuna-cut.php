<?php

declare(strict_types=1);

/**
 * Cut the national Unidades Vecinales cadastre down to one comuna.
 *
 *   php tools/comuna-cut.php --file=UnidadesVecinales_2024v4.shp --comuna=13110 > uv-13110.geojson
 *   occ territorio:import uv-13110.geojson --dataset=unidades-vecinales --label="Unidades Vecinales"
 *
 * A repo-side tool, run once per comuna, and the reason it exists is that **the
 * app must never need the national file**: 128 MB, 6,887 polygons, 346 comunas.
 * One comuna is typically under 100 KB, which is a thing a clinic can import and
 * a release can carry as an asset.
 *
 * EPSG:4674 (SIRGAS 2000) is already degrees, so there is no projection to
 * undo. The `.shp` path still asks GDAL for EPSG:4326, which is a sub-metre
 * datum shift rather than a reprojection: RFC 7946 says GeoJSON is WGS84, and
 * saying so costs nothing. UTF-8 throughout.
 *
 * A `.shp` is converted with `ogr2ogr` (GDAL), which is the tool for the job and
 * is not a dependency of this app — it runs on whoever's machine cuts the file,
 * once. Hand it a `.geojson` instead and it skips that step entirely.
 *
 * ponytail: the conversion shells out and is not covered by any test here, since
 * GDAL is not in the test image. `cutComuna()`, which is where every decision
 * lives, is pure and tested.
 */

// One namespace per tool: each declares a `main()`, and the unit tests
// `require_once` both files into the same process.
namespace Territorio\Tools\Comuna;

use InvalidArgumentException;
use RuntimeException;

/** The attribute names in `UnidadesVecinales_2024v4`, checked against the file. */
const COMUNA_FIELD = 't_com';
const UV_ID_FIELD = 't_id_uv_ca';
const UV_NUMBER_FIELD = 'uv_carto';
const UV_NAME_FIELD = 't_uv_nom';

/**
 * One comuna's Unidades Vecinales, ready to import.
 *
 * @param array<string, mixed> $national
 * @return array<string, mixed>
 * @throws InvalidArgumentException
 */
function cutComuna(array $national, string $cut): array {
	if (preg_match('/^\d{5}$/', $cut) !== 1) {
		throw new InvalidArgumentException(
			sprintf('"%s" no es un código CUT: se esperan cinco dígitos.', $cut),
		);
	}

	if (($national['type'] ?? null) !== 'FeatureCollection') {
		throw new InvalidArgumentException(
			'El archivo nacional debe ser un FeatureCollection de GeoJSON. '
			. 'Si es un .shp, conviértalo primero con ogr2ogr.',
		);
	}

	$features = [];
	foreach ($national['features'] ?? [] as $feature) {
		$properties = $feature['properties'] ?? [];
		if ((string)($properties[COMUNA_FIELD] ?? '') !== $cut) {
			continue;
		}

		$number = trim((string)($properties[UV_NUMBER_FIELD] ?? ''));
		$name = trim((string)($properties[UV_NAME_FIELD] ?? ''));

		$features[] = [
			'type' => 'Feature',
			// Nationally unique, and what a re-import matches on (ADR-0003): a
			// boundary corrected upstream updates the record it already has rather
			// than arriving as a second copy.
			'id' => (string)($properties[UV_ID_FIELD] ?? ''),
			'properties' => [
				// Plenty carry no name, and the number is what people actually say —
				// CONTEXT.md: numbered within its comuna, never a bare number on its
				// own.
				'name' => $name !== '' ? $name : 'Unidad Vecinal ' . $number,
				'category' => 'division_territorial',
				'subcategory' => 'unidad_vecinal',
				'source_es' => 'Cadastro nacional de Unidades Vecinales 2024v4',
			],
			// Untouched. The cadastre's boundary is the boundary; this tool selects,
			// it does not redraw.
			'geometry' => $feature['geometry'] ?? null,
		];
	}

	if ($features === []) {
		throw new InvalidArgumentException(sprintf(
			'Ninguna Unidad Vecinal tiene %s = %s. ¿Es correcto el código CUT?',
			COMUNA_FIELD,
			$cut,
		));
	}

	return [
		'type' => 'FeatureCollection',
		// One fact about the file, stated once. The import refuses a file whose
		// comuna is not the one this install serves.
		'comuna' => $cut,
		'features' => $features,
	];
}

/**
 * @return array<string, mixed>
 * @throws RuntimeException
 */
function readNational(string $path, string $cut): array {
	if (!is_readable($path)) {
		throw new RuntimeException(sprintf('No se puede leer %s', $path));
	}

	// ponytail: a .geojson is read whole and decoded whole, so a national file
	// handed in directly still costs gigabytes. The .shp path filters inside GDAL
	// and does not; cut the national file once and this never matters.
	$geojson = str_ends_with(strtolower($path), '.shp')
		? convert($path, $cut)
		: (string)file_get_contents($path);
	$decoded = json_decode($geojson, true);

	if (!is_array($decoded)) {
		throw new RuntimeException('El archivo no contiene GeoJSON válido.');
	}

	return $decoded;
}

/**
 * The shapefile, converted — and filtered to one comuna while still inside GDAL.
 *
 * The `-where` matters more than it looks. The national file is 128 MB of
 * shapefile and several hundred megabytes as GeoJSON text; reading all of it
 * into one PHP string and then decoding that into arrays costs gigabytes to end
 * up with 37 polygons. GDAL drops the other 345 comunas before a byte reaches
 * us.
 *
 * `-t_srs EPSG:4326` is a datum shift, not a reprojection: the source is already
 * degrees (EPSG:4674, SIRGAS 2000) and the difference is sub-metre, but RFC 7946
 * says GeoJSON is WGS84 and saying so costs nothing.
 *
 * @throws RuntimeException
 */
function convert(string $shapefile, string $cut): string {
	fwrite(STDERR, 'Convirtiendo el shapefile con ogr2ogr…' . PHP_EOL);

	$errors = tempnam(sys_get_temp_dir(), 'ogr');
	$command = sprintf(
		'ogr2ogr -f GeoJSON -t_srs EPSG:4326 -where %s /vsistdout/ %s 2>%s',
		escapeshellarg(sprintf('%s = \'%s\'', COMUNA_FIELD, $cut)),
		escapeshellarg($shapefile),
		escapeshellarg((string)$errors),
	);
	$geojson = shell_exec($command);
	$said = trim((string)@file_get_contents((string)$errors));
	@unlink((string)$errors);

	if (!is_string($geojson) || trim($geojson) === '') {
		throw new RuntimeException(
			'ogr2ogr no devolvió nada. ¿Está GDAL instalado? '
			. 'En Debian/Ubuntu: sudo apt install gdal-bin. '
			. 'También puede convertir el archivo aparte y pasar el .geojson resultante.'
			. ($said === '' ? '' : PHP_EOL . 'ogr2ogr dijo: ' . $said),
		);
	}

	return $geojson;
}

/** @param string[] $argv */
function main(array $argv): int {
	$options = getopt('', ['file:', 'comuna:']) ?: [];
	$file = (string)($options['file'] ?? '');
	$comuna = (string)($options['comuna'] ?? '');

	if ($file === '' || $comuna === '') {
		fwrite(STDERR, sprintf(
			'Uso: php %s --file=<nacional.shp|.geojson> --comuna=<CUT> > uv-<CUT>.geojson%s',
			$argv[0],
			PHP_EOL,
		));

		return 1;
	}

	try {
		$cut = cutComuna(readNational($file, $comuna), $comuna);
	} catch (InvalidArgumentException|RuntimeException $e) {
		fwrite(STDERR, $e->getMessage() . PHP_EOL);

		return 1;
	}

	fwrite(STDERR, sprintf('%d Unidades Vecinales%s', count($cut['features']), PHP_EOL));
	echo json_encode(
		$cut,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
	), PHP_EOL;

	return 0;
}

// Run only when invoked directly, so the tests can require this file for
// cutComuna() without it reading anything.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
	exit(main($argv));
}
