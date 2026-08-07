<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One contact-bearing export, kept forever (ADR-0006) — see `CONTEXT.md`.
 *
 * Named `ExportRecord` rather than `Export` because `export` is a reserved word
 * in enough of the toolchain to be a nuisance, and because what this holds is
 * the record OF an export rather than the export itself, which left the building
 * the moment it was made.
 *
 * @method string getActor()
 * @method void setActor(string $actor)
 * @method \DateTime getExportedAt()
 * @method void setExportedAt(\DateTime $exportedAt)
 * @method string getFormat()
 * @method void setFormat(string $format)
 * @method int getRecords()
 * @method void setRecords(int $records)
 * @method bool getContacts()
 * @method void setContacts(bool $contacts)
 */
class ExportRecord extends Entity {
	protected $actor = '';
	protected $exportedAt = null;
	protected $format = '';
	protected $records = 0;
	protected $contacts = true;

	public function __construct() {
		$this->addType('exportedAt', 'datetime');
		$this->addType('records', 'integer');
		$this->addType('contacts', 'boolean');
	}

	/** @return array<string, mixed> */
	public function toClient(): array {
		return [
			'id' => (int)$this->getId(),
			'actor' => $this->getActor(),
			'exportedAt' => $this->getExportedAt()?->format(\DateTimeInterface::ATOM),
			'format' => $this->getFormat(),
			'records' => $this->getRecords(),
			'contacts' => (bool)$this->getContacts(),
		];
	}
}
