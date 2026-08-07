<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use OCA\Territorio\Service\BoundingBox;
use OCP\AppFramework\Db\Entity;

/**
 * One record in the Registry that has a geometry — see `CONTEXT.md`.
 *
 * Sector and Unidad Vecinal are absent by design: they are derived from
 * geometry, not stored as editable fields (ADR-0008).
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
 */
class Feature extends Entity {
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
	protected $revision = 0;
	protected $retired = false;
	protected $datasetId = null;
	protected $externalId = null;
	protected $official = null;
	protected $overridden = false;

	public function __construct() {
		$this->addType('subcategoryId', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('retired', 'boolean');
		$this->addType('datasetId', 'integer');
		$this->addType('overridden', 'boolean');
		$this->addType('minLat', 'float');
		$this->addType('minLon', 'float');
		$this->addType('maxLat', 'float');
		$this->addType('maxLon', 'float');
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
		];
	}
}
