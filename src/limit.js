/**
 * A Sector's Limit: what somebody says out loud when asked where it ends
 * (issue #15).
 *
 * The distinction this turns on, and the reason a Limit is stored at all rather
 * than derived from the shape: **the Limit records intent and the geometry
 * records the agreed line.** They match when a Sector is generated and stop
 * matching the moment anybody drags a vertex, which is expected and is not drift
 * to be corrected. Nothing here compares one against the other or tries to keep
 * them in step.
 *
 * A part is either a picked Boundary feature or a stretch somebody described in
 * words, because a real limit runs along a canal, the edge of a park, or a line
 * two teams agreed on years ago — and ADR-0007 is explicit that a Sector built
 * without picking anything is just as valid.
 *
 * Names are stored beside the ids, not looked up later. The lines are loaded
 * only while the editor is open and only for what is on screen, so a Limit
 * holding bare ids would read as blanks the moment somebody scrolled away.
 */

/**
 * The Limit for what is currently picked, keeping what is already recorded.
 *
 * @param {number[]} picked ids of the Boundary features chosen right now
 * @param {Array} boundaries those loaded for the view, to take names from
 * @param {Array} parts the Limit as it stands
 * @return {Array} the Limit as it should now stand
 */
export function partsFrom(picked, boundaries, parts) {
	const named = new Map((boundaries ?? []).map((each) => [each.id, each.name]))
	const already = new Map(
		(parts ?? [])
			.filter((part) => part.featureId !== undefined)
			.map((part) => [part.featureId, part]),
	)

	// Described stretches first and always: nothing picks them, so nothing
	// unpicks them either, and rebuilding the list from `picked` alone would
	// quietly delete the half of a Limit that no dataset could have supplied.
	const described = (parts ?? []).filter((part) => part.featureId === undefined)

	return [
		...described,
		...picked.map((id) => already.get(id) ?? { featureId: id, name: named.get(id) ?? `#${id}` }),
	]
}

/**
 * The Limit as a sentence.
 *
 * Names are said once each. A comuna holds one street as dozens of separate
 * ways, so picking "Vicuña Mackenna" records thirty parts, and a Sector whose
 * limit reads "Vicuña Mackenna, Vicuña Mackenna, Vicuña Mackenna…" is not what
 * anybody said.
 */
export function readable(parts) {
	const names = [...new Set((parts ?? []).map((part) => part.name))]

	if (names.length === 0) {
		return ''
	}
	if (names.length === 1) {
		return names[0]
	}

	// "A, B y C" — Spanish takes no comma before the final "y".
	return `${names.slice(0, -1).join(', ')} y ${names[names.length - 1]}`
}

/** A stretch nobody could have picked, because no dataset holds it. */
export function withNote(parts, text) {
	const said = String(text ?? '').trim()

	return said === '' ? parts : [...parts, { name: said }]
}

/** One part removed — a street that turned out not to be the limit after all. */
export function without(parts, index) {
	return parts.filter((_, at) => at !== index)
}
