import { describe, expect, it } from 'vitest'
import { groups, partsFrom, provenance, readable, sectorFields, withNote, without, withoutNote } from '../../src/limit.js'

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
	it('adds a stretch nobody could pick, stamped as its own occurrence', () => {
		expect(withNote([], '  por el canal  ')).toEqual([
			{ uid: expect.stringMatching(/^tramo-\d+$/), name: 'por el canal' },
		])
	})

	/**
	 * The stamp is the stretch's identity, never its name (#99): two
	 * stretches typing the same words — or one typing a name a street already
	 * carries — are two occurrences, removable one at a time.
	 */
	it('stamps a fresh identity on every stretch, never one per name', () => {
		const twice = withNote(withNote([], 'Canal San Carlos'), 'Canal San Carlos')

		expect(twice).toHaveLength(2)
		expect(twice[0].uid).toMatch(/^tramo-\d+$/)
		expect(twice[1].uid).not.toBe(twice[0].uid)
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
			{ name: 'Avenida Vicuña Mackenna', parts: 2, picked: true, gone: 0, key: 'Avenida Vicuña Mackenna' },
			{ name: 'por el canal', parts: 1, picked: false, gone: 0, uid: undefined, key: 'tramo:por el canal#1' },
		])
	})

	it('is empty for an empty Limit', () => {
		expect(groups([])).toEqual([])
		expect(groups(null)).toEqual([])
	})

	/**
	 * Issue #99. A stretch typed with a street's own words is not the street:
	 * folding the two into one row swallowed the «a mano» badge, read the
	 * count as the street's ways, and made Quitar all-or-nothing. The two are
	 * two rows — the street folded by name as it always was, the typed
	 * stretch standing beside it with its own identity.
	 */
	it('keeps a described stretch apart from a street that says the same words', () => {
		expect(groups([
			{ featureId: 7, name: 'Canal San Carlos' },
			{ name: 'Canal San Carlos' },
		])).toEqual([
			{ name: 'Canal San Carlos', parts: 1, picked: true, gone: 0, key: 'Canal San Carlos' },
			{ name: 'Canal San Carlos', parts: 1, picked: false, gone: 0, uid: undefined, key: 'tramo:Canal San Carlos#1' },
		])
	})

	/**
	 * Issue #51. A boundary feature can be retired or withdrawn from the cadastre
	 * after a Limit cited it, and the part must say so without losing its name —
	 * "the sentence a team says out loud does not change because a cadastre
	 * release did".
	 *
	 * Counted part by part rather than as a verdict on the whole row: a street is
	 * dozens of separate ways, and three of Vicuña Mackenna's thirty going away is
	 * three, not "Vicuña Mackenna is gone".
	 */
	it('counts how many parts of a name are gone from the cadastre', () => {
		expect(groups([
			{ featureId: 7, name: 'Avenida Vicuña Mackenna' },
			{ featureId: 8, name: 'Avenida Vicuña Mackenna' },
			{ featureId: 9, name: 'Avenida Vicuña Mackenna' },
		], [8, 9])).toEqual([
			{ name: 'Avenida Vicuña Mackenna', parts: 3, picked: true, gone: 2, key: 'Avenida Vicuña Mackenna' },
		])
	})

	it('says nothing is gone when the server named none', () => {
		expect(groups([{ featureId: 7, name: 'Calle Uno' }])).toEqual([
			{ name: 'Calle Uno', parts: 1, picked: true, gone: 0, key: 'Calle Uno' },
		])
	})

	/**
	 * «a mano» and «ya no está en el catastro» are different answers, and the
	 * picker shows them side by side. A typed stretch cites no feature, so no set
	 * of missing ids can make it gone — and it goes on reading «a mano».
	 */
	it('never calls a hand-typed stretch gone, whatever ids are missing', () => {
		expect(groups([{ name: 'por el canal' }], [7, 8])).toEqual([
			{ name: 'por el canal', parts: 1, picked: false, gone: 0, uid: undefined, key: 'tramo:por el canal#1' },
		])
	})

	/**
	 * `key` is what the picker's list and its Quitar buttons hang off, so no
	 * two rows may share one: a picked street and a same-named typed stretch
	 * coexist, and two raw described parts — nothing has stamped them yet —
	 * still get distinct, deterministic keys by position among their name.
	 */
	it('gives every row a key no other row shares', () => {
		const keys = groups([
			{ featureId: 7, name: 'Canal San Carlos' },
			{ name: 'Canal San Carlos' },
			{ name: 'Canal San Carlos' },
			{ name: 'por el canal' },
		]).map((row) => row.key)

		expect(keys).toEqual([
			'Canal San Carlos',
			'tramo:Canal San Carlos#1',
			'tramo:Canal San Carlos#2',
			'tramo:por el canal#1',
		])
		expect(new Set(keys).size).toBe(keys.length)
	})

	/** A stamp is the stretch's identity wherever the list splices it (#99). */
	it('keys a stamped stretch by its stamp, wherever it sits', () => {
		expect(groups([{ uid: 'tramo-2', name: 'dos' }, { uid: 'tramo-1', name: 'uno' }]))
			.toEqual([
				{ name: 'dos', parts: 1, picked: false, gone: 0, uid: 'tramo-2', key: 'tramo-2' },
				{ name: 'uno', parts: 1, picked: false, gone: 0, uid: 'tramo-1', key: 'tramo-1' },
			])
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
	it('removes every picked part of one name', () => {
		const parts = [
			{ featureId: 7, name: 'Vicuña Mackenna' },
			{ featureId: 8, name: 'Vicuña Mackenna' },
			{ name: 'por el canal' },
		]

		expect(without(parts, 'Vicuña Mackenna')).toEqual([{ name: 'por el canal' }])
	})

	/**
	 * #99: a described stretch saying a street's words is not one of its ways.
	 * Unpicking the street drops the ways and leaves what somebody typed
	 * standing — the picker shows the two as two rows, and this is the half
	 * that keeps them apart when one goes.
	 */
	it('leaves a same-named described stretch standing', () => {
		const typed = { uid: 'tramo-1', name: 'Canal San Carlos' }

		expect(without([
			{ featureId: 7, name: 'Canal San Carlos' },
			typed,
		], 'Canal San Carlos')).toEqual([typed])
	})

	it('leaves everything else alone', () => {
		const parts = [{ featureId: 7, name: 'Uno' }, { name: 'dos' }]

		expect(without(parts, 'no existe')).toEqual(parts)
	})
})

