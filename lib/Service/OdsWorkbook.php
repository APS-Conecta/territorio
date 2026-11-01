<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Exception\ServerFaultException;
use ZipArchive;

/** A bounded, text-only ODS table. No formulas, external resources or macros execute. */
final class OdsWorkbook {
	public const MIME = 'application/vnd.oasis.opendocument.spreadsheet';
	public const MAX_ROWS = 5001;
	private const MAX_BYTES = 8 * 1024 * 1024;
	private const TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
	private const TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
	private const OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

	/** @param array<string, list<list<string|int|float|null>>> $sheets */
	public static function write(array $sheets, array $choices = []): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="' . self::OFFICE . '" xmlns:table="' . self::TABLE
			. '" xmlns:text="' . self::TEXT . '" xmlns:of="urn:oasis:names:tc:opendocument:xmlns:of:1.2" office:version="1.2"><office:body><office:spreadsheet>';
		if ($choices !== []) {
			$xml .= '<table:content-validations>';
			foreach ($choices as $column => $values) {
				$list = implode(';', array_map(static fn (string $v): string => '"' . str_replace('"', '""', $v) . '"', $values));
				$xml .= '<table:content-validation table:name="column' . $column . '" table:allow-empty-cell="true" table:display-list="unsorted" table:condition="'
					. self::escape('of:cell-content-is-in-list(' . $list . ')') . '"/>';
			}
			$xml .= '</table:content-validations>';
		}
		foreach ($sheets as $name => $rows) {
			$xml .= '<table:table table:name="' . self::escape($name) . '">';
			foreach ($rows as $rowIndex => $row) {
				$xml .= '<table:table-row>';
				foreach ($row as $column => $value) {
					// Explicit string cells keep +56 telephone numbers and =text literal.
					$validation = $name === 'Activos' && $rowIndex > 0 && isset($choices[$column]) ? ' table:content-validation-name="column' . $column . '"' : '';
					$xml .= '<table:table-cell office:value-type="string"' . $validation . '><text:p>'
						. self::cellText((string)$value)
						. '</text:p></table:table-cell>';
				}
				$xml .= '</table:table-row>';
			}
			$xml .= '</table:table>';
		}
		$xml .= '</office:spreadsheet></office:body></office:document-content>';
		if (strlen($xml) > self::MAX_BYTES) {
			throw new \OCA\Territorio\Exception\InvalidFeatureException('La planilla supera los 8 MB. Exporte una selección más pequeña.');
		}
		$path = tempnam(sys_get_temp_dir(), 'territorio-ods-');
		if ($path === false) {
			throw new ServerFaultException('No se pudo preparar la planilla ODS.');
		}
		try {
			$zip = new ZipArchive();
			if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
				throw new ServerFaultException('No se pudo crear la planilla ODS.');
			}
			$ok = $zip->addFromString('mimetype', self::MIME)
				&& $zip->setCompressionName('mimetype', ZipArchive::CM_STORE)
				&& $zip->addFromString('content.xml', $xml)
				&& $zip->addFromString('META-INF/manifest.xml', '<?xml version="1.0"?>'
					. '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">'
					. '<manifest:file-entry manifest:full-path="/" manifest:media-type="' . self::MIME . '"/>'
					. '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
					. '</manifest:manifest>');
			$closed = $zip->close();
			$bytes = $ok && $closed ? file_get_contents($path) : false;
			if ($bytes === false) {
				throw new ServerFaultException('No se pudo escribir la planilla ODS.');
			}
			return $bytes;
		} finally {
			if (file_exists($path)) {
				unlink($path);
			}
		}
	}

	/** @return list<list<string>> */
	public static function read(string $bytes): array {
		if (strlen($bytes) > self::MAX_BYTES) {
			throw new InvalidImportException('La planilla ODS supera los 8 MB.');
		}
		$path = tempnam(sys_get_temp_dir(), 'territorio-ods-');
		if ($path === false) {
			throw new ServerFaultException('No se pudo preparar la lectura ODS.');
		}
		try {
			if (file_put_contents($path, $bytes) === false) {
				throw new ServerFaultException('No se pudo preparar la lectura ODS.');
			}
			$zip = new ZipArchive();
			if ($zip->open($path) !== true) {
				throw new InvalidImportException('El archivo no es una planilla ODS válida.');
			}
			try {
				$stat = $zip->statName('content.xml');
				if ($zip->numFiles > 256 || $stat === false || $stat['size'] > self::MAX_BYTES) {
					throw new InvalidImportException('La planilla no contiene una tabla válida de hasta 8 MB descomprimidos.');
				}
				$xml = $zip->getFromName('content.xml', self::MAX_BYTES + 1);
				if ($xml === false || strlen($xml) > self::MAX_BYTES) {
					throw new InvalidImportException('No se pudo leer la tabla ODS.');
				}
			} finally {
				$zip->close();
			}
		} finally {
			unlink($path);
		}
		if (str_contains($xml, "\0") || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml)) {
			throw new InvalidImportException('La planilla contiene declaraciones XML no permitidas.');
		}
		$previous = libxml_use_internal_errors(true);
		try {
			$doc = new DOMDocument();
			if (!$doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
				throw new InvalidImportException('El contenido XML de la planilla es inválido.');
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('table', self::TABLE);
		$xpath->registerNamespace('office', self::OFFICE);
		$tables = $xpath->query('/office:document-content/office:body/office:spreadsheet/table:table[@table:name="Activos"]');
		if ($tables->length !== 1) {
			throw new InvalidImportException('Use la hoja «Activos» de la plantilla descargable.');
		}
		$rows = [];
		$blankRows = 0;
		foreach ($xpath->query('.//table:table-row', $tables->item(0)) as $element) {
			$row = self::cells($element);
			$repeat = max(1, (int)$element->getAttributeNS(self::TABLE, 'number-rows-repeated'));
			if ($row === []) {
				$blankRows += $repeat;
				continue;
			}
			if ($repeat + count($rows) + $blankRows > self::MAX_ROWS) {
				throw new InvalidImportException('Importe hasta 5000 activos por planilla.');
			}
			for ($i = 0; $i < $blankRows; $i++) {
				$rows[] = [];
			}
			$blankRows = 0;
			for ($i = 0; $i < $repeat; $i++) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/** @return list<string> */
	private static function cells(DOMElement $row): array {
		$cells = [];
		$blanks = 0;
		foreach ($row->childNodes as $cell) {
			if (!$cell instanceof DOMElement || $cell->namespaceURI !== self::TABLE) {
				continue;
			}
			if ($cell->hasAttributeNS(self::TABLE, 'formula')) {
				throw new InvalidImportException('La hoja Activos contiene fórmulas. Pegue solo valores antes de importar.');
			}
			$expanded = 0;
			foreach (['s' => ' ', 'tab' => "\t", 'line-break' => "\n"] as $tag => $text) {
				foreach (iterator_to_array($cell->getElementsByTagNameNS(self::TEXT, $tag)) as $space) {
					$count = $tag === 's' ? max(1, (int)$space->getAttributeNS(self::TEXT, 'c')) : 1;
					$expanded += $count;
					if ($expanded > 100000) {
						throw new InvalidImportException('Una celda ODS contiene demasiado espacio repetido.');
					}
					$space->parentNode->replaceChild($cell->ownerDocument->createTextNode(str_repeat($text, $count)), $space);
				}
			}
			$parts = [];
			foreach ($cell->getElementsByTagNameNS(self::TEXT, 'p') as $paragraph) {
				$parts[] = $paragraph->textContent;
			}
			$value = implode("\n", $parts);
			if ($cell->getAttributeNS(self::OFFICE, 'value-type') === 'float') {
				$value = $cell->getAttributeNS(self::OFFICE, 'value');
			}
			$repeat = max(1, (int)$cell->getAttributeNS(self::TABLE, 'number-columns-repeated'));
			if ($value === '') {
				$blanks += $repeat;
				continue;
			}
			if ($repeat + count($cells) + $blanks > 32 || strlen($value) > 100000) {
				throw new InvalidImportException('La hoja Activos tiene demasiadas columnas o una celda demasiado larga.');
			}
			$cells = array_merge($cells, array_fill(0, $blanks, ''), array_fill(0, $repeat, $value));
			$blanks = 0;
		}
		return $cells;
	}

	private static function cellText(string $value): string {
		$text = self::escape($value);
		$text = preg_replace_callback('/ +/', static fn (array $match): string => '<text:s text:c="' . strlen($match[0]) . '"/>', $text);
		return str_replace(["\n", "\t"], ['</text:p><text:p>', '<text:tab/>'], $text);
	}

	private static function escape(string $value): string {
		return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}
