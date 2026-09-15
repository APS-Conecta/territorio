<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The top level of the taxonomy — see `CONTEXT.md`. Editable data, not code: a
 * clinic adds a row and it appears in the tree people toggle, and in reports.
 *
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string getColor()
 * @method void setColor(string $color)
 * @method ?string getGlyph()
 * @method void setGlyph(?string $glyph)
 */
class Category extends Entity {
	protected $slug = '';
	protected $label = '';
	protected $color = '';

	/**
	 * The Maki name the clinic picked, or null for the mark the app ships
	 * (#46, ADR-0017). Null and not '': the column is nullable, and an empty
	 * string would be a second way to say the same thing that `glyphFor()` would
	 * then have to know about.
	 */
	protected $glyph = null;
}
