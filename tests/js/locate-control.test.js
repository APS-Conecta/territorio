import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * «¿Dónde estoy?» stops the GPS when it fails (maps-compare research, FR-2).
 *
 * Measured on NC 34: two clicks with the permission refused left
 * `watchPosition` called twice and `clearWatch` never. Leaflet's
 * `_handleGeolocationError` fires `locationerror` and keeps `_locationWatchId`,
 * so an error handler that only flips the button leaves the first watch alive,
 * and the next click — the start branch, `locating` being false again — starts
 * a second one on top of it. The one line that ends this is `stopLocate()` in
 * `onLocationError`, and the leak survived 78a94e3 → 4ffba02 with
 * locate.test.js green: that file tests the pure helpers and has no reference
 * to stopLocate at all.
 *
 * Source text, as in sidebar.test.js: nothing in this repo mounts a component.
 * That the watch is really cleared is tools/browser-check.py's to count
 * (`the_locate_control_can_ask_where_the_person_is`: watch 1 / clear 1 after a
 * refused click); what can be settled here is that the call is in the handler,
 * and that it comes first.
 *
 * ponytail: reading the SFC as a string, for the reason sidebar.test.js gives.
 */
// fileURLToPath, not .pathname: see imports.test.js.
const SRC = fileURLToPath(new URL('../../src/', import.meta.url))

const control = readFileSync(`${SRC}components/LocateControl.vue`, 'utf8')

/**
 * The body of one method or hook, from its signature to the closing brace at
 * the same indentation. The object literals inside them (`{ type: 'error', … }`)
 * sit on one line and never produce a `\n<tabs>}`, which is why the end is the
 * first one after the signature.
 */
const member = (signature) => {
	const at = control.indexOf(signature)
	expect(at, `LocateControl.vue has no ${signature}`).toBeGreaterThan(-1)
	const lineStart = control.lastIndexOf('\n', at) + 1
	const tabs = control.slice(lineStart, at)

	return control.slice(at, control.indexOf(`\n${tabs}}`, at))
}

describe('onLocationError', () => {
	it('stops the watch Leaflet started, so a retry starts one watch and not one more', () => {
		expect(member('onLocationError(event) {')).toContain('this.map.stopLocate()')
	})

	it('stops it before anything else, so a throw further down cannot leave the GPS awake', () => {
		const handler = member('onLocationError(event) {')

		// Both present first: -1 is less than any index, so a handler with no
		// stopLocate() at all would pass the comparison alone.
		expect(handler).toContain('this.map.stopLocate()')
		expect(handler).toContain('this.locating = false')
		expect(handler.indexOf('this.map.stopLocate()'), 'stopLocate() must be the first statement')
			.toBeLessThan(handler.indexOf('this.locating = false'))
	})
})

// #212: pins only where the reset sits; the browser gate's second position proves the behaviour.
describe('toggleLocate', () => {
	it('re-arms the first-fix recentre in the start branch, so every watch centres on its own first fix', () => {
		const handler = member('toggleLocate(button) {')

		// Present first: indexOf -1 would slice one character and blame the wrong line.
		expect(handler).toContain('this.locating = true')
		expect(handler.slice(handler.indexOf('this.locating = true'))).toContain('this.locatedOnce = false')
	})
})

describe('the two stops that already worked', () => {
	it('still stops on toggle and on unmount', () => {
		expect(member('toggleLocate(button) {')).toContain('this.map.stopLocate()')
		expect(member('beforeUnmount() {')).toContain('this.map?.stopLocate()')
	})
})
