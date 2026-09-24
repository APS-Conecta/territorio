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
// Exported for tests/js/twins.test.js, which pins it against the PHP seed's
// own constant (L1-27): a rename over there breaks the js that assumes it.
export const DIVISIONS_SLUG = 'division_territorial'

/** Sector's slug within it. Nothing else may be filed under this. */
export const SECTOR_SLUG = 'sector'

/**
 * The cadastre's own division — read by the clinic, never drawn by it
 * (CONTEXT.md). Named here beside Sector because the pair is what tells figure
 * from ground (#21), and because the map already needed it to open on the
 * comuna the install serves.
 */
export const UNIDAD_VECINAL_SLUG = 'unidad_vecinal'

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

/**
 * One entry per territorial division, ready to toggle.
 *
 * No colour. The division Category's seeded `#334155` is inert (#29): a
 * division draws a boundary on the map and a ring in the list, neither of which
 * is filled with it, and a copy carried here was a colour waiting to be
 * painted somewhere.
 */
export function divisionsOf(taxonomy) {
	return taxonomy.filter(isDivision).flatMap((category) => category.subcategories.map((subcategory) => ({
		id: subcategory.id,
		slug: subcategory.slug,
		label: subcategory.label,
	})))
}

/**
 * What colour stands for a whole Category — a chart bar, a legend swatch.
 *
 * A territorial division answers with the neutral instead of its own. The
 * seeded `#334155` measures 1.73:1 against the dark theme (WCAG 1.4.11 asks
 * 3:1), and issue #29 decided the division stops being a coloured mark rather
 * than being given a better colour: ADR-0013 measures the neutral band as
 * having no room for a fourth grey, so there was nowhere legible to move it to.
 */
export function categoryColour(category) {
	return isDivision(category) ? DEFAULT_SECTOR_COLOUR : category.color
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
 * Whether a record filed under this Subcategory is a Sector — and not merely
 * any territorial division.
 *
 * Asked in two places for two reasons that are the same reason: the colour
 * input is offered to a Sector alone (#24) and so is the Limit picker, because
 * a Unidad Vecinal is read from the national cadastre and never drawn
 * (CONTEXT.md). It lives here rather than once in each component so that the
 * next surface needing the distinction inherits the null guard below instead of
 * writing a third copy of the comparison.
 *
 * Null is never a Sector: `sectorSubcategoryId` answers null on an install
 * whose seed has not run, and a bare `===` between the two would then call a
 * record filed under nothing a Sector.
 */
export function isSectorSubcategory(taxonomy, subcategoryId) {
	return subcategoryId !== null && subcategoryId === sectorSubcategoryId(taxonomy)
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
	const subcategory = subcategories.get(feature.subcategoryId)

	// A territorial division never falls back to its Category's colour: see
	// categoryColour() for why `#334155` reaches no surface. A Sector nobody
	// tinted reads neutral, which is what App.vue already writes onto a new one,
	// and a Unidad Vecinal has no fill for it to reach at all (#21).
	if (subcategory !== undefined && subcategory.categorySlug === DIVISIONS_SLUG) {
		return feature.colour ?? DEFAULT_SECTOR_COLOUR
	}

	return feature.colour ?? subcategory?.colour ?? UNKNOWN_COLOUR
}

/**
 * A Subcategory's full name reads "Category · Subcategory": on its own,
 * "Otro" appears in nearly every Category and means something different in each.
 */
const fullLabel = (category, subcategory) => `${category.label} · ${subcategory.label}`

/**
 * Subcategory id → { label, colour, categoryGlyph, categorySlug, slug }.
 *
 * The Category's pictogram travels with its colour because the map and the
 * Datos list have nothing but a Subcategory id to go on: a mark stored against
 * a Category and not carried here reaches no surface at all, however correctly
 * it was written (#46).
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
				// The two halves as well as the composed name (#105). The Índice
				// draws Categoría and Subcategoría as separate columns, and
				// splitting "Category · Subcategory" back apart on the separator
				// would break on the first label containing one. They are read off
				// the taxonomy here so the index stays the one reading of it.
				categoryLabel: category.label,
				subcategoryLabel: subcategory.label,
				colour: category.color,
				// `?? null`, because a browser holding initial state from before
				// the column existed has no `glyph` key — and `undefined` would
				// reach glyphFor() as a chosen mark rather than as none.
				categoryGlyph: category.glyph ?? null,
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
