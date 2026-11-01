import { describe, expect, it } from 'vitest'
import { emptyAnalysisFilter, inventoryFor, filterInventory, analysisSummary, analysisChips } from '../../src/analytics.js'

const taxonomy = [
	{ id: 1, slug: 'salud', label: 'Salud', color: '#b91c1c', subcategories: [{ id: 11, slug: 'farmacia', label: 'Farmacia' }] },
	{ id: 2, slug: 'educacion', label: 'Educación', color: '#1d4ed8', subcategories: [{ id: 21, slug: 'colegio', label: 'Colegio' }] },
	{ id: 3, slug: 'division_territorial', label: 'Divisiones', subcategories: [
		{ id: 31, slug: 'sector', label: 'Sector' },
		{ id: 32, slug: 'unidad_vecinal', label: 'Unidad Vecinal' },
		{ id: 33, slug: 'custom_area', label: 'Área personalizada' },
	] },
]
const point = (x, y) => ({ type: 'Point', coordinates: [x, y] })
const square = (left, bottom, right, top) => ({ type: 'Polygon', coordinates: [[[left, bottom], [right, bottom], [right, top], [left, top], [left, bottom]]] })
const records = [
	{ id: 1, name: 'Farmacia Peñaflor', subcategoryId: 11, kind: 'place', geometry: point(1, 1) },
	{ id: 2, name: 'Escuela sin ubicación', subcategoryId: 21, kind: 'place', geometry: null },
	{ id: 3, name: 'Escuela fuera', subcategoryId: 21, kind: 'place', geometry: point(20, 20) },
	{ id: 4, name: 'Parque educativo', subcategoryId: 21, kind: 'zone', geometry: square(2, 2, 5, 5) },
	{ id: 5, name: 'Retirado', subcategoryId: 11, geometry: point(1, 1), retired: true },
	{ id: 10, name: 'Sector A', subcategoryId: 31, geometry: square(0, 0, 3, 3) },
	{ id: 11, name: 'Sector B', subcategoryId: 31, geometry: square(0, 0, 4, 4) },
	{ id: 12, name: 'Unidad 1', subcategoryId: 32, geometry: square(0, 0, 3, 3) },
	{ id: 13, name: 'Área A', subcategoryId: 33, geometry: square(0, 0, 3, 3) },
]

describe('community asset Analytics', () => {
	it('counts the live inventory independently of map state and excludes every geographic boundary', () => {
		const inventory = inventoryFor(records, taxonomy)
		expect(inventory.assets.map(f => f.id)).toEqual([1, 2, 3, 4])
		expect(filterInventory(inventory, emptyAnalysisFilter()).map(f => f.id)).toEqual([1, 2, 3, 4])
		expect(analysisSummary(inventory, inventory.assets).total).toBe(4)
	})
	it('combines category, subcategory, accent-insensitive search and shape without mutating the filter', () => {
		const inventory = inventoryFor(records, taxonomy)
		const filter = { ...emptyAnalysisFilter(), categoryId: '2', kind: 'place' }
		expect(filterInventory(inventory, filter).map(f => f.id)).toEqual([2, 3])
		expect(filterInventory(inventory, { ...filter, subcategoryId: '11' })).toEqual([])
		expect(filterInventory(inventory, { ...emptyAnalysisFilter(), query: 'PENAFLOR' }).map(f => f.id)).toEqual([1])
		expect(filter.kind).toBe('place')
	})
	it('includes every overlapping sector and area, including zones, without inflating inventory totals', () => {
		const inventory = inventoryFor(records, taxonomy)
		for (const selection of [{ sectorId: '10' }, { sectorId: '11' }, { unidadVecinalId: '12' }, { areaId: '13' }]) {
			expect(filterInventory(inventory, { ...emptyAnalysisFilter(), ...selection }).map(f => f.id)).toEqual([1, 4])
		}
		const summary = analysisSummary(inventory, inventory.assets)
		expect(summary.sectors.map(sector => sector.total)).toEqual([2, 2])
		expect(summary.total).toBe(4)
	})

	it('distinguishes missing locations, located assets outside sectors and an inventory with no sector limits', () => {
		const inventory = inventoryFor(records, taxonomy)
		expect(filterInventory(inventory, { ...emptyAnalysisFilter(), location: 'missing' }).map(f => f.id)).toEqual([2])
		expect(filterInventory(inventory, { ...emptyAnalysisFilter(), location: 'outside' }).map(f => f.id)).toEqual([3])
		expect(analysisSummary(inventory, inventory.assets).locations).toEqual({ located: 3, missing: 1, outside: 1, unassessed: 0 })
		const noSectors = inventoryFor(records.filter(f => f.subcategoryId !== 31), taxonomy)
		expect(analysisSummary(noSectors, noSectors.assets).locations).toEqual({ located: 3, missing: 1, outside: 0, unassessed: 3 })
	})

	it('describes every active filter and keeps removed choices visible until explicitly cleared', () => {
		const inventory = inventoryFor(records, taxonomy)
		const filter = { ...emptyAnalysisFilter(), query: 'farmacia', categoryId: '1', sectorId: '10', areaId: '999', location: 'missing' }
		expect(analysisChips(inventory, filter)).toEqual([
			{ key: 'query', label: 'Búsqueda: farmacia' },
			{ key: 'categoryId', label: 'Categoría: Salud' },
			{ key: 'sectorId', label: 'Sector: Sector A' },
			{ key: 'areaId', label: 'Área personalizada: No disponible' },
			{ key: 'location', label: 'Ubicación: Sin ubicación' },
		])
		expect(filterInventory(inventory, filter)).toEqual([])
	})

})
