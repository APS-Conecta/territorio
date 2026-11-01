import { describe, expect, it } from 'vitest'
import { customAreaSubcategoryId, divisionsOf, treeOf } from '../../src/taxonomy.js'
import { labelOptions, shapeStyle, swatchFor } from '../../src/marks.js'

const taxonomy = [
	{ id: 1, slug: 'salud', subcategories: [{ id: 10, slug: 'cesfam', label: 'CESFAM' }] },
	{ id: 2, slug: 'division_territorial', subcategories: [
		{ id: 20, slug: 'sector', label: 'Sector' },
		{ id: 21, slug: 'custom_area', label: 'Área personalizada' },
	] },
]

describe('custom areas alongside official divisions', () => {
	it('files a custom area independently of Sector and keeps it out of the asset taxonomy', () => {
		expect(customAreaSubcategoryId(taxonomy)).toBe(21)
		expect(customAreaSubcategoryId([])).toBe(null)
		expect(divisionsOf(taxonomy).map(item => item.id)).toEqual([20, 21])
		expect(treeOf(taxonomy).map(item => item.slug)).toEqual(['salud'])
	})

	it('identifies custom areas by a permanent name and boundary mark, not an asset dot', () => {
		expect(labelOptions('custom_area').permanent).toBe(true)
		expect(shapeStyle('custom_area', '#949494').fill).toBe(false)
		expect(labelOptions('custom_area').className).toContain('territorio-label--custom')
		expect(swatchFor('custom_area', '#334155')).toEqual({ mark: 'ring', background: '' })
	})
})
