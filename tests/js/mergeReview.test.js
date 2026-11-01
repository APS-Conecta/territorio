import { describe, expect, it } from 'vitest'
import { mergeReview } from '../../src/mergeReview.js'

describe('reviewing complementary community asset information', () => {
	it('keeps the survivor conflicts and proposes missing information from the other record', () => {
		const first = { id: 1, name: 'Biblioteca', phone: '111', address: '', notes: null }
		const second = { id: 2, name: 'Biblioteca vecinal', phone: '222', address: 'Los Aromos 10', notes: 'Acceso universal' }
		const review = mergeReview(first, second)
		expect(review.find((field) => field.key === 'phone').sourceId).toBe(1)
		expect(review.find((field) => field.key === 'address').sourceId).toBe(2)
		expect(review.find((field) => field.key === 'notes').sourceId).toBe(2)
		expect(first.address).toBe('')
	})
})
