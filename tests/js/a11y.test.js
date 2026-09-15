import { describe, expect, it, vi } from 'vitest'
import { makeOperable } from '../../src/a11y.js'

/**
 * Keyboard reach for a vector layer (issue #25).
 *
 * `L.Marker` defaults to `keyboard: true` and sets `tabIndex` and `role`
 * itself, so a Place is already reachable. `L.Path` has no keyboard option at
 * all, so a Sector or a Unidad Vecinal could be selected with a mouse and by no
 * other means.
 *
 * The element is faked rather than built: this repo runs vitest in node with no
 * DOM, and what matters here is the contract — the attributes a screen reader
 * reads, and which keys reach the SAME callback the click handler uses. That
 * the outline is visible and that Tab actually lands on the path are the
 * browser gate's job (tools/browser-check.py), which is where they can be seen.
 */
const fakeElement = () => {
	const attributes = {}
	const listeners = []

	return {
		attributes,
		setAttribute: (name, value) => {
			attributes[name] = value
		},
		addEventListener: (type, handler) => {
			listeners.push({ type, handler })
		},
		/** Press a key on it, the way a browser would. */
		press(key) {
			const event = { key, preventDefault: vi.fn() }
			listeners
				.filter(({ type }) => type === 'keydown')
				.forEach(({ handler }) => handler(event))
			return event
		},
	}
}

describe('makeOperable', () => {
	it('gives the shape a tab stop, a role and the Feature name to announce', () => {
		const element = fakeElement()

		makeOperable(element, 'Sector Los Castaños', () => {})

		expect(element.attributes.tabindex).toBe('0')
		expect(element.attributes.role).toBe('button')
		expect(element.attributes['aria-label']).toBe('Sector Los Castaños')
	})

	it('activates on Enter', () => {
		const element = fakeElement()
		const activate = vi.fn()

		makeOperable(element, 'Sector 3', activate)
		element.press('Enter')

		expect(activate).toHaveBeenCalledTimes(1)
	})

	it('activates on Space, which is the other half of role="button"', () => {
		const element = fakeElement()
		const activate = vi.fn()

		makeOperable(element, 'Sector 3', activate)
		const event = element.press(' ')

		expect(activate).toHaveBeenCalledTimes(1)
		// Or the page scrolls under the person at the same time.
		expect(event.preventDefault).toHaveBeenCalled()
	})

	it('leaves every other key alone, so Tab still moves on', () => {
		const element = fakeElement()
		const activate = vi.fn()

		makeOperable(element, 'Sector 3', activate)
		for (const key of ['Tab', 'Escape', 'a', 'ArrowLeft']) {
			const event = element.press(key)
			expect(event.preventDefault).not.toHaveBeenCalled()
		}

		expect(activate).not.toHaveBeenCalled()
	})

	it('does nothing when the layer has no element yet', () => {
		// The caller passes `layer.getElement?.()`: undefined when the layer has
		// no such method, null for a Path whose SVG is not built yet. Neither may
		// take the redraw down.
		expect(() => makeOperable(null, 'Sector 3', () => {})).not.toThrow()
		expect(() => makeOperable(undefined, 'Sector 3', () => {})).not.toThrow()
	})
})
