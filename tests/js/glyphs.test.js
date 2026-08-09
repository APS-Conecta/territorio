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
