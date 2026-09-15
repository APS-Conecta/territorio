import { describe, expect, it } from 'vitest'
import { CATALOGUE, GLYPH_SLUGS, glyphFor } from '../../src/glyphs.js'

/**
 * The Category → pictogram mapping (issue #20, ADR-0013).
 *
 * This pins the MAPPING, not the markup: that every Category resolves to
 * something, that nothing resolves to nothing, and — since #37 — which
 * pictogram each one draws.
 *
 * Which pictogram is read out of the asset's own `id` (see `makiIdOf`)
 * precisely so the two bundlers' asset handling does not matter: vitest
 * resolves an `.svg` import the way Vite does and webpack resolves it the way
 * `webpack.config.js` says, and asserting on the shape of what those return
 * would be asserting on the test runner. That the glyph is real SVG, drawn
 * white, and legible at its size is the browser gate's job, which is where it
 * can actually be seen.
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
const CHOSEN_BY_SLUG = {
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
		// Before iterating CHOSEN_BY_SLUG: drop a Category from it and the loop below
		// would stop pinning that Category's pictogram while every test here
		// stayed green. The test above only proves a glyph exists.
		expect(Object.keys(CHOSEN_BY_SLUG).sort()).toEqual([...SEEDED].sort())

		for (const [slug, icon] of Object.entries(CHOSEN_BY_SLUG)) {
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

/**
 * The mark a clinic chose for a Category of its own (#46, ADR-0017).
 *
 * The seeded eleven keep the mapping above — it is `GLYPHS`, keyed by slug and
 * shipped with the app — and a stored choice is an OVERRIDE of it rather than a
 * replacement for it: an install where nobody has chosen anything must draw
 * exactly what #37 re-picked, and a clinic that renames «Salud» must not have to
 * re-pick a hospital.
 */
describe('a chosen pictogram', () => {
	/** Not one of the eleven, so nothing here can pass by accident. */
	const CHOSEN = 'bakery'

	it('is offered the whole set the app already vendors', () => {
		// "From the set already vendored — no new dependency" is the criterion,
		// and eleven imports is not a catalogue: the picker has to offer marks
		// that mean «comité de vivienda» and «cancha», which no seeded Category
		// needed. Asserted as "more than the eleven, and every one of them in it"
		// rather than as a count, because Maki's own inventory is Maki's to change.
		expect(CATALOGUE.length).toBeGreaterThan(SEEDED.length)
		expect(CATALOGUE).toContain(CHOSEN)
		for (const icon of Object.values(CHOSEN_BY_SLUG)) {
			expect(CATALOGUE, `${icon} is drawn by a Category but is not offered`).toContain(icon)
		}
	})

	it('is drawn instead of the one the slug would have given', () => {
		// The whole of the feature: a Category the clinic added draws the mark
		// somebody picked for it, and so does a seeded one that was re-pointed.
		expect(makiIdOf(glyphFor('salud', CHOSEN))).toBe(CHOSEN)
		expect(makiIdOf(glyphFor('un_comite_nuevo', CHOSEN))).toBe(CHOSEN)
	})

	it('leaves a Category nobody has chosen for exactly where it was', () => {
		for (const slug of SEEDED) {
			expect(glyphFor(slug, null), `${slug} moved when nothing was chosen`)
				.toBe(glyphFor(slug))
			expect(glyphFor(slug, '')).toBe(glyphFor(slug))
		}
	})

	/**
	 * `constructor` and `toString` are shaped exactly like Maki names — lower
	 * case letters and nothing else — so the server stores them, and a catalogue
	 * held in an object literal answers both of them with a FUNCTION off
	 * Object.prototype. Truthy, so every `??` fallback below is skipped, and the
	 * pin's markup — an HTML string, interpolated unescaped — becomes
	 * «function Object() { [native code] }» where a pictogram should be.
	 */
	it('answers nothing for a name that is only on Object.prototype', () => {
		for (const inherited of ['constructor', 'toString', 'valueOf']) {
			expect(glyphFor('salud', inherited), `${inherited} reached the catalogue`)
				.toBe(glyphFor('salud'))
		}
	})

	it('falls back to the neutral when the stored name is not a mark this app has', () => {
		// The column is a VARCHAR and the app is not the only thing that can
		// write to it — a restored dump, a hand-run UPDATE, a Maki release that
		// retires an icon. None of those may render a blank, which is the case
		// ADR-0017 says must read as «unclassified» instead.
		expect(glyphFor('un_comite_nuevo', 'no-such-maki-icon')).toBe(glyphFor('sin_clasificar'))
		expect(glyphFor('salud', 'no-such-maki-icon')).toBe(glyphFor('salud'))
	})
})
