import { describe, expect, it } from 'vitest'
import { pickedGroups, suggestions, toggleGroup, togglePick } from '../../src/boundaries.js'

/**
 * ADR-0007: the app never resolves a street NAME. It offers what exists and a
 * person chooses. These pin the half of that which is decidable — what is
 * offered, and what a choice does — because the choosing itself is a click.
 *
 * A comuna holds one street as many separate ways, which is why nothing here
 * deals in single objects.
 */
const BOUNDARIES = [
	{ id: 1, name: 'Avenida Vicuña Mackenna' },
	{ id: 2, name: 'Avenida Vicuña Mackenna' },
	{ id: 3, name: 'Avenida Vicuña Mackenna' },
	{ id: 4, name: 'Los Toros' },
	{ id: 5, name: 'Pasaje Los Toros Norte' },
	{ id: 6, name: 'Canal San Carlos' },
]

describe('what typing offers', () => {
	it('groups a street held as many ways into one choice', () => {
		const [mackenna] = suggestions(BOUNDARIES, 'mackenna')

		expect(mackenna.name).toBe('Avenida Vicuña Mackenna')
		expect(mackenna.ids).toEqual([1, 2, 3])
	})

	/** Nobody types the tilde when they are looking for something in a hurry. */
	it('ignores accents and case, like the Registry search does', () => {
		expect(suggestions(BOUNDARIES, 'VICUNA MACKENNA')[0]?.ids).toEqual([1, 2, 3])
	})

	/**
	 * The disambiguation criterion: "Los Toros" and "Pasaje Los Toros Norte" are
	 * different streets, and the app offers both rather than choosing. The
	 * shorter name comes first because it is the closer match to what was typed.
	 */
	it('offers every name that matches and picks none of them', () => {
		expect(suggestions(BOUNDARIES, 'los toros').map((group) => group.name))
			.toEqual(['Los Toros', 'Pasaje Los Toros Norte'])
	})

	it('offers nothing for an empty query, rather than everything', () => {
		expect(suggestions(BOUNDARIES, '')).toEqual([])
		expect(suggestions(BOUNDARIES, '   ')).toEqual([])
	})

	it('stops at a length somebody can read', () => {
		const many = Array.from({ length: 40 }, (_, i) => ({ id: i, name: `Calle ${i}` }))

		expect(suggestions(many, 'calle')).toHaveLength(12)
	})
})

describe('picking', () => {
	it('a click selects, and the same click again deselects', () => {
		expect(togglePick([], 4)).toEqual([4])
		expect(togglePick([4], 4)).toEqual([])
	})

	it('keeps the order things were picked in', () => {
		expect(togglePick(togglePick([1], 6), 4)).toEqual([1, 6, 4])
	})

	/**
	 * The criterion that matters most here: picking a street by typing its name
	 * and picking it by clicking it have to end in the same selection.
	 */
	it('picking a name picks every line that name stands for', () => {
		const [mackenna] = suggestions(BOUNDARIES, 'mackenna')

		expect(toggleGroup([], mackenna.ids)).toEqual([1, 2, 3])
	})

	it('picking a name again lets the whole street go', () => {
		expect(toggleGroup([1, 2, 3], [1, 2, 3])).toEqual([])
	})

	/** Half-picked means picking, not unpicking — otherwise clicking loses work. */
	it('completes a partly picked street rather than clearing it', () => {
		expect(toggleGroup([2], [1, 2, 3])).toEqual([2, 1, 3])
	})

	it('leaves everything else alone', () => {
		expect(toggleGroup([6], [1, 2, 3])).toEqual([6, 1, 2, 3])
	})
})

describe('what has been picked so far', () => {
	it('reads as streets, not as fragments', () => {
		const groups = pickedGroups(BOUNDARIES, [1, 2, 3, 6])

		expect(groups).toEqual([
			{ name: 'Avenida Vicuña Mackenna', ids: [1, 2, 3] },
			{ name: 'Canal San Carlos', ids: [6] },
		])
	})

	/**
	 * A line picked and then panned away from is still picked. Dropping it here
	 * because its name is no longer loaded would silently unpick it.
	 */
	it('keeps a pick whose line is no longer on screen', () => {
		expect(pickedGroups(BOUNDARIES, [99])).toEqual([{ name: null, ids: [99] }])
	})

	it('is empty when nothing is picked', () => {
		expect(pickedGroups(BOUNDARIES, [])).toEqual([])
	})
})
