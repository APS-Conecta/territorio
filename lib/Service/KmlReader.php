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

		// KML declares a default namespace, which SimpleXML's element access
		// ignores unless it is registered. Stripping it once is less to get wrong
		// than naming it at every step of every path below.
		$document->registerXPathNamespace('kml', 'http://www.opengis.net/kml/2.2');

		$features = [];
		foreach ($document->xpath('//kml:Placemark | //Placemark') ?: [] as $placemark) {
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
	 * One Placemark, or null where it holds no geometry this app can store.
	 *
	 * Null rather than an exception: a MyMaps folder can hold a styling entry or
	 * a placemark someone left empty, and refusing the whole file over one is the
	 * opposite of what the import does everywhere else.
	 *
	 * @return ?array<string, mixed>
	 */
	private function feature(SimpleXMLElement $placemark): ?array {
		$geometry = $this->geometry($placemark);
		if ($geometry === null) {
			return null;
		}

		$properties = ['name' => trim((string)$placemark->name)];

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

	/** @return ?array<string, mixed> */
	private function geometry(SimpleXMLElement $placemark): ?array {
		if (isset($placemark->Point->coordinates)) {
			$positions = $this->positions((string)$placemark->Point->coordinates);

			return $positions === [] ? null : ['type' => 'Point', 'coordinates' => $positions[0]];
		}

		if (isset($placemark->LineString->coordinates)) {
			$positions = $this->positions((string)$placemark->LineString->coordinates);

			return $positions === [] ? null : ['type' => 'LineString', 'coordinates' => $positions];
		}

		$ring = $placemark->Polygon->outerBoundaryIs->LinearRing->coordinates ?? null;
		if ($ring !== null) {
			$positions = $this->positions((string)$ring);

			// A KML ring is one flat list of positions; GeoJSON wraps it, because a
			// Polygon has rings — an outer one and any number of holes.
			return $positions === [] ? null : ['type' => 'Polygon', 'coordinates' => [$positions]];
		}

		return null;
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
