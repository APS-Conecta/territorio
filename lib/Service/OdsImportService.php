<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use APS\Common\Text;
use DateTime;
use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\ImportBatch;
use OCA\Territorio\Db\ImportBatchMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Domain\DuplicateFinder;
use OCA\Territorio\Domain\Geometry;
use OCA\Territorio\Domain\Provenance;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Exception\InvalidGeometryException;
use OCA\Territorio\Exception\InvalidImportException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use Throwable;

/** Reviewed spreadsheet edits reuse the Registry write door and reversible import batches. */
class OdsImportService {
	public function __construct(
		private FeatureMapper $features,
		private FeatureService $writes,
		private TaxonomyService $taxonomy,
		private RevisionLog $revisions,
		private DatasetMapper $datasets,
		private ImportBatchMapper $batches,
		private DuplicateService $duplicates,
		private IDBConnection $db,
		private IConfig $config,
	) {
	}

	public function template(): string {
		return OdsAssets::write([], $this->taxonomy->subcategoryIndex(), false);
	}

	/** Reads and validates; no Dataset, batch or candidate pair is written here. */
	public function preview(string $bytes, string $dataset): array {
		if (trim($dataset) === '' || mb_strlen($dataset) > 64) {
			throw new InvalidImportException('Indique un conjunto de datos de hasta 64 caracteres.');
		}
		try {
			if ($this->datasets->findBySlug($dataset)->getBoundary()) {
				throw new InvalidImportException('Elija un conjunto de activos, no uno de fronteras.');
			}
		} catch (DoesNotExistException) {
			// A new collection is created only when the reviewed selection commits.
		}
		$revision = $this->revisions->current();
		$rows = [];
		$ids = [];
		foreach (OdsAssets::read($bytes) as $row) {
			$entry = ['row' => $row['row'], 'name' => $row['values']['name'] ?? '',
				'status' => 'invalid', 'errors' => [], 'candidates' => [], 'changes' => []];
			try {
				[$id, $input, $current] = $this->prepare($row['values']);
				$entry += ['id' => $id, 'input' => $input];
				$entry['status'] = $id === null ? 'new' : 'update';
				if ($id !== null) {
					$ids[$id][] = count($rows);
				}
				foreach ($input as $field => $value) {
					$old = $current?->toClient()[$field] ?? null;
					if ($value !== $old) {
						if ($field === 'subcategoryId') {
						$index = $this->taxonomy->subcategoryIndex();
						$old = isset($index[$old]) ? $index[$old]['categoryLabel'] . ' · ' . $index[$old]['subcategoryLabel'] : null;
						$value = $index[$value]['categoryLabel'] . ' · ' . $index[$value]['subcategoryLabel'];
					}
					$entry['changes'][] = ['field' => $field, 'from' => $old, 'to' => $value];
					}
				}
			} catch (InvalidImportException|InvalidFeatureException|InvalidGeometryException $e) {
				$entry['errors'][] = $e->getMessage();
			}
			$rows[] = $entry;
		}
		foreach ($ids as $indexes) {
			if (count($indexes) > 1) {
				foreach ($indexes as $index) {
					$rows[$index]['status'] = 'invalid';
					$rows[$index]['errors'][] = 'El ID aparece más de una vez. Conserve una sola fila por activo.';
				}
			}
		}
		$this->suggest($rows);
		return ['revision' => $revision, 'rows' => $rows, 'clearable' => OdsAssets::OPTIONAL];
	}

