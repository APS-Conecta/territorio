import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { clusterRing, isApproximate } from '../../src/pins.js'

/**
 * What a cluster bubble and a pin say about the records under them (#22, #23).
 *
 * The arithmetic lives in `src/pins.js` rather than in the component for the
 * reason `taxonomy.js` and `glyphs.js` do: nothing in this suite loads a Vue
 * component, so logic left inside one is logic nothing can assert. What the
 * ring LOOKS like — a 4px band round a count in ink, dashed or solid — is the
 * browser gate's job, which is where it can be seen.
 */

// fileURLToPath, not .pathname: the repo lives under a directory with a space
// in its name, and .pathname hands back the %20 still escaped.
const ROOT = fileURLToPath(new URL('../../', import.meta.url))

const SALUD = '#99564e'
const VERDE = '#407b51'
const REDES = '#0b798a'

/** Members of a cluster, the shape `drawMarkers` hangs on each marker. */
const members = (...colours) => colours.map((colour) => ({ colour, approximate: false }))

describe('clusterRing', () => {
	it('draws one whole ring when every member shares a Category', () => {
		// The criterion a majority glyph fails by construction: a cluster of one
		// Category has to be visually distinct from a mixed one, and one stop
		// covering the whole turn renders as a solid band.
		expect(clusterRing(members(SALUD, SALUD, SALUD)).stops).toEqual([
			{ colour: SALUD, from: 0, to: 100 },
		])
	})

	it('gives each Category a segment in proportion to its members', () => {
		expect(clusterRing(members(SALUD, SALUD, VERDE, REDES)).stops).toEqual([
			{ colour: SALUD, from: 0, to: 50 },
			{ colour: REDES, from: 50, to: 75 },
			{ colour: VERDE, from: 75, to: 100 },
		])
	})

	it('closes the turn exactly, however the thirds fall', () => {
		// A gradient that stops at 99.99% leaves a hairline of nothing, and a
		// third is not representable. The last stop is pinned to 100 rather than
		// computed, so this is the assertion that keeps it pinned.
		const ring = clusterRing(members(SALUD, VERDE, REDES))

		expect(ring.stops.at(-1).to).toBe(100)
		expect(ring.stops.map((stop) => stop.from)).toEqual([0, 33.33, 66.67])
	})

	it('draws the same ring whatever order the markers arrived in', () => {
		// drawMarkers rebuilds the whole cluster group every five seconds, so a
		// ring whose segments followed marker order would spin on the screen for
		// no reason a person could name.
		expect(clusterRing(members(VERDE, SALUD, VERDE, SALUD, REDES)))
			.toEqual(clusterRing(members(SALUD, VERDE, REDES, SALUD, VERDE)))
	})

	it('is approximate when any member is, and not when none is', () => {
		const one = [{ colour: SALUD, approximate: false }, { colour: VERDE, approximate: true }]

		expect(clusterRing(one).approximate).toBe(true)
		expect(clusterRing(members(SALUD, VERDE)).approximate).toBe(false)
	})

	it('still draws a ring when handed nothing', () => {
		// markercluster has no childless cluster, but an empty stop list is an
		// invalid `conic-gradient()` and CSS drops an invalid value in silence —
		// a transparent bubble with a number floating in it.
		expect(clusterRing([]).stops).toHaveLength(1)
	})
})

describe('isApproximate', () => {
	it('reads the value the server actually stores', () => {
		// Read out of the enum rather than copied: a rename leaves the literal
		// behind in exactly this kind of comparison, where nothing fails and the
		// treatment simply stops appearing.
		const php = readFileSync(`${ROOT}lib/Service/LocationQuality.php`, 'utf8')
		const stored = php.match(/case Approximate\s*=\s*'([^']+)'/)?.[1]

		expect(stored).toBeTruthy()
		expect(isApproximate({ locationQuality: stored })).toBe(true)
	})

	it('is false for every other quality, including none at all', () => {
		expect(isApproximate({ locationQuality: 'exacta' })).toBe(false)
		expect(isApproximate({ locationQuality: 'sin_coordenada' })).toBe(false)
		expect(isApproximate({})).toBe(false)
	})
})

describe('TerritorioMap.vue\'s cluster stylesheet', () => {
	it('is not markercluster\'s own, which is nothing but count hues', () => {
		// `MarkerCluster.Default.css` is, in its entirety, the green/yellow/orange
		// buckets `marker-cluster-small|medium|large` plus the geometry of the
		// markup that carries them. With `iconCreateFunction` overridden nothing
		// emits those classes, so importing the file is the only way a hue that
		// means a COUNT can still reach the page — which is the defect #22 is.
		const map = readFileSync(`${ROOT}src/components/TerritorioMap.vue`, 'utf8')

		// The import statement, not the name: the component explains in a comment
		// why it does not import this file, and a check that matched the mention
		// would fail on its own explanation.
		expect(map).not.toMatch(/^import '[^']*MarkerCluster\.Default\.css'/m)

		// And no positive half beside it. `expect(map).toMatch(/iconCreateFunction:/)`
		// stood here and a commented-out line would have satisfied it; whether the
		// hook is actually wired is settled in the browser gate, where a missing
		// `iconCreateFunction` draws markercluster's own `.marker-cluster-*`
		// bubbles and `a_cluster_says_what_is_under_it` fails on them.
	})
})
