/**
 * What a mark on the map says beyond where it is: which Categories are under a
 * cluster, and whether the app is asserting the coordinate precisely.
 *
 * Kept out of `TerritorioMap.vue` because nothing in the js suite loads a
 * component — the same reason `taxonomy.js` and `glyphs.js` are modules. The
 * markup lives here now (L1-32), beside the ring logic that shares its colours.
 */
import { PIN_DOT_SIZE, PIN_GLYPH_SIZE } from './marks.js'
import { UNKNOWN_COLOUR } from './taxonomy.js'

/**
 * Escaped, because the builders interpolate stored colour hexes — a colour is
 * something a person typed into a picker, and these are HTML strings, not DOM
 * nodes.
 */
const escapeHtml = (value) => String(value).replace(
	/[&<>"']/g,
	(character) => ({
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&#39;',
	}[character]),
)

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

/**
 * A record's pin, as pure markup + a size (L1-32).
 *
 * What the pin actually says. Hue only groups Categories into six families;
 * the glyph is what tells a colegio from a farmacia, and it is the half a
 * dichromacy cannot take away (ADR-0013).
 *
 * The glyph is interpolated unescaped, unlike the colour beside it. What is
 * STORED since #46 is the mark's NAME, not its markup: `glyphFor()` looks that
 * name up in the vendored catalogue and answers a bundled asset or the neutral,
 * so nothing that arrived from the database reaches this string. The colour is
 * a hex a person typed into a picker and is written out as it was stored, which
 * is why that one goes through escapeHtml.
 *
 * The ring the pin already wears goes dashed when the Registry only knows the
 * coordinate approximately (#23). A modifier and a `border-style`, because the
 * ring is already there and costs no footprint — which is what settled it
 * against a halo and an edge badge, with #37 simultaneously arguing the pin is
 * too small and La Florida holding ~865 places. Dashed means one thing across
 * this app: the app is not asserting this precisely (ADR-0015).
 *
 * The geometry travels as custom properties rather than as a second copy of
 * the numbers in the stylesheet: `iconSize` is JS and the dot's width is CSS,
 * so 26 used to be written twice in the one component — and #46's picker
 * previews at the mark size, which would have made a third copy in a third
 * file (marks.js).
 *
 * @param {{colour: string, glyph: string, selected: boolean, approximate: boolean}} pin
 * @return {{html: string, size: number}}
 */
export function pinIcon({ colour, glyph, selected, approximate }) {
	const classes = ['territorio-pin__dot']
	if (selected) {
		classes.push('territorio-pin__dot--selected')
	}
	if (approximate) {
		classes.push('territorio-pin__dot--approximate')
	}

	return {
		html: `<span class="${classes.join(' ')}"`
			+ ` style="--territorio-pin:${PIN_DOT_SIZE}px;`
			+ `--territorio-pin-mark:${PIN_GLYPH_SIZE}px;`
			+ `background:${escapeHtml(colour)}">${glyph}</span>`,
		size: PIN_DOT_SIZE,
	}
}

/**
 * A cluster bubble, as pure markup + a size (L1-32).
 *
 * What a cluster bubble says: the Categories under it, and the count.
 *
 * Not the size. markercluster's own icon buckets on `getChildCount()` into
 * three hues, so green meant «Deporte y recreación» at one zoom and "more than
 * 100 records" at the next — one channel carrying two unrelated meanings, on
 * a map where hue is the channel that groups Categories (ADR-0013). The count
 * is written in the middle in the theme's own ink, which is where it always
 * was and the reason hue never needed to carry it.
 *
 * A ring segmented by Category rather than the majority glyph: a majority
 * glyph cannot tell a cluster of one Category from a mixed one, which is the
 * criterion #22 is judged on. `@kalisio/leaflet.donutcluster` does this in 459
 * lines and an undeclared `window._`; this is the hook markercluster already
 * offered.
 *
 * The colours are escaped for the same reason the pin's is: a Category colour
 * is something a person typed into a picker, and this is an HTML string, not a
 * DOM node.
 *
 * @param {Array<{colour: string, approximate: boolean}>} members
 * @param {number} count the cluster's child count
 * @return {{html: string}}
 */
export function clusterIcon(members, count) {
	const { stops, approximate } = clusterRing(members)
	const ring = stops
		.map((stop) => `${escapeHtml(stop.colour)} ${stop.from}% ${stop.to}%`)
		.join(', ')

	return {
		html: `<span class="territorio-cluster__ring${approximate ? ' territorio-cluster__ring--approximate' : ''}"`
			+ ` style="background-image:conic-gradient(${ring})">`
			+ `<span class="territorio-cluster__count">${count}</span>`
			+ '</span>',
	}
}
