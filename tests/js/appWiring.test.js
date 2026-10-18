import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { babelParse, parse } from '@vue/compiler-sfc'
import { describe, expect, it } from 'vitest'

/**
 * What App.vue says to each child component, checked against what that child
 * declares: every `@event` it listens for must be in the child's `emits`, every
 * prop it binds must be in the child's `props`, and every `required` prop must
 * be bound.
 *
 * Why this exists: PR #171 rewrote App.vue against component APIs that were
 * never read. Vue drops an unknown listener and turns an unknown prop into a
 * DOM attribute, both silently, so the search box, the view tabs, the
 * «Dibujar Sector» button and the taxonomy drawer all stopped working while
 * 437 tests and CI stayed green. Nothing in this repo mounts a component (see
 * search-control.test.js), so this reads the .vue files instead, as
 * sidebar.test.js and locate-control.test.js do.
 *
 * Scope: only the components App.vue imports from ./components/. The
 * @nextcloud/vue ones (NcButton, NcNoteCard...) are a library's contract.
 */

const componentsDir = new URL('../../src/components/', import.meta.url)
const readSource = (path) => readFileSync(fileURLToPath(path), 'utf8')

// compiler-core's NodeTypes, which are plain numbers in the AST.
const ELEMENT = 1
const ATTRIBUTE = 6
const DIRECTIVE = 7
const COMPONENT_TAG = 1

/**
 * Attributes every element accepts, which Vue passes through to the child's
 * root element on purpose. These are not props and not a mismatch.
 */
const PASS_THROUGH = /^(class|style|key|ref|id|title|role|tabindex|slot|is|aria-.+|data-.+)$/

const camelize = (name) => name.replace(/-(\w)/g, (_, letter) => letter.toUpperCase())

/** The names an object-or-array `props`/`emits` option declares, and which props are required. */
function declared(node) {
	const names = new Map()
	if (node === undefined) {
		return names
	}
	if (node.type === 'ArrayExpression') {
		for (const element of node.elements) {
			names.set(camelize(element.value), { required: false })
		}
	} else if (node.type === 'ObjectExpression') {
		for (const property of node.properties) {
			const name = property.key.name ?? property.key.value
			const required = property.value.type === 'ObjectExpression'
				&& property.value.properties.some((entry) => (entry.key.name ?? entry.key.value) === 'required'
					&& entry.value.value === true)
			names.set(camelize(name), { required })
		}
	}
	return names
}

/** `{ props, emits }` of a component written with the Options API, which every one in src/components is. */
function contractOf(source, file) {
	const { descriptor } = parse(source, { filename: file })
	if (descriptor.script === null) {
		throw new Error(`${file}: no <script> block. This test reads the Options API; extend it if a component moves to <script setup>.`)
	}
	const program = babelParse(descriptor.script.content, { sourceType: 'module' })
	const exported = program.program.body.find((statement) => statement.type === 'ExportDefaultDeclaration')
	if (exported === undefined || exported.declaration.type !== 'ObjectExpression') {
		throw new Error(`${file}: no \`export default { ... }\` object to read props and emits from.`)
	}
	const option = (name) => exported.declaration.properties
		.find((property) => (property.key.name ?? property.key.value) === name)?.value
	for (const inherited of ['mixins', 'extends']) {
		if (option(inherited) !== undefined) {
			throw new Error(`${file}: uses \`${inherited}\`, whose props and emits this test cannot see.`)
		}
	}
	return { props: declared(option('props')), emits: declared(option('emits')) }
}

/** Every element node under `node`, depth first. */
function* elements(node) {
	for (const child of node.children ?? []) {
		if (child.type === ELEMENT) {
			yield child
		}
		yield* elements(child)
	}
}

/** The `./components/X.vue` imports of App.vue, as `{ X: 'X.vue' }`. */
function importedComponents(appScript) {
	const found = {}
	for (const [, name, file] of appScript.matchAll(/import\s+(\w+)\s+from\s+'\.\/components\/([^']+\.vue)'/g)) {
		found[name] = file
	}
	return found
}

