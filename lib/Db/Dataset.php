<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One named collection inside the Registry whose records share an origin — see
 * `CONTEXT.md`. An install holds many.
 *
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string getOrigin()
 * @method void setOrigin(string $origin)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Dataset extends Entity {
	protected $slug = '';
	protected $label = '';
	protected $origin = '';
	protected $createdAt = null;

	public function __construct() {
		$this->addType('createdAt', 'datetime');
	}

	/** Only a Cadastral Dataset is re-imported, and only its records can be overridden. */
	public function isCadastral(): bool {
		return $this->getOrigin() === Origin::Cadastral->value;
	}

	/** @return array<string, mixed> */
	public function toClient(): array {
		return [
			'id' => (int)$this->getId(),
			'slug' => $this->getSlug(),
			'label' => $this->getLabel(),
			'origin' => $this->getOrigin(),
		];
	}
}
