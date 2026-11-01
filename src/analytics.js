import { intersectsArea } from './areaGeometry.js'
import { countsFor, matches } from './filters.js'
import { DIVISIONS_SLUG, indexSubcategories, treeOf } from './taxonomy.js'

/** Analytics is a personal View of the complete Registry, never the map viewport. */
export function emptyAnalysisFilter() {
	return { query: '', categoryId: '', subcategoryId: '', sectorId: '', unidadVecinalId: '', areaId: '', kind: '', location: '' }
}

export function inventoryFor(features, taxonomy) {
	const subcategories = indexSubcategories(taxonomy)
	const live = features.filter(feature => !feature.retired)
	const boundaries = live.filter(feature => subcategories.get(feature.subcategoryId)?.categorySlug === DIVISIONS_SLUG)
	return {
		assets: live.filter(feature => subcategories.get(feature.subcategoryId)?.categorySlug !== DIVISIONS_SLUG),
		categories: treeOf(taxonomy),
		subcategories,
		sectors: boundaries.filter(feature => subcategories.get(feature.subcategoryId)?.slug === 'sector'),
		unidades: boundaries.filter(feature => subcategories.get(feature.subcategoryId)?.slug === 'unidad_vecinal'),
		areas: boundaries.filter(feature => subcategories.get(feature.subcategoryId)?.slug === 'custom_area'),
	}
}

export function filterInventory(inventory, filter) {
	const selectedAreas = [
		[filter.sectorId, inventory.sectors],
		[filter.unidadVecinalId, inventory.unidades],
		[filter.areaId, inventory.areas],
	].filter(([id]) => id !== '').map(([id, areas]) => areas.find(area => String(area.id) === String(id)))
	const category = inventory.categories.find(category => String(category.id) === String(filter.categoryId))
	const categoryIds = new Set(category?.subcategories.map(subcategory => subcategory.id) ?? [])
	return inventory.assets.filter(feature => matches(feature, filter.query, inventory.subcategories)
		&& (!filter.categoryId || categoryIds.has(feature.subcategoryId))
		&& (!filter.subcategoryId || String(feature.subcategoryId) === String(filter.subcategoryId))
		&& (!filter.kind || feature.kind === filter.kind)
		&& (!filter.location || (filter.location === 'located'
			? feature.geometry != null
			: locationOf(feature, inventory) === filter.location))
		&& selectedAreas.every(area => area && intersectsArea(feature.geometry, area.geometry)))
}

export function analysisSummary(inventory, features) {
	const locations = { located: 0, missing: 0, outside: 0, unassessed: 0 }
	for (const feature of features) {
		const status = locationOf(feature, inventory)
		if (status !== 'missing') locations.located++
		if (status in locations) locations[status]++
	}
	return {
		...countsFor(features, inventory.categories),
		locations,
		sectors: inventory.sectors.map(sector => ({
			id: sector.id,
			label: sector.name,
			total: features.filter(feature => intersectsArea(feature.geometry, sector.geometry)).length,
		})),
	}
}

export const ANALYSIS_KINDS = [
	{ id: 'place', label: 'Lugar' },
	{ id: 'zone', label: 'Zona' },
]

export const ANALYSIS_LOCATIONS = [
	{ id: 'located', label: 'Con ubicación' },
	{ id: 'missing', label: 'Sin ubicación' },
	{ id: 'outside', label: 'Fuera de sectores conocidos' },
	{ id: 'unassessed', label: 'Sin límites para comparar' },
]

export function analysisChips(inventory, filter) {
	const options = [
		['query', 'Búsqueda', null],
		['categoryId', 'Categoría', inventory.categories],
		['subcategoryId', 'Subcategoría', inventory.categories.flatMap(category => category.subcategories)],
		['sectorId', 'Sector', inventory.sectors],
		['unidadVecinalId', 'Unidad Vecinal', inventory.unidades],
		['areaId', 'Área personalizada', inventory.areas],
		['kind', 'Tipo', ANALYSIS_KINDS],
		['location', 'Ubicación', ANALYSIS_LOCATIONS],
	]
	return options.filter(([key]) => String(filter[key] ?? '').trim() !== '').map(([key, label, choices]) => {
		const choice = choices?.find(choice => String(choice.id) === String(filter[key]))
		const value = choices === null ? filter[key] : (choice?.label ?? choice?.name ?? 'No disponible')
		return { key, label: `${label}: ${value}` }
	})
}

function locationOf(feature, inventory) {
	if (feature.geometry == null) return 'missing'
	const sectors = inventory.sectors.filter(sector => sector.geometry != null)
	if (sectors.length === 0) return 'unassessed'
	return sectors.some(sector => intersectsArea(feature.geometry, sector.geometry)) ? 'inside' : 'outside'
}
