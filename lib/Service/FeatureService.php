<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Turns what a person typed into a Feature that is safe to store.
 *
 * Everything derivable is derived here and never accepted from the client: Kind
 * follows the geometry, the bounding box is measured from it, and Location
 * quality is forced to absent when there is no geometry to describe. Sector and
 * Unidad Vecinal are not fields at all (ADR-0008).
 *
 * Controllers stay thin over this, so exposing an OCS API later is a new
 * controller rather than a rewrite.
 */
class FeatureService {
	/** Optional free-text columns, all handled identically: trim, blank becomes null. */
	private const OPTIONAL_TEXT = [
		'address' => 'setAddress',
		'phone' => 'setPhone',
		'email' => 'setEmail',
		'contactPerson' => 'setContactPerson',
		'contactRole' => 'setContactRole',
		'openingHours' => 'setOpeningHours',
		'notes' => 'setNotes',
		'sourceRef' => 'setSourceRef',
	];

	public function __construct(
		private FeatureMapper $features,
		private SubcategoryMapper $subcategories,
	) {
	}

	/** @return Feature[] */
	public function listAll(): array {
		return $this->features->findAll();
	}

	/** @param array<string, mixed> $input */
	public function create(array $input): Feature {
		return $this->features->insert($this->apply(new Feature(), $input));
	}

	/**
	 * @param array<string, mixed> $input
	 * @throws DoesNotExistException when there is no such Feature
	 */
	public function update(int $id, array $input): Feature {
		return $this->features->update($this->apply($this->features->find($id), $input));
	}

	/** @param array<string, mixed> $input */
	private function apply(Feature $feature, array $input): Feature {
		$name = trim((string)($input['name'] ?? ''));
		if ($name === '') {
			throw new InvalidFeatureException('a Feature needs a name');
		}
		$feature->setName($name);

		$subcategoryId = (int)($input['subcategoryId'] ?? 0);
		try {
			$this->subcategories->find($subcategoryId);
		} catch (DoesNotExistException $e) {
			throw new InvalidFeatureException(
				sprintf('subcategory %d does not exist', $subcategoryId), 0, $e,
			);
		}
		$feature->setSubcategoryId($subcategoryId);

		foreach (self::OPTIONAL_TEXT as $key => $setter) {
			$value = trim((string)($input[$key] ?? ''));
			$feature->$setter($value === '' ? null : $value);
		}

		$this->applyGeometry($feature, $input);

		return $feature;
	}

	/**
	 * Geometry, and everything measured from it, in one place — so a Feature can
	 * never carry a bounding box that belongs to a shape it no longer has.
	 *
	 * @param array<string, mixed> $input
	 */
	private function applyGeometry(Feature $feature, array $input): void {
		$raw = $input['geometry'] ?? null;

		if (!is_array($raw) || $raw === []) {
			$feature->setGeometry(null);
			$feature->setMinLat(null);
			$feature->setMinLon(null);
			$feature->setMaxLat(null);
			$feature->setMaxLon(null);
			$feature->setKind(Kind::Place->value);
			// Nothing to describe, so this is not the user's to choose.
			$feature->setLocationQuality(LocationQuality::Absent->value);

			return;
		}

		$geometry = Geometry::fromArray($raw);
		$box = $geometry->boundingBox();

		$feature->setGeometry($geometry->toJson());
		$feature->setMinLat($box->minLat());
		$feature->setMinLon($box->minLon());
		$feature->setMaxLat($box->maxLat());
		$feature->setMaxLon($box->maxLon());
		$feature->setKind($geometry->kind()->value);
		$feature->setLocationQuality($this->locationQuality($input));
	}

	/** @param array<string, mixed> $input */
	private function locationQuality(array $input): string {
		$requested = trim((string)($input['locationQuality'] ?? ''));
		if ($requested === '') {
			return LocationQuality::Exact->value;
		}

		$quality = LocationQuality::tryFrom($requested);
		if ($quality === null || $quality === LocationQuality::Absent) {
			// Absent is derived from having no geometry; claiming it while
			// supplying one would leave the record contradicting itself.
			throw new InvalidFeatureException(sprintf('unknown location quality "%s"', $requested));
		}

		return $quality->value;
	}
}
