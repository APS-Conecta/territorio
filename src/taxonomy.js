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
 * The Category holding the territorial divisions — Sector now, Unidad Vecinal
 * when the cadastre arrives. Matched by slug, which is the stable identifier: a
 * clinic may rename the label, and ids differ per install.
 */
const DIVISIONS_SLUG = 'division_territorial'

/** Sector's slug within it. Nothing else may be filed under this. */
const SECTOR_SLUG = 'sector'

/**
 * What a Sector is coloured when nobody has chosen. Any colour would do — it is
 * only somewhere to begin, and the picker is right there in the sidebar. Shared
 * so the swatch the form shows is the value that will be saved: a colour input
 * has no empty state, and left to itself it would show black and store nothing.
 */
export const DEFAULT_SECTOR_COLOUR = '#2563eb'

export function isDivision(category) {
	return category.slug === DIVISIONS_SLUG
}

/**
 * The Category tree as the Capas panel draws it: everything except the
 * territorial divisions, which get a toggle each beside the tree rather than a
 * branch inside it (CONTEXT.md).
 */
export function treeOf(taxonomy) {
	return taxonomy.filter((category) => !isDivision(category))
}

/** One entry per territorial division, ready to toggle. */
export function divisionsOf(taxonomy) {
	return taxonomy.filter(isDivision).flatMap((category) => category.subcategories.map((subcategory) => ({
		id: subcategory.id,
		slug: subcategory.slug,
		label: subcategory.label,
		colour: category.color,
	})))
}

/**
 * Where a newly drawn Sector is filed.
 *
 * Null rather than a guess when the Subcategory is missing: the seed that puts
 * it there runs on install and on every upgrade, so its absence means something
 * is wrong, and filing sectores under whichever Category sorts first would hide
 * that behind a plausible-looking record.
 */
export function sectorSubcategoryId(taxonomy) {
	const sector = divisionsOf(taxonomy).find((division) => division.slug === SECTOR_SLUG)

	return sector?.id ?? null
}

/**
 * The extent of everything filed under one Subcategory, as Leaflet wants it:
 * `[[south, west], [north, east]]`, or null when there is nothing to measure.
 *
 * Used to open the map on the comuna the install actually serves, from the
 * Unidades Vecinales it imported. Computed here rather than asked of the server
 * because the client already holds those polygons — they are ordinary Features —
 * so a round trip would buy nothing.
 *
 * ponytail: walks every position of every matching Feature. One comuna is a few
 * dozen polygons and it runs once per page load; store a bounding box on the
 * Dataset if a national install ever appears.
 */
export function boundsOf(features, subcategories, slug) {
	let south = Infinity
	let west = Infinity
	let north = -Infinity
	let east = -Infinity

	const stretch = (node) => {
		// Anything that is not a list of positions is not ours to measure — a
		// GeometryCollection, or a `coordinates: null` the reader let through
		// because it only checks that `geometry` is an object. Reading node[0] of
		// one throws inside mounted(), before the tile layer, and the map never
		// appears at all.
		if (!Array.isArray(node)) {
			return
		}

		// A position is [lon, lat]; everything above it is a list of those, however
		// deeply a Polygon's rings nest them.
		if (typeof node[0] === 'number') {
			const [lon, lat] = node
			south = Math.min(south, lat)
			north = Math.max(north, lat)
			west = Math.min(west, lon)
			east = Math.max(east, lon)
			return
		}
		node.forEach(stretch)
	}

	features.forEach((feature) => {
		if (subcategories.get(feature.subcategoryId)?.slug === slug && feature.geometry) {
			stretch(feature.geometry.coordinates)
		}
	})

	return south === Infinity ? null : [[south, west], [north, east]]
}

/**
 * What colour to draw a record in: its own if it has one, otherwise its
 * Category's.
 *
 * Sectores are why the first case exists — they all share one Subcategory, so a
 * per-Category colour would draw every Sector the same. Everything else stays
 * coloured by its Category with nothing to set by hand.
 */
export function colourOf(feature, subcategories) {
	return feature.colour ?? subcategories.get(feature.subcategoryId)?.colour ?? UNKNOWN_COLOUR
}

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