/**
 * Every disagreement between App.vue's template and the components it uses.
 *
 * @param {string} appSource App.vue
 * @param {(file: string) => string} readComponent source of `./components/<file>`
 * @return {string[]} one line per problem, empty when the wiring agrees
 */
export function wiringProblems(appSource, readComponent) {
	const { descriptor } = parse(appSource, { filename: 'App.vue' })
	const imports = importedComponents(descriptor.script.content)
	const contracts = {}
	const problems = []

	for (const element of elements(descriptor.template.ast)) {
		const file = imports[element.tag]
		if (file === undefined || element.tagType !== COMPONENT_TAG) {
			continue
		}
		contracts[file] ??= contractOf(readComponent(file), file)
		const { props, emits } = contracts[file]
		const bound = new Set()
		const where = `<${element.tag}> (line ${element.loc.start.line})`

		for (const prop of element.props) {
			if (prop.type === ATTRIBUTE) {
				const name = camelize(prop.name)
				bound.add(name)
				if (!props.has(name) && !PASS_THROUGH.test(prop.name)) {
					problems.push(`${where}: attribute "${prop.name}" is not a prop of ${file}`)
				}
				continue
			}
			if (prop.type !== DIRECTIVE) {
				continue
			}
			const arg = prop.arg?.isStatic === true ? prop.arg.content : null
			if (prop.name === 'bind' && arg !== null) {
				bound.add(camelize(arg))
				if (!props.has(camelize(arg)) && !PASS_THROUGH.test(arg)) {
					problems.push(`${where}: binds ":${arg}", which ${file} does not declare as a prop`)
				}
			} else if (prop.name === 'on' && arg !== null) {
				if (!emits.has(camelize(arg))) {
					problems.push(`${where}: listens for "@${arg}", which ${file} never emits`)
				}
			} else if (prop.name === 'model') {
				const name = arg ?? 'modelValue'
				bound.add(camelize(name))
				if (!props.has(camelize(name))) {
					problems.push(`${where}: v-model on "${name}", which ${file} does not declare as a prop`)
				}
				if (!emits.has(camelize(`update:${name}`))) {
					problems.push(`${where}: v-model on "${name}", but ${file} never emits "update:${name}"`)
				}
			}
		}

		for (const [name, { required }] of props) {
			if (required && !bound.has(name)) {
				problems.push(`${where}: required prop "${name}" of ${file} is not bound`)
			}
		}
	}
	return problems
}

describe('App.vue wiring', () => {
	it('listens for, and binds, only what each child component declares', () => {
		const problems = wiringProblems(
			readSource(new URL('../../src/App.vue', import.meta.url)),
			(file) => readSource(new URL(file, componentsDir)),
		)
		expect(problems).toEqual([])
	})

	describe('the checker itself', () => {
		const child = `<template><div /></template>
<script>
export default {
	props: { modelValue: { type: String, required: true }, isDrawing: Boolean, taxonomy: { type: Array } },
	emits: ['draw-sector', 'update:modelValue', 'update:showRetired'],
}
</script>`
		const app = (attributes) => `<template><Child ${attributes} /></template>
<script>
import Child from './components/Child.vue'
export default { components: { Child } }
</script>`
		const check = (attributes) => wiringProblems(app(attributes), () => child)

		it('accepts matching events, props, v-model and pass-through attributes', () => {
			expect(check('v-model="q" :is-drawing="d" :taxonomy="t" class="x" data-a="1" @draw-sector="go" @update:show-retired="r"')).toEqual([])
		})

		it('catches a listener the child never emits', () => {
			expect(check('v-model="q" @toggle="go"')).toEqual([expect.stringContaining('"@toggle"')])
		})

		it('catches a prop the child does not declare', () => {
			expect(check('v-model="q" :active="a"')).toEqual([expect.stringContaining('":active"')])
		})

		it('catches a required prop that is left unbound', () => {
			expect(check(':is-drawing="d"')).toEqual([expect.stringContaining('required prop "modelValue"')])
		})
	})
})
