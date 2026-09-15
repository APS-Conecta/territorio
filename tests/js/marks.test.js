import { describe, expect, it } from 'vitest'
import {
	GROUND_LABEL_MIN_ZOOM,
	labelOptions,
	shapeStyle,
	showsGroundLabels,
	swatchFor,
} from '../../src/marks.js'
import { SECTOR_SLUG, UNIDAD_VECINAL_SLUG } from '../../src/taxonomy.js'

/**
 * Issue #21: a Unidad Vecinal is ground and a Sector is figure.
 *
 * These numbers are not preferences. docs/design/map-legibility.md § 2 measures
 * them, and the two halves of the Sector wash — `#949494` and alpha 0.10 —
 * cannot be taken apart: at the 0.15 this shipped, the worst dot-on-tint pair
 * reads 2.90:1, below the 3:1 floor of WCAG 1.4.11.
 */
describe('how a shape is drawn', () => {
	const SECTOR_COLOUR = '#16a34a'

	it('gives a Unidad Vecinal no fill at all', () => {
		expect(shapeStyle(UNIDAD_VECINAL_SLUG, '#334155').fill).toBe(false)
	})

	it('draws a Unidad Vecinal as a dashed hairline, in its own grey', () => {
		const style = shapeStyle(UNIDAD_VECINAL_SLUG, '#334155')

		expect(style.dashArray).toBe('2 3')
		expect(style.weight).toBe(1)
		expect(style.opacity).toBe(0.45)
		// Judged on contrast against the tile, not on separation from the ramp:
		// it is a one-pixel line over a basemap and never a dot beside a dot.
		expect(style.color).toBe('#7d7d7d')
	})

	/**
	 * A cadastre boundary the clinic may never touch must not answer a click the
	 * same way a Sector does — but a person who opened one from the list still
	 * has to see which one they opened.
	 *
	 * This is the branch the app takes for an OPEN Unidad Vecinal, and it was not
	 * always: `drawDraft` hardcoded `shapeStyle(null, …)`, so opening one from the
	 * Datos list drew it as figure while this case went on passing. What the app
	 * passes is now checked in imports.test.js, and the drawn result in
	 * tools/browser-check.py::_ground_stays_ground_while_it_is_open — a style
	 * function nobody calls with the right slug proves nothing on its own.
	 */
	it('keeps a Unidad Vecinal ground even while it is selected', () => {
		const style = shapeStyle(UNIDAD_VECINAL_SLUG, '#334155', true)

		expect(style.fill).toBe(false)
		expect(style.dashArray).toBe('2 3')
		expect(style.weight).toBeGreaterThan(1)
	})

	it('washes a Sector at the alpha the contrast floor was measured at', () => {
		const style = shapeStyle(SECTOR_SLUG, SECTOR_COLOUR)

		expect(style.fillOpacity).toBe(0.1)
		expect(style.fillColor).toBe(SECTOR_COLOUR)
		expect(style.color).toBe(SECTOR_COLOUR)
		expect(style.weight).toBe(2)
		expect(style.dashArray).toBe(null)
	})

	/** A Route, or a Zone that is not a division: unchanged, minus the alpha. */
	it('treats anything the clinic drew as figure', () => {
		expect(shapeStyle('plaza', SECTOR_COLOUR).fill).not.toBe(false)
		expect(shapeStyle(null, SECTOR_COLOUR).fillOpacity).toBe(0.1)
	})
})

describe('the label a shape carries', () => {
	/**
	 * A Sector is identified by its name, not by its colour: CONTEXT.md says a
	 * clinic names its sectores "words, numbers or colours", and only one of
	 * those three is a colour.
	 */
	it('is permanent and at the centroid for a Sector', () => {
		const options = labelOptions(SECTOR_SLUG)

		expect(options.permanent).toBe(true)
		expect(options.direction).toBe('center')
		expect(options.className).toContain('territorio-label')
	})

	it('is permanent for a Unidad Vecinal too, but marked as ground', () => {
		const options = labelOptions(UNIDAD_VECINAL_SLUG)

		expect(options.permanent).toBe(true)
		expect(options.direction).toBe('center')
		expect(options.className).toContain('territorio-label--ground')
	})

	/** Everything else keeps the hover tooltip it has always had. */
	it('is shown on hover for anything that is not a division', () => {
		expect(labelOptions('plaza').permanent).toBe(undefined)
		expect(labelOptions(null).permanent).toBe(undefined)
	})
})

/**
 * ~50-100 Unidades Vecinales across one comuna: labelled at every zoom they are
 * a sheet of text, and the answer is cartography rather than a collision
 * library (map-legibility § 4).
 */
describe('when the ground labels are drawn', () => {
	it('holds them back until a Unidad Vecinal is big enough to hold its name', () => {
		expect(showsGroundLabels(GROUND_LABEL_MIN_ZOOM)).toBe(true)
		expect(showsGroundLabels(GROUND_LABEL_MIN_ZOOM - 1)).toBe(false)
	})

	/** The whole comuna on screen is exactly the zoom that must stay quiet. */
	it('draws none at the zoom the map opens on', () => {
		expect(showsGroundLabels(12)).toBe(false)
	})
})

/**
 * Issue #29: the division stops being a dot rather than getting a better
 * colour. The seeded `#334155` measures 1.73:1 against the dark theme, and
 * ADR-0013 measures the neutral band as having no room for a fourth grey.
 */
describe('the mark the Datos list draws', () => {
	it('is a dashed ring for a Unidad Vecinal, echoing the map', () => {
		expect(swatchFor(UNIDAD_VECINAL_SLUG, '#334155')).toEqual({
			mark: 'ring-dashed',
			background: '',
		})
	})

	it('is a solid ring for a Sector, echoing the map', () => {
		expect(swatchFor(SECTOR_SLUG, '#16a34a')).toEqual({ mark: 'ring', background: '' })
	})

	it('is still a filled dot for everything else', () => {
		expect(swatchFor('cesfam', '#99564e')).toEqual({ mark: 'dot', background: '#99564e' })
	})
})
