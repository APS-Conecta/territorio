import { describe, expect, it } from 'vitest'
import {
	alike,
	findDuplicateCandidate,
	isPotentialDuplicate,
	levenshteinDistance,
	metresBetween,
	normalizeName,
} from '../../src/assets.js'

describe('Proactive Duplicate Shield (pure logic)', () => {
	describe('metresBetween', () => {
		it('calculates 0 metres for identical coordinates', () => {
			expect(metresBetween(-33.45, -70.65, -33.45, -70.65)).toBeCloseTo(0, 1)
		})

		it('returns Infinity when any coordinate is null or undefined', () => {
			expect(metresBetween(null, -70.65, -33.45, -70.65)).toBe(Infinity)
			expect(metresBetween(-33.45, null, -33.45, -70.65)).toBe(Infinity)
			expect(metresBetween(-33.45, -70.65, undefined, -70.65)).toBe(Infinity)
			expect(metresBetween(-33.45, -70.65, -33.45, undefined)).toBe(Infinity)
		})

		it('calculates approximately 111 metres for 0.001 degree of latitude', () => {
			const d = metresBetween(-33.450, -70.65, -33.451, -70.65)
			expect(d).toBeGreaterThan(110)
			expect(d).toBeLessThan(112)
		})

		it('scales longitude difference by cos(latitude)', () => {
			// At -33.45 lat, cos(-33.45) is ~0.834, so 0.001 lon is ~92.8m
			const d = metresBetween(-33.45, -70.650, -33.45, -70.651)
			expect(d).toBeGreaterThan(91)
			expect(d).toBeLessThan(94)
		})
	})

	describe('normalizeName and string distance', () => {
		it('folds accents, punctuation and collapses whitespace', () => {
			expect(normalizeName('Jardín  Infantil   Rayún!')).toBe('jardin infantil rayun')
			expect(normalizeName('CESFAM Dr. Juan Lozic')).toBe('cesfam dr juan lozic')
			expect(normalizeName(null)).toBe('')
			expect(normalizeName(undefined)).toBe('')
		})

		it('computes Levenshtein distance accurately', () => {
			expect(levenshteinDistance('', '')).toBe(0)
			expect(levenshteinDistance('cesfam', 'cesfam')).toBe(0)
			expect(levenshteinDistance('cesfam', 'cesfan')).toBe(1)
			expect(levenshteinDistance('cesfam', '')).toBe(6)
			expect(levenshteinDistance('kitten', 'sitting')).toBe(3)
		})
	})

	describe('alike (name similarity)', () => {
		it('treats identical folded names as alike', () => {
			expect(alike('CESFAM Bellavista', 'cesfam bellavista')).toBe(true)
			expect(alike('Jardín Infantil Rayún', 'JARDIN INFANTIL RAYUN')).toBe(true)
		})

		it('treats substring containment as alike', () => {
			expect(alike('CESFAM Bellavista', 'CESFAM Bellavista (SSMSO)')).toBe(true)
			expect(alike('Junta Vecinal N 12', 'Junta Vecinal N 12 Los Boldos')).toBe(true)
		})

		it('returns false for empty names', () => {
			expect(alike('', 'CESFAM')).toBe(false)
			expect(alike('CESFAM', '')).toBe(false)
			expect(alike(null, 'CESFAM')).toBe(false)
		})

		it('distinguishes different facilities with similar words', () => {
			// "Escuela Bellavista" vs "Liceo Bellavista" share letters but are different schools
			expect(alike('Escuela Bellavista', 'Liceo Bellavista')).toBe(false)
		})
	})

	describe('isPotentialDuplicate and findDuplicateCandidate', () => {
		const existingFeatures = [
			{
				id: 101,
				name: 'CESFAM Central',
				lat: -33.4489,
				lon: -70.6501,
				subcategoryId: 11,
			},
			{
				id: 102,
				name: 'Colegio Gabriela Mistral',
				lat: -33.4520,
				lon: -70.6550,
				subcategoryId: 21,
			},
			{
				id: 103,
				name: 'Plaza de Armas',
				lat: -33.4378,
				lon: -70.6505,
				subcategoryId: 31,
			},
		]

		it('detects duplicate on coordinate proximity (<= 25m) even with different name', () => {
			const draft = {
				id: null,
				name: 'Consultorio Central Antiguo',
				lat: -33.44892,
				lon: -70.65012, // approx 3m away
			}
			const candidate = findDuplicateCandidate(draft, existingFeatures)
			expect(candidate).not.toBeNull()
			expect(candidate.feature.id).toBe(101)
			expect(candidate.reason).toBe('coordenada')
			expect(isPotentialDuplicate(draft, existingFeatures)).toBe(true)
		})

		it('detects duplicate on alike name within block distance (<= 150m)', () => {
			const draft = {
				id: null,
				name: 'Colegio Gabriela Mistral (Anexo)', // alike via containment
				lat: -33.4525, // ~60m away
				lon: -70.6550,
			}
			const candidate = findDuplicateCandidate(draft, existingFeatures)
			expect(candidate).not.toBeNull()
			expect(candidate.feature.id).toBe(102)
			expect(candidate.reason).toBe('nombre')
			expect(isPotentialDuplicate(draft, existingFeatures)).toBe(true)
		})

		it('does not flag duplicate if name is alike but distance is far (> 150m)', () => {
			const draft = {
				id: null,
				name: 'Plaza de Armas',
				lat: -33.4500, // > 1 km away
				lon: -70.6505,
			}
			expect(findDuplicateCandidate(draft, existingFeatures)).toBeNull()
			expect(isPotentialDuplicate(draft, existingFeatures)).toBe(false)
		})

		it('does not flag duplicate against itself when editing an existing feature', () => {
			const draft = {
				id: 101, // same ID as existing
				name: 'CESFAM Central Modificado',
				lat: -33.4489,
				lon: -70.6501,
			}
			expect(findDuplicateCandidate(draft, existingFeatures)).toBeNull()
			expect(isPotentialDuplicate(draft, existingFeatures)).toBe(false)
		})

		it('handles draft without coordinates gracefully without error', () => {
			const draft = {
				id: null,
				name: 'Centro Comunitario Sin Coordenadas',
				lat: null,
				lon: null,
			}
			expect(findDuplicateCandidate(draft, existingFeatures)).toBeNull()
			expect(isPotentialDuplicate(draft, existingFeatures)).toBe(false)
		})
	})

	describe('records as the server sends them', () => {
		// toClient() has no flat lat/lon: a Place is GeoJSON, [lon, lat], and a
		// record without coordinates has `geometry: null`. `retired` is a boolean.
		const place = (id, name, lat, lon, extra = {}) => ({
			id,
			name,
			geometry: { type: 'Point', coordinates: [lon, lat] },
			retired: false,
			...extra,
		})
		const draft = { id: null, name: 'Consultorio Norte', lat: '-33.4489', lon: '-70.6501' }

		it('finds a neighbour that carries GeoJSON geometry', () => {
			const candidate = findDuplicateCandidate(draft, [place(7, 'Otro', -33.44892, -70.65012)])
			expect(candidate?.feature.id).toBe(7)
			expect(candidate?.reason).toBe('coordenada')
		})

		it('ignores a retired neighbour', () => {
			expect(findDuplicateCandidate(draft, [place(7, 'Otro', -33.44892, -70.65012, { retired: true })])).toBeNull()
		})

		it('skips a neighbour with no geometry rather than failing', () => {
			expect(findDuplicateCandidate(draft, [{ id: 8, name: 'Sin mapa', geometry: null, retired: false }])).toBeNull()
		})

		it('does not compare a draft that has no coordinates yet', () => {
			expect(findDuplicateCandidate({ id: null, name: 'X', lat: '', lon: '' }, [place(7, 'X', -33.4489, -70.6501)])).toBeNull()
		})
	})
})

describe('a draft typed with a decimal comma', () => {
	it('still meets its neighbour', () => {
		const neighbour = { id: 7, name: 'Otro', geometry: { type: 'Point', coordinates: [-70.65012, -33.44892] }, retired: false }
		expect(findDuplicateCandidate({ id: null, name: 'X', lat: '-33,4489', lon: '-70,6501' }, [neighbour])?.reason).toBe('coordenada')
	})
})
