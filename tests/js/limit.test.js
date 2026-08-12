import { describe, expect, it } from 'vitest'
import { groups, partsFrom, readable, sectorFields, withNote, without } from '../../src/limit.js'

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

describe('groups', () => {
	/**
	 * A comuna holds one street as dozens of ways, so picking a name records
	 * dozens of parts. Listing them one per row is thirty identical lines with
	 * thirty identical Quitar buttons — the same unreadable list #13 grouped the
	 * suggestions to avoid.
	 */
	it('shows one row per name, with what it stands for', () => {
		expect(groups([
			{ featureId: 7, name: 'Avenida Vicuña Mackenna' },
			{ featureId: 8, name: 'Avenida Vicuña Mackenna' },
			{ name: 'por el canal' },
		])).toEqual([
			{ name: 'Avenida Vicuña Mackenna', parts: 2, picked: true },
			{ name: 'por el canal', parts: 1, picked: false },
		])
	})

	it('is empty for an empty Limit', () => {
		expect(groups([])).toEqual([])
		expect(groups(null)).toEqual([])
	})
})

describe('without', () => {
	/**
	 * Removing is by NAME, not by row index.
	 *
	 * "Quitar Vicuña Mackenna" has to mean all thirty of its ways. Removing one
	 * of them would leave the sentence unchanged — names are said once — while
	 * the tick in the picker flipped off, so the screen would disagree with
	 * itself and the next click would put it back.
	 */
	it('removes every part of one name', () => {
		const parts = [
			{ featureId: 7, name: 'Vicuña Mackenna' },
			{ featureId: 8, name: 'Vicuña Mackenna' },
			{ name: 'por el canal' },
		]

		expect(without(parts, 'Vicuña Mackenna')).toEqual([{ name: 'por el canal' }])
	})

	it('leaves everything else alone', () => {
		const parts = [{ featureId: 7, name: 'Uno' }, { name: 'dos' }]

		expect(without(parts, 'no existe')).toEqual(parts)
	})
})

/**
 * The three fields only a Sector draft carries, and the reason they are built in
 * one place: they have drifted apart twice already.
 *
 * `limit` absent meant a tramo typed before the first save was dropped (#48).
 * `pickedGeometry` absent meant `save()` carried undefined over a fresh object
 * (#49). Both were one caller having a field the other did not.
 */
describe('the fields a Sector draft carries', () => {
	it('gives a new Sector all three, empty', () => {
		expect(sectorFields()).toEqual({ limit: [], picked: [], pickedGeometry: {} })
	})

	/**
	 * `picked` is derived from the Limit, never stored beside it: the Limit is the
	 * record of which lines were chosen (#15), and the ticks are a view of it.
	 */
	it('derives the ticks from the stored Limit', () => {
		const limit = [
			{ name: 'Avenida Vicuña Mackenna', featureId: 7, parts: 3, picked: true },
			{ name: 'por detrás del colegio', parts: 1, picked: false },
			{ name: 'Canal San Carlos', featureId: 9, parts: 1, picked: true },
		]

		expect(sectorFields(limit)).toEqual({
			limit,
			picked: [7, 9],
			pickedGeometry: {},
		})
	})

	/**
	 * Empty by necessity, not by omission: the server holds the names a Limit runs
	 * along and never the shapes, which arrive as the map brings each line back
	 * into view.
	 */
	it('starts with no shapes even for a Limit full of picks', () => {
		expect(sectorFields([{ name: 'Uno', featureId: 1, parts: 1, picked: true }]).pickedGeometry)
			.toEqual({})
	})
})
