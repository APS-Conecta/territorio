/**
 * One pictogram per Category, from Maki (CC0-1.0).
 *
 * Hue groups Categories; the glyph identifies them (ADR-0013). That is not a
 * preference: `2·C·sin(Δh/2)` says eleven hues separated by ΔE 0.10 need a
 * chroma louder than the palette this replaced, so the ramp carries six hue
 * families and the glyph carries the identity. A dichromacy touches the hue and
 * never the shape, which is the whole point.
 *
 * Keyed by Category **slug**, which is the stable identifier — a clinic may
 * rename a label, and ids differ per install (see taxonomy.js).
 *
 * Two of these are chosen rather than obvious. `place-of-worship` over
 * `religious-christian`, because the app must not assume a denomination.
 * `circle-stroked` for "Sin clasificar", because it has to read as *nobody has
 * decided yet* — which is a real seeded Category — and not as an error.
 *
 * Maki icons are a single `<path>` with no `fill` attribute on a
 * `viewBox="0 0 15 15"`, so they take their colour from CSS wherever they land.
 * `webpack.config.js` loads this package as `asset/source` rather than the
 * Nextcloud default of `asset/inline`: a data URI cannot be recoloured and
 * cannot be inlined into the Leaflet divIcon's HTML string, and both are needed.
 */
import bus from '@mapbox/maki/icons/bus.svg'
import circleStroked from '@mapbox/maki/icons/circle-stroked.svg'
import hospital from '@mapbox/maki/icons/hospital.svg'
import pitch from '@mapbox/maki/icons/pitch.svg'
import placeOfWorship from '@mapbox/maki/icons/place-of-worship.svg'
import police from '@mapbox/maki/icons/police.svg'
import recycling from '@mapbox/maki/icons/recycling.svg'
import residentialCommunity from '@mapbox/maki/icons/residential-community.svg'
import school from '@mapbox/maki/icons/school.svg'
import shop from '@mapbox/maki/icons/shop.svg'
import townHall from '@mapbox/maki/icons/town-hall.svg'

const GLYPHS = {
	salud: hospital,
	educacion: school,
	deporte_recreacion: pitch,
	seguridad: police,
	medioambiente: recycling,
	organizacion_social: residentialCommunity,
	servicio_publico: townHall,
	movilidad_transporte: bus,
	religion: placeOfWorship,
	comercio_economia: shop,
	sin_clasificar: circleStroked,
}

/**
 * The glyph a Category is drawn with.
 *
 * Falls back rather than returning nothing, for two cases that are both real: a
 * Category a clinic added, which has no glyph and cannot have one until somebody
 * chooses it; and a Feature pointing at a Subcategory the taxonomy no longer
 * has, which `colourOf` already handles the same way. A blank dot is worse than
 * a neutral one — it reads as a rendering failure rather than as "unclassified",
 * and the row beside it already says which it is.
 *
 * @param {?string} categorySlug the Category's slug, from indexSubcategories()
 * @return {string} SVG markup, never empty
 */
export function glyphFor(categorySlug) {
	return GLYPHS[categorySlug] ?? GLYPHS.sin_clasificar
}

/** Every Category slug that has a glyph of its own. Read by the tests. */
export const GLYPH_SLUGS = Object.keys(GLYPHS)
