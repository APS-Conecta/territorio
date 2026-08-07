/**
 * Picking the lines a Sector's Limit runs along.
 *
 * ADR-0007's whole trick is refusing to resolve street NAMES. "Which Los
 * Toros?" and "which three kilometres of Vicuña Mackenna?" are settled by the
 * person in one click, not guessed by a geocoder — so everything here hands back
 * candidates for someone to choose between, and nothing here ever picks one.
 *
 * Pure, so it can be tested at all: the picking itself lives in a component and
 * the deciding lives here.
 */

/** Folded for comparison, exactly as the Registry search folds (filters.js). */
const fold = (value) => String(value ?? '')
	.normalize('NFD')
	.replace(/\p{Diacritic}/gu, '')
	.toLowerCase()

/**
 * What someone typing has to choose between.
 *
 * Grouped by name, because a comuna holds one street as dozens of separate ways
 * — "Vicuña Mackenna" is not one object — and offering thirty identical rows is
 * not a choice anybody can make. Each group carries every matching line, so
 * picking the name picks all of it, and the count says how much that is.
 *
 * Returns [] for an empty query rather than everything: eleven thousand rows is
 * not a suggestion.
 */
export function suggestions(boundaries, query, limit = 12) {
	const needle = fold(query).trim()
	if (needle === '') {
		return []
	}

	const groups = new Map()
	boundaries.forEach((boundary) => {
		if (!fold(boundary.name).includes(needle)) {
			return
		}
		const group = groups.get(boundary.name) ?? { name: boundary.name, ids: [] }
		group.ids.push(boundary.id)
		groups.set(boundary.name, group)
	})

	// Shortest name first: someone typing "los toros" wants "Los Toros" above
	// "Pasaje Los Toros Norte", and a shorter name is the closer match to what
	// they typed.
	return [...groups.values()]
		.sort((a, b) => a.name.length - b.name.length || a.name.localeCompare(b.name))
		.slice(0, limit)
}

/**
 * A selection with one id added or removed.
 *
 * Toggling, because that is what clicking a line on the map means: the same
 * click selects and deselects, and a person should not have to remember which
 * mode they are in.
 */
export function togglePick(picked, id) {
	return picked.includes(id)
		? picked.filter((each) => each !== id)
		: [...picked, id]
}

/**
 * Every id in a group added, or — if the whole group is already picked — every
 * one removed.
 *
 * Picking by name and picking by clicking have to land in the same place, which
 * is the criterion; this is the same toggle, applied to the objects a name
 * stands for rather than to one of them.
 */
export function toggleGroup(picked, ids) {
	const all = ids.every((id) => picked.includes(id))

	return all
		? picked.filter((id) => !ids.includes(id))
		: [...picked, ...ids.filter((id) => !picked.includes(id))]
}

/**
 * The picked lines, grouped for display, in the order they were picked.
 *
 * Grouped like the suggestions are: a Limit reads "Vicuña Mackenna, the rail
 * line, Departamental", not as ninety numbered fragments.
 */
export function pickedGroups(boundaries, picked) {
	const byId = new Map(boundaries.map((boundary) => [boundary.id, boundary]))
	const groups = new Map()

	picked.forEach((id) => {
		const boundary = byId.get(id)
		// A line that has scrolled out of the loaded view is still picked; it just
		// cannot be named yet. Dropping it would silently unpick it.
		const name = boundary?.name ?? null
		const key = name ?? `#${id}`
		const group = groups.get(key) ?? { name, ids: [] }
		group.ids.push(id)
		groups.set(key, group)
	})

	return [...groups.values()]
}
