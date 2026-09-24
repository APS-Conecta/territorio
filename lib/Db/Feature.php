<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use OCA\Territorio\Domain\BoundingBox;
use OCA\Territorio\Domain\Kind;
use OCP\AppFramework\Db\Entity;

/**
 * One record in the Registry that has a geometry — see `CONTEXT.md`.
 *
 * Sector and Unidad Vecinal are here as ids and are never editable fields
 * (ADR-0008): they are resolved from the geometry and stored, so that filtering
 * and counting by them stay ordinary indexed reads. Correcting an assignment
 * means correcting a boundary, which is the honest fix.
 *
 * @method int getSubcategoryId()
 * @method void setSubcategoryId(int $subcategoryId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getGeometry()
 * @method void setGeometry(?string $geometry)
 * @method ?float getMinLat()
 * @method void setMinLat(?float $minLat)
 * @method ?float getMinLon()
 * @method void setMinLon(?float $minLon)
 * @method ?float getMaxLat()
 * @method void setMaxLat(?float $maxLat)
 * @method ?float getMaxLon()
 * @method void setMaxLon(?float $maxLon)
 * @method ?string getAddress()
 * @method void setAddress(?string $address)
 * @method ?string getPhone()
 * @method void setPhone(?string $phone)
 * @method ?string getEmail()
 * @method void setEmail(?string $email)
 * @method ?string getContactPerson()
 * @method void setContactPerson(?string $contactPerson)
 * @method ?string getContactRole()
 * @method void setContactRole(?string $contactRole)
 * @method ?string getLocationQuality()
 * @method void setLocationQuality(?string $locationQuality)
 * @method ?string getOpeningHours()
 * @method void setOpeningHours(?string $openingHours)
 * @method ?string getNotes()
 * @method void setNotes(?string $notes)
 * @method ?string getSourceRef()
 * @method void setSourceRef(?string $sourceRef)
 * @method ?string getColour()
 * @method void setColour(?string $colour)
 * @method ?int getSectorId()
 * @method void setSectorId(?int $sectorId)
 * @method ?int getUnidadVecinalId()
 * @method void setUnidadVecinalId(?int $unidadVecinalId)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method bool getRetired()
 * @method void setRetired(bool $retired)
 * @method ?int getDatasetId()
 * @method void setDatasetId(?int $datasetId)
 * @method ?string getExternalId()
 * @method void setExternalId(?string $externalId)
 * @method ?string getOfficial()
 * @method void setOfficial(?string $official)
 * @method bool getOverridden()
 * @method void setOverridden(bool $overridden)
 * @method ?int getMergedInto()
 * @method void setMergedInto(?int $mergedInto)
 * @method ?string getBoundaryLimit()
 * @method void setBoundaryLimit(?string $boundaryLimit)
 */
class Feature extends Entity {
	/**
	 * The comparable keys whose name is not their property name.
	 *
	 * `comparable()` is keyed as the client knows a Feature; `getUpdatedFields()`
	 * is keyed by property. Every key coincides except this one — the column is
	 * `boundary_limit` because `limit` is reserved — so anything gating one on the
	 * other silently skips it. That is what kept a Sector's Limit out of its own
	 * history: `isset($writing['limit'])` could never be true.
	 *
	 * On the entity because this is where the divergence is created, and the next
	 * column needing a different client name adds a line.
	 *
	 * @var array<string, string> comparable key => entity property
	 */
	public const PROPERTY_OF = ['limit' => 'boundaryLimit'];

	protected $subcategoryId = 0;
	protected $kind = '';
	protected $name = '';
	protected $geometry = null;
	protected $minLat = null;
	protected $minLon = null;
	protected $maxLat = null;
	protected $maxLon = null;
	protected $address = null;
	protected $phone = null;
	protected $email = null;
	protected $contactPerson = null;
	protected $contactRole = null;
	protected $locationQuality = null;
	protected $openingHours = null;
	protected $notes = null;
	protected $sourceRef = null;
	// A Sector's own colour, because a clinic tells its sectores apart by colour
	// and they all share one Subcategory. Null everywhere else: the record then
	// takes its Category's colour, so nothing has to be coloured by hand.
	protected $colour = null;
	// Derived, never typed (ADR-0008). Null is a real answer: no coordinate, or a
	// coordinate outside every boundary this install holds.
	protected $sectorId = null;
	protected $unidadVecinalId = null;
	protected $revision = 0;
	protected $retired = false;
	protected $datasetId = null;
	protected $externalId = null;
	protected $official = null;
	protected $overridden = false;
	// Where a record went when somebody decided it and another were the same
	// place (issue #16). Null everywhere else. This is the mapping a clinic used
	// to keep in a spreadsheet beside the cadastre, and the question it answers —
	// "we retired this one, which record replaced it?" — is asked of the retired
	// record, so it lives here rather than on the pair.
	protected $mergedInto = null;
	// What a Sector runs along, in words (issue #15). JSON, like `geometry`
	// beside it. Null on everything that is not a Sector, and legitimately empty
	// on a Sector somebody drew by hand — ADR-0007 keeps that just as valid.
	protected $boundaryLimit = null;

