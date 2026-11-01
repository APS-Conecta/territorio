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
		$batch = $service->commit($bytes, $slug, $preview['revision'], [['row' => 2, 'clear' => [], 'confirmDuplicates' => true]], 'admin', $preview['taxonomySignature']);
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
		$batch = $service->commit($edited, $slug, $preview['revision'], [['row' => 2, 'clear' => [], 'confirmDuplicates' => true]], 'admin', $preview['taxonomySignature']);
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
		$service->commit($bytes, 'test-ods-stale', $preview['revision'], [['row' => 2, 'clear' => []]], 'admin', $preview['taxonomySignature']);
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
			$service->commit($bytes, 'test-ods-invalid', $preview['revision'], [['row' => 2, 'clear' => []]], 'admin', $preview['taxonomySignature']);
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
		$batch = $service->commit($bytes, $slug, $preview['revision'], [['row' => 2, 'clear' => ['phone'], 'confirmDuplicates' => true]], 'admin', $preview['taxonomySignature']);
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

	public function testAddressOnlyDuplicateMatchesLocatedAssetAndTheReverse(): void {
		$this->place('ODS located duplicate', ['address' => 'Calle Tres 123']);
		$this->place('ODS unlocated duplicate', ['geometry' => null, 'address' => 'Calle Cuatro 123']);
		$bytes = OdsWorkbook::write(['Activos' => [array_values(OdsAssets::COLUMNS),
			['', '', 'ODS located duplicate', 'salud', 'otro', '', '', 'Calle Tres 123'],
			['', '', 'ODS unlocated duplicate', 'salud', 'otro', '-33.7', '-70.7', 'Calle Cuatro 123']]]);
		$preview = Server::get(OdsImportService::class)->preview($bytes, 'test-ods-address-candidates');
		self::assertNotEmpty($preview['rows'][0]['candidates']);
		self::assertNotEmpty($preview['rows'][1]['candidates']);
	}

	public function testClassificationCanBeCorrectedWithoutWritingTheRegistry(): void {
		$bytes = OdsWorkbook::write(['Activos' => [array_values(OdsAssets::COLUMNS),
			['', '', 'ODS classification correction', 'not a category', 'not a subcategory']]]);
		$service = Server::get(OdsImportService::class);
		$preview = $service->preview($bytes, 'test-ods-correction');
		self::assertSame('invalid', $preview['rows'][0]['status']);
		$choice = $preview['classifications'][0];
		$corrected = $service->preview($bytes, 'test-ods-correction', [2 => $choice['id']]);
		self::assertSame('new', $corrected['rows'][0]['status']);
		self::assertSame($choice['id'], $corrected['rows'][0]['input']['subcategoryId']);
		self::assertSame($preview['revision'], Server::get(FeatureService::class)->currentRevision());
		$this->expectException(InvalidImportException::class);
		$service->preview($bytes, 'test-ods-correction', [2 => PHP_INT_MAX]);
	}

	public function testSpreadsheetEditsKeepSourceProvenanceAndSurviveSourceReimport(): void {
		$slug = 'test-ods-' . bin2hex(random_bytes(4));
		$this->datasets[] = $slug;
		$source = json_encode(['type' => 'FeatureCollection', 'features' => [[
			'type' => 'Feature', 'id' => 'source-ods-local', 'geometry' => null,
			'properties' => ['name' => 'ODS original source', 'category' => 'salud', 'subcategory' => 'otro'],
		]]]);
		$imports = Server::get(\OCA\Territorio\Service\ImportService::class);
		$imports->importFile($source, 'source.geojson', $slug);
		$dataset = Server::get(\OCA\Territorio\Db\DatasetMapper::class)->findBySlug($slug);
		$original = array_values(Server::get(FeatureMapper::class)->findByDataset((int)$dataset->getId()))[0];
		$id = (int)$original->getId();
		$official = $original->getOfficial();
		$service = Server::get(OdsImportService::class);
		foreach (['ODS local correction', 'ODS second local correction'] as $name) {
			$table = OdsWorkbook::read(Server::get(ExportService::class)->export([$id], 'ods', false)['body']);
			$table[1][2] = $name;
			$bytes = OdsWorkbook::write(['Activos' => $table]);
			$preview = $service->preview($bytes, $slug);
			self::assertSame('update', $preview['rows'][0]['status']);
			$service->commit($bytes, $slug, $preview['revision'], [['row' => 2, 'confirmDuplicates' => true]], 'admin', $preview['taxonomySignature']);
			$current = Server::get(FeatureMapper::class)->find($id);
			self::assertTrue($current->getOverridden());
			self::assertSame($official, $current->getOfficial());
			$imports->importFile($source, 'source.geojson', $slug);
			self::assertSame($name, Server::get(FeatureMapper::class)->find($id)->getName());
		}
	}

	public function testTaxonomyChangeInvalidatesPreviewWithoutARegistryRevision(): void {
		$slug = 'test-ods-' . bin2hex(random_bytes(4));
		$this->datasets[] = $slug;
		$bytes = OdsWorkbook::write(['Activos' => [array_values(OdsAssets::COLUMNS),
			['', '', 'ODS taxonomy stale', 'salud', 'otro']]]);
		$service = Server::get(OdsImportService::class);
		$taxonomy = Server::get(\OCA\Territorio\Service\TaxonomyService::class);
		$category = array_values(array_filter($taxonomy->tree(), static fn ($c) => $c['slug'] === 'salud'))[0];
		$preview = $service->preview($bytes, $slug);
		try {
			$taxonomy->renameCategory('salud', 'Salud ODS temporal');
			self::assertSame($preview['revision'], Server::get(FeatureService::class)->currentRevision());
			try {
				$service->commit($bytes, $slug, $preview['revision'], [['row' => 2, 'confirmDuplicates' => true]], 'admin', $preview['taxonomySignature']);
				self::fail('A changed taxonomy must invalidate the reviewed classification');
			} catch (InvalidImportException $e) {
				self::assertStringContainsString('clasificación cambió', $e->getMessage());
			}
		} finally {
			$taxonomy->renameCategory('salud', $category['label']);
		}
	}

	public function testUndoSpreadsheetCorrectionRestoresSourceStewardship(): void {
		$slug = 'test-ods-' . bin2hex(random_bytes(4));
		$this->datasets[] = $slug;
		$source = ['type' => 'FeatureCollection', 'features' => [[
			'type' => 'Feature', 'id' => 'source-ods-undo', 'geometry' => null,
			'properties' => ['name' => 'ODS original source', 'category' => 'salud', 'subcategory' => 'otro'],
		]]];
		$imports = Server::get(\OCA\Territorio\Service\ImportService::class);
		$imports->importFile(json_encode($source), 'source.geojson', $slug);
		$dataset = Server::get(\OCA\Territorio\Db\DatasetMapper::class)->findBySlug($slug);
		$mapper = Server::get(FeatureMapper::class);
		$original = array_values($mapper->findByDataset((int)$dataset->getId()))[0];
		$id = (int)$original->getId();
		self::assertFalse($original->getOverridden());
		$table = OdsWorkbook::read(Server::get(ExportService::class)->export([$id], 'ods', false)['body']);
		$table[1][2] = 'ODS local correction';
		$bytes = OdsWorkbook::write(['Activos' => $table]);
		$service = Server::get(OdsImportService::class);
		$preview = $service->preview($bytes, $slug);
		$batch = $service->commit($bytes, $slug, $preview['revision'], [['row' => 2, 'confirmDuplicates' => true]], 'admin', $preview['taxonomySignature']);
		self::assertTrue($mapper->find($id)->getOverridden());

		$reverts = Server::get(RevertService::class);
		$undo = $reverts->revert((int)$batch->getId(), 'admin');
		self::assertSame('ODS original source', $mapper->find($id)->getName());
		self::assertFalse($mapper->find($id)->getOverridden());
		$redo = $reverts->revert((int)$undo->getId(), 'admin');
		self::assertSame('ODS local correction', $mapper->find($id)->getName());
		self::assertTrue($mapper->find($id)->getOverridden());
		$reverts->revert((int)$redo->getId(), 'admin');
		$source['features'][0]['properties']['name'] = 'ODS refreshed source';
		$imports->importFile(json_encode($source), 'source.geojson', $slug);
		self::assertSame('ODS refreshed source', $mapper->find($id)->getName());
		self::assertFalse($mapper->find($id)->getOverridden());
	}

}
