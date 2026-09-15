<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\ExportRecordMapper;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Service\CsvWriter;
use OCA\Territorio\Service\ExportService;
use OCA\Territorio\Service\GeoJsonWriter;
use OCA\Territorio\Service\Kind;
use OCA\Territorio\Service\KmlWriter;
use OCA\Territorio\Service\TaxonomyService;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * KMZ: the KML this app already writes, inside an archive (issue #102).
 *
 * Against mocked mappers rather than the database, for the same reason
 * `RegistryServiceTest` is: what needs proving here is the dispatch and the
 * archive — that `kmz` reaches the KML writer unchanged, that it is served as an
 * archive, that the privacy rule which refuses a KML with contacts refuses a KMZ
 * too, and that the temporary file the archive is built through never survives
 * the call. None of that needs a row to come out of Postgres, and
 * `ExportTest` covers what does.
 */
final class ExportServiceTest extends TestCase {
	private FeatureMapper&MockObject $features;
	private ExportRecordMapper&MockObject $log;

	protected function setUp(): void {
		$this->features = $this->createMock(FeatureMapper::class);
		$this->features->method('findAll')->willReturn([self::feature()]);
		$this->log = $this->createMock(ExportRecordMapper::class);
	}

	/** Temporary files this process can see right now, so a leak is a new one. */
	private static function strays(): array {
		return glob(sys_get_temp_dir() . '/territorio-kmz*') ?: [];
	}

	private function service(?KmlWriter $kml = null): ExportService {
		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->method('tree')->willReturn([]);

		return new ExportService(
			$this->features,
			$taxonomy,
			$this->log,
			new GeoJsonWriter(),
			new CsvWriter(),
			$kml ?? new KmlWriter(),
			$this->createMock(IUserSession::class),
		);
	}

	private static function feature(): Feature {
		$feature = new Feature();
		$feature->setId(1);
		$feature->setSubcategoryId(11);
		$feature->setName('Junta de Vecinos Los Navegantes');
		$feature->setKind(Kind::Place->value);
		$feature->setPhone('+56 9 1234 5678');
		$feature->setGeometry('{"type":"Point","coordinates":[-70.5811,-33.5211]}');

		return $feature;
	}

	/** What the archive holds, or null if it holds no `doc.kml`. */
	private static function docKml(string $kmz): ?string {
		$path = tempnam(sys_get_temp_dir(), 'territorio-kmz-test-');
		file_put_contents($path, $kmz);

		try {
			$zip = new ZipArchive();
			self::assertTrue($zip->open($path) === true, 'the KMZ should open as an archive');
			$doc = $zip->getFromName('doc.kml');
			$zip->close();

			return $doc === false ? null : $doc;
		} finally {
			unlink($path);
		}
	}

	/**
	 * The whole claim of the format: a KMZ is a KML in a ZIP, and the KML inside
	 * is the one the KML export would have handed over — not a second rendering
	 * that can drift from it.
	 */
	public function testAKmzCarriesExactlyTheKmlTheKmlExportWouldHaveWritten(): void {
		$exports = $this->service();

		$kmz = $exports->export([1], 'kmz', false);
		$kml = $exports->export([1], 'kml', false);

		self::assertSame($kml['body'], self::docKml($kmz['body']));
	}

	/** Named `doc.kml`, which is the entry Google Earth opens an archive for. */
	public function testTheArchiveNamesItsKmlDocKml(): void {
		self::assertNotNull(
			self::docKml($this->service()->export([1], 'kmz', false)['body']),
			'a KMZ without a doc.kml is not a KMZ',
		);
	}

	public function testAKmzIsServedAsAnArchive(): void {
		$file = $this->service()->export([1], 'kmz', false);

		self::assertSame('application/vnd.google-earth.kmz', $file['mime']);
		self::assertStringEndsWith('.kmz', $file['filename']);
	}

	/**
	 * A KMZ is a KML, so the refusal that covers KML covers it (ADR-0006).
	 *
	 * The same message, because the reason is the same one: a file with nothing
	 * personal in it must not ask for a password. And nothing recorded, because
	 * an entry here would be the export log — the one record of who took personal
	 * data out of the building — saying somebody did when nobody did (ADR-0012).
	 */
	public function testAKmzCannotBeAskedForWithContacts(): void {
		$this->log->expects(self::never())->method('insert');

		try {
			$this->service()->export([1], 'kmz', true);
			self::fail('a KMZ with contacts should be refused');
		} catch (InvalidFeatureException $refusal) {
			self::assertStringContainsString('no lleva datos de contacto', $refusal->getMessage());
		}
	}

	/**
	 * `ZipArchive` cannot write to memory, so the archive is built through a
	 * temporary file — and a failure after it exists must not leave it behind.
	 *
	 * The failure is injected where a real one would happen: the KML is rendered
	 * into the open archive, so a writer that throws is the exact shape of "the
	 * write failed halfway".
	 *
	 * What this pins is the criterion — a file existed while the export was
	 * failing and is gone afterwards. It does not pin the `finally` ALONE:
	 * measured on PHP 8.3 / libzip, an archive discarded with no entries in it is
	 * deleted by libzip when the `ZipArchive` is destroyed, so this one path
	 * would come out clean even without it. The `finally` is what covers the
	 * paths after an entry exists — a `close()` that fails, a read-back that
	 * fails — which nothing in this suite can force.
	 */
	public function testTheTemporaryArchiveIsRemovedEvenWhenTheWriteFails(): void {
		$before = self::strays();
		$duringTheFailure = [];

		$kml = $this->createMock(KmlWriter::class);
		$kml->method('write')->willReturnCallback(
			function () use (&$duringTheFailure): string {
				// Read from inside the failure, because "nothing was left behind"
				// is only worth asserting if something was there to leave: an
				// export that never opened a file would satisfy the end state
				// without exercising any of this.
				$duringTheFailure = self::strays();

				throw new RuntimeException('no se pudo escribir');
			},
		);

		try {
			$this->service($kml)->export([1], 'kmz', false);
			self::fail('the failing write should not have been swallowed');
		} catch (RuntimeException $failure) {
			// The INJECTED failure, not any failure: `InvalidFeatureException`
			// extends `RuntimeException`, so a bare catch here would have been
			// satisfied by "Formato desconocido: kmz" — a test that passes before
			// the feature exists, against a path that never opened a file.
			self::assertSame('no se pudo escribir', $failure->getMessage());
		}

		self::assertCount(
			count($before) + 1,
			$duringTheFailure,
			'the failure did not happen with the temporary archive open, so this proves nothing',
		);
		self::assertSame($before, self::strays(), 'the temporary archive was left behind');
	}

	/** And it is gone after a write that worked, too. */
	public function testTheTemporaryArchiveIsRemovedAfterASuccessfulExport(): void {
		$before = self::strays();

		$this->service()->export([1], 'kmz', false);

		self::assertSame($before, self::strays(), 'the temporary archive was left behind');
	}
}
