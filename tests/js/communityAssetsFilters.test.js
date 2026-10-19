import { describe, expect, it } from 'vitest'
import { sortAssets } from '../../src/assets.js'

describe('Community Assets Sorting (pure logic)', () => {
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
