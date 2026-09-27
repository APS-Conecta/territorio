import { describe, expect, it } from 'vitest'
import { removeLimitPart } from '../../src/composables/useLimitEditor.js'

/**
 * The Limit editor's seam to the picker's rows (#99) — the composable's first
 * tests.
 *
 * The composable is pure functions over (draft, whatIsOnScreen), mutating a
 * draft a test builds in one expression — no component is mounted, which is
 * the file's own contract, App.vue being the one file the suite cannot load.
 * What is pinned here is the #99 branch: a row of the parts list is removed
 * AS A ROW — a picked street by name, a described stretch by the stamp it was
 * typed with — never by the words both can be saying.
 */

/** A row of the picker's parts list, as groups() builds it. */
const pickedRow = (name) => ({ name, parts: 1, picked: true, gone: 0, key: name })
const describedRow = (name, uid) => ({ name, parts: 1, picked: false, gone: 0, uid, key: uid })

describe('removeLimitPart', () => {
	/**
	 * The #99 seam: Quitar on a described row drops exactly the occurrence its
	 * stamp names. Nothing unpicks — nothing was ever picked — and no geometry
	 * is touched, not even the street's that says the same words.
	 */
	it('removes only the described stretch its row names, unpicking nothing', () => {
		const street = { type: 'LineString', coordinates: [[0, 0], [1, 1]] }
		const draft = {
			picked: [7],
			limit: [
				{ featureId: 7, name: 'Canal San Carlos' },
				{ uid: 'tramo-1', name: 'Canal San Carlos' },
				{ uid: 'tramo-2', name: 'Canal San Carlos' },
			],
			pickedGeometry: { 7: street },
		}

		removeLimitPart(draft, [], describedRow('Canal San Carlos', 'tramo-1'))

		expect(draft.limit).toEqual([
			{ featureId: 7, name: 'Canal San Carlos' },
			{ uid: 'tramo-2', name: 'Canal San Carlos' },
		])
		expect(draft.picked).toEqual([7])
		expect(draft.pickedGeometry).toEqual({ 7: street })
	})

	/**
	 * The other side of the seam: Quitar on the picked row unpicks every way
	 * of the street — the tick in the picker flips — while the same-named
	 * typed stretch survives it.
	 */
	it('unpicks every way of the named street and leaves the typed stretch standing', () => {
		const draft = {
			picked: [7, 8],
			limit: [
				{ featureId: 7, name: 'Canal San Carlos' },
				{ featureId: 8, name: 'Canal San Carlos' },
				{ uid: 'tramo-1', name: 'Canal San Carlos' },
			],
			pickedGeometry: {
				7: { type: 'LineString', coordinates: [[0, 0], [1, 1]] },
				8: { type: 'LineString', coordinates: [[2, 2], [3, 3]] },
			},
		}

		removeLimitPart(draft, [], pickedRow('Canal San Carlos'))

		expect(draft.limit).toEqual([{ uid: 'tramo-1', name: 'Canal San Carlos' }])
		expect(draft.picked).toEqual([])
		expect(draft.pickedGeometry).toEqual({})
	})
})
