<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Service\ExportService;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\OdsAssets;
use OCA\Territorio\Service\OdsImportService;
use OCA\Territorio\Service\OdsWorkbook;
use OCA\Territorio\Service\RevertService;
use OCP\Server;

final class OdsImportTest extends IntegrationTestCase {
	public function testPreviewWritesNothingAndSelectedImportCanBeUndone(): void {
		$slug = 'test-ods-' . bin2hex(random_bytes(4));
		$this->datasets[] = $slug;
		$bytes = OdsWorkbook::write(['Activos' => [array_values(OdsAssets::COLUMNS),
			['', '', 'ODS test community place', 'salud', 'otro', '-33.52', '-70.58', 'Una dirección', '', '', '', '', '', 'Nota']]]);
		$service = Server::get(OdsImportService::class);
		$before = Server::get(FeatureService::class)->currentRevision();
		$preview = $service->preview($bytes, $slug);
		self::assertSame($before, Server::get(FeatureService::class)->currentRevision());
		self::assertSame('new', $preview['rows'][0]['status']);
		$batch = $service->commit($bytes, $slug, $preview['revision'], [['row' => 2, 'clear' => [], 'confirmDuplicates' => true]], 'admin');
		self::assertSame(1, $batch->getCreated());
		$revert = Server::get(RevertService::class)->revert((int)$batch->getId(), 'admin');
		self::assertSame(1, $revert->getUpdated());
	}

	public function testExportedManualRecordUpdatesItselfAndBlankCellsPreserveInformation(): void {
		$id = $this->place('ODS roundtrip', ['phone' => '+56911111111', 'notes' => 'Keep this note']);
		$bytes = Server::get(ExportService::class)->export([$id], 'ods', true)['body'];
		$table = OdsWorkbook::read($bytes);
		$table[1][2] = 'ODS renamed';
		$table[1][8] = '';
		$table[1][13] = '';
		$edited = OdsWorkbook::write(['Activos' => $table]);
		$slug = 'test-ods-' . bin2hex(random_bytes(4));
		$this->datasets[] = $slug;
		$service = Server::get(OdsImportService::class);
		$preview = $service->preview($edited, $slug);
		self::assertSame('update', $preview['rows'][0]['status']);
		$batch = $service->commit($edited, $slug, $preview['revision'], [['row' => 2, 'clear' => [], 'confirmDuplicates' => true]], 'admin');
		self::assertSame(0, $batch->getCreated());
		self::assertSame(1, $batch->getUpdated());
		$feature = Server::get(FeatureMapper::class)->find($id);
		self::assertSame('ODS renamed', $feature->getName());
		self::assertSame('+56911111111', $feature->getPhone());
		self::assertSame('Keep this note', $feature->getNotes());
	}

	public function testChangedRegistryRejectsCommitBeforeWriting(): void {
		$id = $this->place('ODS stale');
		$bytes = Server::get(ExportService::class)->export([$id], 'ods', false)['body'];
		$service = Server::get(OdsImportService::class);
		$preview = $service->preview($bytes, 'test-ods-stale');
		$this->place('Concurrent new record');
		$this->expectException(InvalidImportException::class);
		$service->commit($bytes, 'test-ods-stale', $preview['revision'], [['row' => 2, 'clear' => []]], 'admin');
	}
	public function testInvalidRowsAndUnreviewedCandidatesCannotWrite(): void {
		$this->place('ODS duplicate without coordinates', ['geometry' => null, 'address' => 'Calle Uno 123']);
		$bytes = OdsWorkbook::write(['Activos' => [array_values(OdsAssets::COLUMNS),
			['', '', 'ODS duplicate without coordinates', 'salud', 'otro', '', '', 'Calle Uno 123'],
			['', '', 'ODS incomplete coordinate', 'salud', 'otro', '-33.52', '']]]);
		$service = Server::get(OdsImportService::class);
		$preview = $service->preview($bytes, 'test-ods-invalid');
		self::assertNotEmpty($preview['rows'][0]['candidates']);
		self::assertSame('invalid', $preview['rows'][1]['status']);
		$before = Server::get(FeatureService::class)->currentRevision();
		try {
			$service->commit($bytes, 'test-ods-invalid', $preview['revision'], [['row' => 2, 'clear' => []]], 'admin');
			self::fail('An unreviewed possible duplicate must not be imported');
		} catch (InvalidImportException $e) {
			self::assertStringContainsString('duplicados', $e->getMessage());
		}
		self::assertSame($before, Server::get(FeatureService::class)->currentRevision());
	}

