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
import { fold } from './filters.js'

/**
 * Which of two street names is the closer match to what somebody typed: the
 * shorter one, then alphabetical.
 *
 * Someone typing "los toros" wants "Los Toros" above "Pasaje Los Toros Norte",
 * and a shorter name is the closer match to what they typed. Exported because
 * the street search ranks by the same rule (streets.js): one app, one idea of
 * which street comes first.
 */
export const closerFirst = (a, b) => a.name.length - b.name.length || a.name.localeCompare(b.name)

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

	// Keyed on the folded name so "LOS TOROS" and "Los Toros" are one row, and
	// labelled with the first spelling seen.
	//
	// ponytail: grouped by name alone. Two genuinely different streets in one
	// comuna that share a name collapse into a single row, and picking it picks
	// both — the map is the way out, since clicking a line toggles it on its own.
	// The street search does tell them apart (BoundaryService::names() splits a
	// name by place), but this picker holds only the lines in view, where that
	// collision is rare. Adopt the split here when somebody hits it.
	const groups = new Map()
	boundaries.forEach((boundary) => {
		const key = fold(boundary.name)
		if (!key.includes(needle)) {
			return
		}
		const group = groups.get(key) ?? { name: boundary.name, ids: [] }
		group.ids.push(boundary.id)
		groups.set(key, group)
	})

	return [...groups.values()]
		.sort(closerFirst)
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
 * A boundary's name, from the records the app already holds.
 *
 * «Sin asignar» covers both real answers — no coordinate, and a coordinate
 * outside every boundary — because from here they are the same fact: nothing to
 * show. One copy, because the sidebar and the Índice must never describe one
 * assignment differently, and two copies of a wording is how they come to.
 */
export const nameOf = (boundaries, id) => {
	if (id === null || id === undefined) {
		return 'sin asignar'
	}
	if (boundaries instanceof Map) {
		return boundaries.get(id)?.name ?? 'sin asignar'
	}
	return boundaries.find((boundary) => boundary.id === id)?.name ?? 'sin asignar'
}
