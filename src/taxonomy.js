/**
 * One reading of the Category tree, shared by everything that needs it.
 *
 * The list, the map and the form each want a slightly different slice of the
 * same thing — a label, a colour, a set of options — and building each one
 * separately is how the map ends up drawing a colour the legend disagrees with.
 */

/**
 * Used when a Feature points at a Subcategory the taxonomy no longer has.
 *
 * A broken reference must look different from a record somebody correctly left
 * unclassified — the rule the list already follows by refusing to LABEL this case
 * "Sin clasificar" (FeatureList.vue). It was `#6b7280`, byte-identical to the old
 * `sin_clasificar`, so the colour said they were the same thing while the label
 * said they were not.
 *
 * Separated from it by dE 0.055 — barely. Three neutrals share the lightness band
 * where a colour clears 3:1 against both a white page and the dark theme, and
 * there is no room for a fourth. Checked by tools/ramp-check.py.
 */
export const UNKNOWN_COLOUR = '#62676c'

/**
 * The Category holding the territorial divisions — Sector now, Unidad Vecinal
 * when the cadastre arrives. Matched by slug, which is the stable identifier: a
 * clinic may rename the label, and ids differ per install.
 */
const DIVISIONS_SLUG = 'division_territorial'

/** Sector's slug within it. Nothing else may be filed under this. */
const SECTOR_SLUG = 'sector'

/**
 * What a Sector is coloured when nobody has chosen. Neutral, because a clinic
 * names its sectores "words, numbers or colours" (CONTEXT.md) and only one of
 * those three is a colour — so colour cannot be the identity. ADR-0013.
 *
 * `#949494` and not any grey: the lightest that still clears 3:1 against a white
 * page while staying dE 0.101 from `sin_clasificar`. An earlier `#7d7d7d` sat
 * dE 0.026 away, so an un-recoloured Sector read as an unclassified record —
 * which is why tools/ramp-check.py measures this constant rather than merely
 * checking it differs from the Categories.
 *
 * Not the same as absent: App.vue writes it onto a new draft, so it is stored
 * like any other colour. And it is shared with the form because a colour input
 * has no empty state — left to itself it shows black and saves nothing.
 */
export const DEFAULT_SECTOR_COLOUR = '#949494'

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
