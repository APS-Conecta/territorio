import { describe, expect, it } from 'vitest'
import { diffLayers, pointSignature, shapeSignature } from '../../src/mapdiff.js'
import { indexSubcategories } from '../../src/taxonomy.js'

/**
 * What the diff-apply redraw decides by: a signature moves iff a drawn input
 * moved, and the diff partitions ids into exactly the three things a redraw
 * owes the layer groups.
 *
 * The signatures live in `src/mapdiff.js` rather than in the component for the
 * reason `pins.js` does: nothing in this suite loads a Vue component, so the
 * decision left inside one is a decision nothing can assert. What the diff
 * LOOKS like on screen — a poll that rebuilds nothing, a draft that takes its
 * shape down — is the browser gate's job, where the layers can be counted.

 * The fixtures mirror what App.vue actually holds: a taxonomy index built by
 * `indexSubcategories`, and records replaced wholesale by the poll — the
 * "equal across replaced objects" pins below are the case the five-second
 * delivery hits every time nothing changed.
 */

const TAXONOMY = [
	{
		label: 'Salud',
		slug: 'salud',
		color: '#99564e',
		subcategories: [{ id: 1, slug: 'consultorio', label: 'Consultorio' }],
	},
	{
		label: 'Deporte y recreación',
		slug: 'deporte_recreacion',
		color: '#407b51',
		subcategories: [{ id: 2, slug: 'cancha', label: 'Cancha' }],
	},
]

const subcategories = indexSubcategories(TAXONOMY)
const context = (over = {}) => ({ selectedId: null, subcategories, ...over })

const point = (over = {}) => ({
	id: 7,
	name: 'Consultorio Uno',
	geometry: { type: 'Point', coordinates: [-70.6, -33.5] },
	subcategoryId: 1,
	colour: null,
	locationQuality: 'exacta',
	...over,
})

const shape = (over = {}) => ({
	id: 3,
	name: 'Sector Norte',
	geometry: {
		type: 'Polygon',
		coordinates: [[[-70.6, -33.5], [-70.59, -33.5], [-70.59, -33.49], [-70.6, -33.5]]],
	},
	subcategoryId: 1,
	colour: null,
	...over,
})

describe('pointSignature', () => {
	it('is equal across replaced record objects with the same drawn inputs', () => {
		expect(pointSignature(point(), context()))
			.toBe(pointSignature(point(), context()))
	})

	it('moves with the coordinate', () => {
		expect(pointSignature(point({ geometry: { type: 'Point', coordinates: [-70.6, -33.55] } }), context()))
			.not.toBe(pointSignature(point(), context()))
	})

	it('moves with the drawn colour', () => {
		// A record's own colour wins over its Category's (colourOf), so setting
		// it changes what the pin draws without anything else moving.
		expect(pointSignature(point({ colour: '#123456' }), context()))
			.not.toBe(pointSignature(point(), context()))
	})

	it('moves with the glyph, which moves with the Category', () => {
		expect(pointSignature(point({ subcategoryId: 2 }), context()))
			.not.toBe(pointSignature(point(), context()))
	})

	it('moves with selection', () => {
		expect(pointSignature(point(), context({ selectedId: 7 })))
			.not.toBe(pointSignature(point(), context()))
	})

	it('moves with exactitude', () => {
		expect(pointSignature(point({ locationQuality: 'aproximada' }), context()))
			.not.toBe(pointSignature(point(), context()))
	})

	it('does not move with anything the pin does not draw: a rename', () => {
		// The registry key is the id and the inputs above are everything a pin
		// is drawn from — a renamed point must not rebuild its marker.
		expect(pointSignature(point({ name: 'Consultorio Renombrado' }), context()))
			.toBe(pointSignature(point(), context()))
	})
})

describe('shapeSignature', () => {
	it('is equal across replaced record objects with the same drawn inputs', () => {
		expect(shapeSignature(shape(), context()))
			.toBe(shapeSignature(shape(), context()))
	})

	it('moves with the geometry', () => {
		const moved = shape({ geometry: {
			type: 'Polygon',
			coordinates: [[[-70.7, -33.5], [-70.59, -33.5], [-70.59, -33.49], [-70.7, -33.5]]],
		} })

		expect(shapeSignature(moved, context())).not.toBe(shapeSignature(shape(), context()))
	})

	it('moves with the filing: the slug chooses the style branch and the label', () => {
		expect(shapeSignature(shape({ subcategoryId: 2 }), context()))
			.not.toBe(shapeSignature(shape(), context()))
	})

	it('moves with the drawn colour', () => {
		expect(shapeSignature(shape({ colour: '#123456' }), context()))
			.not.toBe(shapeSignature(shape(), context()))
	})

	it('moves with selection', () => {
		expect(shapeSignature(shape(), context({ selectedId: 3 })))
			.not.toBe(shapeSignature(shape(), context()))
	})

	it('moves with the name, which the tooltip carries', () => {
		// The one input a pin ignores and a shape draws: the division's
		// permanent centroid label.
		expect(shapeSignature(shape({ name: 'Sector Sur' }), context()))
			.not.toBe(shapeSignature(shape(), context()))
	})
})

describe('diffLayers', () => {
	it('partitions by id into added, changed and removed', () => {
		const previous = new Map([
			[1, 'x'], // unchanged
			[2, 'y'], // changed
			[3, 'z'], // removed
		])
		const next = [
			{ id: 1, signature: 'x' },
			{ id: 2, signature: 'y2' },
			{ id: 4, signature: 'w' },
		]

		expect(diffLayers(previous, next)).toEqual({
			added: [{ id: 4, signature: 'w' }],
			changed: [{ id: 2, signature: 'y2' }],
			removed: [3],
		})
	})

	it('answers nothing when the next set re-presents equal signatures', () => {
		// The identity-replacement no-op: the poll re-delivers new objects for
		// every record, and an empty diff is what keeps an unchanged comuna
		// from rebuilding hundreds of identical markers every five seconds.
		const delivered = [point(), shape()]
		const previous = new Map([
			[7, pointSignature(point(), context())],
			[3, shapeSignature(shape(), context())],
		])
		const next = delivered.map((feature) => ({
			id: feature.id,
			signature: feature.geometry.type === 'Point'
				? pointSignature(point(), context())
				: shapeSignature(shape(), context()),
		}))

		expect(diffLayers(previous, next)).toEqual({ added: [], changed: [], removed: [] })
	})

	it('treats a shape left out of the next set as removed, and its return as an add', () => {
		// drawShapes excludes the drafted record from its next set — the draft
		// layer is drawing that one — so opening a draft on a drawn shape must
		// take the map's copy down, and closing the draft must bring it back.
		const opening = diffLayers(new Map([[3, 's']]), [])
		const closing = diffLayers(new Map(), [{ id: 3, signature: 's' }])

		expect(opening).toEqual({ added: [], changed: [], removed: [3] })
		expect(closing).toEqual({ added: [{ id: 3, signature: 's' }], changed: [], removed: [] })
	})

	it('partitions an empty registry into adds alone', () => {
		// The first draw: mounted() has map-added the groups but drawn nothing,
		// so every record is an add and nothing is removed or changed.
		expect(diffLayers(new Map(), [{ id: 1, signature: 'x' }]))
			.toEqual({ added: [{ id: 1, signature: 'x' }], changed: [], removed: [] })
	})
})