	/** The whole selected batch commits atomically, or changes nothing. */
	public function commit(string $bytes, string $dataset, int $revision, array $decisions, ?string $actor): ImportBatch {
		$this->db->beginTransaction();
		try {
			if ($this->revisions->lockCurrent() !== $revision) {
				throw new InvalidImportException('El inventario cambió. Vuelva a revisar la planilla antes de importar.');
			}
			$preview = $this->preview($bytes, $dataset);
			$byRow = array_column($preview['rows'], null, 'row');
			$selected = [];
			foreach ($decisions as $decision) {
				$number = is_array($decision) ? ($decision['row'] ?? null) : null;
				if (!is_int($number) || !isset($byRow[$number]) || isset($selected[$number])) {
					throw new InvalidImportException('La selección de filas no es válida.');
				}
				$row = $byRow[$number];
				if ($row['status'] === 'invalid') {
					throw new InvalidImportException('Corrija los errores de la fila ' . $number . ' antes de importarla.');
				}
				if ($row['candidates'] !== [] && ($decision['confirmDuplicates'] ?? false) !== true) {
					throw new InvalidImportException('Revise los posibles duplicados de la fila ' . $number . '.');
				}
				$clear = $decision['clear'] ?? [];
				if (!is_array($clear)) {
					throw new InvalidImportException('La selección de campos a borrar no es válida.');
				}
				foreach ($clear as $field) {
					if (!is_string($field) || !in_array($field, OdsAssets::OPTIONAL, true)) {
						throw new InvalidImportException('Solo se pueden borrar campos opcionales de texto.');
					}
					$row['input'][$field] = '';
				}
				$selected[$number] = $row;
			}
			if ($selected === []) {
				throw new InvalidImportException('Seleccione al menos una fila válida.');
			}
			$collection = $this->datasets->findOrCreate($dataset, $dataset);
			$batch = new ImportBatch();
			$batch->setDatasetId((int)$collection->getId());
			$batch->setActor($actor);
			$batch->setStartedAt(new DateTime());
			$batch->setRevisionFrom($revision);
			$batch->setRevisionTo($revision);
			$batch = $this->batches->insert($batch);
			$this->revisions->duringImport((int)$batch->getId(), function () use ($selected, $collection, $batch): void {
				foreach ($selected as $row) {
					if ($row['id'] === null) {
						$this->writes->create($row['input'], new Provenance((int)$collection->getId(),
							'ods:' . bin2hex(random_bytes(16)), $row['input']));
						$batch->setCreated($batch->getCreated() + 1);
					} else {
						$current = $this->features->find($row['id']);
						$input = $row['input'];
						$official = json_decode($current->getOfficial() ?? '{}', true);
						$provenance = $current->getDatasetId() === null ? null : new Provenance(
							$current->getDatasetId(), $current->getExternalId(), array_replace($official ?? [], $input));
						$this->writes->update($row['id'], $input, $provenance);
						$batch->setUpdated($batch->getUpdated() + 1);
					}
				}
			});
			$batch->setSkipped(count($preview['rows']) - count($selected));
			$batch->setRevisionTo($this->revisions->current());
			$this->batches->update($batch);
			$this->duplicates->scan();
			$this->db->commit();
			return $batch;
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** @return array{?int, array<string,mixed>, ?Feature} */
	private function prepare(array $values): array {
		$id = null;
		$current = null;
		$givenId = $values['id'] ?? '';
		if ($givenId !== '') {
			$prefix = $this->config->getSystemValueString('instanceid') . ':';
			$number = str_starts_with($givenId, $prefix) ? substr($givenId, strlen($prefix)) : '';
			if (!ctype_digit($number) || (int)$number < 1 || (string)(int)$number !== $number) {
				throw new InvalidImportException('El ID no pertenece a esta instalación. Exporte de nuevo el activo.');
			}
			$id = (int)$number;
			try {
				$current = $this->features->find($id);
			} catch (DoesNotExistException) {
				throw new InvalidImportException('El ID no corresponde a un activo existente.');
			}
			if ($current->getRetired() || $current->getOverridden()) {
				throw new InvalidImportException('El activo está retirado o tiene una edición local protegida. Revíselo en el mapa.');
			}
			if ($current->getKind() !== 'place') {
				throw new InvalidImportException('ODS actualiza solo activos puntuales.');
			}
			if (($values['revision'] ?? '') !== (string)$current->getRevision()) {
				throw new InvalidImportException('El activo cambió desde la exportación. Exporte una copia actualizada.');
			}
		} elseif (($values['revision'] ?? '') !== '') {
			throw new InvalidImportException('Una fila nueva debe dejar ID y Revisión vacíos.');
		}
		$subcategoryId = null;
		foreach ($this->taxonomy->subcategoryIndex() as $key => $pair) {
			if ($pair['category'] === 'division_territorial') {
				continue;
			}
			if ($this->matches($values['category'], $pair['category'], $pair['categoryLabel'])
				&& $this->matches($values['subcategory'], $pair['subcategory'], $pair['subcategoryLabel'])) {
				$subcategoryId = $key;
				break;
			}
		}
		if ($subcategoryId === null) {
			throw new InvalidImportException('Categoría o subcategoría no reconocida. Consulte la hoja Clasificación.');
		}
		$input = ['name' => $values['name'], 'subcategoryId' => $subcategoryId];
		foreach (OdsAssets::OPTIONAL as $key) {
			if (($values[$key] ?? '') !== '') {
				$input[$key] = $values[$key];
			}
		}
		$lat = $values['lat'] ?? '';
		$lon = $values['lon'] ?? '';
		if ($lat !== '' || $lon !== '') {
			if (!is_numeric($lat) || !is_numeric($lon)) {
				throw new InvalidImportException('Complete Latitud y Longitud con dos números válidos (punto decimal).');
			}
			$input['geometry'] = json_decode(Geometry::fromArray(['type' => 'Point', 'coordinates' => [(float)$lon, (float)$lat]])->toJson(), true);
		} elseif ($id === null) {
			$input['geometry'] = null;
		}
		$geometry = $input['geometry'] ?? ($current === null ? null : json_decode($current->getGeometry() ?? 'null', true));
		$quality = $values['locationQuality'] ?? '';
		if ($geometry !== null) {
			$quality = $quality !== '' ? $quality : (($current?->getLocationQuality() === 'aproximada' ? 'aproximada' : 'exacta'));
			if (!in_array($quality, ['exacta', 'aproximada'], true)) {
				throw new InvalidImportException('Ubicación debe ser exacta o aproximada cuando hay coordenadas.');
			}
			$input['locationQuality'] = $quality;
		} elseif ($quality !== '' && $quality !== 'sin_coordenada') {
			throw new InvalidImportException('El activo no tiene coordenadas: deje Ubicación vacía o use sin_coordenada.');
		}
		$this->writes->checkNameAndText($input);
		return [$id, $input, $current];
	}

	private function matches(string $value, string $slug, string $label): bool {
		return Text::fold($value) === Text::fold($slug) || Text::fold($value) === Text::fold($label);
	}

	/** Adds candidate labels without persisting them; upload rows use negative IDs. */
	private function suggest(array &$rows): void {
		$records = [];
		$lookup = [];
		foreach ($this->features->findPlaces() as $feature) {
			$point = $feature->anchor();
			$records[] = ['id' => (int)$feature->getId(), 'name' => $feature->getName(), 'lon' => $point[0] ?? null,
				'lat' => $point[1] ?? null, 'address' => $feature->getAddress() ?? ''];
		}
		$existing = array_column($records, null, 'id');
		foreach ($rows as $index => $row) {
			if ($row['status'] === 'invalid') {
				continue;
			}
			$current = $existing[$row['id']] ?? [];
			$point = $row['input']['geometry']['coordinates'] ?? [$current['lon'] ?? null, $current['lat'] ?? null];
			$records[] = ['id' => -$row['row'], 'name' => $row['name'], 'lon' => $point[0] ?? null,
				'lat' => $point[1] ?? null, 'address' => $row['input']['address'] ?? $current['address'] ?? ''];
			$lookup[-$row['row']] = $index;
		}
		$named = array_column($records, null, 'id');
		$add = function (int $one, int $other) use (&$rows, $lookup, $named): void {
			if (!isset($lookup[$one])) {
				return;
			}
			$index = $lookup[$one];
			if (($rows[$index]['id'] ?? null) === $other) {
				return;
			}
			$label = $other < 0 ? 'Fila ' . -$other . ': ' : 'Activo #' . $other . ': ';
			if (count($rows[$index]['candidates']) < 5) {
				$rows[$index]['candidates'][$other] = $label . $named[$other]['name'];
			}
		};
		foreach (DuplicateFinder::pairs($records) as $pair) {
			$add($pair->firstId, $pair->secondId);
			$add($pair->secondId, $pair->firstId);
		}
		$byAddress = [];
		foreach ($records as $record) {
			if ($record['lat'] === null && trim($record['name']) !== '') {
				$key = Text::fold($record['name'] . '|' . $record['address']);
				foreach ($byAddress[$key] ?? [] as $other) {
					$add($record['id'], $other);
					$add($other, $record['id']);
				}
				if (count($byAddress[$key] ?? []) < 5) {
					$byAddress[$key][] = $record['id'];
				}
			}
		}
		foreach ($rows as &$row) {
			$row['candidates'] = array_values($row['candidates']);
		}
	}
}
