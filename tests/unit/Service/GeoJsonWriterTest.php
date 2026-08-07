<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Service\GeoJsonReader;
use OCA\Territorio\Service\GeoJsonWriter;
use PHPUnit\Framework\TestCase;

/**
 * The lossless export (issue #17).
 *
 * Checked by reading what it writes with `GeoJsonReader`, which was written for
 * the real catastro and knows nothing about this. That is what makes "lossless"
 * a claim with an independent witness rather than a comparison of the writer
 * with itself: if the two ever disagree about a property name, the row that
 * comes back is not the row that went out and this fails.
 */
final class GeoJsonWriterTest extends TestCase {
	/** Everything a Feature can carry, with nothing left null by accident. */
	private const FULL = [
		'externalId' => 'catastro:abc123',
		'name' => 'Junta de Vecinos Los Navegantes',
		'categorySlug' => 'organizacion',
		'subcategorySlug' => 'junta_vecinos',
		'geometry' => ['type' => 'Point', 'coordinates' => [-70.5811, -33.5211]],
		'locationQuality' => 'exacta',
		'address' => 'Los Navegantes 1234',
		'phone' => '+56 9 1234 5678',
		'email' => 'contacto@ejemplo.cl',
		'contactPerson' => 'Presidenta',
		'contactRole' => 'Presidenta',
		'openingHours' => 'Lunes a viernes, 9 a 17',
		'notes' => 'Entrada por el pasaje',
		'sourceRef' => 'Catastro municipal',
	];

	/** @param list<array<string, mixed>> $rows */
	private function roundTrip(array $rows, bool $contacts = true): array {
		$json = (new GeoJsonWriter())->write($rows, $contacts);

		return (new GeoJsonReader())->read($json, 'catastro');
	}

	public function testEveryFieldSurvivesAReImport(): void {
		$back = $this->roundTrip([self::FULL]);

		self::assertCount(1, $back);
		// 'comuna' is what the FILE claims and only the UV cadastre claims it, so
		// it is not a property of the record and cannot round-trip.
		unset($back[0]['comuna']);
		self::assertSame(self::FULL, $back[0]);
	}

	/**
	 * The external id is what a re-import matches on, so it has to come back
	 * exactly — otherwise re-importing an export duplicates the whole Registry
	 * instead of updating it (ADR-0003).
	 */
	public function testTheExternalIdComesBackUnchanged(): void {
		$back = $this->roundTrip([self::FULL]);
		self::assertSame('catastro:abc123', $back[0]['externalId']);
	}

	/**
	 * A record with nothing but a name is a real record and must survive too.
	 *
	 * With one named exception, which is the reader's rule and not a loss here: a
	 * row that names no source takes the Dataset's slug as its source, so a
	 * record whose `sourceRef` was null comes back carrying the Dataset name.
	 * There is no way to say "no source" through the reader — an empty
	 * `source_es` folds to null and falls back the same way — and the cost is one
	 * spurious "Actualizado" the first time an export is re-imported, on records
	 * that had no source to begin with.
	 */
	public function testARecordWithOnlyANameSurvives(): void {
		$bare = [
			'externalId' => 'catastro:bare',
			'name' => 'Sin nada más',
			'categorySlug' => null,
			'subcategorySlug' => null,
			'geometry' => null,
			'locationQuality' => null,
			'address' => null,
			'phone' => null,
			'email' => null,
			'contactPerson' => null,
			'contactRole' => null,
			'openingHours' => null,
			'notes' => null,
			'sourceRef' => null,
		];

		$back = $this->roundTrip([$bare]);
		unset($back[0]['comuna']);

		self::assertSame('catastro', $back[0]['sourceRef'], 'the Dataset slug stands in for a missing source');
		unset($back[0]['sourceRef'], $bare['sourceRef']);
		self::assertSame($bare, $back[0]);
	}

	/**
	 * Without contact fields the file must not carry them at all.
	 *
	 * Not blanked — absent. A column of empty strings still says "this export
	 * was about these people", and the whole point of the unconfirmed export is
	 * that it carries nothing about anybody (ADR-0006).
	 */
	public function testWithoutContactsTheFieldsAreAbsentRatherThanEmpty(): void {
		$json = (new GeoJsonWriter())->write([self::FULL], false);
		$properties = json_decode($json, true)['features'][0]['properties'];

		foreach (['phone', 'email', 'contact_person', 'contact_role'] as $field) {
			self::assertArrayNotHasKey($field, $properties);
		}

		// And what is left is still a usable record.
		self::assertSame('Junta de Vecinos Los Navegantes', $properties['name']);
		self::assertSame('Los Navegantes 1234', $properties['address']);
	}

	/** A geometry is written as GeoJSON says, not as a string of our own. */
	public function testTheGeometryIsWrittenWhereGeoJsonPutsIt(): void {
		$written = json_decode((new GeoJsonWriter())->write([self::FULL], true), true);

		self::assertSame('FeatureCollection', $written['type']);
		self::assertSame(
			['type' => 'Point', 'coordinates' => [-70.5811, -33.5211]],
			$written['features'][0]['geometry'],
		);
	}

	/** Exporting nothing is an empty collection, not a broken file. */
	public function testAnEmptySetIsStillAValidFile(): void {
		$written = json_decode((new GeoJsonWriter())->write([], true), true);

		self::assertSame('FeatureCollection', $written['type']);
		self::assertSame([], $written['features']);
	}
}
