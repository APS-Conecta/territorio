/**
 * What Leaflet does not give a vector layer: a way in from the keyboard.
 *
 * `L.Marker` takes `keyboard: true` by default and sets `tabIndex` and `role`
 * on its own icon, which is why a Place is already reachable. `L.Path` has no
 * keyboard option at all — neither `Path.js` nor `Layer.js` mentions one — so
 * every Zone and every Route in the Registry could be selected with a mouse and
 * by no other means (issue #25, docs/design/map-legibility.md D6). No plugin
 * fixes it; this is ours.
 *
 * A module of its own rather than ten lines inside the component, because this
 * repo runs vitest in node with no DOM and loads no component anywhere: inline,
 * the only proof that any of it works would be the browser gate.
 */

/**
 * Make one shape operable from the keyboard.
 *
 * `activate` must be the SAME function the layer's click handler runs, not a
 * parallel emit: the click handler holds the `placing` guard that lets a click
 * through to the map while a draft is waiting for its coordinate, and a second
 * path around it would select a Sector at the one moment selecting one moves
 * the open record.
 *
 * @param {Element|null} element the layer's SVG element, `getElement()`
 * @param {string} name what a screen reader announces — the Feature's name
 * @param {Function} activate what a click on this layer already does
 */
export function makeOperable(element, name, activate) {
	// `_initPath` builds the SVG only when the layer joins the map, so this is
	// null before `addLayer` — and a GeometryCollection comes back from
	// `geometryToLayer` as a FeatureGroup, which has no element at any point.
	// Neither may take the whole redraw down.
	if (!element) {
		return
	}

	element.setAttribute('tabindex', '0')
	element.setAttribute('role', 'button')
	element.setAttribute('aria-label', name)

	element.addEventListener('keydown', (event) => {
		// Both keys, because the element now says it is a button: Enter is what
		// the issue asks for and Space is what a screen reader user presses on
		// anything announced as one.
		if (event.key !== 'Enter' && event.key !== ' ') {
			return
		}

		// Space scrolls the page otherwise, and it would scroll it out from under
		// the record that just opened.
		event.preventDefault()
		activate(event)
	})
}
