/**
 * The Limit editor's coupled fields (L1-31): `picked`, `pickedGeometry`,
 * `limit` — six methods in App.vue that mutated the draft through three
 * invariants (#49's shapes-survive-the-pan rule above all), unreachable by
 * tests for being in the one file the js suite cannot load.
 *
 * Same shape as useChanges.js: pure functions over (draft, whatIsOnScreen),
 * mutating the draft object a test builds in one line. App.vue keeps the
 * `editingLimit` gate and the `loadBoundaries` round trip; the field
 * choreography moves.
 */
import { toggleGroup, togglePick } from '../boundaries.js'
import { partsFrom, withNote, without } from '../limit.js'

/** Clicking a line on the map is the same pick as choosing its name. */
export function togglePicked(draft, onScreen, id) {
	if (draft?.picked !== undefined) {
		draft.picked = togglePick(draft.picked, id)
		keepPickedGeometry(draft, onScreen, [id])
		recordLimit(draft, onScreen)
	}
}

/** A whole named group, as the picker's checkbox means it. */
export function togglePickedGroup(draft, onScreen, ids) {
	if (draft?.picked !== undefined) {
		draft.picked = toggleGroup(draft.picked, ids)
		keepPickedGeometry(draft, onScreen, ids)
		recordLimit(draft, onScreen)
	}
}

/** A stretch that follows nothing anybody could pick. */
export function addLimitNote(draft, text) {
	if (draft?.limit !== undefined) {
		draft.limit = withNote(draft.limit, text)
	}
}

/**
 * One name dropped from the Limit, and everything it stood for unpicked.
 *
 * By name and not by row: a street is dozens of ways, so "Quitar Vicuña
 * Mackenna" means all of them. Dropping one way would change nothing the
 * person can read — the sentence says each name once — while unticking the
 * street in the picker, and the next pick would put it back.
 */
export function removeLimitPart(draft, onScreen, name) {
	if (draft?.limit === undefined) {
		return
	}

	const dropped = draft.limit
		.filter((part) => part.name === name && part.featureId !== undefined)
		.map((part) => part.featureId)

	draft.limit = without(draft.limit, name)
	draft.picked = draft.picked.filter((id) => !dropped.includes(id))
	// And forget their shapes. keepPickedGeometry() holds exactly what is
	// picked — clicking a line on the map goes through it either way — and
	// this path dropped the ids without it, so a shape outlived its pick.
	keepPickedGeometry(draft, onScreen, dropped)
}

/**
 * Keep the shapes of the picked lines on the draft — as they are picked,
 * and again as they come back into view.
 *
 * `onScreen` is what is ON SCREEN: the caller's cadastre-line list is replaced
 * wholesale every time the map settles, and capped at three thousand rows
 * against a comuna's eleven thousand. Building a Sector from four avenues means
 * panning along them, and zooming out to click inside the block refetches
 * — so a line picked five minutes ago is very often not in that list any
 * more.
 *
 * Re-deriving the geometries from it at generate time therefore dropped
 * picks silently, and the two ways that shows are both bad: "las calles no
 * cierran" about lines that do, or a LARGER face accepted as the Sector
 * because the line that would have closed the small one was missing. The
 * second is silent and wrong, which is the kind this app has been bitten
 * by before.
 */
export function keepPickedGeometry(draft, onScreen, ids) {
	const kept = { ...(draft.pickedGeometry ?? {}) }
	const lines = new Map(onScreen.map((each) => [each.id, each]))

	ids.forEach((id) => {
		if (!draft.picked.includes(id)) {
			// Unpicked: forget it, or a line removed on purpose would keep
			// shaping the polygon.
			delete kept[id]
			return
		}
		const geometry = lines.get(id)?.geometry
		// `!= null`, matching canPolygonize: a Boundary row whose geometry is
		// empty or corrupt arrives decoded to null, and once this runs on
		// every settle rather than once per pick, writing that would overwrite
		// a shape already held with nothing.
		if (geometry != null) {
			kept[id] = geometry
		}
	})

	draft.pickedGeometry = kept
}

/** The Limit follows what is picked, keeping what is already written down.
 *
 * Names are taken from the lines on screen at the moment of picking and
 * then kept, because a Limit outlives the picker: the lines load only
 * while the editor is open and only for the part of the comuna in view
 * (issue #12), so a Limit holding bare ids would read as blanks as soon as
 * anybody scrolled away.
 */
export function recordLimit(draft, onScreen) {
	draft.limit = partsFrom(draft.picked, onScreen, draft.limit ?? [])
}
