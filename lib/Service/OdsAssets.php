<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use APS\Common\Text;
use OCA\Territorio\Exception\InvalidImportException;

/** The downloadable spreadsheet contract, shared by export, template and review. */
final class OdsAssets {
	public const COLUMNS = [
		'id' => 'ID (no modificar)', 'revision' => 'Revisión (no modificar)',
		'name' => 'Nombre', 'category' => 'Categoría', 'subcategory' => 'Subcategoría',
		'lat' => 'Latitud', 'lon' => 'Longitud', 'address' => 'Dirección',
		'phone' => 'Teléfono', 'email' => 'Correo', 'contactPerson' => 'Persona de contacto',
		'contactRole' => 'Cargo', 'openingHours' => 'Horario', 'notes' => 'Notas',
		'sourceRef' => 'Fuente', 'locationQuality' => 'Ubicación',
	];
	public const OPTIONAL = ['address', 'phone', 'email', 'contactPerson', 'contactRole', 'openingHours', 'notes', 'sourceRef'];
	private const CONTACTS = ['phone', 'email', 'contactPerson', 'contactRole'];

	/** @param list<array<string,mixed>> $rows @param array<int,array<string,mixed>> $taxonomy */
	public static function write(array $rows, array $taxonomy, bool $contacts): string {
		if (count($rows) >= OdsWorkbook::MAX_ROWS) {
			throw new \OCA\Territorio\Exception\InvalidFeatureException('Exporte hasta 5000 activos por planilla.');
		}
		$table = [array_values(self::COLUMNS)];
		foreach ($rows as $row) {
			$point = $row['geometry']['coordinates'] ?? null;
			$values = $row + ['category' => $row['categorySlug'] ?? '', 'subcategory' => $row['subcategorySlug'] ?? '',
				'lat' => $point[1] ?? '', 'lon' => $point[0] ?? ''];
			$table[] = array_map(static fn (string $key): string => !$contacts && in_array($key, self::CONTACTS, true)
				? '' : (string)($values[$key] ?? ''), array_keys(self::COLUMNS));
		}
		if ($rows === []) {
			$table[] = array_fill(0, count(self::COLUMNS), '');
		}
		$categories = [];
		$subcategories = [];
		$reference = [['Categoría', 'Subcategoría', 'Nombre de categoría', 'Nombre de subcategoría']];
		foreach ($taxonomy as $pair) {
			if ($pair['category'] !== 'division_territorial') {
				$categories[] = $pair['category'];
				$subcategories[] = $pair['subcategory'];
				$reference[] = [$pair['category'], $pair['subcategory'], $pair['categoryLabel'], $pair['subcategoryLabel']];
			}
		}
		return OdsWorkbook::write([
			'Activos' => $table,
			'Instrucciones' => [
				['Plantilla de activos comunitarios'],
				['Una fila por activo puntual. Hasta 5000 activos por archivo. Las áreas se dibujan en el mapa.'],
				['Nombre, Categoría y Subcategoría son obligatorios. Use los códigos o nombres de la hoja Clasificación.'],
				['Conserve ID y Revisión al actualizar. Para un activo nuevo, deje ambas celdas vacías.'],
				['Latitud y Longitud son opcionales, pero deben completarse juntas. No se busca una ubicación desde la dirección.'],
				['Teléfono, Correo, Persona de contacto, Cargo, Horario, Notas, Fuente y Dirección son opcionales.'],
				['Las celdas opcionales vacías conservan la información existente. Para borrarla, marque los campos durante la revisión.'],
				['Ubicación: exacta o aproximada cuando hay coordenadas; sin_coordenada cuando no hay ubicación.'],
				['No incluya fórmulas: pegue solo valores. Subir el archivo muestra una revisión antes de guardar.'],
				['Los ID solo sirven en la instalación de Territorio que exportó el archivo.'],
			],
			'Clasificación' => $reference,
		], array_filter([3 => array_values(array_unique($categories)), 4 => array_values(array_unique($subcategories)), 15 => ['exacta', 'aproximada', 'sin_coordenada']]));
	}

	/** @return list<array{row: int, values: array<string,string>}> */
	public static function read(string $bytes): array {
		$table = OdsWorkbook::read($bytes);
		$headers = array_shift($table) ?? [];
		$known = [];
		foreach (self::COLUMNS as $key => $label) {
			$known[Text::fold($label)] = $key;
		}
		$columns = [];
		foreach ($headers as $index => $header) {
			$key = $known[Text::fold(trim($header))] ?? null;
			if ($key !== null) {
				if (in_array($key, $columns, true)) {
					throw new InvalidImportException('La planilla repite una columna: ' . $header);
				}
				$columns[$index] = $key;
			}
		}
		foreach (['name', 'category', 'subcategory'] as $required) {
			if (!in_array($required, $columns, true)) {
				throw new InvalidImportException('Falta la columna ' . self::COLUMNS[$required] . '. Use la plantilla ODS.');
			}
		}
		$rows = [];
		foreach ($table as $index => $cells) {
			$values = [];
			foreach ($columns as $column => $key) {
				$values[$key] = trim($cells[$column] ?? '');
			}
			if (array_filter($values, static fn (string $v): bool => $v !== '') !== []) {
				$rows[] = ['row' => $index + 2, 'values' => $values];
			}
		}
		return $rows;
	}
}
