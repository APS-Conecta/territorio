import { describe, expect, it } from 'vitest'
import {
	filterAssets,
	sortAssets,
} from '../../src/assets.js'

describe('Community Assets Filtering & Sorting (pure logic)', () => {
	const sampleSubcategories = new Map([
		[11, { id: 11, label: 'CESFAM', categoryId: 1, categoryLabel: 'Salud' }],
		[12, { id: 12, label: 'Farmacia', categoryId: 1, categoryLabel: 'Salud' }],
		[21, { id: 21, label: 'Colegio', categoryId: 2, categoryLabel: 'Educación' }],
		[31, { id: 31, label: 'Plaza', categoryId: 3, categoryLabel: 'Espacio Público' }],
	])

	const sampleFeatures = [
		{
			id: 1,
			name: 'CESFAM Bellavista',
			subcategoryId: 11,
			sectorId: 10,
			unidadVecinalId: 100,
			locationQuality: 'exact',
			address: 'Av. Providencia 1234',
			contactPerson: 'Dra. Soto',
			notes: 'Atención primaria',
			revision: 4,
			retired: false,
		},
		{
			id: 2,
			name: 'Farmacia Popular',
			subcategoryId: 12,
			sectorId: 10,
			unidadVecinalId: 101,
			locationQuality: 'approximate',
			address: 'Calle Real 555',
			contactPerson: 'Q.F. Morales',
			notes: 'Medicamentos a bajo costo',
			revision: 2,
			retired: false,
		},
		{
			id: 3,
			name: 'Colegio Arturo Prat',
			subcategoryId: 21,
			sectorId: 20,
			unidadVecinalId: 100,
			locationQuality: 'exact',
			address: 'Pasaje Prat 88',
			contactPerson: 'Profesor Silva',
			notes: 'Escuela básica',
			revision: 7,
			retired: false,
		},
		{
			id: 4,
			name: 'Plaza Las Flores',
			subcategoryId: 31,
			sectorId: null, // sin sector asignado
			unidadVecinalId: null,
			locationQuality: 'exact',
			address: 'Las Flores s/n',
			contactPerson: null,
			notes: null,
			revision: 1,
			retired: false,
		},
		{
			id: 5,
			name: 'Policlínico Antiguo',
			subcategoryId: 11,
			sectorId: 10,
			unidadVecinalId: 100,
			locationQuality: 'approximate',
			address: 'Calle Vieja 12',
			contactPerson: null,
			notes: 'Cerrado por traslado',
			revision: 9,
			retired: true, // Retirado
		},
	]

	describe('filterAssets', () => {
		it('returns all active features when filter criteria is empty', () => {
			const result = filterAssets(sampleFeatures, {}, sampleSubcategories)
			expect(result.map(f => f.id)).toEqual([1, 2, 3, 4])
		})

		it('filters out active features and returns only retired when showRetired is true', () => {
			const result = filterAssets(sampleFeatures, { showRetired: true }, sampleSubcategories)
			expect(result.map(f => f.id)).toEqual([5])
		})

		it('filters features by sectorId', () => {
			const result = filterAssets(sampleFeatures, { sectorId: 10 }, sampleSubcategories)
			expect(result.map(f => f.id)).toEqual([1, 2])
		})

		it('filters features with no sector assigned (sectorId: null explicit)', () => {
			const result = filterAssets(sampleFeatures, { sectorId: null }, sampleSubcategories)
			expect(result.map(f => f.id)).toEqual([4])
		})

		it('filters features by unidadVecinalId', () => {
			const result = filterAssets(sampleFeatures, { unidadVecinalId: 100 }, sampleSubcategories)
			expect(result.map(f => f.id)).toEqual([1, 3])
		})

		it('filters features by locationQuality', () => {
			const exactResult = filterAssets(sampleFeatures, { locationQuality: 'exact' }, sampleSubcategories)
			expect(exactResult.map(f => f.id)).toEqual([1, 3, 4])

			const approxResult = filterAssets(sampleFeatures, { locationQuality: 'approximate' }, sampleSubcategories)
			expect(approxResult.map(f => f.id)).toEqual([2])
		})

		it('filters features by subcategoryId', () => {
			const result = filterAssets(sampleFeatures, { subcategoryId: 11 }, sampleSubcategories)
			expect(result.map(f => f.id)).toEqual([1])
		})

		it('filters features by categoryId', () => {
			const result = filterAssets(sampleFeatures, { categoryId: 1 }, sampleSubcategories)
			expect(result.map(f => f.id)).toEqual([1, 2])
		})

		it('filters features by text query matching name, address, contact, notes, or taxonomy', () => {
			expect(filterAssets(sampleFeatures, { query: 'Bellavista' }, sampleSubcategories).map(f => f.id)).toEqual([1])
			expect(filterAssets(sampleFeatures, { query: 'Calle Real' }, sampleSubcategories).map(f => f.id)).toEqual([2])
			expect(filterAssets(sampleFeatures, { query: 'Silva' }, sampleSubcategories).map(f => f.id)).toEqual([3])
			expect(filterAssets(sampleFeatures, { query: 'medicamentos' }, sampleSubcategories).map(f => f.id)).toEqual([2])
			expect(filterAssets(sampleFeatures, { query: 'Educación' }, sampleSubcategories).map(f => f.id)).toEqual([3])
		})

		it('composes multiple filter criteria simultaneously', () => {
			const result = filterAssets(sampleFeatures, {
				sectorId: 10,
				locationQuality: 'exact',
			}, sampleSubcategories)
			expect(result.map(f => f.id)).toEqual([1])
		})
	})

	describe('sortAssets', () => {
		it('sorts alphabetically by name ascending (default)', () => {
			const activeOnly = sampleFeatures.filter(f => !f.retired)
			const sorted = sortAssets(activeOnly, { sortBy: 'name', sortOrder: 'asc' })
			expect(sorted.map(f => f.name)).toEqual([
				'CESFAM Bellavista',
				'Colegio Arturo Prat',
				'Farmacia Popular',
				'Plaza Las Flores',
			])
		})

		it('sorts alphabetically by name descending', () => {
			const activeOnly = sampleFeatures.filter(f => !f.retired)
			const sorted = sortAssets(activeOnly, { sortBy: 'name', sortOrder: 'desc' })
			expect(sorted.map(f => f.name)).toEqual([
				'Plaza Las Flores',
				'Farmacia Popular',
				'Colegio Arturo Prat',
				'CESFAM Bellavista',
			])
		})

		it('sorts by revision descending (newest first)', () => {
			const activeOnly = sampleFeatures.filter(f => !f.retired)
			const sorted = sortAssets(activeOnly, { sortBy: 'revision', sortOrder: 'desc' })
			expect(sorted.map(f => f.id)).toEqual([3, 1, 2, 4]) // revisions: 7, 4, 2, 1
		})

		it('does not mutate the source array', () => {
			const original = [...sampleFeatures]
			const sorted = sortAssets(sampleFeatures, { sortBy: 'name', sortOrder: 'asc' })
			expect(sampleFeatures).toEqual(original)
			expect(sorted).not.toBe(sampleFeatures)
		})
	})
})
