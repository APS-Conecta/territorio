<?php

declare(strict_types=1);

/**
 * Produce a comuna's Boundary features as GeoJSON, for importing as a Cadastral
 * Dataset.
 *
 *   php tools/boundary-features.php --bbox=<sur,oeste,norte,este> [--source=<slug>] > limites.geojson
 *   occ territorio:import limites.geojson --dataset=<slug> --boundary
 *
 * A repo-side tool, run by hand, and deliberately NOT part of the app: ADR-0004
 * keeps Overpass out of the runtime entirely, because it is a free public
 * service with rate limits and no SLA and a clinic cannot have that as a failure
 * mode mid-consultation. Refreshing the data is re-running this and re-importing,
 * which ADR-0003 governs like any other re-import.
 *
 * Lives outside `lib/` and outside the app's namespace, so nothing in the app
 * can reach it even by accident. The two functions that decide anything are pure
 * and unit-tested; only `main()` touches the network.
 */

// One namespace per tool: each declares a `main()`, and the unit tests
// `require_once` both files into the same process.
namespace Territorio\Tools\Boundary;

use InvalidArgumentException;
use RuntimeException;

const OVERPASS_URL = 'https://overpass-api.de/api/interpreter';

/** Generous: 11k ways for one comuna is a slow answer, not a broken one. */
const OVERPASS_TIMEOUT_SECONDS = 300;

/**
 * OSM's usage policy asks every client to identify itself, and the endpoints in
 * front of Overpass enforce it — an anonymous POST comes back 406 before the
 * query is ever read. PHP sends no User-Agent of its own.
 */
const USER_AGENT = 'territorio-boundary-features/1.0 (+https://github.com/APS-Conecta/territorio)';

/**
 * The three kinds of line a Limit can run along (CONTEXT.md), each required to
 * carry a name.
 *
 * The name filter is not only about volume — though it is that too: for La
 * Florida it is the difference between 11,178 ways and 19,034, the rest being
 * service roads and driveways nobody bounds a sector with. It is that a Limit is
 * stated out loud as the thing it follows, so an unnamed way could not be said;
 * and that the import refuses a nameless row outright, so one would abort the
 * whole file rather than be quietly skipped.
 */
const KINDS = ['highway', 'waterway', 'railway'];

/**
 * @param string $bbox "south,west,north,east" in decimal degrees
 * @throws InvalidArgumentException when the box is not one
 */
function overpassQuery(string $bbox): string {
	$box = bboxForQuery($bbox);

	$ways = '';
	foreach (KINDS as $kind) {
		$ways .= sprintf('  way["%s"]["name"](%s);%s', $kind, $box, PHP_EOL);
	}

	// `out geom` is what makes the answer usable on its own: without it Overpass
	// returns node references and the shapes would have to be reassembled here.
	return sprintf(
		'[out:json][timeout:%d];%s(%s%s);%sout geom;%s',
		OVERPASS_TIMEOUT_SECONDS,
		PHP_EOL,
		PHP_EOL,
		rtrim($ways, PHP_EOL),
		PHP_EOL,
		PHP_EOL,
	);
}

/**
 * Checked rather than trusted: this goes into the query text, and a bbox is four
 * numbers or it is a mistake.
 *
 * @throws InvalidArgumentException
 */
function bboxForQuery(string $bbox): string {
	$parts = explode(',', trim($bbox));
	if (count($parts) !== 4) {
		throw new InvalidArgumentException(
			'--bbox necesita cuatro números: sur,oeste,norte,este',
		);
	}

	$numbers = [];
	foreach ($parts as $part) {
		$part = trim($part);
		if (!is_numeric($part)) {
			throw new InvalidArgumentException(sprintf('"%s" no es un número', $part));
		}
		$numbers[] = (float)$part;
	}

	[$south, $west, $north, $east] = $numbers;
	if ($south < -90 || $north > 90 || $west < -180 || $east > 180) {
		throw new InvalidArgumentException('El área queda fuera del mundo');
	}
	if ($south >= $north || $west >= $east) {
		throw new InvalidArgumentException(
			'El área está invertida: se espera sur,oeste,norte,este',
		);
	}

	// Reformatted from the parsed numbers, so what reaches the query is what was
	// understood and nothing else.
	return implode(',', array_map(static fn (float $n): string => sprintf('%.7F', $n), $numbers));
}

