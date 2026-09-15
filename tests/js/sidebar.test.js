import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * Where the sidebar puts a record's destructive action (issue #35).
 *
 * Reported as "there is no button that allows deletion". «Retirar del mapa»
 * existed all along — it was the last control of a form rendered BELOW the Limit
 * editor, so on a real Sector it sat 721px past the bottom of a 620px screen.
 * A control nobody can reach is a control that is not there.
 *
 * Source text, as in imports.test.js: nothing in this repo loads a component, so
 * these are the cheapest assertions that fail when the layout is undone. They
 * cannot see a pixel — that the control is actually inside the viewport is
 * asserted in tools/browser-check.py, which is the only thing here that measures
 * one.
 *
 * ponytail: reading the SFC as a string. A test that mounts the component wants
 * @vue/test-utils and a DOM, neither of which this repo has, for guards that are
 * about markup that is written once and then only ever broken by accident.
 */
// fileURLToPath, not .pathname: see imports.test.js.
const SRC = fileURLToPath(new URL('../../src/', import.meta.url))

const app = readFileSync(`${SRC}App.vue`, 'utf8')
const detail = readFileSync(`${SRC}components/FeatureDetail.vue`, 'utf8')

/**
 * The action group as written, so the assertions below are about what is inside
 * it rather than about what happens to be near it.
 *
 * Relies on the group holding no nested `<div>` — it holds buttons, a `<template>`
 * and a `<p>` — which is why the end is the first `</div>` after it.
 */
const actions = () => {
	const start = detail.indexOf('territorio-detail__actions')
	expect(start, 'FeatureDetail.vue has no action group at all').toBeGreaterThan(-1)

	return detail.slice(start, detail.indexOf('</div>', start))
}

describe('the sidebar of a Sector (#35)', () => {
	it('renders the record above the Limit editor', () => {
		// Both present first: -1 is less than any index, so an App.vue that had
		// dropped <FeatureDetail> altogether would pass the comparison alone.
		expect(app).toContain('<FeatureDetail')
		expect(app).toContain('<BoundaryPicker')

		expect(app.indexOf('<FeatureDetail'), 'FeatureDetail must come before BoundaryPicker')
			.toBeLessThan(app.indexOf('<BoundaryPicker'))
	})

	it('renders the Limit editor inside the record form, which is what lets the bar stick', () => {
		// Measured in the browser, not reasoned: `position: sticky` is bounded by
		// its containing block, which is the <form>. With <BoundaryPicker> a
		// SIBLING of the form, scrolling past the form's own height carried the
		// action group off screen with it — the browser gate reported the retire
		// button at y=-420 on a 620px screen. Nested inside the form, the
		// containing block spans everything that grows, and the bar stays.
		expect(app).toContain('<FeatureDetail')
		expect(app).toContain('</FeatureDetail>')
		expect(app).toContain('<BoundaryPicker')

		expect(app.indexOf('<BoundaryPicker'), 'the Limit editor must be inside FeatureDetail')
			.toBeGreaterThan(app.indexOf('<FeatureDetail'))
		expect(app.indexOf('<BoundaryPicker'), 'the Limit editor must be inside FeatureDetail')
			.toBeLessThan(app.indexOf('</FeatureDetail>'))
	})
})

describe('the action group (#35)', () => {
	it('holds Guardar and «Retirar del mapa» together', () => {
		expect(actions()).toContain('Guardar')
		expect(actions()).toContain('Retirar del mapa')
	})

	it('keeps Guardar a submit button, so the form still submits', () => {
		expect(actions()).toMatch(/type="submit"/)
	})

	it('keeps the two-step confirmation, and what it promises', () => {
		expect(actions()).toContain('confirmingRetire = true')
		expect(actions()).toMatch(/No se borra: conserva su historial y\s+se puede restaurar/)
	})

	it('is stuck to the top of the sidebar, so the form cannot push it off', () => {
		const rule = detail.slice(detail.indexOf('.territorio-detail__actions {'))

		expect(rule.slice(0, rule.indexOf('}'))).toMatch(/position: sticky/)
	})
})