describe('withoutNote', () => {
	/**
	 * The other half of #99: a described stretch is removed by the identity it
	 * was stamped with, one occurrence at a time — never by the words it says,
	 * which a second stretch may be saying too.
	 */
	it('removes exactly the one occurrence the stamp names', () => {
		const parts = [
			{ uid: 'tramo-1', name: 'por el canal' },
			{ uid: 'tramo-2', name: 'por el canal' },
			{ uid: 'tramo-3', name: 'por detrás del colegio' },
		]

		expect(withoutNote(parts, 'tramo-2')).toEqual([
			{ uid: 'tramo-1', name: 'por el canal' },
			{ uid: 'tramo-3', name: 'por detrás del colegio' },
		])
	})

	it('removes nothing when no part carries the stamp, and keeps the order', () => {
		const parts = [{ uid: 'tramo-1', name: 'uno' }, { featureId: 7, name: 'Dos' }]

		expect(withoutNote(parts, 'tramo-9')).toEqual(parts)
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
			limit: [
				limit[0],
				{ ...limit[1], uid: expect.stringMatching(/^tramo-\d+$/) },
				limit[2],
			],
			picked: [7, 9],
			pickedGeometry: {},
		})
	})

	/**
	 * Stored parts come back bare — the server's normalizer keeps `featureId`
	 * and `name` and nothing else — so this is the reopened-draft door
	 * (`draftFrom`): every described stretch arrives without an identity and
	 * gets a fresh one (#99). Picked parts need none: their `featureId` is an
	 * identity already.
	 */
	it('stamps described parts coming back from the server, and picked parts never', () => {
		const fields = sectorFields([
			{ featureId: 7, name: 'Avenida Vicuña Mackenna' },
			{ name: 'por el canal' },
		])

		expect(fields.limit[1].uid).toMatch(/^tramo-\d+$/)
		expect(fields.limit[0].uid).toBeUndefined()
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

/**
 * Drawn freehand, the Limit typed afterwards — a first-class shape, not an
 * afterthought: ADR-0007 is explicit that a Sector built without picking
 * anything is just as valid, and «a mano» stretches are typed, never picked.
 * No revision ever wrote the two together, which is what makes it hand-drawn.
 */
const DRAWN_THEN_LIMIT = [
	revision(4, { limit: { from: null, to: STREETS } }, 'updated', '2026-08-20 08:00:00'),
	...DRAWN,
]

/** Generated, a vertex dragged, and then the Limit edited once more. */
const DRAGGED_THEN_LIMIT = [
	revision(5, { limit: { from: STREETS, to: STREETS } }, 'updated', '2026-08-25 08:00:00'),
	...DRAGGED,
]

/**
 * Drawn by hand, the Limit typed, and then «Generar» run on the reopened
 * Sector — which #49 exists to make possible, and which writes `geometry`
 * alone because the Limit it was generated from did not change.
 */
const REGENERATED = [
	revision(3, { geometry: { from: SHAPE, to: SHAPE } }, 'updated', '2026-08-12 09:30:00'),
	revision(2, { limit: { from: null, to: STREETS } }, 'updated', '2026-08-06 09:00:00'),
	...DRAWN,
]

/**
 * A third origin, and the one history cannot see: imported from a Dataset.
 * `ImportService::classify()` files a row under any Subcategory, Sector
 * included — CHANGELOG 0.33.0 talks about undoing exactly that — and an import
 * writes no Limit, so the Created revision carries `geometry` alone. That is
 * DRAWN byte for byte, which is why these fixtures ARE DRAWN: what tells the
 * two apart is not in the history at all but on the record, as `datasetId`
 * (`FeatureService::applyProvenance` sets it on import and nowhere else).
 */
const IMPORTED = DRAWN

/** Imported, and somebody wrote down afterwards what it runs along. */
const IMPORTED_THEN_LIMIT = DRAWN_THEN_LIMIT

/**
 * Imported, and the line written again later — a re-import publishing a new
 * official shape, or somebody dragging a vertex. Indistinguishable, so the
 * sentence names neither.
 */
const IMPORTED_THEN_REDRAWN = [
	revision(3, { geometry: { from: SHAPE, to: SHAPE } }, 'updated', '2026-08-12 09:30:00'),
	...DRAWN,
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
	 *
	 * And its FIRST criterion in the same breath, which reading only the newest
	 * write of each field used to throw away: this Sector's creation wrote
	 * `geometry` and `limit` together, so the boundary DID come from the Limit,
	 * and the state a person is likeliest to open — generated, then dragged —
	 * said only when the line was last saved and never where it came from.
	 */
	it('names the origin, and dates the line moving after the Limit', () => {
		expect(provenance(DRAGGED))
			.toBe('Línea fijada junto con el límite el 05-08-2026 y guardada de nuevo el 12-08-2026.')
	})

	/**
	 * And it says WHEN rather than by which act, because history cannot tell the
	 * act: writing `geometry` alone in a later revision is what a dragged vertex
	 * does AND what «Generar» does on a reopened Sector whose Limit did not
	 * change (#49, `keepPickedGeometry`). Calling this one «ajustada» announced a
	 * hand adjustment for a polygon the app had just generated.
	 */
	it('does not call a later line a hand adjustment, since «Generar» writes the same shape', () => {
		expect(provenance(REGENERATED)).toBe('Línea guardada el 12-08-2026, después del límite.')
		// And it claims no origin either: no revision here ever wrote the two
		// together, so there is no «junto con el límite» to report — which is what
		// keeps this apart from the generated-then-dragged Sector above.
		expect(provenance(REGENERATED)).not.toBe(provenance(DRAGGED))
	})

	/**
	 * The other order, which is just as ordinary: somebody writes down what the
	 * sector runs along long after the line was settled. Saying "después del
	 * límite" here would be false, so it says the true thing instead.
	 */
	it('says so when the Limit was written down after the line', () => {
		expect(provenance(DRAWN_THEN_LIMIT)).toBe('Línea dibujada a mano; el límite se anotó después.')
	})

	/**
	 * Issue #50's fifth criterion, at the one place it used to collapse: with the
	 * Limit as the newest write, a hand-drawn Sector and a generated-then-moved
	 * one read as one sentence. They are distinguishable — generating needs
	 * picked lines, so a Limit, and «Generar» saves the two together — so a line
	 * written while no Limit had ever been recorded can only have been drawn.
	 */
	it('keeps a hand-drawn Sector apart from a generated one whose Limit is the newest write', () => {
		expect(provenance(DRAGGED_THEN_LIMIT))
			.toBe('Línea fijada junto con el límite el 05-08-2026 y guardada de nuevo el 12-08-2026; '
				+ 'el límite se editó después.')
		expect(provenance(DRAWN_THEN_LIMIT)).not.toBe(provenance(DRAGGED_THEN_LIMIT))
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
			revision(3, { geometry: { from: SHAPE, to: SHAPE } }, 'updated', '2026-09-01T08:00:00+00:00'),
			revision(2, { limit: { from: null, to: STREETS } }, 'updated', '2026-08-20T08:00:00+00:00'),
			...iso,
		])).toBe('Línea guardada el 01-09-2026, después del límite.')
	})

	/** A timestamp that is not a date says nothing, rather than saying nonsense. */
	it('leaves the date out when the timestamp is not one', () => {
		expect(provenance([
			revision(2, { geometry: { from: SHAPE, to: SHAPE } }, 'updated', null),
			...GENERATED,
		])).toBe('Línea fijada junto con el límite el 05-08-2026 y guardada de nuevo.')
	})

	/** Nothing to say rather than a guess: a record whose history never arrived. */
	it('says nothing when no revision recorded a geometry', () => {
		expect(provenance([])).toBe('')
		expect(provenance([revision(1, { name: { from: null, to: 'Sector 3' } }, 'created')])).toBe('')
		expect(provenance(undefined)).toBe('')
	})

	/**
	 * The one claim this feature could not support, and the reviewer of this
	 * slice caught it: a boundary the municipality published was being told to
	 * the user as drawn by hand. The two histories are the same list — the fix
	 * cannot come from history, and it does not: `datasetId` is the record's own
	 * answer to "did this come from a Dataset", `Feature::toClient()` already
	 * ships it, and `draftFrom()` now carries it onto the draft.
	 */
	it('says an imported Sector came from the catastro rather than naming a hand', () => {
		expect(provenance(IMPORTED, true)).toBe('Línea importada del catastro.')
		expect(provenance(IMPORTED)).toBe('Línea dibujada a mano.')
	})

	/** Writing down what it runs along does not make an imported line hand-drawn. */
	it('keeps an imported line imported when the Limit is typed afterwards', () => {
		expect(provenance(IMPORTED_THEN_LIMIT, true))
			.toBe('Línea importada del catastro; el límite se anotó después.')
	})

	/**
	 * Once the newest write of the line is not the import's own creation, the
	 * catastro is no longer what the line shows: a re-import and a dragged vertex
	 * both write `geometry` on an `updated` revision. So it says WHEN, as it does
	 * everywhere else it cannot name the act.
	 */
	it('stops crediting the catastro once the line was written again', () => {
		expect(provenance(IMPORTED_THEN_REDRAWN, true)).toBe('Línea guardada el 12-08-2026.')
	})

	/**
	 * The rule behind the three above, so a future wording cannot reintroduce the
	 * defect: nothing said about a record that came from a Dataset names a hand.
	 */
	it('never names a hand for a record that came from a Dataset', () => {
		for (const history of [IMPORTED, IMPORTED_THEN_LIMIT, IMPORTED_THEN_REDRAWN,
			GENERATED, DRAGGED, DRAGGED_THEN_LIMIT, REGENERATED]) {
			expect(provenance(history, true)).not.toContain('a mano')
		}
	})

	/**
	 * Issue #50's second criterion, asserted as a rule rather than trusted to the
	 * wordings above: the Limit records intent and the geometry records the agreed
	 * line, they are EXPECTED to differ, and nothing here may read as a defect to
	 * be corrected (CONTEXT.md). A future wording that calls it a mismatch fails
	 * here rather than reaching a screen.
	 */
	it('never words it as a mismatch to be corrected', () => {
		const histories = [DRAWN, GENERATED, DRAGGED, DRAWN_THEN_LIMIT, DRAGGED_THEN_LIMIT, REGENERATED]
		const said = [
			...histories.map((history) => provenance(history)),
			...histories.map((history) => provenance(history, true)),
			provenance(IMPORTED_THEN_REDRAWN, true),
		].join(' ').toLowerCase()

		for (const wrong of ['desfase', 'no coinciden', 'desajust', 'corregir', 'error', 'inconsist']) {
			expect(said).not.toContain(wrong)
		}
	})
})
