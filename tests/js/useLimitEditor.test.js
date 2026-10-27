import { describe, expect, it } from 'vitest'
import { removeLimitPart, togglePickedGroup } from '../../src/composables/useLimitEditor.js'
import { canPolygonize, faceAround } from '../../src/sectors.js'

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

describe('togglePickedGroup', () => {
	/**
	 * Picking a name picks every on-screen Boundary feature of it (#214), and
	 * one of Norte's holds no edge. The Limit still generates, through the same
	 * unfiltered expression seedSector hands `faceAround`.
	 */
	it('picks a zero-length fragment with its street and still lets the Limit generate', () => {
		const line = (coordinates) => ({ type: 'LineString', coordinates })
		const onScreen = [
			{ id: 1, name: 'Sur', geometry: line([[0, 0], [4, 0]]) },
			{ id: 2, name: 'Este', geometry: line([[4, 0], [4, 4]]) },
			{ id: 3, name: 'Norte', geometry: line([[4, 4], [0, 4]]) },
			{ id: 4, name: 'Oeste', geometry: line([[0, 4], [0, 0]]) },
			{ id: 5, name: 'Norte', geometry: line([[9, 9], [9, 9]]) },
		]
		const draft = { picked: [], limit: [], pickedGeometry: {} }
		const idsOf = (name) => onScreen.filter((row) => row.name === name).map((row) => row.id)

		for (const name of ['Sur', 'Este', 'Norte', 'Oeste']) {
			togglePickedGroup(draft, onScreen, idsOf(name))
		}

		expect(draft.picked).toContain(5)
		expect(canPolygonize(draft.picked, draft.pickedGeometry)).toBe(true)
		expect(faceAround(draft.picked.map((id) => draft.pickedGeometry[id]), [2, 2])).not.toBeNull()
	})
})
