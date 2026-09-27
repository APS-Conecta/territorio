import { describe, expect, it } from 'vitest'
import { mergeInto } from '../../src/composables/useChanges.js'

/**
 * What the change feed's merge decides (#585's late-response ordering, the
 * close-open-draft-on-retire case): which list a delivered record belongs in,
 * and whether the sidebar is now showing something that left the screen.
 *
 * The rules live in `src/composables/useChanges.js` rather than App.vue for
 * the reason every pure module in this app exists: the poll's decisions are
 * exactly what a five-second timer makes load-bearing, and nothing in this
 * suite loads a component. App.vue keeps the axios call, the timer and the
 * visibility listener; everything that DECIDES is pinned here.
 *
 * These pins are also what lets a later change drop the export set's
 * `.filter((feature) => !feature.retired)` guard: the retire move below is
 * the recorded reason `features` can never hold a retired record.
 */

const live = (id, over = {}) => ({ id, name: `Registro ${id}`, retired: false, ...over })
const retired = (id, over = {}) => ({ id, name: `Registro ${id}`, retired: true, ...over })

const state = (over = {}) => ({
	features: [live(1), live(2)],
	retiredFeatures: [retired(3)],
	selectedId: null,
	showRetired: false,
	...over,
})

describe('mergeInto', () => {
	it('replaces a live record, keeping its position', () => {
		const result = mergeInto(state(), live(2, { name: 'Registro 2 (editado)' }))

		expect(result.features.map((feature) => feature.name))
			.toEqual(['Registro 1', 'Registro 2 (editado)'])
		expect(result.retiredFeatures).toEqual([retired(3)])
		expect(result.closeOpen).toBe(false)
	})

	it('retires: the record leaves the live list and joins the retired one', () => {
		const result = mergeInto(state(), retired(1))

		expect(result.features.map((feature) => feature.id)).toEqual([2])
		expect(result.retiredFeatures.map((feature) => feature.id)).toEqual([1, 3])
	})

	it('restores: the record leaves the retired list and rejoins the live one', () => {
		const result = mergeInto(state(), live(3))

		expect(result.retiredFeatures).toEqual([])
		// The newest change is prepended, which is where App.vue's own save
		// puts a record too — the list reads "what just happened" at the top.
		expect(result.features.map((feature) => feature.id)).toEqual([3, 1, 2])
	})

	it('inserts a record nobody had as the newest live entry', () => {
		const result = mergeInto(state(), live(9))

		expect(result.features.map((feature) => feature.id)).toEqual([9, 1, 2])
		expect(result.retiredFeatures).toEqual([retired(3)])
	})

	it('closes the open draft only for the record the sidebar is showing, retired, while the live list is on screen', () => {
		// Not "differs from the toggle" — that also caught a LIVE record open
		// while the retired list was on show, which is an ordinary thing to be
		// looking at since #67, and closing it on the record's own save is what
		// made the save look like it had failed.
		const closed = mergeInto(state({ selectedId: 1 }), retired(1))
		const toggledAway = mergeInto(state({ selectedId: 1, showRetired: true }), retired(1))
		const ownSave = mergeInto(state({ selectedId: 1 }), live(1))
		const elsewhere = mergeInto(state({ selectedId: 2 }), retired(1))

		expect(closed.closeOpen).toBe(true)
		expect(toggledAway.closeOpen).toBe(false)
		expect(ownSave.closeOpen).toBe(false)
		expect(elsewhere.closeOpen).toBe(false)
	})

	it('is idempotent: a re-delivered record changes no list further', () => {
		// A slow response can land after a later one and re-deliver a record;
		// the second application must be a no-op on the lists.
		const first = mergeInto(state(), retired(1))
		const second = mergeInto(first, retired(1))

		expect(second.features).toEqual(first.features)
		expect(second.retiredFeatures).toEqual(first.retiredFeatures)
	})

	it('answers new arrays on every path, changed or not', () => {
		// A caller spreading the result keeps its reactivity only if the
		// arrays are fresh — and the poll spreads them on every delivery,
		// including the ones that change nothing.
		const before = state()
		const results = [mergeInto(before, live(2)), mergeInto(before, retired(1))]

		results.forEach((result) => {
			expect(result.features).not.toBe(before.features)
			expect(result.retiredFeatures).not.toBe(before.retiredFeatures)
		})
	})
})
