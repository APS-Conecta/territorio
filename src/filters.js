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
 * Every function here is pure and returns a new filter, so a filter can be
 * compared, replayed or thrown away — and none of it ever leaves the browser.
 * The View is personal (CONTEXT.md): changing it changes nothing for anyone else.
 */

/** Nothing hidden: everything is on screen. */
export function emptyFilter() {
	return { hidden: new Set() }
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

	return { hidden }
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

	return { hidden }
}

/**
 * What the Category's checkbox should show: 'all', 'none', or 'some' for the
 * indeterminate state.
 *
 * A Category with no Subcategories is 'none' — it can show nothing, because
 * there is nothing under it to show.
 */
export function categoryState(filter, category) {
	const ids = category.subcategories.map((subcategory) => subcategory.id)
	if (ids.length === 0) {
		return 'none'
	}

	const visible = ids.filter((id) => isSubcategoryVisible(filter, id)).length
	if (visible === 0) {
		return 'none'
	}

	return visible === ids.length ? 'all' : 'some'
}

export function filterFeatures(features, filter) {
	return features.filter((feature) => isSubcategoryVisible(filter, feature.subcategoryId))
}

/**
 * Counts for Análisis, over whatever set it is handed.
 *
 * It does no filtering of its own — it is given the already-filtered features,
 * which is what stops the numbers drifting away from the list and the map.
 *
 * Categories with nothing in them are kept so the legend does not reshuffle
 * every time a filter changes; a Feature whose Subcategory the taxonomy no
 * longer has is counted under `unclassified` rather than dropped, because a
 * total that quietly disagrees with the list is worse than an awkward one.
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
			color: category.color,
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
