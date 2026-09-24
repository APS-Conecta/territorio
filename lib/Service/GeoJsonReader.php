<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use JsonException;
use OCA\Territorio\Exception\InvalidImportException;

/**
 * Reads a GeoJSON FeatureCollection into rows an import can match on.
 *
 * Property names follow the catastro this app inherits — `contact_person`,
 * `ubicacion`, `source_url` — because that is the shape of the files that
 * actually exist, not a format invented here.
 *
 * Depends on no OCP class, so it tests in bare PHP.
 */
class GeoJsonReader {
	/** How many characters of the digest an id keeps, matching the prior art. */
	private const DIGEST_LENGTH = 16;

	/**
	 * Decimal places a coordinate is fixed to before it is hashed.
	 *
	 * Seven is about a centimetre — far finer than any catastro — and the point
	 * is not the precision but that it is FIXED. `json_encode` of a float obeys
	 * `serialize_precision`, and CLI and FPM routinely load different php.ini
	 * files: at -1 the coordinate encodes as -70.5991, at 17 as
	 * -70.599100000000007. Two different digests, so `occ territorio:import` and
	 * a browser upload of the same file would derive disjoint ids and the second
	 * run would duplicate the whole Dataset instead of matching it — the exact
	 * outcome ADR-0003 forbids.
	 */
	private const COORDINATE_PLACES = 7;

	/**
	 * A file this app wrote WITHOUT the contact fields, which must not come back
	 * in (ADR-0006).
	 *
	 * The fields are absent rather than empty, so an import would read absence as
	 * deletion and quietly clear every telephone number it matched. Refused at
	 * the door instead: the person who wants a file that goes back in can export
	 * one with contact details, which is confirmed and recorded like any other.
	 */
	private const REFUSE_PARTIAL = 'El archivo se exportó sin datos de contacto y volver a importarlo '
		. 'borraría los teléfonos y correos que ya están guardados. Exporte de nuevo '
		. 'marcando "Incluir datos de contacto" si quiere volver a importarlo.';

	/** What `ubicacion` says, and what Location quality calls it. */
	private const QUALITIES = [
		'exacta' => 'exacta',
		'aproximada' => 'aproximada',
	];

	/**
	 * @return list<array<string, mixed>>
	 * @throws InvalidImportException
	 */
	public function read(string $json, string $datasetSlug): array {
		try {
			$decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new InvalidImportException(
				'El archivo no es JSON válido: ' . $e->getMessage(), 0, $e,
			);
		}

		return $this->fromCollection(is_array($decoded) ? $decoded : [], $datasetSlug);
	}

	/**
	 * The same reading, from a FeatureCollection already in memory.
	 *
	 * This is the seam `KmlReader` comes in through: KML becomes a
	 * FeatureCollection and then IS a GeoJSON import — same derived ids, same
	 * refusal of a nameless row, same row shape. Two readers producing rows
	 * separately is how the two formats would drift into importing differently.
	 *
	 * @param array<string, mixed> $collection
	 * @return list<array<string, mixed>>
	 * @throws InvalidImportException
	 */
	public function fromCollection(array $collection, string $datasetSlug): array {
		if (($collection['type'] ?? null) !== 'FeatureCollection') {
			throw new InvalidImportException(
				'El archivo debe ser un FeatureCollection de GeoJSON.',
			);
		}

		// Refused before a single row is read, like every other reason a file is
		// refused here: this one would not fail, it would succeed and delete
		// things.
		if (($collection[ContactFields::PARTIAL] ?? false) === true) {
			throw new InvalidImportException(self::REFUSE_PARTIAL);
		}

		// Stated once at the top of the file and carried onto each row from there.
		// The import refuses a file whose comuna is not the one this install
		// serves, and the row is where it can see it without decoding twice.
		$comuna = trim((string)($collection['comuna'] ?? ''));

		$features = $collection['features'] ?? null;
		if (!is_array($features)) {
			throw new InvalidImportException('El FeatureCollection no tiene una lista de features.');
		}

		return array_values(array_map(
			fn (mixed $feature, int $index): array => $this->row($feature, $index, $datasetSlug, $comuna),
			$features,
			array_keys($features),
		));
	}

