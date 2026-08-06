/**
 * One reading of the Category tree, shared by everything that needs it.
 *
 * The list, the map and the form each want a slightly different slice of the
 * same thing — a label, a colour, a set of options — and building each one
 * separately is how the map ends up drawing a colour the legend disagrees with.
 */

/** Used when a Feature points at a Subcategory the taxonomy no longer has. */
export const UNKNOWN_COLOUR = '#6b7280'

/**
 * A Subcategory's full name reads "Category · Subcategory": on its own,
 * "Otro" appears in nearly every Category and means something different in each.
 */
const fullLabel = (category, subcategory) => `${category.label} · ${subcategory.label}`

/**
 * Subcategory id → { label, colour, categorySlug, slug }.
 *
 * @param {Array} taxonomy the Category tree from initial state
 * @return {Map} indexed by Subcategory id
 */
export function indexSubcategories(taxonomy) {
	const index = new Map()
	taxonomy.forEach((category) => {
		category.subcategories.forEach((subcategory) => {
			index.set(subcategory.id, {
				label: fullLabel(category, subcategory),
				colour: category.color,
				categorySlug: category.slug,
				slug: subcategory.slug,
			})
		})
	})
	return index
}

/** Flat, ordered options for the Subcategory picker. */
export function subcategoryOptions(taxonomy) {
	return taxonomy.flatMap((category) => category.subcategories.map((subcategory) => ({
		id: subcategory.id,
		label: fullLabel(category, subcategory),
	})))
}

/**
 * What a brand-new record should be classified as.
 *
 * Explicitly "Sin clasificar · Otro", never simply the first entry: Categories
 * are ordered by label, so the first one is "Comercio y economía · Feria", and a
 * user who adds a colegio without touching the picker would silently file it as
 * a market stall. "Unclassified" is the only honest default — it is a real
 * seeded Category that says nobody has decided yet.
 *
 * @param {Array} taxonomy the Category tree from initial state
 * @return {?number} the Subcategory id to start from
 */
export function defaultSubcategoryId(taxonomy) {
	const unclassified = taxonomy.find((category) => category.slug === 'sin_clasificar')
	const fallback = unclassified?.subcategories?.[0] ?? taxonomy[0]?.subcategories?.[0]

	return fallback?.id ?? null
}