	public function __construct() {
		$this->addType('subcategoryId', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('retired', 'boolean');
		$this->addType('datasetId', 'integer');
		$this->addType('overridden', 'boolean');
		$this->addType('sectorId', 'integer');
		$this->addType('unidadVecinalId', 'integer');
		$this->addType('minLat', 'float');
		$this->addType('minLon', 'float');
		$this->addType('maxLat', 'float');
		$this->addType('maxLon', 'float');
		$this->addType('mergedInto', 'integer');
	}

	/**
	 * The four bounding-box columns move together or not at all — a Feature
	 * carrying three quarters of a box, or a box belonging to a shape it no
	 * longer has, is the kind of state that hides records from the map.
	 */
	public function setBoundingBox(?BoundingBox $box): void {
		$this->setMinLat($box?->minLat());
		$this->setMinLon($box?->minLon());
		$this->setMaxLat($box?->maxLat());
		$this->setMaxLon($box?->maxLon());
	}

	/**
	 * The box this record's geometry spans, or null where it has none.
	 *
	 * The columns are already here, so nothing re-parses the geometry to find out
	 * — and the bounding-box test that stands in front of point-in-polygon
	 * (ADR-0008) is the same one `BoundingBox` already implements.
	 */
	public function boundingBox(): ?BoundingBox {
		if ($this->getMinLat() === null) {
			return null;
		}

		return BoundingBox::around([
			[$this->getMinLon(), $this->getMinLat()],
			[$this->getMaxLon(), $this->getMaxLat()],
		]);
	}

	/**
	 * What the browser gets. The Subcategory is sent as an id, not expanded: the
	 * client already holds the whole taxonomy for the Capas panel, so expanding
	 * it here would repeat the same label on every row for nothing.
	 *
	 * @return array<string, mixed>
	 */
	public function toClient(): array {
		return [
			'id' => (int)$this->getId(),
			'subcategoryId' => $this->getSubcategoryId(),
			'kind' => $this->getKind(),
			'name' => $this->getName(),
			'geometry' => $this->getGeometry() === null ? null : json_decode($this->getGeometry(), true),
			'address' => $this->getAddress(),
			'phone' => $this->getPhone(),
			'email' => $this->getEmail(),
			'contactPerson' => $this->getContactPerson(),
			'contactRole' => $this->getContactRole(),
			'locationQuality' => $this->getLocationQuality(),
			'openingHours' => $this->getOpeningHours(),
			'notes' => $this->getNotes(),
			'sourceRef' => $this->getSourceRef(),
			'colour' => $this->getColour(),
			// The client filters and counts on these rather than asking the geometry
			// again — which is the whole reason they are stored (ADR-0008).
			'sectorId' => $this->getSectorId(),
			'unidadVecinalId' => $this->getUnidadVecinalId(),
			'revision' => $this->getRevision(),
			// Ships even though retired records are absent from every default
			// read: the change feed deliberately carries them, so a client learns
			// to stop drawing one it already has (ADR-0005).
			'retired' => (bool)$this->getRetired(),
			'datasetId' => $this->getDatasetId(),
			'externalId' => $this->getExternalId(),
			// What the authority last published, where it differs from what is
			// shown. ADR-0003 keeps a local edit AND the official value, so the
			// two stay comparable and the override can be undone.
			'overridden' => (bool)$this->getOverridden(),
			// Ships for the same reason `retired` does: a client holding the losing
			// record needs to be able to say where it went rather than just drop it.
			'mergedInto' => $this->getMergedInto(),
			// Decoded here rather than on the client: it is a list of parts, and
			// every reader of it wants the list.
			'limit' => $this->getBoundaryLimit() === null
				? null
				: json_decode($this->getBoundaryLimit(), true),
			'official' => $this->getOfficial() === null
				? null
				: json_decode($this->getOfficial(), true),
		];
	}

	/**
	 * The fields history compares, keyed as the client knows them.
	 *
	 * Derived values are absent on purpose — the bounding box, the Kind and the
	 * Revision all follow from something else here, and recording "kind: place →
	 * place" on every save would bury the edit a person actually made.
	 *
	 * @return array<string, mixed>
	 */
	public function comparable(): array {
		return [
			'name' => $this->getName(),
			'subcategoryId' => $this->getSubcategoryId(),
			'geometry' => $this->getGeometry(),
			'address' => $this->getAddress(),
			'phone' => $this->getPhone(),
			'email' => $this->getEmail(),
			'contactPerson' => $this->getContactPerson(),
			'contactRole' => $this->getContactRole(),
			'locationQuality' => $this->getLocationQuality(),
			'openingHours' => $this->getOpeningHours(),
			'notes' => $this->getNotes(),
			'sourceRef' => $this->getSourceRef(),
			'colour' => $this->getColour(),
			// In history on purpose: changing what a Sector runs along is a decision
			// about the territory, and "quién cambió el límite" is exactly the kind
			// of question history exists to answer (issue #15).
			'limit' => $this->getBoundaryLimit(),
			// In history on purpose. ADR-0008: "Redrawing a Sector reassigns every
			// Place inside it. That is the point, and it must be visible in history
			// like any other change."
			'sectorId' => $this->getSectorId(),
			'unidadVecinalId' => $this->getUnidadVecinalId(),
		];
	}

	/**
	 * The single coordinate a record is assigned by, or null where it has none.
	 *
	 * Only a Place has one. A Sector is not "in" a Sector, and asking which
	 * Unidad Vecinal a route running across four of them belongs to has no
	 * answer worth storing.
	 *
	 * THE ANCHOR RULE, stated once (L1-20): "A Place's bounding box IS its
	 * coordinate — min and max are the same point — so this needs no parsing."
	 * Keyed off the box, not the geometry: `setBoundingBox()` is what nulls
	 * those columns, and the two are written and cleared together. Formerly
	 * AssignmentService::anchorOf(), duplicated in DuplicateService's comment.
	 *
	 * @return ?array{float, float} [longitude, latitude]
	 */
	public function anchor(): ?array {
		if ($this->getKind() !== Kind::Place->value || $this->getMinLon() === null) {
			return null;
		}

		return [(float)$this->getMinLon(), (float)$this->getMinLat()];
	}
}
