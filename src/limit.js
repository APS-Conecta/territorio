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

/**
 * A described stretch's identity while one draft is open (#99).
 *
 * Never its name — a second stretch can say the same words, and so can a
 * street — and never its position, which any splice moves. A counter is
 * enough because the server's normalizer keeps `featureId` and `name` and
 * nothing else, so the stamp never survives a save and is only ever needed
 * within the session that typed it. `tramo-` is what the picker calls one.
 */
let stamps = 0

/** The next session identity for a described stretch (#99). */
function nextUid() {
	stamps += 1
	return `tramo-${stamps}`
}

/** A stretch nobody could have picked, because no dataset holds it. */
export function withNote(parts, text) {
	const said = String(text ?? '').trim()

	return said === '' ? parts : [...parts, { uid: nextUid(), name: said }]
}

/**
 * The Limit as rows a person can read: picked parts folded by name, and every
 * described stretch a row of its own (#99).
 *
 * A comuna holds one street as dozens of separate ways, so picking "Vicuña
 * Mackenna" records dozens of parts. Listing them one per row is thirty
 * identical lines with thirty identical Quitar buttons — the same list #13
 * grouped the suggestions to avoid, for the same reason. But the fold was
 * never meant for described stretches, which have no thirty-ways case: a
 * stretch typed with a street's own words is a row beside it, «a mano», and
 * Quitar on either leaves the other standing.
 *
 * `picked` says whether the row stands for Boundary features, because removing
 * one has to unpick them too, and a described stretch has nothing to unpick.
 *
 * `key` is the row's identity for the list and its Quitar button: a picked
 * row's name — unique by construction, the fold — and a described row's
 * stamp. Parts that reach here unstamped, before `withNote` or
 * `sectorFields` has seen them, still get distinct, deterministic keys by
 * position among their own name, so no two rows ever share one.
 *
 * `gone` is how many of the row's parts cite a Boundary feature the cadastre no
 * longer has (issue #51). The server says WHICH ids those are — the client
 * cannot tell «gone» from «off-screen», since `/boundaries` only ever loads what
 * is in view. Counted part by part rather than decided for the row: a street is
 * dozens of separate ways, so three of Vicuña Mackenna's thirty going away is
 * three, and calling the whole name gone would be false. A part with no
 * `featureId` cites nothing and can never be gone — that row reads «a mano»,
 * which is the other answer the picker has to keep distinct.
 *
 * @param {Array} parts the Limit as stored
 * @param {number[]} gone ids the server says are no longer live Boundary features
 */
export function groups(parts, gone = []) {
	const missing = new Set(gone)
	const rows = []
	const byName = new Map()
	const unstamped = new Map()

	;(parts ?? []).forEach((part) => {
		if (part.featureId !== undefined) {
			let row = byName.get(part.name)
			if (row === undefined) {
				row = { name: part.name, parts: 0, picked: true, gone: 0, key: part.name }
				byName.set(part.name, row)
				rows.push(row)
			}
			row.parts += 1
			// By id, so a part that cites nothing can never be gone: `featureId` is
			// undefined on a typed stretch and the server only ever names integers.
			if (missing.has(part.featureId)) {
				row.gone += 1
			}
			return
		}

		// A described stretch is one row, never folded into a same-named street
		// (#99): the «a mano» badge and its own Quitar button are what keep what
		// somebody typed apart from what the cadastre holds when the two say the
		// same words.
		const nth = (unstamped.get(part.name) ?? 0) + 1
		unstamped.set(part.name, nth)
		rows.push({
			name: part.name,
			parts: 1,
			picked: false,
			gone: 0,
			uid: part.uid,
			key: part.uid ?? `tramo:${part.name}#${nth}`,
		})
	})

	return rows
}

/**
 * Every picked part of one name removed — a street that turned out not to
 * be the limit.
 *
 * By name rather than by row, because a name is what the person sees and what
 * the sentence says. Removing one of a street's thirty ways would change nothing
 * they can read, while the tick in the picker flipped off: the screen would
 * disagree with itself, and the next pick would put it straight back.
 *
 * A described stretch saying the same words stays (#99): it is its own row,
 * and unpicking a street never took down what somebody typed.
 */
export function without(parts, name) {
	return (parts ?? []).filter((part) => part.name !== name || part.featureId === undefined)
}

/**
 * One described stretch removed, by the identity it was stamped with (#99).
 *
 * By stamp and not by name, because the name is what a stretch SAYS, not what
 * it IS: a second stretch can be saying the same words, and removing one has
 * to leave the other standing. Exactly one occurrence, not every part that
 * happens to carry the stamp — uniqueness is the stamper's contract, and
 * this is where it is kept.
 */
