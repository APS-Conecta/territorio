<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The second level of the taxonomy, and the finest thing a person switches on
 * or off — see `CONTEXT.md`.
 *
 * @method int getCategoryId()
 * @method void setCategoryId(int $categoryId)
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string getLabel()
 * @method void setLabel(string $label)
 */
class Subcategory extends Entity {
	protected $categoryId = 0;
	protected $slug = '';
	protected $label = '';

	public function __construct() {
		$this->addType('categoryId', 'integer');
	}
}