	/** @return array<string, mixed> */
	private function row(mixed $feature, int $index, string $datasetSlug, string $comuna): array {
		if (!is_array($feature)) {
			throw new InvalidImportException(sprintf('El feature %d no es un objeto.', $index + 1));
		}

		$properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
		$name = trim((string)($properties['name'] ?? ''));
		if ($name === '') {
			// Refused rather than imported blank: a nameless row is unfindable in
			// the list and unidentifiable in the duplicate queue, and importing
			// hundreds of them is worse than telling someone the file is wrong.
			throw new InvalidImportException(sprintf('El feature %d no tiene nombre.', $index + 1));
		}

		$geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : null;
		$source = trim((string)($properties['source'] ?? '')) ?: $datasetSlug;

		return [
			'externalId' => $this->externalId($feature, $properties, $source, $name, $geometry),
			'name' => $name,
			// Raw label or slug, as the file carries it — classify() owns the
			// matching vocabularies (L1-06). Our own export writes slugs here
			// and still round-trips through the slug path.
			'category' => trim((string)($properties['category'] ?? '')) ?: null,
			'subcategory' => trim((string)($properties['subcategory'] ?? '')) ?: null,
			'geometry' => $geometry,
			'locationQuality' => self::QUALITIES[strtolower(trim((string)($properties['ubicacion'] ?? '')))] ?? null,
			'address' => $this->text($properties['address'] ?? null),
			'phone' => $this->text($properties['phone'] ?? null),
			'email' => $this->text($properties['email'] ?? null),
			'contactPerson' => $this->text($properties['contact_person'] ?? null),
			'contactRole' => $this->text($properties['contact_role'] ?? null),
			'openingHours' => $this->text($properties['opening_hours'] ?? null),
			'notes' => $this->text($properties['notes'] ?? null),
			'sourceRef' => $this->sourceRef($properties, $source),
			// Which comuna the FILE says it is for, or '' where it does not say.
			// Only the Unidad Vecinal cadastre says (issue #9).
			'comuna' => $comuna,
		];
	}

	/**
	 * The id a re-import matches on.
	 *
	 * An explicit id in the file always wins. Where there is none — which is the
	 * case for the whole La Florida catastro, checked rather than assumed — it is
	 * derived from the source, the name and the position, so reading the same
	 * file twice produces the same id and the second import updates instead of
	 * duplicating.
	 *
	 * Name and position together, not name alone: two plazas called "Plaza" at
	 * opposite ends of the comuna are two plazas, and merging them on every
	 * import would be silent.
	 *
	 * ponytail: a correction upstream to a name or a coordinate reads as a new
	 * record. That is the price of a file with no ids; a Dataset that carries
	 * real ones is matched on those instead.
	 *
	 * @param array<string, mixed> $properties
	 */
	private function externalId(
		array $feature,
		array $properties,
		string $source,
		string $name,
		?array $geometry,
	): string {
		foreach ([$properties['uid'] ?? null, $feature['id'] ?? null] as $explicit) {
			if (is_string($explicit) && trim($explicit) !== '') {
				return trim($explicit);
			}
			if (is_int($explicit)) {
				return (string)$explicit;
			}
		}

		$digest = substr(
			sha1($name . '|' . $this->position($geometry['coordinates'] ?? null)),
			0,
			self::DIGEST_LENGTH,
		);

		return $source . ':' . $digest;
	}

	/**
	 * A coordinate rendered so that the same file always hashes the same way,
	 * whatever `serialize_precision` happens to be. Nested arrays are flattened
	 * rather than special-cased per geometry type: the id only needs to be
	 * stable and distinct, not meaningful.
	 */
	private function position(mixed $coordinates): string {
		if (!is_array($coordinates)) {
			return 'sin-coordenadas';
		}

		$flat = [];
		array_walk_recursive($coordinates, static function (mixed $value) use (&$flat): void {
			$flat[] = is_int($value) || is_float($value)
				? sprintf('%.' . self::COORDINATE_PLACES . 'F', $value)
				: 'x';
		});

		return implode(',', $flat);
	}

	/** @param array<string, mixed> $properties */
	private function sourceRef(array $properties, string $source): ?string {
		$url = $this->text($properties['source_url'] ?? null);
		$label = $this->text($properties['source_es'] ?? null) ?? $source;

		return $url === null ? $label : $label . ' — ' . $url;
	}

	private function text(mixed $value): ?string {
		if (!is_string($value) && !is_int($value) && !is_float($value)) {
			return null;
		}
		$text = trim((string)$value);

		return $text === '' ? null : $text;
	}
}
