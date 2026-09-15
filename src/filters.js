/**
 * The Capas filter: which Subcategories are currently on screen.
 *
 * There is no Layer object (CONTEXT.md) — the panel a person toggles IS the
 * Category tree, and this is the state behind it. One filter feeds the map, the
 * Datos list and the Análisis counts, so the three can never disagree about
 * what is being shown.
 *
 * The filter records what is HIDDEN, never what is visible. That is the whole
 * reason a Subcategory added to the taxonomy appears on its own: it is absent
 * from the hidden set, so it is shown, and nobody has to remember to add it
 * anywhere. Storing visible ids would make every new row invisible until some
 * code went looking for it.
 *
 * Every function here is pure and returns a new filter rather than mutating one,
 * so a component can hand its filter around without wondering who else might
 * change it. None of it ever leaves the browser: the View is personal
 * (CONTEXT.md), and changing it changes nothing for anyone else.
 */
import { categoryColour } from './taxonomy.js'

/** Nothing hidden and nothing typed: everything is on screen. */
export function emptyFilter() {
	return { hidden: new Set(), query: '' }
}

/**
 * Text as it is compared: lower-cased and stripped of accents.
 *
 * Nobody types "Peñaflor" with the tilde when they are looking for something
 * in a hurry, and a search that only matches the accented form is a search that
 * quietly finds nothing. NFD splits a letter from its diacritic so the diacritic
 * can be dropped on its own; \p{Diacritic} then covers every accent Spanish uses
 * rather than a hand-written list of five.
 */
const fold = (value) => String(value ?? '')
	.normalize('NFD')
	.replace(/\p{Diacritic}/gu, '')
	.toLowerCase()

/**
 * Whether a record answers to what someone typed.
 *
 * Matches what a person would actually reach for looking for a record: its name,
 * where it is, who to talk to, and what it is. The Subcategory index already
 * holds "Category · Subcategory" as one string, so one comparison covers both
 * levels of the taxonomy.
 *
 * ponytail: substring over folded text, no ranking and no fuzzy matching. One
 * comuna is hundreds of records in memory; reach for an index when a search
 * actually feels slow.
 */
export function matches(feature, query, subcategories) {
	const needle = fold(query).trim()
	if (needle === '') {
		return true
	}

	return [
		feature.name,
		feature.address,
		feature.contactPerson,
		subcategories.get(feature.subcategoryId)?.label,
	].some((field) => fold(field).includes(needle))
}

/**
 * Whether anything is being narrowed at all — what "Ver todo" keys off, and what
 * tells Análisis its numbers describe a slice rather than the Registry.
 *
 * A typed query counts. Someone who searched and found little needs the same way
 * back as someone who unticked half the tree.
 */
export function isFiltered(filter) {
	return filter.hidden.size > 0 || (filter.query ?? '').trim() !== ''
}

export function isSubcategoryVisible(filter, subcategoryId) {
	return !filter.hidden.has(subcategoryId)
}

export function toggleSubcategory(filter, subcategoryId) {
	const hidden = new Set(filter.hidden)
	if (hidden.has(subcategoryId)) {
		hidden.delete(subcategoryId)
	} else {
		hidden.add(subcategoryId)
	}

	// Spread, so everything else in the View survives a toggle. Returning a bare
	// `{ hidden }` dropped whatever had been typed, and someone who searched and
	// then reached for a checkbox lost their search without being told.
	return { ...filter, hidden }
}

/**
 * Clicking a Category is "show me all of this" unless all of it is already
 * shown, in which case it is "hide all of this".
 *
 * The half-hidden case is the one that matters: clicking the parent of a
 * partly-hidden branch reveals the rest rather than hiding what was left, so
 * the parent checkbox is always a way back to seeing everything.
 */
export function toggleCategory(filter, category) {
	const ids = category.subcategories.map((subcategory) => subcategory.id)
	const hidden = new Set(filter.hidden)

	if (categoryState(filter, category) === 'all') {
		ids.forEach((id) => hidden.add(id))
	} else {
		ids.forEach((id) => hidden.delete(id))
	}

	// Spread, for the reason toggleSubcategory spreads.
	return { ...filter, hidden }
}

/**
 * What the Category's checkbox should show: 'all', 'none', or 'some' for the
 * indeterminate state.
 *
 * A Category with no Subcategories is 'all': nothing under it is hidden, because
 * there is nothing under it. Reporting 'none' would draw an unchecked box next to
 * a Category that is hiding nothing — and a Category without Subcategories is
 * reachable precisely through the "add a row, change no code" path this panel
 * exists to support.
 */
