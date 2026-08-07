import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * Every helper a component uses is imported.
 *
 * This exists because it didn't: `listFor` was called in App.vue without being
 * imported, the `listed` computed threw a ReferenceError, and the whole Datos
 * column went with it — shipped, in a build, past three green suites. Nothing
 * caught it. The unit tests exercise `filters.js` directly and never load a
 * component, webpack is happy to emit a call to a name it cannot see, and there
 * is no JS linter here.
 *
 * A linter would cover this and much more; until there is one, this is the
 * cheapest thing that fails on exactly that mistake.
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

const MODULES = ['filters.js', 'taxonomy.js']

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
