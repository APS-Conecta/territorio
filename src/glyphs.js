/**
 * One pictogram per Category, from Maki (CC0-1.0).
 *
 * Hue groups Categories; the glyph identifies them (ADR-0013). That is not a
 * preference: `2·C·sin(Δh/2)` says eleven hues separated by ΔE 0.10 need a
 * chroma louder than the palette this replaced, so the ramp carries six hue
 * families and the glyph carries the identity. A dichromacy touches the hue and
 * never the shape, which is the whole point.
 *
 * The seeded eleven are keyed by Category **slug**, which is the stable
 * identifier — a clinic may rename a label, and ids differ per install (see
 * taxonomy.js). A Category the clinic added has no seeded mark and chooses one
 * from `CATALOGUE` instead (#46); that choice is stored and OVERRIDES this map,
 * so an install where nobody has chosen anything still draws exactly what #37
 * re-picked.
 *
 * All eleven were re-picked at pin size for #37, against the vocabulary a
 * person already has from a phone map, and judged as rendered pixels at 19px
 * rather than as vector art at a desk — which is how the first pass got
 * `school` (a pencil crossed with an apple) past a review it could not survive
 * on a busy basemap. `college` is the cap every map draws education with,
 * `park` is the tree every map draws green space with, and `town` says
 * *neighbourhood* where `residential-community` said *one apartment block*.
 *
 * Three of these are chosen rather than obvious. `place-of-worship` over
 * `religious-christian`, because the app must not assume a denomination.
 * `circle-stroked` for "Sin clasificar", because it has to read as *nobody has
 * decided yet* — which is a real seeded Category — and not as an error.
 * `pitch` over `soccer` for deporte, because the list and Capas draw the same
 * mark at 12px and 11px, where `soccer`'s ball detaches into a stray speck and
 * `pitch` stays one figure.
 *
 * `police` is the weakest of the eleven and stayed anyway: Maki has no shield
 * or badge, and every alternative in the set means something narrower (a flame
 * is bomberos, a warning triangle is danger) or nothing at all. Flagged for the
 * owner in #37 rather than quietly swapped for a worse-but-crisper mark.
 *
 * Maki icons are a single `<path>` with no `fill` attribute on a
 * `viewBox="0 0 15 15"`, so they take their colour from CSS wherever they land.
 */
import { layouts, svgArray } from '@mapbox/maki/browser.esm.js'

/**
 * Every mark this app can draw, by Maki name.
 *
 * Read out of the package's own browser bundle, which ships the names and the
 * markup as two parallel arrays. Not `require.context()` over `icons/`, and not
 * a hand-written list of imports: the context helper is webpack's alone, so the
 * catalogue would be empty under vitest and every assertion about a chosen mark
 * would pass while proving nothing — and a hand-written list is a second copy of
 * an inventory Maki owns.
 *
 * `@mapbox/maki/browser.esm.js` and not `@mapbox/maki`: the package's `main` is
 * a Node script that globs the filesystem, which is what a bare specifier
 * resolves to outside a browser target, and it throws on import under vitest.
 *
 * This replaced eleven `.svg` imports resolved by a webpack rule of their own.
 * The markup is the same bytes — Maki's build writes the files and this bundle
 * from one source — so `webpack.config.js` no longer needs to know about this
 * package at all.
 *
 * A Map and not an object literal, because the key comes from a database column.
 * `constructor`, `toString` and `valueOf` are shaped exactly like Maki names —
 * lower case letters and nothing else — so the server's shape check stores them
 * and an object would answer all three with a function off `Object.prototype`.
 * Truthy, so the fallbacks below would be skipped, and the pin's markup is an
 * HTML string interpolated unescaped: it would read «function Object() { [native
 * code] }» where the pictogram belongs.
 */
const MARKS = new Map(layouts.map((name, index) => [name, svgArray[index]]))

/** Every mark's name, in Maki's own order — what the picker offers (#46). */
export const CATALOGUE = layouts

/** The mark each seeded Category is drawn with, until somebody chooses another. */
const GLYPHS = {
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
 * The glyph a Category is drawn with: the one it was given, then the one its
 * slug ships with, then the neutral.
 *
 * Falls back rather than returning nothing, for three cases that are all real: a
 * Category a clinic added and has not chosen a mark for; a Feature pointing at a
 * Subcategory the taxonomy no longer has, which `colourOf` already handles the
 * same way; and a stored name this app cannot draw — the column is a VARCHAR and
 * a restored dump or a retired Maki icon can put one there. A blank dot is worse
 * than a neutral one — it reads as a rendering failure rather than as
 * "unclassified", and the row beside it already says which it is (ADR-0017).
 *
 * @param {?string} categorySlug the Category's slug, from indexSubcategories()
 * @param {?string} chosen the Maki name stored against the Category, if any
 * @return {string} SVG markup, never empty
 */
export function glyphFor(categorySlug, chosen = null) {
	return MARKS.get(chosen) ?? MARKS.get(GLYPHS[categorySlug]) ?? MARKS.get(GLYPHS.sin_clasificar)
}

/** Every Category slug that has a glyph of its own. Read by the tests. */
export const GLYPH_SLUGS = Object.keys(GLYPHS)
