<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Exception\InvalidImportException;
use SimpleXMLElement;

/**
 * Reads a KML export — in practice, a Google MyMaps one.
 *
 * It does not read rows itself. It turns the file into a GeoJSON
 * FeatureCollection and hands that to `GeoJsonReader`, so a KML import IS a
 * GeoJSON import: the same derived external ids, the same refusal of a nameless
 * row, the same row shape. Two readers building rows separately is how the two
 * formats would quietly start importing differently.
 *
 * **KML carries far less than GeoJSON, and the app says so before anyone
 * commits.** Checked against the real `La_Florida_Google_MyMaps.kml`: 865
 * placemarks, not one `ExtendedData` element between them. So no phone, no
 * email, no contact person, no opening hours, no source. What survives is the
 * geometry, the name, the Folder it sat in, and a description where somebody
 * typed one.
 *
 * Parsed with SimpleXML, which is in PHP already. A KML file is XML with a
 * handful of elements we care about; a library would be a dependency to read
 * five tag names.
 */
class KmlReader {
	/** What a Folder's name has to survive to be usable as a Category slug. */
	private const ACCENTS = [
		'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
		'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
	];

	public function __construct(
		private GeoJsonReader $geoJson,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 * @throws InvalidImportException
	 */
	public function read(string $xml, string $datasetSlug): array {
		return $this->geoJson->fromCollection($this->asCollection($xml), $datasetSlug);
	}

	/**
	 * @return array<string, mixed>
	 * @throws InvalidImportException
	 */
	private function asCollection(string $xml): array {
		$previous = libxml_use_internal_errors(true);
		$document = simplexml_load_string($xml);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($document === false) {
			throw new InvalidImportException('El archivo no es XML válido; se esperaba un KML.');
		}

		// Matched by local name, because KML files do not agree on a namespace:
		// OGC writes `opengis.net/kml/2.2` and Google Earth still writes
		// `earth.google.com/kml/2.1`. Naming one of them found nothing in the
		// others and told the person their file held no Placemarks, which blames
		// the file for the reader's assumption.
		$features = [];
		foreach ($document->xpath('//*[local-name()="Placemark"]') ?: [] as $placemark) {
			$feature = $this->feature($placemark);
			if ($feature !== null) {
				$features[] = $feature;
			}
		}

		if ($features === []) {
			throw new InvalidImportException('El archivo no contiene ningún Placemark.');
		}

		return ['type' => 'FeatureCollection', 'features' => $features];
	}

	/**
	 * One Placemark, or null where there is no record in it.
	 *
	 * A named Placemark whose geometry cannot be read still becomes a record,
	 * with no geometry — that is a valid state here (the catastro holds
	 * organisations whose address never resolved) and it is better than dropping
	 * a row silently, which is what the batch's counts exist to prevent.
	 *
	 * A Placemark with no name at all is dropped, and only that. It is a styling
	 * or scaffolding entry rather than a record, and passing it on would abort the
	 * whole file — the reader downstream refuses a nameless row, rightly.
	 *
	 * @return ?array<string, mixed>
	 */
	private function feature(SimpleXMLElement $placemark): ?array {
		$name = trim((string)$placemark->name);
		if ($name === '') {
			return null;
		}

		$geometry = $this->geometry($placemark);
		$properties = ['name' => $name];

		$description = trim((string)$placemark->description);
		if ($description !== '') {
			// The one field a person may have typed. It goes to notes, which is
			// where free text belongs — guessing that it is an address would be
			// inventing a value the file does not have.
			$properties['notes'] = $description;
		}

		// The Folder is the only classification a MyMaps export carries, and it
		// works because those folders were built from these Categories. Slugified,
		// not matched: which Categories exist is the import's business, not the
		// reader's.
		$folder = $this->folderOf($placemark);
		if ($folder !== null) {
			$properties['category'] = $folder;
		}

		return ['type' => 'Feature', 'properties' => $properties, 'geometry' => $geometry];
	}

	/**
	 * The placemark's geometry, looking through a MultiGeometry wrapper.
	 *
	 * MyMaps wraps a single shape in `<MultiGeometry>` often enough that treating
	 * that as unreadable would throw away ordinary records, so one shape inside
	 * is simply that shape. Several make a Multi* geometry, which this app does
	 * not store — and it has to arrive as a type `Geometry::supports()` REFUSES,
	 * so the import counts it and the batch reports it. Returning null instead
	 * imported the placemark shapeless, which is indistinguishable from a record
	 * somebody never located.
	 *
	 * @return ?array<string, mixed>
	 */
	private function geometry(SimpleXMLElement $placemark): ?array {
		if (!isset($placemark->MultiGeometry)) {
			return $this->shapeIn($placemark);
		}

		$inside = $placemark->MultiGeometry;
		$shapes = count($inside->Point) + count($inside->LineString) + count($inside->Polygon);

		if ($shapes === 1) {
			return $this->shapeIn($inside);
		}

		return $shapes === 0 ? null : ['type' => 'GeometryCollection', 'coordinates' => []];
	}

	/**
	 * The one shape held directly by an element — a Placemark, or a
	 * MultiGeometry wrapping exactly one.
	 *
	 * @return ?array<string, mixed>
	 */
	private function shapeIn(SimpleXMLElement $container): ?array {
		if (isset($container->Point->coordinates)) {
			$positions = $this->positions((string)$container->Point->coordinates);

			return $positions === [] ? null : ['type' => 'Point', 'coordinates' => $positions[0]];
		}

		if (isset($container->LineString->coordinates)) {
			$positions = $this->positions((string)$container->LineString->coordinates);

			return $positions === [] ? null : ['type' => 'LineString', 'coordinates' => $positions];
		}

		$ring = $container->Polygon->outerBoundaryIs->LinearRing->coordinates ?? null;
		if ($ring === null) {
			return null;
		}

		$outer = $this->positions((string)$ring);
		if ($outer === []) {
			return null;
		}

		// A KML ring is one flat list of positions; GeoJSON wraps it, because a
		// Polygon has rings — an outer one and any number of holes. The holes used
		// to be dropped here, which left the boundary claiming every record
		// standing in the lagoon it was drawn around. Nothing said so: the shape
		// still drew and still assigned, just wrongly.
		$rings = [$outer];
		foreach ($container->Polygon->innerBoundaryIs ?? [] as $inner) {
			$hole = $this->positions((string)($inner->LinearRing->coordinates ?? ''));
			if ($hole !== []) {
				$rings[] = $hole;
			}
		}

		return ['type' => 'Polygon', 'coordinates' => $rings];
	}

	/**
	 * KML writes `lon,lat[,altitude]` separated by whitespace. The altitude is
	 * dropped: GeoJSON positions in this app are two numbers, and a third would
	 * fail the geometry check for a value nothing here uses.
	 *
	 * @return list<array{float, float}>
	 */
	private function positions(string $coordinates): array {
		$positions = [];
		foreach (preg_split('/\s+/', trim($coordinates)) ?: [] as $triple) {
			if ($triple === '') {
				continue;
			}
			$parts = explode(',', $triple);
			if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
				continue;
			}
			$positions[] = [(float)$parts[0], (float)$parts[1]];
		}

		return $positions;
	}