export function categoryState(filter, category) {
	const ids = category.subcategories.map((subcategory) => subcategory.id)
	if (ids.length === 0) {
		return 'all'
	}

	const visible = ids.filter((id) => isSubcategoryVisible(filter, id)).length
	if (visible === 0) {
		return 'none'
	}

	return visible === ids.length ? 'all' : 'some'
}

/**
 * What the Datos list shows.
 *
 * The retired list is deliberately NOT filtered. It is the recovery screen
 * (ADR-0005), and gating it on a map filter means someone who hid Educación and
 * then retired a colegio is told "No hay registros retirados" — the record they
 * are looking for appears destroyed.
 *
 * This decision lives here rather than in the component so it can be tested;
 * when it lived in a computed property it was wrong and nothing noticed.
 */
export function listFor({ features, retiredFeatures, showRetired, filter, subcategories }) {
	if (!showRetired) {
		return filterFeatures(features, filter, subcategories)
	}

	// The search DOES reach the retired list, where the Capas toggles do not. A
	// toggle is a background setting someone forgot they left on; a query is
	// someone saying out loud what they are looking for, and ignoring it in the
	// list they are staring at would be the app overruling an explicit
	// instruction.
	return retiredFeatures.filter((feature) => matches(feature, filter.query, subcategories))
}

/**
 * The record behind an id, from the two sets the app holds rather than from any
 * one view of them.
 *
 * Selecting is not a list operation. The map draws the live Registry whatever the
 * retired toggle says, and `listFor()` above answers with the retired list alone
 * once that toggle is on — so resolving a map click against the list's answer
 * looked up a live id in a set that by design holds none, found nothing, and left
 * the pin selected with no sidebar (issue #67). The Capas filter narrows the same
 * way: it decides what is listed, never what exists.
 */
export function featureById(features, retiredFeatures, id) {
	return features.find((feature) => feature.id === id)
		?? retiredFeatures.find((feature) => feature.id === id)
}

/**
 * The one filtered set: what Capas leaves visible, narrowed again by what was
 * typed.
 *
 * Both narrowings are applied here, in one place, rather than search being a
 * mechanism of its own beside the toggles. That is what keeps the map, the Datos
 * list and the Análisis counts agreeing about what is on screen — they all read
 * this, and a second path is how they would drift apart.
 *
 * `subcategories` is the index from `indexSubcategories()`, passed in rather than
 * imported so this module stays pure and knows nothing about the taxonomy's
 * shape. Required, deliberately: defaulting it to an empty Map would let a
 * caller that forgot it return a plausible non-empty list that silently matched
 * nothing by Category or Subcategory — a wrong answer is worse than a crash.
 */
export function filterFeatures(features, filter, subcategories) {
	return features.filter((feature) => isSubcategoryVisible(filter, feature.subcategoryId)
		&& matches(feature, filter.query, subcategories))
}

/**
 * Counts for Análisis, over whatever set it is handed.
 *
 * It does no filtering of its own — it is given the already-filtered features,
 * which is what stops the numbers drifting away from the list and the map.
 *
 * Every Category is reported, including empty ones, so the caller can decide
 * whether to draw them — Análisis hides empty rows to keep the chart readable,
 * but a legend or an export may well want them. A Feature whose Subcategory the
 * taxonomy no longer has is counted under `unclassified` rather than dropped,
 * because a total that quietly disagrees with the list is worse than an awkward
 * one.
 */
export function countsFor(features, taxonomy) {
	const perSubcategory = new Map()
	features.forEach((feature) => {
		perSubcategory.set(feature.subcategoryId, (perSubcategory.get(feature.subcategoryId) ?? 0) + 1)
	})

	const known = new Set()
	const categories = taxonomy.map((category) => {
		const subcategories = category.subcategories.map((subcategory) => {
			known.add(subcategory.id)
			return {
				id: subcategory.id,
				label: subcategory.label,
				total: perSubcategory.get(subcategory.id) ?? 0,
			}
		})

		return {
			id: category.id,
			label: category.label,
			// What a bar is DRAWN in, already resolved — not the stored seed. A
			// territorial division answers with the neutral, because its `#334155`
			// measures 1.73:1 against the dark theme and ADR-0013 found no room for
			// a fourth grey to move it to (#29). Resolved here rather than in the
			// chart because a section has two bindings — the Category row and the
			// Subcategory rows under it — and resolving per binding left one of
			// them still painting the seed.
			color: categoryColour(category),
			total: subcategories.reduce((sum, subcategory) => sum + subcategory.total, 0),
			subcategories,
		}
	})

	let unclassified = 0
	perSubcategory.forEach((total, subcategoryId) => {
		if (!known.has(subcategoryId)) {
			unclassified += total
		}
	})

	return { total: features.length, categories, unclassified }
}
