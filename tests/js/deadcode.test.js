import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { babelParse, parse } from '@vue/compiler-sfc'
import { describe, expect, it } from 'vitest'
import * as assets from '../../src/assets.js'
import * as boundaries from '../../src/boundaries.js'
import * as glyphs from '../../src/glyphs.js'

/**
 * Code that was audited to have no production caller and then deleted (T5a).
 *
 * Each case asserts the absence, so a reintroduction fails here rather than
 * lingering: ESLint flags an unused local but never an unused `export`, an
 * unread prop or an emit nobody fires, which is how these survived. Every
 * deletion below was checked by exact-symbol search over src, lib, tests,
 * tools, templates, docs and the README before it was made.
 *
 * Not here, on purpose: the exports that tests import (`metresBetween`,
 * `normalizeName`, `levenshteinDistance`, `clusterRing`) stay exported.
 */

const source = (path) => readFileSync(fileURLToPath(new URL(path, import.meta.url)), 'utf8')

/** The keys of the `props:` object and the names in the `emits:` array of an Options API component. */
function contractOf(path) {
	const { descriptor } = parse(source(path), { filename: path })
	const program = babelParse(descriptor.script.content, { sourceType: 'module' }).program
	const component = program.body.find((statement) => statement.type === 'ExportDefaultDeclaration').declaration
	const option = (name) => component.properties.find((property) => property.key.name === name)?.value
	return {
		props: (option('props')?.properties ?? []).map((property) => property.key.name ?? property.key.value),
		emits: (option('emits')?.elements ?? []).map((element) => element.value),
		template: descriptor.template.content,
	}
}

describe('src/assets.js', () => {
	it('no longer exports filterAssets, which filters.js already does as filterFeatures', () => {
		expect(assets).not.toHaveProperty('filterAssets')
	})

	it('no longer exports isPotentialDuplicate, which only tests called', () => {
		expect(assets).not.toHaveProperty('isPotentialDuplicate')
	})

	it('keeps its thresholds to itself', () => {
		for (const name of ['METRES_PER_DEGREE', 'SAME_PLACE_METRES', 'SAME_BLOCK_METRES', 'ALIKE_THRESHOLD']) {
			expect(assets, name).not.toHaveProperty(name)
		}
	})

	it('still exports what production and the tests use', () => {
		for (const name of ['findDuplicateCandidate', 'alike', 'metresBetween', 'normalizeName', 'levenshteinDistance', 'sortAssets', 'viewTargetOf', 'matchOmnibox']) {
			expect(assets, name).toHaveProperty(name)
		}
	})
})

describe('src/boundaries.js', () => {
	it('keeps closerFirst to itself: streets.js does not import it', () => {
		expect(boundaries).not.toHaveProperty('closerFirst')
	})
})

describe('src/glyphs.js', () => {
	it('does not export GLYPH_SLUGS: nothing reads the list but a test', () => {
		expect(glyphs).not.toHaveProperty('GLYPH_SLUGS')
	})
})

describe('LayersDropdownButton', () => {
	const button = contractOf('../../src/components/LayersDropdownButton.vue')

	it('declares only the props App.vue binds', () => {
		expect(button.props.sort()).toEqual(['filter', 'taxonomy'])
	})

	it('draws no per-category count badge', () => {
		expect(button.template.includes('counts')).toBe(false)
		expect(source('../../src/components/LayersDropdownButton.vue').includes('territorio-layers-item__badge')).toBe(false)
	})
})

describe('FeatureDetail', () => {
	it('declares only events it fires', () => {
		const path = '../../src/components/FeatureDetail.vue'
		const neverFired = contractOf(path).emits.filter((event) => !new RegExp(`\\$?emit\\(\\s*'${event}'`).test(source(path)))
		expect(neverFired).toEqual([])
	})
})
