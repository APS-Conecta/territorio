import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * Static checks over the components, for mistakes that compile and ship.
 *
 * Both of these exist because they happened, and because the same three things
 * missed each one: the unit suites exercise `filters.js` and `taxonomy.js`
 * directly and never load a component, webpack emits whatever it is given, and
 * nothing in the repo drives the app.
 *
 * 1. `listFor` was called in App.vue without being imported. The `listed`
 *    computed threw a ReferenceError and the whole Datos column went with it.
 * 2. `native-type="submit"` was passed to `NcButton`, which is the v8 prop name.
 *    In v9 it is `type`, so the prop fell through as an inert HTML attribute,
 *    the button stayed a `type="button"`, and Guardar never submitted its form —
 *    for eight slices, since the very first one.
 * 3. `bindTooltip(feature.name)` handed Leaflet a string, and its
 *    `DivOverlay._updateContent` assigns string content with `innerHTML` — so a
 *    stored name was parsed as markup. Nextcloud's CSP stops script and every
 *    exfiltration channel, but nothing covers navigation, and an injected
 *    `<meta http-equiv="refresh">` was confirmed navigating a real browser.
 *
 *    Checked here rather than in the browser gate on purpose: a tooltip only
 *    exists while Leaflet is hovering a shape it has chosen to render, which
 *    made five gate runs measure the render lifecycle instead of the bug. The
 *    rule is a text node rather than an escape because a node cannot be parsed
 *    at all, where an escape is a thing you can get subtly wrong.
 */
// fileURLToPath, not .pathname: the repo lives under a directory with a space
// in its name, and .pathname hands back the %20 still escaped.
const SRC = fileURLToPath(new URL('../../src/', import.meta.url))

const componentFiles = [
	join(SRC, 'App.vue'),
	join(SRC, 'AdminSettings.vue'),
	...readdirSync(join(SRC, 'components')).map((name) => join(SRC, 'components', name)),
]

/** Every name a local module exports, so we know what a component could be using. */
const exportsOf = (module) => [...readFileSync(join(SRC, module), 'utf8')
	.matchAll(/export (?:function|const) (\w+)/g)].map((match) => match[1])

const MODULES = ['filters.js', 'marks.js', 'taxonomy.js']

describe('component imports', () => {
	it.each(componentFiles)('%s calls nothing it has not imported', (file) => {
		const source = readFileSync(file, 'utf8')
		const script = source.slice(source.indexOf('<script>'), source.indexOf('</script>'))

		MODULES.forEach((module) => {
			const imported = script.match(new RegExp(`import\\s*{([^}]*)}\\s*from\\s*'[^']*${module}'`))
			const names = (imported?.[1] ?? '').split(',').map((name) => name.trim())

			exportsOf(module).forEach((name) => {
				// A component may legitimately define a computed or a method of the
				// same name — FeatureDetail has its own `isDivision` — so definitions
				// are dropped before looking for calls.
				const calls = script.replace(new RegExp(`^\\s*${name}\\s*\\([^)]*\\)\\s*{`, 'gm'), '')

				// A call, not a mention: `foo,` in an object literal is a use too, but
				// a call is the one that throws, and it is the one that cannot be a
				// coincidence of naming.
				if (!new RegExp(`(^|[^.\\w])${name}\\s*\\(`, 'm').test(calls)) {
					return
				}

				expect(names, `${file} calls ${name}() from ${module}`).toContain(name)
			})
		})
	})
})

/**
 * Props that @nextcloud/vue removed.
 *
 * Vue passes an unknown prop straight through as an HTML attribute, so a stale
 * prop name is silent: no warning, no build error, just a component that quietly
 * keeps its default. `native-type` is the one that cost this project every save
 * it never made — v8's name for what v9 calls `type`, which on a button IS the
 * native type, so the button stayed `type="button"` inside a form.
 *
 * ponytail: a hard-coded list of two, matched as source text. A real check reads
 * the installed component's prop types — that is `vue-tsc`, and this is the
 * cheapest thing that fails on what has actually bitten us.
 */
describe('props @nextcloud/vue 9 no longer has', () => {
	const GONE = {
		'native-type': 'type',
		nativeType: 'type',
	}

	it.each(componentFiles)('%s uses none of them', (file) => {
		const source = readFileSync(file, 'utf8')

		Object.entries(GONE).forEach(([dead, live]) => {
			expect(source, `${file}: "${dead}" is a v8 prop — v9 calls it "${live}"`)
				.not.toMatch(new RegExp(`(^|[\\s:])${dead}\\s*=`, 'm'))
		})
	})
})

describe('map tooltips', () => {
	it.each(componentFiles)('%s hands Leaflet a node, never a string to parse', (file) => {
		// Every entry point to the same DivOverlay._updateContent: the bug is one
		// bindPopup away, and a guard that knows only bindTooltip is silent about it.
		const sinks = /(?:bindTooltip|bindPopup|setTooltipContent|setPopupContent)\(([^)]*)/g
		const args = [...readFileSync(file, 'utf8').matchAll(sinks)]
			.map((match) => match[1].trim())

		args.forEach((argument) => {
			expect(argument, `bindTooltip(${argument}) in ${file}`)
				.toMatch(/^document\.createTextNode\(/)
		})
	})
})

/**
 * What a shape IS decides how it is drawn, and no component may answer that by
 * hand.
 *
 * `shapeStyle(null, …)` is the figure branch: solid outline, weight 4, a fill,
 * and geoman handles on top. TerritorioMap passed a literal `null` for the
 * draft layer, reasoning that a draft is a shape the clinic is drawing right
 * now — true of a new polygon, false of an opened Unidad Vecinal, because
 * `draftFrom` copies the geometry of ANY opened shape record onto the draft
 * (App.vue). So opening a cadastre boundary from the Datos list gave it the
 * fill #21 exists to remove, while marks.test.js asserted the ground branch the
 * app was no longer taking — the criterion read as covered and the real path
 * was untested.
 *
 * Static, for the same reason as the checks above: nothing in this repo loads a
 * component, so a slug the app never passes reaches no suite.
 */
describe('what a shape is drawn as', () => {
	it.each(componentFiles)('%s never tells shapeStyle a shape is figure by hand', (file) => {
		const slugs = [...readFileSync(file, 'utf8').matchAll(/shapeStyle\(([^,]*),/g)]
			.map((match) => match[1].trim())

		slugs.forEach((slug) => {
			expect(slug, `shapeStyle(${slug}, …) in ${file}`).not.toBe('null')
		})
	})
})
