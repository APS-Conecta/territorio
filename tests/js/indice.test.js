import { describe, expect, it } from 'vitest'
import { COLUMNS, QUALITY_LABELS, rowsFor, sortRows } from '../../src/indice.js'
import { indexSubcategories } from '../../src/taxonomy.js'

/**
 * The Índice is the Registry read record by record (#105). Everything it can be
 * tested without a browser for lives here: what a row says, and what clicking a
 * header does to the order.
 *
 * It narrows nothing. The filtered set arrives already filtered — `filters.js`
 * says why a second narrowing path is a mistake — so there is no test here for
 * membership, on purpose: sorting changes the order and never the rows.
 */
const TAXONOMY = [
	{
		id: 1,
		slug: 'salud',
		label: 'Salud',
		color: '#dc2626',
		subcategories: [
			{ id: 11, slug: 'cesfam', label: 'CESFAM' },
		],
	},
	{
		id: 2,
		slug: 'educacion',
		label: 'Educación',
		color: '#2563eb',
		subcategories: [
			{ id: 21, slug: 'colegio', label: 'Colegio' },
		],
	},
	{
		id: 3,
		slug: 'division_territorial',
		label: 'División territorial',
		color: '#334155',
		subcategories: [
			{ id: 31, slug: 'sector', label: 'Sector' },
			{ id: 32, slug: 'unidad_vecinal', label: 'Unidad Vecinal' },
		],
	},
]

const SUBCATEGORIES = indexSubcategories(TAXONOMY)

/** The Sectores and Unidades Vecinales the app holds, as App.vue derives them. */
const BOUNDARIES = [
	{ id: 900, subcategoryId: 31, name: 'Sector Norte' },
	{ id: 901, subcategoryId: 31, name: 'Sector Sur' },
	{ id: 950, subcategoryId: 32, name: 'UV 12' },
]

const feature = (extra) => ({
	id: 1,
	name: 'Plaza',
	subcategoryId: 11,
	sectorId: null,
	unidadVecinalId: null,
	address: '',
	phone: '',
	email: '',
	locationQuality: 'exacta',
	revision: 3,
	...extra,
})

/** The row a feature becomes, for the assertions that only want its cells. */
const cellsOf = (extra) => rowsFor([feature(extra)], SUBCATEGORIES, BOUNDARIES)[0].cells

describe('the columns the Índice offers', () => {
	it('is the set the issue names, in that order', () => {
		expect(COLUMNS.map((column) => column.label)).toStrictEqual([
			'Nombre',
			'Categoría',
			'Subcategoría',
			'Sector',
			'Unidad Vecinal',
			'Dirección',
			'Teléfono',
			'Correo',
			'Persona de contacto',
			'Exactitud',
			'Revisión',
		])
	})

	it('names every column a row actually carries', () => {
		const cells = cellsOf({})

		COLUMNS.forEach((column) => {
			expect(cells).toHaveProperty(column.key)
		})
	})
})

describe('what a row says', () => {
	/**
	 * The criterion: never an id. A stored assignment is a Feature id and a
	 * stored classification is a Subcategory id, and a table that printed either
	 * would be a table nobody can read.
	 */
	it('resolves the classification into its two columns', () => {
		expect(cellsOf({ subcategoryId: 21 })).toMatchObject({
			category: 'Educación',
			subcategory: 'Colegio',
		})
	})

	it('resolves both territorial assignments through the boundaries it is handed', () => {
		expect(cellsOf({ sectorId: 901, unidadVecinalId: 950 })).toMatchObject({
			sector: 'Sector Sur',
			unidadVecinal: 'UV 12',
		})
	})

	/**
	 * The sidebar's own word for the same fact (FeatureDetail.nameOf), so the two
	 * surfaces do not describe one record differently. Both real answers — no
	 * coordinate, and a coordinate outside every boundary — land here.
	 */
	it('says «sin asignar» rather than printing nothing for an unassigned record', () => {
		expect(cellsOf({ sectorId: null })).toMatchObject({ sector: 'sin asignar' })
	})

	it('says it for an assignment whose boundary is not among the ones it holds', () => {
		expect(cellsOf({ sectorId: 404 })).toMatchObject({ sector: 'sin asignar' })
	})

	/**
	 * FeatureList's rule, kept: a broken reference must not read «Sin
	 * clasificar», which is a real seeded Category — that would make a pointer at
	 * nothing look like a record somebody correctly left undecided.
	 */
	it('refuses to call a broken classification «Sin clasificar»', () => {
		expect(cellsOf({ subcategoryId: 999 }).category).toBe('Clasificación desconocida')
		expect(cellsOf({ subcategoryId: 999 }).category).not.toContain('Sin clasificar')
	})

	it('spells out the Exactitud rather than passing the stored value through', () => {
		expect(cellsOf({ locationQuality: 'aproximada' }).quality).toBe('Aproximada')
		expect(cellsOf({ locationQuality: 'sin_coordenada' }).quality).toBe('Sin coordenada')
		expect(QUALITY_LABELS.exacta).toBe('Exacta')
	})

	it('carries the record id, because clicking the row opens that record', () => {
		expect(rowsFor([feature({ id: 77 })], SUBCATEGORIES, BOUNDARIES)[0].id).toBe(77)
	})

	it('turns a null field into an empty cell, never «null»', () => {
		expect(cellsOf({ phone: null, email: null })).toMatchObject({ phone: '', email: '' })
	})

	/**
	 * The column FeatureDetail itself offers in this position, after «Correo»:
	 * the table reads its cells the way the form offers them. Mostly empty on a
	 * catastro — the same reason the empties-sink sort exists.
	 */
	it('carries the contact person as text, and an absent one as an empty cell', () => {
		expect(cellsOf({ contactPerson: 'Violeta Fuentes' }).contactPerson).toBe('Violeta Fuentes')
		expect(cellsOf({ contactPerson: null }).contactPerson).toBe('')
	})
})

