<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import L from 'leaflet'
import { warnError } from '../api.js'
import {
	chosen,
	cornersOf,
	hintFor,
	nextActive,
	placeDetail,
	placesMatching,
	searchStatus,
} from '../streets.js'

/**
 * «Buscar una calle», under «¿Dónde estoy?»: type part of a street's name,
 * choose one of the places it names, and the map goes there.
 *
 * Built the way LocateControl is, and for its reasons: the DOM is Leaflet's,
 * made by hand in `onAdd` and changed by hand after that, and this component
 * renders nothing. The deciding — what matches, which Unidad Vecinal, where
 * the arrow keys go, what to say — is streets.js's, where it is tested; this
 * file is the DOM and the map.
 *
 * A WAI-ARIA combobox with a list
 * (https://www.w3.org/WAI/ARIA/apg/patterns/combobox/): the focus stays in the
 * box, the arrow keys move the active row under it, Enter takes the active
 * row and Escape clears. Never the first match by default (ADR-0007).
 *
 * The streets arrive once, the first time the box is focused, and are kept for
 * the session: they change only when somebody re-imports the cadastre
 * (ADR-0003).
 */

/** One map per page, so one list: the id the combobox's ARIA points at. */
const LIST_ID = 'territorio-search-list'

export default {
	name: 'SearchControl',

	props: {
		/** The Leaflet map, handed over once the parent has built it. Never proxied. */
		map: { type: Object, required: true },
		/**
		 * The comuna's Unidades Vecinales, whatever Capas is hiding: what tells
		 * the rows of one name apart (streets.js, hintFor).
		 */
		unidades: { type: Array, default: () => [] },
	},

	created() {
		// Non-data instance handles, for the reason TerritorioMap's created()
		// gives — nothing here is rendered by Vue — and one of its own: `places`
		// is five thousand rows, and data() would proxy every one of them.
		this.input = null
		this.list = null
		this.status = null
		// null until the first load answers: «no streets» is said about an
		// answer, never about a request still on its way (searchStatus).
		this.places = null
		this.loading = false
		this.failed = false
		// Whether the search is showing its answer — from a focus or a keystroke
		// until a pick, a blur or Escape. `options` is what the list holds, empty
		// whenever it is closed, and `active` is the row the keyboard is on, -1
		// for none.
		this.open = false
		this.options = []
		this.active = -1
	},

	mounted() {
		this.addSearchControl()
	},

	methods: {
		addSearchControl() {
			const control = L.control({ position: 'topright' })

			control.onAdd = () => {
				const container = L.DomUtil.create('div', 'leaflet-bar territorio-search')

				const input = L.DomUtil.create('input', 'territorio-search__input', container)
				input.type = 'text'
				input.placeholder = 'Buscar una calle'
				input.autocomplete = 'off'
				// A street name underlined in red is not a mistake anybody can fix.
				input.spellcheck = false
				input.setAttribute('aria-label', 'Buscar una calle')
				input.setAttribute('role', 'combobox')
				input.setAttribute('aria-autocomplete', 'list')
				input.setAttribute('aria-controls', LIST_ID)
				input.setAttribute('aria-expanded', 'false')

				const list = L.DomUtil.create('ul', 'territorio-search__list', container)
				list.id = LIST_ID
				list.setAttribute('role', 'listbox')
				list.setAttribute('aria-label', 'Calles')
				list.hidden = true

				// Always in the page and empty at rest: a live region has to be
				// there before it speaks, or the first thing it says is lost.
				const status = L.DomUtil.create('p', 'territorio-search__status', container)
				status.setAttribute('role', 'status')

				// Or a click in the box would place the open draft under it (the
				// map's own click), and the wheel over the list would zoom the map.
				L.DomEvent.disableClickPropagation(container)
				L.DomEvent.disableScrollPropagation(container)
				// Focus and every keystroke alike: the first one loads the streets,
				// and after a failure the next one tries again — which is what the
				// failure message tells the person to do (searchStatus).
				L.DomEvent.on(input, 'focus input', () => this.ask())
				L.DomEvent.on(input, 'blur', () => this.close())
				L.DomEvent.on(input, 'keydown', (event) => this.onKeydown(event))
				// Keeps the focus in the box while a row is clicked, so the blur
				// above does not take the list away before the click lands.
				L.DomEvent.on(list, 'mousedown', L.DomEvent.preventDefault)

				this.input = input
				this.list = list
				this.status = status
				return container
			}

			control.addTo(this.map)
		},

		/** The search is in use: load the streets if they are not here yet, and answer. */
		ask() {
			this.open = true
			this.load()
			this.search()
		},

		/**
		 * The streets, once. A failure leaves `places` null, so the next focus or
		 * keystroke asks again; while one request is out, nobody sends another.
		 */
		async load() {
			if (this.places !== null || this.loading) {
				return
			}

			this.loading = true
			this.failed = false
			this.draw()
			try {
				const { data } = await axios.get(generateUrl('/apps/territorio/boundaries/names'))
				this.places = data
			} catch (exception) {
				// The search failing must not take the map with it. It says so in
				// words instead (searchStatus), where an empty list would say nothing.
				warnError('could not load the street names', exception)
				this.failed = true
			} finally {
				this.loading = false
			}
			this.search()
		},

		/** Match what is typed against the streets, and show it. */
		search() {
			this.options = this.open && this.places !== null
				? placesMatching(this.places, this.input.value)
				: []
			this.draw()
		},

		close() {
			this.open = false
			this.options = []
			this.draw()
		},

		/**
		 * The list and the status line, rebuilt from the state above. At most
		 * twelve rows, so rebuilding them is simpler than keeping them in step —
		 * and no row is active after a rebuild: the keyboard starts again from
		 * the box.
		 */
		draw() {
			const expanded = this.options.length > 0

			this.active = -1
			this.list.replaceChildren(...this.options.map((place, index) => this.optionFor(place, index)))
			this.list.hidden = !expanded
			this.input.setAttribute('aria-expanded', String(expanded))
			this.input.removeAttribute('aria-activedescendant')
			this.status.textContent = this.open
				? searchStatus({
					loading: this.loading,
					failed: this.failed,
					places: this.places,
					query: this.input.value,
					matches: this.options,
				})
				: ''
		},

		/**
		 * One row: the name, and under it which Unidad Vecinal and how many
		 * tramos — what tells sixteen «Pasaje Dos» apart.
		 *
		 * Text, never markup: these names come out of an imported cadastre, not
		 * from anyone here (#62).
		 */
		optionFor(place, index) {
			const option = L.DomUtil.create('li', 'territorio-search__option')
			option.id = `${LIST_ID}-${index}`
			option.setAttribute('role', 'option')
			option.setAttribute('aria-selected', 'false')

			L.DomUtil.create('span', 'territorio-search__name', option).textContent = place.name
			L.DomUtil.create('span', 'territorio-search__detail', option).textContent
				= placeDetail(hintFor(place, this.unidades), place.count)

			L.DomEvent.on(option, 'click', () => this.pick(place))
			return option
		},

		/** The arrows move the active row, Enter takes it, Escape starts again. */
		onKeydown(event) {
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				// Or the caret jumps to the start or the end of the text.
				event.preventDefault()
				this.setActive(nextActive(this.active, this.options.length, event.key))
				return
			}

			if (event.key === 'Enter') {
				// ADR-0007: the active row or nothing — never the first match
				// because it came first, not even when it is the only one.
				const place = chosen(this.options, this.active)
				if (place !== null) {
					this.pick(place)
				}
				return
			}

			if (event.key === 'Escape') {
				this.input.value = ''
				this.search()
			}
		},

		/** Make one row the active one, or none, and keep it in sight. */
		setActive(index) {
			this.active = index
			this.list.childNodes.forEach((option, at) => option.setAttribute('aria-selected', String(at === index)))

			const option = this.list.childNodes[index]
			if (option !== undefined) {
				this.input.setAttribute('aria-activedescendant', option.id)
				// Twelve rows are taller than a phone's map, so the list scrolls.
				option.scrollIntoView({ block: 'nearest' })
			}
		},

		/**
		 * Go to the chosen place, and close the list over it.
		 *
		 * Unanimated, which is load-bearing: an animated move is the window the
		 * null-map error kept landing in (TerritorioMap's mounted() and
		 * created()). And no closer than zoom 17, or a sixty-metre pasaje fills
		 * the screen at 19.
		 */
		pick(place) {
			this.map.fitBounds(cornersOf(place), { padding: [32, 32], maxZoom: 17, animate: false })
			this.input.value = place.name
			this.close()
		},
	},

	render() {
		return null
	},
}
</script>
