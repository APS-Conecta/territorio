import { describe, expect, it } from 'vitest'
import { groups, partsFrom, provenance, readable, sectorFields, withNote, without } from '../../src/limit.js'

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

/**
 * How the current line came to be, read off the revision history (issue #50).
 *
 * No column records it, and none is needed: every write already stamps which
 * fields it changed, and a Sector generated from picked lines changes `geometry`
 * and `limit` in the same revision while a dragged vertex changes `geometry`
 * alone. Nothing here compares the two VALUES or asks whether they still agree —
 * they stop agreeing the moment somebody drags a vertex, which is expected
 * (CONTEXT.md). It reads only WHEN each was last written.
 *
 * History arrives newest first, as `GET /features/{id}/history` returns it.
 */
const revision = (number, changes, action = 'updated', changedAt = '2026-08-12 09:30:00') => ({
	revision: number,
	action,
	actor: 'ana',
	changedAt,
	changes,
})

const SHAPE = '{"type":"Polygon","coordinates":[[[0,0],[1,0],[1,1],[0,0]]]}'
const STREETS = '[{"featureId":7,"name":"Avenida Vicuña Mackenna"}]'

/** Drawn freehand: a creation carrying a geometry, and no Limit ever recorded. */
const DRAWN = [
	revision(1, {
		name: { from: null, to: 'Sector 3' },
		geometry: { from: null, to: SHAPE },
	}, 'created', '2026-08-05 11:00:00'),
]

/** Generated from picked lines: one revision carries both. */
const GENERATED = [
	revision(1, {
		name: { from: null, to: 'Sector 3' },
		geometry: { from: null, to: SHAPE },
		limit: { from: null, to: STREETS },
	}, 'created', '2026-08-05 11:00:00'),
]

/** Generated, then a vertex dragged: a later revision touches the geometry alone. */
const DRAGGED = [
	revision(2, { geometry: { from: SHAPE, to: SHAPE } }, 'updated', '2026-08-12 16:45:00'),
	...GENERATED,
]

describe('provenance', () => {
	it('says a Sector was drawn by hand when no revision ever recorded a Limit', () => {
		expect(provenance(DRAWN)).toBe('Línea dibujada a mano.')
	})

	it('says the line was fixed together with the Limit when one revision wrote both', () => {
		expect(provenance(GENERATED)).toBe('Línea fijada junto con el límite el 05-08-2026.')
	})

	/**
	 * Issue #50's third criterion: when the geometry was last edited, relative to
	 * the Limit, without reading raw history. The date is the geometry's own.
	 */
	it('dates the adjustment when the line moved after the Limit', () => {
		expect(provenance(DRAGGED)).toBe('Línea ajustada el 12-08-2026, después del límite.')
	})

	/**
	 * The other order, which is just as ordinary: somebody writes down what the
	 * sector runs along long after the line was settled. Saying "después del
	 * límite" here would be false, so it says the true thing instead.
	 */
	it('says so when the Limit was written down after the line', () => {
		const history = [
			revision(3, { limit: { from: null, to: STREETS } }, 'updated', '2026-08-20 08:00:00'),
			...DRAWN,
		]

		expect(provenance(history)).toBe('Línea fijada el 05-08-2026; el límite se anotó después.')
	})

	/**
	 * A Limit emptied is not a Limit recorded. The service stores an empty Limit
	 * as null rather than `[]` ("a Sector drawn by hand has no Limit at all",
	 * FeatureService::limit), so the revision that cleared it carries
	 * `to: null` — counting that as a Limit would tell somebody their hand-drawn
	 * Sector was fixed against a Limit that no longer exists.
	 */
	it('does not count a Limit that was removed', () => {
		const history = [
			revision(2, { limit: { from: STREETS, to: null } }, 'updated', '2026-08-20 08:00:00'),
			...DRAWN,
		]

		expect(provenance(history)).toBe('Línea dibujada a mano.')
	})

	/**
	 * The timestamp is whatever the database handed back — `RevisionLog` reads the
	 * column raw — so the date is taken off either shape rather than off a
	 * separator this driver happens to use today.
	 */
	it('reads the date whichever separator the timestamp carries', () => {
		const iso = [revision(1, { geometry: { from: null, to: SHAPE } }, 'created', '2026-08-05T11:00:00+00:00')]

		expect(provenance(iso)).toBe('Línea dibujada a mano.')
		expect(provenance([
			revision(2, { limit: { from: null, to: STREETS } }, 'updated', '2026-09-01T08:00:00+00:00'),
			...iso,
		])).toBe('Línea fijada el 05-08-2026; el límite se anotó después.')
	})

	/** A timestamp that is not a date says nothing, rather than saying nonsense. */
	it('leaves the date out when the timestamp is not one', () => {
		expect(provenance([
			revision(2, { geometry: { from: SHAPE, to: SHAPE } }, 'updated', null),
			...GENERATED,
		])).toBe('Línea ajustada, después del límite.')
	})

	/** Nothing to say rather than a guess: a record whose history never arrived. */
	it('says nothing when no revision recorded a geometry', () => {
		expect(provenance([])).toBe('')
		expect(provenance([revision(1, { name: { from: null, to: 'Sector 3' } }, 'created')])).toBe('')
		expect(provenance(undefined)).toBe('')
	})

	/**
	 * Issue #50's second criterion, asserted as a rule rather than trusted to the
	 * wordings above: the Limit records intent and the geometry records the agreed
	 * line, they are EXPECTED to differ, and nothing here may read as a defect to
	 * be corrected (CONTEXT.md). A future wording that calls it a mismatch fails
	 * here rather than reaching a screen.
	 */
	it('never words it as a mismatch to be corrected', () => {
		const said = [DRAWN, GENERATED, DRAGGED].map(provenance).join(' ').toLowerCase()

		for (const wrong of ['desfase', 'no coinciden', 'desajust', 'corregir', 'error', 'inconsist']) {
			expect(said).not.toContain(wrong)
		}
	})
})
