/**
 * A Sector's Limit: what somebody says out loud when asked where it ends
 * (issue #15).
 *
 * The distinction this turns on, and the reason a Limit is stored at all rather
 * than derived from the shape: **the Limit records intent and the geometry
 * records the agreed line.** They match when a Sector is generated and stop
 * matching the moment anybody drags a vertex, which is expected and is not drift
 * to be corrected. Nothing here compares one VALUE against the other or tries to
 * keep them in step — `provenance()` reads only WHEN each was last written, which
 * is a different question and the only one history can answer.
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

/**
 * The Limit as rows a person can read: one per name.
 *
 * A comuna holds one street as dozens of separate ways, so picking "Vicuña
 * Mackenna" records dozens of parts. Listing them one per row is thirty
 * identical lines with thirty identical Quitar buttons — the same list #13
 * grouped the suggestions to avoid, for the same reason.
 *
 * `picked` says whether the row stands for Boundary features, because removing
 * one has to unpick them too, and a described stretch has nothing to unpick.
 */
export function groups(parts) {
	const rows = new Map()

	;(parts ?? []).forEach((part) => {
		const row = rows.get(part.name) ?? { name: part.name, parts: 0, picked: false }
		row.parts += 1
		row.picked = row.picked || part.featureId !== undefined
		rows.set(part.name, row)
	})

	return [...rows.values()]
}

/**
 * Every part of one name removed — a street that turned out not to be the limit.
 *
 * By name rather than by row, because a name is what the person sees and what
 * the sentence says. Removing one of a street's thirty ways would change nothing
 * they can read, while the tick in the picker flipped off: the screen would
 * disagree with itself, and the next pick would put it straight back.
 */
export function without(parts, name) {
	return (parts ?? []).filter((part) => part.name !== name)
}

/**
 * The three fields only a Sector draft carries, built in one place because they
 * have to arrive together.
 *
 * They have drifted apart twice. `limit` absent meant `addLimitNote` had nothing
 * to key on, so a tramo typed before the first save was dropped (#48).
 * `pickedGeometry` absent meant `save()` carried undefined over a fresh object
 * (#49). Both were one caller holding a field the other did not, and the next
 * field would have been the third.
 *
 * `picked` is derived from the Limit rather than kept beside it: the Limit IS the
 * record of which lines were chosen (#15), and the ticks in the picker are a view
 * of it. `pickedGeometry` starts empty by necessity — the server holds the names a
 * Limit runs along and never the shapes, which come back as the map brings each
 * line into view.
 */
export function sectorFields(limit = []) {
	return {
		limit,
		picked: limit
			.filter((part) => part.featureId !== undefined)
			.map((part) => part.featureId),
		pickedGeometry: {},
	}
}

/** A revision that wrote a value into this field — not one that cleared it. */
function wrote(entry, field) {
	const change = (entry?.changes ?? {})[field]

	return change !== undefined && change.to !== null
}

/** A stored timestamp as a date is said out loud here: 12-08-2026. */
function said(changedAt) {
	const [date] = String(changedAt ?? '').split(' ')
	const parts = date.split('-')

	return parts.length === 3 ? ` el ${parts[2]}-${parts[1]}-${parts[0]}` : ''
}

/**
 * How this Sector's current line came to be, as one sentence (issue #50).
 *
 * It costs no column, because the revision history already holds it: every write
 * records which fields it changed, a Sector generated from picked lines writes
 * `geometry` and `limit` in the same revision, and a dragged vertex writes
 * `geometry` alone. A column would be no better anyway — `faceAround()` runs in
 * this browser and the server never witnesses "generated", so a stored flag would
 * be the client's word for the same thing.
 *
 * It states provenance and never a defect. The Limit records intent and the
 * geometry records the agreed line; they stop matching the moment somebody drags
 * a vertex, and that is expected rather than drift to be corrected (CONTEXT.md).
 * So no wording here calls it a mismatch and nothing renders it as a warning.
 *
 * Known limitation, worth knowing before trusting a sentence: everything one save
 * does lands in one revision, so a Sector generated and then dragged BEFORE its
 * first save, and one drawn by hand with streets picked in the same save, both
 * read as "fijada junto con el límite". Telling those apart needs the app to
 * record the act rather than the fields it changed, which is a product question
 * and not this function's.
 *
 * @param {Array} history the record's revisions, newest first, as the API returns them
 * @return {string} the sentence, or '' when history says nothing about a line
 */
export function provenance(history) {
	const entries = history ?? []
	const line = entries.find((entry) => wrote(entry, 'geometry'))
	const limit = entries.find((entry) => wrote(entry, 'limit'))

	if (line === undefined) {
		return ''
	}
	if (limit === undefined) {
		return 'Línea dibujada a mano.'
	}
	if (wrote(line, 'limit')) {
		return `Línea fijada junto con el límite${said(line.changedAt)}.`
	}

	// Both orders happen and only one of them is an adjustment: a Limit written
	// down long after the line was settled is ordinary, and saying "después del
	// límite" about it would be false.
	return line.revision > limit.revision
		? `Línea ajustada${said(line.changedAt)}, después del límite.`
		: `Línea fijada${said(line.changedAt)}; el límite se anotó después.`
}
