import { describe, expect, it } from 'vitest'
import { partsFrom, readable, withNote, without } from '../../src/limit.js'

/**
 * A Sector's Limit — what somebody says out loud when asked where the sector
 * ends (issue #15).
 *
 * The distinction the ticket turns on: the Limit records INTENT and the geometry
 * records the agreed line. Once a boundary is dragged by hand the two no longer
 * match exactly, and that is expected rather than drift to be corrected. So
 * nothing here derives one from the other or checks them against each other.
 *
 * A part is either a picked Boundary feature or a stretch somebody described in
 * words, because a real limit runs along things no dataset contains.
 */

const PICKED = [
	{ id: 7, name: 'Avenida Vicuña Mackenna' },
	{ id: 8, name: 'Avenida Vicuña Mackenna' },
	{ id: 9, name: 'Canal San Carlos' },
]

describe('partsFrom', () => {
	it('records the picked features by name, not only by id', () => {
		expect(partsFrom([7, 9], PICKED, [])).toEqual([
			{ featureId: 7, name: 'Avenida Vicuña Mackenna' },
			{ featureId: 9, name: 'Canal San Carlos' },
		])
	})

	/**
	 * The name is stored, not looked up later, and that is the point: a Limit
	 * outlives the picker. The lines it names are loaded only while the editor is
	 * open and only for the part of the comuna on screen, so a Limit that held
	 * bare ids would render as blanks the moment anybody scrolled away — or after
	 * the boundary Dataset was re-imported.
	 */
	it('keeps the name of a feature that is no longer loaded', () => {
		const already = [{ featureId: 7, name: 'Avenida Vicuña Mackenna' }]

		expect(partsFrom([7], [], already)).toEqual(already)
	})

	it('drops a part whose feature was unpicked', () => {
		const already = [
			{ featureId: 7, name: 'Avenida Vicuña Mackenna' },
			{ featureId: 9, name: 'Canal San Carlos' },
		]

		expect(partsFrom([9], PICKED, already))
			.toEqual([{ featureId: 9, name: 'Canal San Carlos' }])
	})

	/** A described stretch belongs to nothing picked, so nothing unpicks it. */
	it('keeps hand-described stretches whatever is picked', () => {
		const already = [{ name: 'por el canal, detrás del colegio' }]

		expect(partsFrom([7], PICKED, already)).toEqual([
			{ name: 'por el canal, detrás del colegio' },
			{ featureId: 7, name: 'Avenida Vicuña Mackenna' },
		])
	})

	it('is empty for a Sector drawn entirely by hand', () => {
		expect(partsFrom([], [], [])).toEqual([])
	})
})

describe('readable', () => {
	it('says the names, in Spanish, as somebody would', () => {
		expect(readable([
			{ featureId: 7, name: 'Avenida Vicuña Mackenna' },
			{ featureId: 9, name: 'Canal San Carlos' },
		])).toBe('Avenida Vicuña Mackenna y Canal San Carlos')
	})

	it('uses commas until the last one', () => {
		expect(readable([
			{ featureId: 1, name: 'Uno' },
			{ featureId: 2, name: 'Dos' },
			{ featureId: 3, name: 'Tres' },
		])).toBe('Uno, Dos y Tres')
	})

	/**
	 * A comuna holds one street as dozens of ways, so picking a name picks many
	 * parts. Saying it thirty times is not what a person would say.
	 */
	it('says a repeated name once', () => {
		expect(readable([
			{ featureId: 7, name: 'Avenida Vicuña Mackenna' },
			{ featureId: 8, name: 'Avenida Vicuña Mackenna' },
			{ featureId: 9, name: 'Canal San Carlos' },
		])).toBe('Avenida Vicuña Mackenna y Canal San Carlos')
	})

	it('reads a described stretch the same as a picked one', () => {
		expect(readable([
			{ featureId: 7, name: 'Avenida Vicuña Mackenna' },
			{ name: 'por el canal, detrás del colegio' },
		])).toBe('Avenida Vicuña Mackenna y por el canal, detrás del colegio')
	})

	/** An empty Limit is a valid Limit, and says nothing rather than "ninguno". */
	it('is empty for a Sector drawn by hand', () => {
		expect(readable([])).toBe('')
		expect(readable(null)).toBe('')
	})
})

describe('withNote', () => {
	it('adds a stretch nobody could pick', () => {
		expect(withNote([], '  por el canal  ')).toEqual([{ name: 'por el canal' }])
	})

	it('refuses to add nothing', () => {
		expect(withNote([], '   ')).toEqual([])
	})
})

describe('without', () => {
	it('removes one part and leaves the rest in order', () => {
		const parts = [
			{ featureId: 7, name: 'Uno' },
			{ name: 'dos' },
			{ featureId: 9, name: 'Tres' },
		]

		expect(without(parts, 1)).toEqual([
			{ featureId: 7, name: 'Uno' },
			{ featureId: 9, name: 'Tres' },
		])
	})
})
