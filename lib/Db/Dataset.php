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
 * @method bool getBoundary()
 * @method void setBoundary(bool $boundary)
 */
class Dataset extends Entity {
	protected $slug = '';
	protected $label = '';
	protected $origin = '';
	protected $createdAt = null;
	/**
	 * Whether this Dataset holds Boundary features — the lines a Sector's Limit
	 * can follow (CONTEXT.md).
	 *
	 * It is a property of the Dataset and not of each record because that is the
	 * scale the decision is made at: a comuna's streets arrive as one file, are
	 * eleven thousand lines nobody browses one by one, and are wanted only while
	 * a Sector is being edited. Marking the collection keeps every ordinary read
	 * one condition away from ignoring the whole lot.
	 */
	protected $boundary = false;

	public function __construct() {
		$this->addType('createdAt', 'datetime');
		$this->addType('boundary', 'boolean');
	}

}