export function withoutNote(parts, uid) {
	let removed = false

	return (parts ?? []).filter((part) => {
		if (!removed && part.uid === uid) {
			removed = true
			return false
		}
		return true
	})
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
	// Stored parts come back bare — the server's normalizer keeps `featureId`
	// and `name` and nothing else — so every described stretch arrives without
	// an identity and is stamped one here, the only door a stored Limit comes
	// back through (`draftFrom`). Picked parts need no stamp: a `featureId` is
	// an identity nothing here mints twice.
	const parts = (limit ?? []).map((part) =>
		part.featureId === undefined && part.uid === undefined
			? { ...part, uid: nextUid() }
			: part)

	return {
		limit: parts,
		picked: parts
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

/**
 * A stored timestamp as a date is said out loud here: 12-08-2026.
 *
 * Split on either separator, and nothing said at all when the shape is not the
 * one expected. History hands this over exactly as the database returned it —
 * `RevisionLog` reads the raw column — so the separator is the driver's choice,
 * and a date rendered as «el 12T09:30:00+00:00-08-2026» is worse than a sentence
 * with no date in it.
 */
function said(changedAt) {
	const [date] = String(changedAt ?? '').split(/[ T]/)
	const parts = date.split('-')

	return parts.length === 3 ? ` el ${parts[2]}-${parts[1]}-${parts[0]}` : ''
}

/**
 * How the current line got there, for the case where no Limit stood when it was
 * written — the only branch that can name an act at all.
 *
 * Three origins, not the two the ticket names. A Sector can also be IMPORTED:
 * `ImportService::classify()` files a row under any Subcategory, Sector
 * included (CHANGELOG 0.33.0 undoes exactly that), and an import writes no
 * Limit — so a boundary the municipality published has the same Created entry
 * as a hand-drawn one, `geometry` and nothing else. History cannot tell them
 * apart and never will; the record can. `datasetId` is set on import and
 * nowhere else (`FeatureService::applyProvenance`), so a record that has one
 * came from a Dataset, and nothing here names a hand for it.
 *
 * Only the import's own creation is credited to the catastro. A later
 * `geometry` write is a re-import publishing a new official shape OR somebody
 * dragging a vertex, indistinguishable as always, so that one says WHEN.
 *
 * @param {object} line the newest revision that wrote a geometry
 * @param {boolean} imported whether the record came from a Dataset
 * @return {string} the clause, without its closing punctuation
 */
function origin(line, imported) {
	if (!imported) {
		return 'Línea dibujada a mano'
	}

	return line.action === 'created'
		? 'Línea importada del catastro'
		: `Línea guardada${said(line.changedAt)}`
}

/**
 * How this Sector's current line came to be, as one sentence (issue #50).
 *
 * It costs no column, because the revision history already holds it: every write
 * records which fields it changed, and a Sector generated from picked lines writes
 * `geometry` and `limit` in the same revision. A column would be no better anyway
 * — `faceAround()` runs in this browser and the server never witnesses
 * "generated", so a stored flag would be the client's word for the same thing.
 *
 * What history CANNOT say is by which act a later line was written. `geometry`
 * alone is what a dragged vertex writes, and also what «Generar» writes on a
 * reopened Sector whose Limit did not change — a flow #49 exists to support. So
 * where the two are indistinguishable this says WHEN the line was last written and
 * never that a hand moved it.
 *
 * Nor can history say a line was IMPORTED: an imported Sector's Created entry is
 * `geometry` and nothing else, exactly a hand-drawn one's. That is what `imported`
 * is for, and it is why this takes an argument history cannot supply — see
 * `origin()` above.
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
 * @param {boolean} imported whether the record came from a Dataset — `datasetId`
 *   on the record, which only an import sets
 * @return {string} the sentence, or '' when history says nothing about a line
 */
export function provenance(history, imported = false) {
	const entries = history ?? []
	const line = entries.find((entry) => wrote(entry, 'geometry'))
	const limit = entries.find((entry) => wrote(entry, 'limit'))

	if (line === undefined) {
		return ''
	}
	if (limit === undefined) {
		return `${origin(line, imported)}.`
	}
	if (wrote(line, 'limit')) {
		return `Línea fijada junto con el límite${said(line.changedAt)}.`
	}
	// Generating needs picked lines, so a Limit, and «Generar» saves the two in one
	// revision. A line last written while no Limit had ever been recorded was
	// therefore drawn by hand or imported — never generated — whatever somebody
	// typed afterwards; `origin()` is what tells those two apart.
	// Without this, a hand-drawn Sector stopped saying so the moment its Limit was
	// written down, and read exactly like a generated one that was later moved.
	if (!entries.some((entry) => wrote(entry, 'limit') && entry.revision <= line.revision)) {
		return `${origin(line, imported)}; el límite se anotó después.`
	}

	// A revision that wrote the two together IS the boundary's origin — «Generar»
	// saves the polygon and the Limit it came from in one revision — and it stays
	// the origin after the line is written again. Looking only at the NEWEST write
	// of each field threw it away, so a generated Sector with a dragged vertex said
	// when it was last saved and never that it came from the Limit at all (#50,
	// criterion 1). The date of the save stays, because that is criterion 3.
	const together = entries.find((entry) =>
		wrote(entry, 'geometry') && wrote(entry, 'limit') && entry.revision <= line.revision)

	if (together !== undefined) {
		const since = `Línea fijada junto con el límite${said(together.changedAt)} y guardada de nuevo${said(line.changedAt)}`

		// Two dates in order already say the line was written after the Limit, so
		// only the other order still needs a clause of its own — and there the
		// Limit was EDITED rather than first noted, because `together` proves one
		// already stood. That is why the branches below, where none ever did, say
		// «se anotó» and this one does not.
		return line.revision > limit.revision ? `${since}.` : `${since}; el límite se editó después.`
	}

	// Nothing here ever wrote the two together, so there is no origin to name — a
	// Limit typed onto a hand-drawn Sector and then «Generar» on the reopened
	// record (#49) is exactly this shape. Both orders happen and neither names an
	// act: a Limit written down long after the line was settled is ordinary, so
	// saying "después del límite" about it would be false, and the other order is
	// a drag or a regenerate indifferently.
	return line.revision > limit.revision
		? `Línea guardada${said(line.changedAt)}, después del límite.`
		: `Línea guardada${said(line.changedAt)}; el límite se anotó después.`
}
