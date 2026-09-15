/**
 * What a mark on the map says beyond where it is: which Categories are under a
 * cluster, and whether the app is asserting the coordinate precisely.
 *
 * Kept out of `TerritorioMap.vue` because nothing in the js suite loads a
 * component — the same reason `taxonomy.js` and `glyphs.js` are modules. The
 * markup stays in the component, beside the pin markup it has to match.
 */
import { UNKNOWN_COLOUR } from './taxonomy.js'

/**
 * The stored Exactitud that means «roughly here» — `LocationQuality::Approximate`.
 *
 * `sin_coordenada` deliberately has no mark: a record with no coordinate is not
 * drawn at all, and the Datos list already says «· sin coordenada» (#23).
 */
const APPROXIMATE = 'aproximada'

/**
 * Whether a Feature's coordinate is only approximate, and so draws a dashed ring.
 *
 * One home for the comparison because this is where a rename goes wrong: the
 * literal survives a renamed enum case, nothing throws, and the treatment simply
 * stops appearing. `tests/js/pins.test.js` reads the value out of
 * `LocationQuality.php` rather than keeping a second copy of it here.
 *
 * @param {object} feature a Feature as the client holds it
 * @return {boolean}
 */
export function isApproximate(feature) {
	return feature.locationQuality === APPROXIMATE
}

/**
 * A cluster's ring: one segment per Category under it, sized by how many
 * records that Category holds, plus whether any of them is approximate.
 *
 * This is the whole of #22. `leaflet.markercluster` buckets on
 * `getChildCount()` alone — green under 10, yellow under 100, orange at 100+ —
 * so green means «Deporte y recreación» at one zoom and "more than 100 records"
 * at the next, on the same map. The count is already written inside the bubble,
 * so hue was duplicating it badly at the cost of the one channel that carries
 * Category (ADR-0013).
 *
 * Segments are ordered by size and then by hex, never by the order the markers
 * arrived in: `drawMarkers` rebuilds the whole group on every poll, and a ring
 * that followed marker order would spin on screen for no reason a person could
 * name.
 *
 * Percentages, not degrees, because `conic-gradient()` takes either and the
 * count of records is what they are a proportion of. The last stop is pinned to
 * 100 rather than computed: thirds do not close, and a gradient ending at
 * 99.99% leaves a hairline of nothing where the turn comes round.
 *
 * @param {Array<{colour: string, approximate: boolean}>} members what
 *   `getAllChildMarkers()` is carrying, as `drawMarkers` hung it on each marker
 * @return {{stops: Array<{colour: string, from: number, to: number}>, approximate: boolean}}
 */
export function clusterRing(members) {
	const counts = new Map()
	members.forEach(({ colour }) => counts.set(colour, (counts.get(colour) ?? 0) + 1))

	const ordered = [...counts].sort(
		([leftColour, left], [rightColour, right]) => right - left || leftColour.localeCompare(rightColour),
	)

	const total = members.length
	const percent = (count) => Math.round((count / total) * 10_000) / 100
	let done = 0

	const stops = ordered.map(([colour, count], index) => {
		const from = percent(done)
		done += count

		return { colour, from, to: index === ordered.length - 1 ? 100 : percent(done) }
	})

	return {
		// markercluster has no childless cluster, but an empty stop list is an
		// invalid `conic-gradient()`, and CSS drops an invalid value in silence:
		// a transparent bubble with a number floating in it, and nothing to say why.
		stops: stops.length > 0 ? stops : [{ colour: UNKNOWN_COLOUR, from: 0, to: 100 }],
		approximate: members.some((member) => member.approximate),
	}
}