describe('sorting the Índice', () => {
	const rows = (...cells) => cells.map((cell, index) => ({ id: index, cells: cell }))

	it('is pure: it hands back a new array and leaves the one it was given alone', () => {
		const given = rows({ name: 'b' }, { name: 'a' })
		const sorted = sortRows(given, 'name', true)

		expect(sorted).not.toBe(given)
		expect(given.map((row) => row.cells.name)).toStrictEqual(['b', 'a'])
		expect(sorted.map((row) => row.cells.name)).toStrictEqual(['a', 'b'])
	})

	it('reverses when the same header is clicked again', () => {
		const given = rows({ name: 'a' }, { name: 'c' }, { name: 'b' })

		expect(sortRows(given, 'name', false).map((row) => row.cells.name))
			.toStrictEqual(['c', 'b', 'a'])
	})

	/**
	 * Sorts what is ON SCREEN, which for a Sector column is a name. Sorting the
	 * stored ids would order the column by the accident of which boundary was
	 * imported first — invisible, and wrong in a way nobody could see.
	 */
	it('orders a resolved column by its names, not by the ids behind them', () => {
		const features = [
			feature({ id: 1, sectorId: 900 }),
			feature({ id: 2, sectorId: 901 }),
		]
		const sorted = sortRows(rowsFor(features, SUBCATEGORIES, BOUNDARIES), 'sector', false)

		expect(sorted.map((row) => row.cells.sector)).toStrictEqual(['Sector Sur', 'Sector Norte'])
	})

	/**
	 * A clinic names its sectores «words, numbers or colours» (CONTEXT.md), and
	 * the numbers are the ones a codepoint sort gets wrong: "10" before "2".
	 */
	it('counts numbers in a name rather than comparing them character by character', () => {
		const given = rows({ name: 'Sector 10' }, { name: 'Sector 2' })

		expect(sortRows(given, 'name', true).map((row) => row.cells.name))
			.toStrictEqual(['Sector 2', 'Sector 10'])
	})

	/** Chilean Spanish: «Ñuñoa» sorts after «Norte», and an accent is not a letter. */
	it('sorts in Spanish, and ignores case and accents', () => {
		const given = rows({ name: 'Ñuñoa' }, { name: 'ábaco' }, { name: 'Norte' })

		expect(sortRows(given, 'name', true).map((row) => row.cells.name))
			.toStrictEqual(['ábaco', 'Norte', 'Ñuñoa'])
	})

	/**
	 * Most of these columns are empty on most records — a catastro carries few
	 * telephones — so a column sorted either way that opens on forty blank rows
	 * has told nobody anything.
	 */
	it('sinks the empty cells whichever way the column points', () => {
		const given = rows({ phone: '' }, { phone: '221' }, { phone: '' }, { phone: '999' })

		expect(sortRows(given, 'phone', true).map((row) => row.cells.phone))
			.toStrictEqual(['221', '999', '', ''])
		expect(sortRows(given, 'phone', false).map((row) => row.cells.phone))
			.toStrictEqual(['999', '221', '', ''])
	})

	/** Stable, so two records with the same Category keep the Registry's order. */
	it('leaves records that tie in the order it was given them', () => {
		const given = [
			{ id: 5, cells: { category: 'Salud' } },
			{ id: 3, cells: { category: 'Salud' } },
			{ id: 9, cells: { category: 'Salud' } },
		]

		expect(sortRows(given, 'category', true).map((row) => row.id)).toStrictEqual([5, 3, 9])
	})
})