	/** The name of the nearest enclosing Folder, slugified, or null. */
	private function folderOf(SimpleXMLElement $placemark): ?string {
		$folders = $placemark->xpath('ancestor::*[local-name()="Folder"][1]/*[local-name()="name"]');
		$name = trim((string)($folders[0] ?? ''));

		return $name === '' ? null : $this->slug($name);
	}

	/**
	 * "Movilidad y transporte" → `movilidad_transporte`, which is the slug the
	 * seed uses. Short words are dropped for the same reason the seed leaves them
	 * out: nobody writes `movilidad_y_transporte`.
	 *
	 * ponytail: a heuristic that happens to reproduce all twelve seeded slugs —
	 * checked, not assumed. A Category a clinic adds later can miss it ("Casa de
	 * la cultura" slugs to `casa_cultura`), and the row lands in Sin clasificar
	 * rather than anywhere wrong. Match on the Category's label instead when that
	 * starts costing somebody real re-filing.
	 */
	private function slug(string $name): string {
		$folded = strtolower(strtr($name, self::ACCENTS));
		$words = array_filter(
			preg_split('/[^a-z0-9]+/', $folded) ?: [],
			static fn (string $word): bool => $word !== '' && !in_array($word, ['y', 'de', 'la', 'el'], true),
		);

		return implode('_', $words);
	}
}
