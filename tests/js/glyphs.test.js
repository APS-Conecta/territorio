import { describe, expect, it } from 'vitest'
import { GLYPH_SLUGS, glyphFor } from '../../src/glyphs.js'

/**
 * The Category → pictogram mapping (issue #20, ADR-0013).
 *
 * This pins the MAPPING, not the markup. vitest resolves an `.svg` import the
 * way Vite does and webpack resolves it the way `webpack.config.js` says, so
 * what these imports evaluate to differs between here and the browser — and
 * asserting on it here would be asserting on the test runner's asset handling.
 * That the glyph is real SVG, drawn white, and legible is the browser gate's
 * job, which is where it can actually be seen.
 *
 * What matters here is that every Category resolves to something and that
 * nothing resolves to nothing.
 */

/** The eleven the seed ships — EnsureSeedData::TAXONOMY. */
const SEEDED = [
	'salud', 'educacion', 'deporte_recreacion', 'seguridad', 'medioambiente',
	'organizacion_social', 'servicio_publico', 'movilidad_transporte',
	'religion', 'comercio_economia', 'sin_clasificar',
]

/**
 * The Maki icon each Category draws, re-picked at pin size for #37.
 *
 * Named here rather than left implicit because the choice is the decision: #37
 * re-picked all eleven against the vocabulary a phone map uses, and a silent
 * swap afterwards would undo that without anything saying so.
 */
const CHOSEN = {
	salud: 'hospital',
	educacion: 'college',
	deporte_recreacion: 'pitch',
	seguridad: 'police',
	medioambiente: 'park',
	organizacion_social: 'town',
	servicio_publico: 'town-hall',
	movilidad_transporte: 'bus',
	religion: 'place-of-worship',
	comercio_economia: 'shop',
	sin_clasificar: 'circle-stroked',
}

/**
 * The Maki icon name out of whatever the bundler handed us.
 *
 * Maki writes the icon's own name into the `<svg>`'s `id` — the first `id` in
 * the file — and both handlings carry it: webpack's `asset/source` as raw markup
 * with double quotes, vitest's as a URL-encoded data URI with single ones and
 * the angle brackets escaped, which is why nothing here anchors on a `<svg`
 * that is only literal in one of them. That is the whole reason this can
 * assert a name without keeping a second copy of the mapping to drift against
 * the first — and it is still the mapping being asserted, not the markup.
 *
 * @param {string} glyph whatever `glyphFor()` returned
 * @return {?string} the Maki icon name, or null if this file's premise broke
 */
function makiIdOf(glyph) {
	return String(glyph).match(/id=['"]([a-z0-9-]+)['"]/)?.[1] ?? null
}

describe('glyphFor', () => {
	it('gives every seeded Category a glyph', () => {
		for (const slug of SEEDED) {
			expect(glyphFor(slug), `${slug} has no glyph`).toBeTruthy()
		}
	})

	it('covers exactly the seeded Categories and no more', () => {
		// A glyph for a slug the seed does not ship is dead weight; a seeded slug
		// with no glyph falls back silently and looks unclassified on the map.
		expect([...GLYPH_SLUGS].sort()).toEqual([...SEEDED].sort())
	})

	it('gives each Category a glyph of its own', () => {
		// Two Categories sharing a pictogram would put the identification back on
		// hue, which is the thing ADR-0013 says hue cannot carry.
		const glyphs = SEEDED.map(glyphFor)

		expect(new Set(glyphs).size).toBe(SEEDED.length)
	})

	it('draws each Category with the icon #37 re-picked for it', () => {
		for (const [slug, icon] of Object.entries(CHOSEN)) {
			const drawn = makiIdOf(glyphFor(slug))

			// Before comparing: a null here means the id could not be read at all,
			// and every comparison below it would be vacuously about `null`.
			expect(drawn, `${slug}: no Maki id could be read out of its glyph`).not.toBeNull()
			expect(drawn, `${slug} draws a different pictogram`).toBe(icon)
		}
	})

	/**
	 * A Category a clinic added, and a Feature pointing at a Subcategory that no
	 * longer exists, both arrive here. Neither may render blank: an empty dot
	 * reads as a rendering failure, and the row beside it already says which case
	 * it is.
	 */
	it('falls back rather than rendering nothing', () => {
		for (const unknown of ['una_categoria_nueva', '', null, undefined]) {
			expect(glyphFor(unknown), `${unknown} rendered nothing`).toBeTruthy()
		}

		expect(glyphFor('una_categoria_nueva')).toBe(glyphFor('sin_clasificar'))
	})
})
