<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The pictogram a clinic chose for a Category (issue #46, ADR-0017).
 *
 * Nullable, and null is the ordinary state rather than an unfinished one: the
 * eleven Categories the seed ships draw the marks #37 re-picked for them, which
 * live in `src/glyphs.js` keyed by slug and are not copied into any row. A value
 * here is an OVERRIDE of that map — what a clinic picked for a Category of its
 * own, or for a seeded one it wanted drawn differently — and its absence means
 * "whatever the app ships", not "nothing to draw". `glyphFor()` falls back to
 * the seeded mark and then to the neutral, so a null never renders a blank.
 *
 * The NAME of a Maki icon, not the markup: the markup is a bundled asset that
 * changes with the vendored package, and storing it would freeze one release's
 * drawing of a mark in the database, where nothing would ever update it.
 * VARCHAR(64) because `place-of-worship` is the longest of the eleven at 16 and
 * the whole Maki inventory stays well inside it — the same width as a slug, for
 * the same reason: it is an identifier nobody types.
 */
class Version001600Date20260915000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$categories = $schema->getTable('territorio_category');
		if (!$categories->hasColumn('glyph')) {
			$categories->addColumn('glyph', Types::STRING, ['notnull' => false, 'length' => 64]);
		}

		return $schema;
	}
}
