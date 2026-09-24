<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Domain\OsmAttribution;
use OCA\Territorio\Service\CsvWriter;
use OCA\Territorio\Service\GeoJsonReader;
use OCA\Territorio\Service\GeoJsonWriter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The interchange row shape, named (L1-11).
 *
 * Reader output and writer input are one contract spelled from two ends. They
 * meet on the shared core — externalId, name, geometry, locationQuality, the
 * seven optional texts — and until this test the spellings were held together
 * by docblocks and per-writer fixtures: renaming a row key compiled, and only a
 * writer test caught it, if that writer mapped the key.
 *
 * The writer side is read out of the writers THEMSELVES — their private column
 * maps, by reflection — so a rename inside a writer moves the reflected set and
 * breaks HERE first, not in a fixture three suites away.
 */
final class RowShapeTest extends TestCase {
	/** The keys the two shapes share by role — the interchange proper. A key
	 *  listed here must come out of the reader AND be read by some writer,
	 *  spelled identically. */
	private const SHARED = [
		'externalId', 'name', 'geometry', 'locationQuality', 'address', 'phone',
		'email', 'contactPerson', 'contactRole', 'openingHours', 'notes', 'sourceRef',
	];

	/** The keys OsmAttribution reads off an export row — held to the row by
	 *  this test rather than by OsmAttribution's docblock ("as
	 *  ExportService::row() builds them"). `official` rides the row for exactly
	 *  this decision and is mapped by no writer. */
	private const OSM_READS = ['sourceRef', 'official'];

	/** The keys every reader row carries, in order — additions are loud on
	 *  purpose: a new key is a contract change someone must route. */
	public function testTheReaderProducesExactlyTheAgreedKeys(): void {
		$rows = (new GeoJsonReader())->fromCollection([
			'type' => 'FeatureCollection',
			'features' => [[
				'type' => 'Feature',
				'properties' => ['name' => 'Plaza de Armas'],
				'geometry' => ['type' => 'Point', 'coordinates' => [-70.55, -33.51]],
			]],
		], 'demo');

		self::assertSame(
			[
				'externalId', 'name', 'category', 'subcategory', 'geometry',
				'locationQuality', 'address', 'phone', 'email', 'contactPerson',
				'contactRole', 'openingHours', 'notes', 'sourceRef', 'comuna',
			],
			array_keys($rows[0]),
		);
	}

	public function testEverySharedKeyIsSpelledTheSameOnBothEnds(): void {
		$writerKeys = $this->writerKeys();

		foreach (self::SHARED as $key) {
			self::assertContains($key, $writerKeys, sprintf(
				'«%s» is what the readers produce, and no writer maps it — the interchange broke on the writer side.',
				$key,
			));
		}
	}

	public function testTheReaderSharesEveryKeySomeWriterMaps(): void {
		// The mirror of the pin above: a writer key that is NOT part of the
		// shared core is one of the four known extras — categorySlug,
		// subcategorySlug, categoryLabel (DB-fed writer input, not reader rows —
		// Phase 2's seam note) and limit (the export's sentence, deliberately not
		// read back on import). A FIFTH extra is a new role someone introduced
		// silently, and this fails until it is either shared or named here.
		$knownExtras = ['categorySlug', 'subcategorySlug', 'categoryLabel', 'limit'];

		foreach (array_diff($this->writerKeys(), self::SHARED) as $key) {
			self::assertContains($key, $knownExtras, sprintf(
				'A writer maps «%s», which is neither shared with the reader nor one of the known writer-only keys — name it here or share it.',
				$key,
			));
		}
	}

	public function testOsmAttributionReadsOnlyKeysTheExportRowCarries(): void {
		// The export row = what the writers map, plus `official`, which rides
		// along unmapped for exactly this decision (ExportService::row()).
		$rowKeys = [...$this->writerKeys(), 'official'];

		foreach (self::OSM_READS as $key) {
			self::assertContains($key, $rowKeys, sprintf(
				'OsmAttribution reads «%s» off the row and nothing builds it — the licence decision went blind.',
				$key,
			));
		}
	}

	/**
	 * The union of every key any writer maps, reflected out of the writers' own
	 * column maps plus the keys their code reads directly.
	 *
	 * @return list<string>
	 */
	private function writerKeys(): array {
		// name and geometry are read directly by all three writers; externalId
		// and sourceRef by the GeoJSON one (KML carries neither, CSV neither).
		$keys = ['name', 'geometry', 'externalId', 'sourceRef'];

		// CsvWriter maps heading → row key (values are the row keys); GeoJsonWriter
		// maps row key → file property (keys are the row keys). Each contributes
		// its row-key side, which is the side the reader must match.
		$csv = new ReflectionClass(CsvWriter::class);
		foreach (['PLAIN', 'CONTACT'] as $map) {
			if ($csv->hasConstant($map)) {
				$keys = [...$keys, ...array_values($csv->getConstant($map))];
			}
		}
		$geo = new ReflectionClass(GeoJsonWriter::class);
		foreach (['PLAIN', 'CONTACT'] as $map) {
			if ($geo->hasConstant($map)) {
				$keys = [...$keys, ...array_keys($geo->getConstant($map))];
			}
		}

		return array_values(array_unique($keys));
	}
}
