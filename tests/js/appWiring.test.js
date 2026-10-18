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
 * omnibox-streets.test.js), so this reads the .vue files instead, as
 * sidebar.test.js and locate-control.test.js do.
 *
 * Also fails on: a listener for an event the child declares but never fires
 * (FeatureDetail's `cancel` was one: nothing could leave an open record), a
 * handler or bound name App.vue does not define (Vue compiles it to
 * `undefined` and the control does nothing), and a component tag it cannot map
 * to an import (a typo'd or kebab-cased tag is skipped by Vue, not reported).
 *
 * Scope: only the components App.vue imports from ./components/. The
 * @nextcloud/vue ones (NcButton, NcNoteCard...) are a library's contract.
 *
 * ponytail: Options API only, and single-quoted `./components/X.vue` imports,
 * which is all src/ has; anything else throws or is reported rather than
 * skipped. Not checked, because nothing here needs it and each is a page of
 * code: the type of a bound value (a Set where a Map is declared), named
 * slots, `v-bind="obj"` and dynamic arguments (reported, to be read by hand).
 * tools/browser-check.py --only redesign measures what this cannot.
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

/** Event names a component fires, read off its whole source — template and script alike. */
function firedBy(source) {
	const literal = [...source.matchAll(/\$?emit\(\s*(['"`])([^'"`]+)\1/g)].map(([, , name]) => camelize(name))
	const dynamic = /\$?emit\(\s*[^'"`\s]/.test(source)
	return { fired: new Set(literal), dynamic }
}

/** Everything App.vue's template may name: its data, computed, methods and imports. */
function namesOf(script) {
	const names = new Set(['$event', '$refs', '$emit', '$slots', '$attrs', '$props', '$el', 'true', 'false', 'null', 'undefined',
		'this', 'typeof', 'in', 'of', 'new', 'void', 'NaN', 'Infinity', 'Math', 'Number', 'String', 'Boolean', 'Array', 'Object', 'Set', 'Map', 'JSON'])
	const program = babelParse(script, { sourceType: 'module' }).program
	const key = (property) => property.key?.name ?? property.key?.value
	for (const statement of program.body) {
		if (statement.type === 'ImportDeclaration') {
			statement.specifiers.forEach((specifier) => names.add(specifier.local.name))
		} else if (statement.type === 'VariableDeclaration') {
			statement.declarations.forEach((declaration) => declaration.id.name && names.add(declaration.id.name))
		} else if (statement.type === 'FunctionDeclaration') {
			names.add(statement.id.name)
		}
	}
	const exported = program.body.find((statement) => statement.type === 'ExportDefaultDeclaration').declaration
	for (const section of exported.properties) {
		const name = key(section)
		if (['methods', 'computed', 'components'].includes(name)) {
			section.value.properties.forEach((property) => names.add(key(property)))
		} else if (name === 'data') {
			const returned = section.body?.body?.find((statement) => statement.type === 'ReturnStatement')
				?? section.value?.body?.body?.find((statement) => statement.type === 'ReturnStatement')
			returned?.argument.properties.forEach((property) => names.add(key(property)))
		}
	}
	return names
}

/** The names a template expression reads, minus string contents and `.member` accesses. */
function identifiersIn(expression) {
	const bare = expression.replace(/(['"`])(?:\\.|(?!\1).)*\1/g, '""')
	return [...bare.matchAll(/(?<![\w$.])[A-Za-z_$][\w$]*/g)].map(([name]) => name)
		.filter((name) => !/^\d/.test(name))
}

const pascal = (tag) => tag.replace(/(^|-)(\w)/g, (_, __, letter) => letter.toUpperCase())

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
 * @return {{problems: string[], checked: Set<string>}} one line per problem, and the components compared
 */
export function inspect(appSource, readComponent) {
	const { descriptor } = parse(appSource, { filename: 'App.vue' })
	const imports = importedComponents(descriptor.script.content)
	const known = namesOf(descriptor.script.content)
	const contracts = {}
	const checked = new Set()
	const problems = []

	const undefinedNames = (expression, where, what) => {
		for (const name of identifiersIn(expression)) {
			if (!known.has(name)) {
				problems.push(`${where}: ${what} "${expression}" uses "${name}", which App.vue does not define`)
			}
		}
	}

	for (const element of elements(descriptor.template.ast)) {
		if (element.tagType !== COMPONENT_TAG || /^Nc[A-Z]/.test(element.tag) || element.tag === 'component') {
			continue
		}
		const where = `<${element.tag}> (line ${element.loc.start.line})`
		const file = imports[pascal(element.tag)]
		if (file === undefined) {
			problems.push(`${where}: no ./components import by this name, so it is not a component and Vue renders nothing`)
			continue
		}
		checked.add(file)
		contracts[file] ??= { ...contractOf(readComponent(file), file), ...firedBy(readComponent(file)) }
		const { props, emits, fired, dynamic } = contracts[file]
		const bound = new Set()

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
			const expression = prop.exp?.content ?? ''
			if ((prop.name === 'bind' || prop.name === 'on') && arg === null) {
				problems.push(`${where}: "${prop.rawName}" has no fixed name, so its props or events cannot be checked; read it by hand`)
			} else if (prop.name === 'bind') {
				bound.add(camelize(arg))
				if (!props.has(camelize(arg)) && !PASS_THROUGH.test(arg)) {
					problems.push(`${where}: binds ":${arg}", which ${file} does not declare as a prop`)
				}
				undefinedNames(expression, where, `":${arg}"`)
			} else if (prop.name === 'on') {
				if (!emits.has(camelize(arg))) {
					problems.push(`${where}: listens for "@${arg}", which ${file} never emits`)
				} else if (!dynamic && !fired.has(camelize(arg))) {
					problems.push(`${where}: listens for "@${arg}", which ${file} declares but never fires`)
				}
				// A handler is a name or a call; `x = $event` and the like assign to App's own data.
				undefinedNames(expression, where, `"@${arg}"`)
			} else if (prop.name === 'model') {
				const name = arg ?? 'modelValue'
				bound.add(camelize(name))
				if (!props.has(camelize(name))) {
					problems.push(`${where}: v-model on "${name}", which ${file} does not declare as a prop`)
				}
				if (!emits.has(camelize(`update:${name}`))) {
					problems.push(`${where}: v-model on "${name}", but ${file} never emits "update:${name}"`)
				}
				undefinedNames(expression, where, 'v-model')
			}
		}

		for (const [name, { required }] of props) {
			if (required && !bound.has(name)) {
				problems.push(`${where}: required prop "${name}" of ${file} is not bound`)
			}
		}
	}
	return { problems, checked }
}

export const wiringProblems = (appSource, readComponent) => inspect(appSource, readComponent).problems

describe('App.vue wiring', () => {
	const { problems, checked } = inspect(
		readSource(new URL('../../src/App.vue', import.meta.url)),
		(file) => readSource(new URL(file, componentsDir)),
	)

	it('listens for, and binds, only what each child component declares', () => {
		expect(problems).toEqual([])
	})

	it('actually compared the components, so a parser that stopped matching cannot pass empty', () => {
		expect([...checked].sort()).toEqual(expect.arrayContaining([
			'DrawSectorButton.vue', 'FeatureDetail.vue', 'FeatureList.vue', 'LayersDropdownButton.vue',
			'OmniboxControl.vue', 'TaxonomyDrawer.vue', 'TerritorioMap.vue',
		]))
	})

	describe('the checker itself', () => {
		const child = `<template><div /></template>
<script>
export default {
	props: { modelValue: { type: String, required: true }, isDrawing: Boolean, taxonomy: { type: Array } },
	emits: ['draw-sector', 'update:modelValue', 'update:showRetired', 'never-fired'],
	methods: { go() { this.$emit('draw-sector'); this.$emit('update:showRetired') } },
}
</script>`
		const app = (attributes, extra = '') => `<template><Child ${attributes} />${extra}</template>
<script>
import Child from './components/Child.vue'
export default {
	components: { Child },
	data() { return { q: '', d: false, t: [], r: false, a: 0 } },
	methods: { go() {} },
}
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

		it('catches a listener for an event the child declares but never fires', () => {
			expect(check('v-model="q" @never-fired="go"')).toEqual([expect.stringContaining('declares but never fires')])
		})

		it('catches a handler or bound name App.vue does not define', () => {
			const problems = check('v-model="q" @draw-sector="goo" :taxonomy="tt"')
			expect(problems).toHaveLength(2)
			expect(problems.join('\n')).toMatch(/uses "tt"[^]*uses "goo"|uses "goo"[^]*uses "tt"/)
		})

		it('catches a component tag it cannot map to an import', () => {
			expect(wiringProblems(app('v-model="q"', '<OtherThing />'), () => child))
				.toEqual([expect.stringContaining('no ./components import')])
		})

		it('asks for a by-hand read of a spread or dynamic binding', () => {
			expect(check('v-model="q" v-bind="{ zzz: 1 }"')).toEqual([expect.stringContaining('no fixed name')])
		})

		it('catches a required prop that is left unbound', () => {
			expect(check(':is-drawing="d"')).toEqual([expect.stringContaining('required prop "modelValue"')])
		})
	})
})
