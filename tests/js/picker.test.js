import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * Where the pictogram picker's focus guard sits (#46).
 *
 * The grid opens beside the field a new Category is being named in, and that
 * field cancels the whole add on blur (#45) — `cancel()` clears `editing`,
 * `picking` AND `draftGlyph`, so anything that moves the focus out of it
 * throws the typed name and the already-picked mark away with no message.
 * `@mousedown.prevent` is what keeps the focus where it is.
 *
 * The guard belongs on the picker ROOT, not on its cells. Cell-only guards
 * leave the title, the container's own padding and the `gap` between the
 * flex-wrapped 26px cells unprotected, and a mousedown on any of those blurs
 * the field: 215 circles laid out in a wrapped row are mostly gaps, so the
 * dead space is the likely hit, not the unlikely one.
 *
 * Read out of the SOURCE, the way marks.test.js reads the pin sizes: nothing
 * in this suite mounts a component, so what the guard DOES is the browser
 * gate's job (`a_pictogram_can_be_picked_while_a_category_is_named`, which
 * clicks the title and watches the name survive). What can be settled here is
 * where it sits — and this test fails on the cell-only version that shipped.
 */
describe('the picker keeps the focus in the field beside it', () => {
	// fileURLToPath, not .pathname, for the reason marks.test.js gives.
	const ROOT = fileURLToPath(new URL('../../', import.meta.url))
	const capas = readFileSync(`${ROOT}src/components/CapasPanel.vue`, 'utf8')

	/** The whole opening tag of the one element carrying `attribute`. */
	const openingTag = (attribute) => {
		const at = capas.indexOf(attribute)
		expect(at, `no element carries ${attribute}`).toBeGreaterThan(-1)
		const start = capas.lastIndexOf('<', at)

		return capas.slice(start, capas.indexOf('>', at))
	}

	it('guards the whole grid, its title and its gaps, at the root', () => {
		expect(openingTag('class="territorio-capas__picker"')).toMatch(/@mousedown\.prevent/)
	})

	it('guards the swatch that opens it, which sits outside the grid', () => {
		// Same hazard, different element: this one is a sibling of the field,
		// so the root guard above cannot cover it.
		expect(openingTag('title="Elegir pictograma de la nueva categoría"'))
			.toMatch(/@mousedown\.prevent/)
	})
})
