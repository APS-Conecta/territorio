import { describe, expect, it } from 'vitest'
import {
	matchOmnibox,
} from '../../src/assets.js'

describe('Omnibox Unified Matcher (pure logic)', () => {
	const sampleSubcategories = new Map([
		// The shape indexSubcategories() builds: `label` is the composed name.
		[11, { label: 'Salud · CESFAM', subcategoryLabel: 'CESFAM', categoryLabel: 'Salud' }],
		[21, { label: 'Educación · Colegio', subcategoryLabel: 'Colegio', categoryLabel: 'Educación' }],
	])

	const sampleAssets = [
		{
			id: 1,
			name: 'CESFAM Central',
			subcategoryId: 11,
			address: 'Av. Libertador 100',
		},
		{
			id: 2,
			name: 'Colegio Libertador',
			subcategoryId: 21,
			address: 'Calle Nueva 20',
		},
		{
			id: 3,
			name: 'Farmacia Libertador',
			subcategoryId: 11,
			address: 'Pasaje Corto 5',
		},
	]

	const sampleStreets = [
		{
			name: 'Av. Libertador Bernardo O’Higgins',
			south: -33.45, west: -70.67, north: -33.43, east: -70.63, count: 12,
		},
		{
			name: 'Libertad',
			south: -33.44, west: -70.68, north: -33.42, east: -70.68, count: 3,
		},
		{
			name: 'Av. Providencia',
			south: -33.43, west: -70.62, north: -33.42, east: -70.60, count: 7,
		},
	]

	it('returns empty array when query is empty or whitespace', () => {
		expect(matchOmnibox('', { assets: sampleAssets, streets: sampleStreets, subcategories: sampleSubcategories })).toEqual([])
		expect(matchOmnibox('   ', { assets: sampleAssets, streets: sampleStreets, subcategories: sampleSubcategories })).toEqual([])
		expect(matchOmnibox(null, { assets: sampleAssets, streets: sampleStreets, subcategories: sampleSubcategories })).toEqual([])
	})

	it('matches both assets and streets in a single unified list', () => {
		const results = matchOmnibox('Libertador', {
			assets: sampleAssets,
			streets: sampleStreets,
			subcategories: sampleSubcategories,
		})

		const kinds = results.map(r => r.kind)
		expect(kinds).toContain('asset')
		expect(kinds).toContain('street')
	})

	it('prioritizes exact prefix matches over substring matches', () => {
		const results = matchOmnibox('Libertad', {
			assets: sampleAssets,
			streets: sampleStreets,
			subcategories: sampleSubcategories,
		})

		// "Libertad" street starts with "libertad" exactly, so it should rank higher than "Colegio Libertador"
		expect(results[0].name).toBe('Libertad')
	})

	it('respects the limit option', () => {
		const results = matchOmnibox('Libertador', {
			assets: sampleAssets,
			streets: sampleStreets,
			subcategories: sampleSubcategories,
			limit: 2,
		})
		expect(results.length).toBe(2)
	})

	it('decorates matched assets with their category and subcategory labels', () => {
		const results = matchOmnibox('CESFAM Central', {
			assets: sampleAssets,
			streets: sampleStreets,
			subcategories: sampleSubcategories,
		})
		expect(results[0].kind).toBe('asset')
		expect(results[0].subcategoryLabel).toBe('CESFAM')
		expect(results[0].categoryLabel).toBe('Salud')
	})

	it('keeps the street edges the server sent, which goToStreet fits the map to', () => {
		const [street] = matchOmnibox('Providencia', {
			assets: sampleAssets,
			streets: sampleStreets,
			subcategories: sampleSubcategories,
		})
		expect(street).toMatchObject({
			kind: 'street',
			name: 'Av. Providencia',
			south: -33.43,
			west: -70.62,
			north: -33.42,
			east: -70.60,
		})
	})

	it('identifies an asset by id alone, since its position lives in its geometry', () => {
		const [asset] = matchOmnibox('CESFAM Central', {
			assets: sampleAssets,
			streets: sampleStreets,
			subcategories: sampleSubcategories,
		})
		expect(asset.id).toBe(1)
		expect(asset).not.toHaveProperty('lat')
		expect(asset).not.toHaveProperty('lon')
	})

	it('badges an asset with its own subcategory label, not the composed one', () => {
		const [asset] = matchOmnibox('Colegio Libertador', {
			assets: sampleAssets,
			streets: sampleStreets,
			subcategories: sampleSubcategories,
		})
		expect(asset.subcategoryLabel).toBe('Colegio')
		expect(asset.categoryLabel).toBe('Educación')
	})
})
