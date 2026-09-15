/**
 * Figure and ground: how a territorial division is drawn, and how it is not.
 *
 * CONTEXT.md separates a Cadastral Dataset — "the clinic reads it, never draws
 * it" — from a Curated Dataset, "born inside the clinic". Enormous operational
 * weight and, until #21, zero visual weight: a Unidad Vecinal and a Sector were
 * drawn with the same fill, the same outline and the same hover tooltip,
 * differing only in hue. So a Unidad Vecinal becomes **ground** and a Sector
 * becomes **figure**, and a Sector is identified by a permanent label rather
 * than by its colour — CONTEXT.md says a clinic names its sectores "words,
 * numbers or colours", so a colour-first identity is right for one naming
 * scheme out of three.
 *
 * Here rather than inside TerritorioMap.vue for the reason glyphs.js is not
 * inside it either: the map and the Datos list have to draw the SAME record the
 * same way, and the numbers below are measured values that must be checkable
 * without a browser. Every one of them comes from
 * docs/design/map-legibility.md § 2, computed with tools/ramp-check.py's own
 * functions rather than judged by eye.
 */
import { SECTOR_SLUG, UNIDAD_VECINAL_SLUG } from './taxonomy.js'

/**
 * The Unidad Vecinal hairline. NOT `DEFAULT_SECTOR_COLOUR`, though it used to
 * be the same grey: this one is a one-pixel line over a raster basemap, judged
 * on contrast against the tile (1.35–1.49:1 over OSM land, water and green;
 * 1.87:1 over the dark background — visible, never loud). The Sector default is
 * an 18px dot beside other dots and is judged on separation from the ramp,
 * which is why it moved to `#949494` and this did not follow it.
 */
const GROUND_OUTLINE = '#7d7d7d'

/**
 * The alpha a Sector's colour is washed at, and half of one decision.
 *
 * Composited over OSM land, `#949494` at 0.10 leaves the worst dot-on-tint pair
 * at 3.04:1 — above WCAG 1.4.11's 3:1 floor, but only just. At the 0.15 this
 * shipped, the same pair measures 2.90:1. So the neutral default and this
 * number are one decision and #21 owns both; changing either alone puts a
 * measured contrast below its floor.
 */
const FIGURE_FILL_OPACITY = 0.10

/**
 * The zoom at which a Unidad Vecinal is big enough to carry its name.
 *
 * At latitude -33.5 a pixel is 130557/2^z metres, so a 1.2 km Unidad Vecinal —
 * about the average across a comuna of fifty — spans 150px at zoom 14 and
 * 300px at 15. Fourteen is also where the whole comuna fits on screen, and
 * fifty labels at once is a sheet of text rather than a map. Fifteen shows
 * roughly a quarter of the comuna, which is where somebody is reading UV
 * numbers in the first place.
 *
 * A threshold and not a collision library: 620 Leaflet plugins were swept and
 * the only candidate monkey-patches `L.LayerGroup.prototype`, which is the
 * exact invariant the layer-swapping in TerritorioMap.vue protects
 * (map-legibility § 4).
 */
export const GROUND_LABEL_MIN_ZOOM = 15

/**
 * The Leaflet path style for one shape.
 *
 * @param {?string} slug the record's Subcategory slug, from indexSubcategories()
 * @param {string} colour what it is drawn in — ignored for ground
 * @param {boolean} selected whether it is the open record
 * @return {object} options for Path.setStyle
 */
export function shapeStyle(slug, colour, selected = false) {
	if (slug === UNIDAD_VECINAL_SLUG) {
		return {
			color: GROUND_OUTLINE,
			// Thicker and firmer when open, because a record opened from the list
			// still has to say which one it is. It stays hairline and dashed: a
			// cadastre boundary does not become figure by being looked at.
			weight: selected ? 3 : 1,
			opacity: selected ? 0.9 : 0.45,
			dashArray: '2 3',
			// Leaflet writes `fill="none"`, so the interior stops answering clicks
			// as well as stops being painted — which is the correct behaviour for a
			// boundary that tiles the whole view: a click meant for the map beneath
			// it now reaches the map.
			fill: false,
		}
	}

	return {
		color: colour,
		weight: selected ? 4 : 2,
		opacity: 1,
		fillColor: colour,
		fillOpacity: FIGURE_FILL_OPACITY,
		dashArray: selected ? '6 4' : null,
	}
}

/**
 * How a shape's name is bound: permanently at the centroid for a division, on
 * hover for everything else.
 *
 * `direction: 'center'` is what makes Leaflet 1.9 fall through to
 * `Polygon.getCenter()` → `PolyUtil.polygonCenter()`, a true area-weighted
 * centroid. No plugin, no geometry of our own.
 *
 * @param {?string} slug the record's Subcategory slug
 * @return {object} options for Layer.bindTooltip
 */
export function labelOptions(slug) {
	if (slug === SECTOR_SLUG) {
		return { permanent: true, direction: 'center', className: 'territorio-label' }
	}

	if (slug === UNIDAD_VECINAL_SLUG) {
		return {
			permanent: true,
			direction: 'center',
			className: 'territorio-label territorio-label--ground',
		}
	}

	return {}
}

/** Whether a Unidad Vecinal's label fits at this zoom. */
export function showsGroundLabels(zoom) {
	return zoom >= GROUND_LABEL_MIN_ZOOM
}

/**
 * The mark the Datos list draws beside a record, and what colour fills it.
 *
 * A territorial division draws a **ring**, never a dot — dashed for a Unidad
 * Vecinal, solid for a Sector — so the list says the same thing the map does.
 * That is issue #29's answer to a swatch measuring 1.73:1 against the dark
 * theme: the division stops being a dot rather than being given a better
 * colour, because ADR-0013 measures the neutral band as having no room for a
 * fourth grey. The stored colour is returned empty rather than repainted, which
 * is what leaves `#334155` in the seed as inert data.
 *
 * @param {?string} slug the record's Subcategory slug
 * @param {string} colour what a dot would be filled with
 * @return {{mark: string, background: string}} the mark and its fill
 */
export function swatchFor(slug, colour) {
	if (slug === UNIDAD_VECINAL_SLUG) {
		return { mark: 'ring-dashed', background: '' }
	}

	if (slug === SECTOR_SLUG) {
		return { mark: 'ring', background: '' }
	}

	return { mark: 'dot', background: colour }
}
