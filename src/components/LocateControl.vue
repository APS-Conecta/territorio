<script>
import L from 'leaflet'
import { accuracyLabel, isFixTrustworthy, locateErrorMessage } from '../locate.js'

/**
 * The «¿Dónde estoy?» button, under the zoom control (L1-32: out of
 * TerritorioMap.vue).
 *
 * A real `<button>` inside a Leaflet control rather than a div with a click
 * handler, so it is focusable, announced and operable from the keyboard
 * without any of `a11y.js` — that helper exists for SVG layers, which have no
 * such element to borrow.
 *
 * Renders nothing: the control's DOM is Leaflet's, outside Vue's tree, and the
 * notices it produces stay in the PARENT's template (`locateNotice` is the
 * only place they are ever drawn). Mounting is gated on the parent's
 * `mapReady` — the map handle is deliberately non-reactive (see
 * TerritorioMap's created() docblock), and a child's `mounted()` runs BEFORE
 * the parent's, so the handle arrives as a plain prop only once the parent
 * has finished building it.
 */
const LOCATION_COLOUR = '#1a73e8'

export default {
	name: 'LocateControl',

	props: {
		/** The Leaflet map, handed over once the parent has built it. Never proxied. */
		map: { type: Object, required: true },
	},

	emits: ['notice'],

	created() {
		// Non-data instance handles, for the reason the parent's created()
		// gives: nothing here is rendered, so none of it needed to be reactive.
		this.locateButton = null
		this.locationLayer = null
		this.locatedOnce = false
		this.control = null
	},

	data() {
		return {
			// The control's own state, so the button can say whether pressing
			// it again stops following.
			locating: false,
		}
	},

	mounted() {
		this.addLocateControl()
		this.map.on('locationfound', this.onLocationFound)
		this.map.on('locationerror', this.onLocationError)
	},

	beforeUnmount() {
		// Before the parent's `map.remove()` lands, and explicitly: a watch
		// started with `locate({ watch: true })` is a `navigator.geolocation`
		// subscription held by the browser, which would otherwise keep waking
		// the GPS after the map it fed is gone.
		this.map?.stopLocate()
		this.map?.off('locationfound', this.onLocationFound)
		this.map?.off('locationerror', this.onLocationError)
		this.locationLayer?.remove()
		this.locationLayer = null
	},

	methods: {
		addLocateControl() {
			const control = L.control({ position: 'topright' })

			control.onAdd = () => {
				const container = L.DomUtil.create('div', 'leaflet-bar territorio-locate')
				const button = L.DomUtil.create('a', '', container)
				button.href = '#'
				button.role = 'button'
				button.textContent = '◎'
				button.title = '¿Dónde estoy?'
				button.setAttribute('aria-label', '¿Dónde estoy?')

				// Or the click pans the map underneath and the href scrolls the page.
				L.DomEvent.disableClickPropagation(container)
				L.DomEvent.on(button, 'click', (event) => {
					L.DomEvent.preventDefault(event)
					this.toggleLocate(button)
				})

				this.locateButton = button
				return container
			}

			control.addTo(this.map)
			this.control = control
		},

		/**
		 * Start or stop following the device's position.
		 *
		 * `watch`, not a single reading: somebody using this is walking a sector
		 * with a phone, and a one-shot fix is stale by the next corner. Stopping
		 * is half the feature — a watch nobody turns off keeps the GPS awake.
		 */
		toggleLocate(button) {
			if (this.locating) {
				this.map.stopLocate()
				this.locating = false
				this.locationLayer?.remove()
				this.locationLayer = null
				this.$emit('notice', { text: '', type: 'warning' })
				button?.setAttribute('aria-pressed', 'false')
				return
			}

			this.locating = true
			this.$emit('notice', { text: '', type: 'warning' })
			button?.setAttribute('aria-pressed', 'true')
			this.map.locate({
				watch: true,
				enableHighAccuracy: true,
				// Not `setView`: this map may be mid-draw, and Leaflet moving the
				// view under a hand placing a vertex is the same class of problem
				// the animated zoom caused. The first fix recentres, later ones do
				// not — see below.
				setView: false,
			})
		},

		/**
		 * Draw where the person is, and say how sure we are.
		 *
		 * The accuracy circle is not decoration. This app decides which Sector a
		 * point falls in (ADR-0008), and a fix taken indoors is routinely loose
		 * enough to sit on either side of a boundary while looking exactly as
		 * confident as a good one. So a loose fix is drawn as the circle it really
		 * is, and says so in words.
		 */
		onLocationFound(event) {
			const accuracy = event.accuracy
			const trustworthy = isFixTrustworthy(accuracy)

			this.locationLayer?.remove()
			this.locationLayer = L.layerGroup([
				// The area the reading actually covers, always — at a good fix it is
				// a small ring around the dot, which reads as precision rather than
				// as a claim.
				L.circle(event.latlng, {
					radius: Math.max(accuracy || 0, 1),
					interactive: false,
					weight: 1,
					color: LOCATION_COLOUR,
					fillColor: LOCATION_COLOUR,
					fillOpacity: 0.12,
				}),
				// A dot, not a pin: a pin here would read as a Place somebody filed,
				// and hue in this app means a Category (ADR-0013). The white ring is
				// what keeps it legible over both the earth and the water of the
				// basemap.
				L.circleMarker(event.latlng, {
					radius: 6,
					interactive: false,
					weight: 3,
					color: '#ffffff',
					fillColor: LOCATION_COLOUR,
					fillOpacity: 1,
				}),
			]).addTo(this.map)

			if (!this.locatedOnce) {
				this.locatedOnce = true
				this.map.setView(event.latlng, Math.max(this.map.getZoom(), 16), { animate: false })
			}

			this.$emit('notice', {
				type: 'warning',
				text: trustworthy
					? ''
					: `Su ubicación es aproximada (${accuracyLabel(accuracy)}). Puede no coincidir con el sector que se muestra.`,
			})
		},

		onLocationError(event) {
			// First, before the state flips: Leaflet fires `locationerror` and
			// keeps `_locationWatchId`, so without this the first watch stays
			// alive — measured on NC 34: two refused clicks, watchPosition ×2,
			// clearWatch ×0 — and the next click, `locating` being false again,
			// starts a second one on top of it. Harmless when nothing is
			// watching, so no guard. (maps-compare research, FR-2)
			this.map.stopLocate()
			this.locating = false
			this.locateButton?.setAttribute('aria-pressed', 'false')
			this.locationLayer?.remove()
			this.locationLayer = null
			this.$emit('notice', { type: 'error', text: locateErrorMessage(event?.code) })
		},
	},

	render() {
		return null
	},
}
</script>