	public function testDuplicateRowsAreSuggestedWithoutIdsAndRefusedWithSameId(): void {
		$bytes = OdsWorkbook::write(['Activos' => [array_values(OdsAssets::COLUMNS),
			['', '', 'ODS duplicate rows', 'salud', 'otro', '', '', 'Calle Dos 23'],
			['', '', 'ODS duplicate rows', 'salud', 'otro', '', '', 'Calle Dos 23']]]);
		$service = Server::get(OdsImportService::class);
		$preview = $service->preview($bytes, 'test-ods-duplicates');
		self::assertStringContainsString('Fila 3', $preview['rows'][0]['candidates'][0]);
		self::assertStringContainsString('Fila 2', $preview['rows'][1]['candidates'][0]);
		$id = $this->place('ODS repeated ID');
		$table = OdsWorkbook::read(Server::get(ExportService::class)->export([$id], 'ods', false)['body']);
		$table[] = $table[1];
		$preview = $service->preview(OdsWorkbook::write(['Activos' => $table]), 'test-ods-duplicates');
		self::assertSame(['invalid', 'invalid'], array_column($preview['rows'], 'status'));
	}

	public function testExplicitClearingIsReversibleAndOtherFieldsStay(): void {
		$id = $this->place('ODS clear', ['phone' => '+56911111111', 'notes' => 'Keep this note']);
		$bytes = Server::get(ExportService::class)->export([$id], 'ods', false)['body'];
		$slug = 'test-ods-' . bin2hex(random_bytes(4));
		$this->datasets[] = $slug;
		$service = Server::get(OdsImportService::class);
		$preview = $service->preview($bytes, $slug);
		$batch = $service->commit($bytes, $slug, $preview['revision'], [['row' => 2, 'clear' => ['phone'], 'confirmDuplicates' => true]], 'admin');
		$feature = Server::get(FeatureMapper::class)->find($id);
		self::assertNull($feature->getPhone());
		self::assertSame('Keep this note', $feature->getNotes());
		Server::get(RevertService::class)->revert((int)$batch->getId(), 'admin');
		self::assertSame('+56911111111', Server::get(FeatureMapper::class)->find($id)->getPhone());
	}

	public function testForeignAndStaleExportIdsAreInvalidBeforeCommit(): void {
		$id = $this->place('ODS stale export');
		$bytes = Server::get(ExportService::class)->export([$id], 'ods', false)['body'];
		$current = Server::get(FeatureMapper::class)->find($id)->toClient();
		Server::get(FeatureService::class)->update($id, array_replace($current, ['name' => 'Changed since export']));
		$service = Server::get(OdsImportService::class);
		$preview = $service->preview($bytes, 'test-ods-stale-export');
		self::assertSame('invalid', $preview['rows'][0]['status']);
		self::assertStringContainsString('exportación', $preview['rows'][0]['errors'][0]);
		$table = OdsWorkbook::read($bytes);
		$table[1][0] = 'another-installation:1';
		$preview = $service->preview(OdsWorkbook::write(['Activos' => $table]), 'test-ods-foreign');
		self::assertSame('invalid', $preview['rows'][0]['status']);
		self::assertStringContainsString('instalación', $preview['rows'][0]['errors'][0]);
	}

}
