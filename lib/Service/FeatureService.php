<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\RevisionLog;
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
		private RevisionLog $revisions,
	) {
	}

	/** @return Feature[] */
	public function listAll(): array {
		return $this->features->findAll();
	}

	/**
	 * What a client holding `$since` has not seen yet (ADR-0002).
	 *
	 * The common case by far is that nothing has changed, and it costs exactly
	 * one indexed read of the Revision log — the Feature table is not touched at
	 * all. That is what makes a five-second poll from every open map affordable
	 * on an instance with no push infrastructure.
	 *
	 * @return array{revision: int, features: list<array<string, mixed>>}
	 */
	public function changesSince(int $since): array {
		$current = $this->revisions->current();

		if ($current <= $since) {
			return ['revision' => $current, 'features' => []];
		}

		return [
			'revision' => $current,
			'features' => array_map(
				static fn (Feature $feature): array => $feature->toClient(),
				$this->features->findChangedSince($since),
			),
		];
	}

	/**
	 * Retired records, for the screen that offers to bring one back.
	 *
	 * @return Feature[]
	 */
	public function listRetired(): array {
		return $this->features->findRetired();
	}

	/**
	 * ADR-0005: deleting is retiring. Nothing is destroyed, so nothing needs a
	 * confirmation that warns about permanence — because there is none.
	 *
	 * @throws DoesNotExistException
	 */
	public function retire(int $id): Feature {
		$feature = $this->features->find($id);
		if ($feature->getRetired()) {
			// History is append-only and never tidied, so a no-op that still logged
			// would leave a second "Retirado" entry describing nothing.
			throw new InvalidFeatureException('El registro ya estaba retirado.');
		}

		return $this->features->retire($feature);
	}

	/** @throws DoesNotExistException */
	public function restore(int $id): Feature {
		$feature = $this->features->find($id);
		if (!$feature->getRetired()) {
			throw new InvalidFeatureException('El registro no está retirado.');
		}

		return $this->features->restore($feature);
	}

	/**
	 * @return list<array<string, mixed>>
	 * @throws DoesNotExistException when there is no such Feature
	 */
	public function history(int $id): array {
		// Reading the Feature first so a history request for something that never
		// existed is a 404 rather than a convincing empty list.
		$this->features->find($id);

		return $this->revisions->forFeature($id);
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
		$feature = $this->features->find($id);

		// A retired record is invisible on every map, so an edit saved into one
		// would simply vanish — including one made by someone who had the record
		// open when a colleague retired it. Restoring first makes the edit land
		// somewhere people can see.
		if ($feature->getRetired()) {
			throw new InvalidFeatureException('El registro está retirado. Restáurelo antes de editarlo.');
		}

		return $this->features->update($this->apply($feature, $input));
	}

	/** @param array<string, mixed> $input */
	private function apply(Feature $feature, array $input): Feature {
		$name = trim((string)($input['name'] ?? ''));
		if ($name === '') {
			throw new InvalidFeatureException('El registro necesita un nombre.');
		}
		$feature->setName($name);

		$subcategoryId = (int)($input['subcategoryId'] ?? 0);
		try {
			$this->subcategories->find($subcategoryId);
		} catch (DoesNotExistException $e) {
			throw new InvalidFeatureException(
				sprintf('La subcategoría %d no existe.', $subcategoryId), 0, $e,
			);
		}
		$feature->setSubcategoryId($subcategoryId);

		// Only fields actually submitted are written. A request that omits one
		// leaves it alone rather than blanking it: history can now say what a
		// field used to hold, but silently blanking it would still be a change
		// nobody asked for. Clearing a field is possible — send it empty.
		foreach (self::OPTIONAL_TEXT as $key => $setter) {
			if (!array_key_exists($key, $input)) {
				continue;
			}
			$value = trim((string)$input[$key]);
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
			$feature->setBoundingBox(null);
			$feature->setKind(Kind::Place->value);
			// Nothing to describe, so this is not the user's to choose.
			$feature->setLocationQuality(LocationQuality::Absent->value);

			return;
		}

		$geometry = Geometry::fromArray($raw);

		// Places only, for now. Geometry can measure a Zone or a Route perfectly
		// well, but nothing in this slice draws one — a Polygon would land on the
		// map as a single pin on an arbitrary vertex, which is worse than a clear
		// refusal. Lift this when Sectores get their own rendering (issue #10).
		if ($geometry->kind() !== Kind::Place) {
			throw new InvalidFeatureException(sprintf(
				'Por ahora solo se admiten puntos; %s llegará con los sectores y las rutas.',
				$geometry->type(),
			));
		}

		$feature->setGeometry($geometry->toJson());
		$feature->setBoundingBox($geometry->boundingBox());
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
			throw new InvalidFeatureException(sprintf('Exactitud desconocida: "%s".', $requested));
		}

		return $quality->value;
	}
}
