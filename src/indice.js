/**
 * The Índice: the Registry read record by record, across its fields (#105).
 *
 * The map answers "where", the Datos list answers "what is there" one card at a
 * time, and Análisis answers "how many". None of them lets somebody read the
 * catastro down a column, which is what checking a municipal list against this
 * one actually looks like.
 *
 * It narrows NOTHING. `filters.js` holds the one filtered set and explains why a
 * second narrowing path is a mistake — the table would show one number while
 * Exportar exported another — so the already-filtered array arrives as a prop and
 * everything here only reorders it. Sorting is safe precisely because it changes
 * the order and never the membership.
 *
 * Pure, and a module rather than component methods, for the reason a11y.js is a
 * module: vitest runs in node and loads no component, so logic left inside a
 * `.vue` file has the browser gate as its only proof.
 */

/**
 * What the stored `locationQuality` is called on screen (`LocationQuality` in
 * PHP).
 *
 * Here rather than a fourth copy: FeatureHistory had the only full one and reads
 * this now. FeatureDetail keeps a list of its own on purpose — that one is the
 * two values a person may CHOOSE, and `sin_coordenada` is derived from having no
 * geometry and must never appear in a picker.
 */
export const QUALITY_LABELS = {
	exacta: 'Exacta',
	aproximada: 'Aproximada',
	sin_coordenada: 'Sin coordenada',
}

/**
 * The columns, in the order they are drawn, and the only place their labels
 * live — the header row, the show/hide checkboxes and the cells of every row all
 * read this one list.
 *
 * `Revisión` and not a «fecha de modificación»: a Feature carries no timestamp
 * to the client, only its integer revision. The date lives in the revision log,
 * one request per row, and is its own issue.
 */
export const COLUMNS = [
	{ key: 'name', label: 'Nombre' },
	{ key: 'category', label: 'Categoría' },
	{ key: 'subcategory', label: 'Subcategoría' },
	{ key: 'sector', label: 'Sector' },
	{ key: 'unidadVecinal', label: 'Unidad Vecinal' },
	{ key: 'address', label: 'Dirección' },
	{ key: 'phone', label: 'Teléfono' },
	{ key: 'email', label: 'Correo' },
	{ key: 'quality', label: 'Exactitud' },
	{ key: 'revision', label: 'Revisión' },
]

/** An absent field is an empty cell, never the string "null". */
const text = (value) => (value === null || value === undefined ? '' : String(value))

/**
 * A boundary's name, from the records the app already holds — the sidebar's own
 * rule and its own word (FeatureDetail.nameOf), so the table and the record it
 * opens never describe one assignment differently. No coordinate and a
 * coordinate outside every boundary are the same fact from here: nothing to
 * show.
 */
const boundaryName = (names, id) => names.get(id) ?? 'sin asignar'

/**
 * The filtered Features as rows of text, ready to draw and ready to sort.
 *
 * Text, because the criterion is that Sector, Unidad Vecinal, Categoría and
 * Subcategoría render as names and never as ids — and because sorting the
 * resolved value is the only sort that matches what a person sees. Ordering the
 * Sector column by the stored id would order it by which boundary happened to be
 * imported first: invisible, and wrong in a way nobody on screen could catch.
 *
 * @param {Array} features the already-filtered set, in Registry order
 * @param {Map} subcategories the index from `indexSubcategories()`
 * @param {Array} boundaries the Sectores and Unidades Vecinales the app holds
 * @return {Array} one `{ id, cells }` per Feature
 */
export function rowsFor(features, subcategories, boundaries) {
	const names = new Map(boundaries.map((boundary) => [boundary.id, boundary.name]))

	return features.map((feature) => {
		const subcategory = subcategories.get(feature.subcategoryId)

		return {
			// The Feature's own id: a click on the row opens that record, through
			// the same `select` every other surface emits.
			id: feature.id,
			cells: {
				name: text(feature.name),
				// A Subcategory the taxonomy no longer has is a broken reference,
				// and FeatureList's rule holds here: it must not read «Sin
				// clasificar», which is a real seeded Category — saying so would
				// make a pointer at nothing look like a record somebody correctly
				// left undecided. One missing id breaks both columns, so the
				// sentence is said once, in the first of them, rather than twice in
				// every row.
				category: subcategory?.categoryLabel ?? 'Clasificación desconocida',
				subcategory: subcategory?.subcategoryLabel ?? '',
				sector: boundaryName(names, feature.sectorId),
				unidadVecinal: boundaryName(names, feature.unidadVecinalId),
				address: text(feature.address),
				phone: text(feature.phone),
				email: text(feature.email),
				quality: QUALITY_LABELS[feature.locationQuality] ?? '',
				revision: text(feature.revision),
			},
		}
	})
}

/**
 * The rows in the order a clicked header asks for.
 *
 * Pure and returning a new array: the component re-derives this from the
 * filtered prop on every change, and sorting the prop's own array in place would
 * reorder the set the map and Análisis read.
 *
 * `localeCompare` in Spanish rather than `<`: «Ñuñoa» sorts after «Norte» only
 * under Spanish collation, `numeric` is what puts "Sector 2" before "Sector 10"
 * — a clinic names its sectores "words, numbers or colours" (CONTEXT.md) — and
 * `sensitivity: 'base'` is the same accent- and case-blindness the search
 * already folds for, so the two agree about what is "the same letter".
 *
 * @param {Array} rows what `rowsFor()` built
 * @param {string} key which column's cell to compare
 * @param {boolean} ascending first click ascending, the next one descending
 * @return {Array} a new array, sorted
 */
export function sortRows(rows, key, ascending) {
	const direction = ascending ? 1 : -1

	return [...rows].sort((a, b) => {
		const left = a.cells[key]
		const right = b.cells[key]

		if (left === right) {
			// Stable, so records that tie keep the order the Registry gave them.
			return 0
		}

		// Empty cells sink whichever way the column points. Most of these columns
		// are empty on most records — a catastro carries few telephone numbers —
		// so a descending sort that opens on forty blank rows tells nobody
		// anything, and a person clicking «Teléfono» is asking to see the numbers.
		if (left === '') {
			return 1
		}
		if (right === '') {
			return -1
		}

		return direction * left.localeCompare(right, 'es', { numeric: true, sensitivity: 'base' })
	})
}