/**
 * Overpass's answer as a GeoJSON FeatureCollection the import can read.
 *
 * Ids are OSM's own — `way/12345` — which the reader takes in preference to the
 * digest it would otherwise derive from name and position. That is what makes a
 * street renamed or redrawn upstream update the record it already has instead of
 * arriving as a second copy of the same street (ADR-0003).
 *
 * Category and Subcategory are the fallback pair on purpose. A Boundary feature
 * is never drawn on the ordinary map and never toggled in Capas, so what it is
 * gets said by the Dataset it belongs to; seeding a taxonomy row for 11,000
 * invisible records would put a branch in the panel that means nothing to
 * anyone.
 *
 * @param array<string, mixed> $overpass
 * @return array<string, mixed>
 */
function toGeoJson(array $overpass, string $source): array {
	$features = [];

	foreach ($overpass['elements'] ?? [] as $element) {
		if (!is_array($element) || ($element['type'] ?? null) !== 'way') {
			continue;
		}

		$name = trim((string)($element['tags']['name'] ?? ''));
		$points = is_array($element['geometry'] ?? null) ? $element['geometry'] : [];
		// Two points or it is not a line, and a nameless way could not be named as
		// a Limit — either one would refuse the whole import downstream.
		if ($name === '' || count($points) < 2) {
			continue;
		}

		$id = 'way/' . $element['id'];
		$features[] = [
			'type' => 'Feature',
			'id' => $id,
			'properties' => [
				'name' => $name,
				'category' => 'sin_clasificar',
				'subcategory' => 'otro',
				'source' => $source,
				'source_es' => 'OpenStreetMap',
				'source_url' => 'https://www.openstreetmap.org/' . $id,
			],
			'geometry' => [
				'type' => 'LineString',
				'coordinates' => array_map(
					// GeoJSON orders positions [longitude, latitude]; Overpass
					// answers lat/lon, and getting this backwards puts a Chilean
					// street in the South Atlantic without tripping any range check.
					static fn (array $point): array => [(float)$point['lon'], (float)$point['lat']],
					array_values($points),
				),
			],
		];
	}

	return ['type' => 'FeatureCollection', 'features' => $features];
}

/** @param string[] $argv */
function main(array $argv): int {
	$options = getopt('', ['bbox:', 'source::']) ?: [];
	$bbox = (string)($options['bbox'] ?? '');
	$source = (string)($options['source'] ?? 'osm');

	if ($bbox === '') {
		fwrite(STDERR, sprintf(
			'Uso: php %s --bbox=<sur,oeste,norte,este> [--source=<slug>] > limites.geojson%s',
			$argv[0],
			PHP_EOL,
		));

		return 1;
	}

	try {
		$query = overpassQuery($bbox);
		fwrite(STDERR, 'Consultando Overpass…' . PHP_EOL);
		$collection = toGeoJson(fetch($query), $source);
	} catch (InvalidArgumentException|RuntimeException $e) {
		fwrite(STDERR, $e->getMessage() . PHP_EOL);

		return 1;
	}

	fwrite(STDERR, sprintf('%d líneas con nombre%s', count($collection['features']), PHP_EOL));
	echo json_encode(
		$collection,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
	), PHP_EOL;

	return 0;
}

/**
 * @return array<string, mixed>
 * @throws RuntimeException
 */
function fetch(string $query): array {
	$answer = @file_get_contents(OVERPASS_URL, false, stream_context_create([
		'http' => [
			'method' => 'POST',
			'header' => implode("\r\n", [
				'Content-Type: application/x-www-form-urlencoded',
				'User-Agent: ' . USER_AGENT,
				'Accept: application/json',
			]),
			'content' => http_build_query(['data' => $query]),
			'timeout' => OVERPASS_TIMEOUT_SECONDS,
			// Overpass answers 429 when it is busy and 504 when the query outruns
			// its own timeout; both carry a body worth showing.
			'ignore_errors' => true,
		],
	]));

	if ($answer === false) {
		throw new RuntimeException('No se pudo contactar a Overpass: ' . OVERPASS_URL);
	}

	$decoded = json_decode($answer, true);
	if (!is_array($decoded) || !isset($decoded['elements'])) {
		throw new RuntimeException(
			'Overpass no devolvió un resultado usable: ' . substr(trim($answer), 0, 300),
		);
	}

	return $decoded;
}

// Run only when invoked directly, so the tests can require this file for the
// pure functions above without it reaching for the network.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
	exit(main($argv));
}
