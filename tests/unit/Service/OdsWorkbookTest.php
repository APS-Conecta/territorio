<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Service\OdsWorkbook;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class OdsWorkbookTest extends TestCase {
	public function testWorkbookRoundTripsUnicodeNewlinesAndFormulaLookingTextAsText(): void {
		$rows = [['Nombre', 'Teléfono', 'Notas'], ['Plaza Ñuñoa', '+56912345678', "=SUM(A1:A2)\nsegunda línea"]];
		$bytes = OdsWorkbook::write(['Activos' => $rows, 'Instrucciones' => [['No modificar ID']]]);
		self::assertSame($rows, OdsWorkbook::read($bytes));
	}
	private function workbookWithCell(string $cell): string {
		$path = tempnam(sys_get_temp_dir(), 'ods-test-');
		try {
			$zip = new ZipArchive();
			$zip->open($path, ZipArchive::OVERWRITE);
			$zip->addFromString('content.xml', '<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:spreadsheet><table:table table:name="Activos"><table:table-row>' . $cell . '</table:table-row></table:table></office:spreadsheet></office:body></office:document-content>');
			$zip->close();
			return file_get_contents($path);
		} finally {
			unlink($path);
		}
	}

	public function testReadsLibreOfficeExplicitWhitespace(): void {
		$bytes = $this->workbookWithCell('<table:table-cell office:value-type="string"><text:p>Centro<text:s text:c="3"/>Vecinal<text:tab/>A<text:line-break/>B</text:p></table:table-cell>');
		self::assertSame([["Centro   Vecinal\tA\nB"]], OdsWorkbook::read($bytes));
	}

	public function testRefusesFormulaCellsRatherThanImportingCachedResults(): void {
		$bytes = $this->workbookWithCell('<table:table-cell table:formula="of:=1+1" office:value-type="float" office:value="2"><text:p>2</text:p></table:table-cell>');
		$this->expectException(InvalidImportException::class);
		OdsWorkbook::read($bytes);
	}

	public function testReadsUnderlyingNumericValueRatherThanLocaleFormattedText(): void {
		$bytes = $this->workbookWithCell('<table:table-cell office:value-type="float" office:value="-33.52"><text:p>-33,52</text:p></table:table-cell>');
		self::assertSame([['-33.52']], OdsWorkbook::read($bytes));
	}

	public function testRepeatedNonemptyCellsCannotExpandWithoutLimit(): void {
		$bytes = $this->workbookWithCell('<table:table-cell table:number-columns-repeated="999999999"><text:p>X</text:p></table:table-cell>');
		$this->expectException(InvalidImportException::class);
		OdsWorkbook::read($bytes);
	}
	public function testWhitespaceExpansionHasAWorkbookWideBudget(): void {
		$cell = '<table:table-cell><text:p><text:s text:c="100000"/></text:p></table:table-cell>';
		// Each cell is individually allowed; the collection exceeds the 8 MB budget.
		$bytes = $this->workbookWithCell(str_repeat($cell . '</table:table-row><table:table-row>', 90));
		$this->expectException(InvalidImportException::class);
		$this->expectExceptionMessage('texto expandido');
		OdsWorkbook::read($bytes);
	}

	public function testRepeatedRowsCannotExpandTheDecodedTableBeyondItsBudget(): void {
		$bytes = $this->workbookWithCell('</table:table-row><table:table-row table:number-rows-repeated="1000"><table:table-cell><text:p>' . str_repeat('x', 100000) . '</text:p></table:table-cell>');
		$this->expectException(InvalidImportException::class);
		$this->expectExceptionMessage('valores de la planilla');
		OdsWorkbook::read($bytes);
	}

	public function testRepeatedCellsAcrossRowsShareTheDecodedTableBudget(): void {
		$cell = '<table:table-cell table:number-columns-repeated="32"><text:p>' . str_repeat('x', 100000) . '</text:p></table:table-cell>';
		$bytes = $this->workbookWithCell($cell . '</table:table-row><table:table-row>' . $cell . '</table:table-row><table:table-row>' . $cell);
		$this->expectException(InvalidImportException::class);
		$this->expectExceptionMessage('valores de la planilla');
		OdsWorkbook::read($bytes);
	}

}
