<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The top level of the taxonomy — see `CONTEXT.md`. Editable data, not code: a
 * clinic adds a row and it appears in the Capas panel and in reports.
 *
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string getColor()
 * @method void setColor(string $color)
 */
class Category extends Entity {
	protected $slug = '';
	protected $label = '';
	protected $color = '';
}
