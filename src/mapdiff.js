/**
 * What the map's diff-apply redraw decides by: which drawn records changed,
 * so a redraw touches those alone.
 *
 * Kept out of `TerritorioMap.vue` for the reason `pins.js` and `taxonomy.js`
 * are: nothing in the js suite loads a component, so the decidable half of the
 * redraw lives where a test can reach it. The component keeps the Leaflet
 * half — the groups, the builders, the registries.
 *
 * A signature is a string over exactly the inputs that reach the drawn layer
 * and nothing else. Records are replaced wholesale by the poll (`mergeInto`
 * builds new arrays of new objects), so anything about record identity would
 * diff every record on every delivery — the opposite of what the diff is for.
 * The pin is decided by its coordinate, colour, glyph, selection and
 * exactitude; the shape by its geometry, filing (the slug drives both the
 * style and the label), colour, selection and name. The glyph is signed by
 * what `glyphFor` answers rather than by its lookup keys, because the two
 * differ in a real window: a chosen mark this page has not loaded yet draws
 * the neutral until `loadCatalogue()` resolves, and the next redraw must see
 * that as a change.
 *
 * The redraw this serves used to swap the whole group per pass — a workaround
 * from when Leaflet handles could still go reactive (see TerritorioMap's
 * created()); the handles are non-reactive by construction now, and removing
 * from the live group is safe. What the signatures add is the other half:
 * a five-second poll that re-delivers an unchanged comuna rebuilds nothing.
 */

import { glyphFor } from './glyphs.js'
import { isApproximate } from './pins.js'
import { colourOf } from './taxonomy.js'

/**
 * Everything a pin is drawn from, as one comparable string.
 *
 * The exact inputs of the component's `iconFor` plus the coordinate and the
 * cluster payload: if none of these moved, the marker on the map is already
 * the truth and the diff must leave it standing.
 *
 * @param {object} feature a Feature as the client holds it
 * @param {{selectedId: ?number, subcategories: Map}} context the map's selection and taxonomy index
 * @return {string}
 */
export function pointSignature(feature, { selectedId, subcategories }) {
	const category = subcategories.get(feature.subcategoryId)

	return JSON.stringify([
		feature.geometry.coordinates,
		colourOf(feature, subcategories),
		glyphFor(category?.categorySlug, category?.categoryGlyph),
		feature.id === selectedId,
		isApproximate(feature),
	])
}

/**
 * Everything a shape is drawn from, as one comparable string: the geometry,
 * the Subcategory slug (which chooses the style branch and the label
 * treatment), the colour, the selection outline, and the name the tooltip
 * carries.
 *
 * @param {object} feature a Feature as the client holds it
 * @param {{selectedId: ?number, subcategories: Map}} context the map's selection and taxonomy index
 * @return {string}
 */
export function shapeSignature(feature, { selectedId, subcategories }) {
	return JSON.stringify([
		feature.geometry,
		subcategories.get(feature.subcategoryId)?.slug ?? null,
		colourOf(feature, subcategories),
		feature.id === selectedId,
		feature.name,
	])
}

/**
 * Partition records into what a redraw owes the groups, by id alone.
 *
 * Signatures decide, ids partition: every id in `next` lands in `added` (no
 * previous row) or `changed` (previous row, different signature) or neither;
 * every previous id `next` no longer names lands in `removed`. An unchanged
 * id appears nowhere — that absence is the whole point. `added`/`changed`
 * answer the entries of `next` themselves, `removed` answers bare ids.
 *
 * @param {Map} previous id → the signature last drawn
 * @param {Array<{id, signature}>} next what this pass wants on the map
 * @return {{added: Array, changed: Array, removed: Array}}
 */
export function diffLayers(previous, next) {
	const added = []
	const changed = []
	const seen = new Set()

	next.forEach((entry) => {
		seen.add(entry.id)
		if (!previous.has(entry.id)) {
			added.push(entry)
		} else if (previous.get(entry.id) !== entry.signature) {
			changed.push(entry)
		}
	})

	const removed = [...previous.keys()].filter((id) => !seen.has(id))

	return { added, changed, removed }
}
