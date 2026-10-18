import { describe, expect, it } from 'vitest'
import {
	matchOmnibox,
} from '../../src/assets.js'

describe('Omnibox Unified Matcher (pure logic)', () => {
	const sampleSubcategories = new Map([
		[11, { id: 11, label: 'CESFAM', categoryLabel: 'Salud' }],
		[21, { id: 21, label: 'Colegio', categoryLabel: 'Educación' }],
	])

	const sampleAssets = [
		{
			id: 1,
			name: 'CESFAM Central',
			subcategoryId: 11,
			address: 'Av. Libertador 100',
			lat: -33.44,
			lon: -70.65,
		},
		{
			id: 2,
			name: 'Colegio Libertador',
			subcategoryId: 21,
			address: 'Calle Nueva 20',
			lat: -33.45,
			lon: -70.66,
		},
		{
			id: 3,
			name: 'Farmacia Libertador',
			subcategoryId: 11,
			address: 'Pasaje Corto 5',
			lat: -33.46,
			lon: -70.67,
		},
	]

	const sampleStreets = [
		{
			name: 'Av. Libertador Bernardo O’Higgins',
			bounds: [[-33.45, -70.67], [-33.43, -70.63]],
			coordinates: [[-33.45, -70.67], [-33.43, -70.63]],
		},
		{
			name: 'Libertad',
			bounds: [[-33.44, -70.68], [-33.42, -70.68]],
			coordinates: [[-33.44, -70.68], [-33.42, -70.68]],
		},
		{
			name: 'Av. Providencia',
			bounds: [[-33.43, -70.62], [-33.42, -70.60]],
			coordinates: [[-33.43, -70.62], [-33.42, -70.60]],
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
})
