import { describe, expect, it } from 'vitest'
import { decisionsForPreview } from '../../src/odsReview.js'

const row = (number, name, extra = {}) => ({
	row: number, name, id: null, status: 'new', errors: [], candidates: [], changes: [],
	input: { name, subcategoryId: 1 }, ...extra,
})

describe('ODS preview review choices', () => {
	it('keeps skipped rows skipped and preserves explicit clearing when another classification is corrected', () => {
		const skipped = row(2, 'Intentionally skipped')
		const updating = row(3, 'Keep selected', { id: 42, status: 'update' })
		const invalid = row(4, 'Classification to correct', { status: 'invalid', errors: ['Unknown classification'] })
		const acknowledged = row(5, 'Reviewed candidate', { candidates: ['Activo #10: Similar place'] })
		const previous = { rows: [skipped, updating, invalid, acknowledged] }
		const decisions = [
			{ row: 3, clear: ['phone'], confirmDuplicates: false },
			{ row: 5, clear: [], confirmDuplicates: true },
		]
		const revised = { rows: [skipped, updating, row(4, 'Classification to correct', { input: { name: 'Classification to correct', subcategoryId: 9 } }), acknowledged] }

		expect(decisionsForPreview(revised, previous, decisions)).toEqual([
			{ row: 3, clear: ['phone'], confirmDuplicates: false },
			{ row: 5, clear: [], confirmDuplicates: true },
		])
		expect(decisions).toEqual([
			{ row: 3, clear: ['phone'], confirmDuplicates: false },
			{ row: 5, clear: [], confirmDuplicates: true },
		])
	})
	it('requires a fresh decision when reviewed data or candidate pairs change', () => {
		const first = row(2, 'Edited input')
		const second = row(3, 'New candidate')
		const previous = { rows: [first, second] }
		const decisions = [
			{ row: 2, clear: ['notes'], confirmDuplicates: false },
			{ row: 3, clear: [], confirmDuplicates: true },
		]
		const revised = { rows: [
			{ ...first, input: { ...first.input, subcategoryId: 9 } },
			{ ...second, candidates: ['Activo #99: Another place'] },
		] }
		expect(decisionsForPreview(revised, previous, decisions)).toEqual([])
	})

	it('initially selects only valid rows without possible duplicates', () => {
		expect(decisionsForPreview({ rows: [
			row(2, 'Ready'),
			row(3, 'Invalid', { status: 'invalid' }),
			row(4, 'Possible duplicate', { candidates: ['Activo #10'] }),
		] })).toEqual([{ row: 2, clear: [], confirmDuplicates: false }])
	})

})
