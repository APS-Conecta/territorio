<template>
	<div class="territorio-map">
		<NcNoteCard v-if="basemapUnreachable"
			type="warning"
			class="territorio-map__notice">
			No se pudo cargar el fondo de mapa. El mapa sigue disponible; solo falta
			la imagen de fondo.
		</NcNoteCard>

		<div ref="container" class="territorio-map__canvas" data-testid="map-canvas" />
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import 'leaflet.markercluster'
import 'leaflet.markercluster/dist/MarkerCluster.css'
import 'leaflet.markercluster/dist/MarkerCluster.Default.css'
import { indexSubcategories, UNKNOWN_COLOUR } from '../taxonomy.js'

/**
 * OpenStreetMap's own credit, as markup, from here and never from configuration.
 * Leaflet writes attribution with innerHTML, so a stored string is escaped
 * before it goes anywhere near the control (see attributionHtml).
 */
const OSM_ATTRIBUTION = '© <a href="https://www.openstreetmap.org/copyright"'
	+ ' target="_blank" rel="noreferrer noopener">OpenStreetMap</a> contributors'

// ponytail: fixed opening view until the install knows its Comuna (issue #9),
// which replaces this with that comuna's bounds.
const INITIAL_VIEW = { center: [-33.52, -70.58], zoom: 12 }

const escapeHtml = (value) => String(value).replace(
	/[&<>"']/g,
	(character) => ({
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&#39;',
	}[character]),
)

export default {
	name: 'TerritorioMap',

	components: { NcNoteCard },

	props: {
		features: { type: Array, required: true },
		taxonomy: { type: Array, required: true },
		selectedId: { type: Number, default: null },
	},

	emits: ['select', 'map-click'],

	data() {
		return {
			basemap: loadState('territorio', 'basemap'),
			tileFailed: false,
			tileLoaded: false,
			map: null,
			markers: null,
			resizeObserver: null,
		}
	},

	computed: {
		/**
		 * OpenStreetMap tiles get OpenStreetMap's own link, because that markup is
		 * ours and its licence requires the credit. Anything an administrator
		 * typed is text, escaped — Leaflet writes attribution with innerHTML.
		 */
		attributionHtml() {
			return this.basemap.usesOpenStreetMap
				? OSM_ATTRIBUTION
				: escapeHtml(this.basemap.attribution)
		},

		/**
		 * Only warn when the basemap is genuinely unreachable — no tile has ever
		 * loaded. A healthy server still 404s tiles at the edge of coverage, and
		 * latching on the first of those would pin the banner forever.
		 */
		basemapUnreachable() {
			return this.tileFailed && !this.tileLoaded
		},

		/** Shared with the list, so a marker can never disagree with its legend. */
		subcategories() {
			return indexSubcategories(this.taxonomy)
		},

		/** Only Features with a geometry can be drawn; the rest are Datos-only. */
		mappable() {
			return this.features.filter((feature) => feature.geometry !== null)
		},
	},

	watch: {
		mappable: {
			handler() {
				this.drawMarkers()
			},
			deep: true,
		},
		selectedId() {
			this.drawMarkers()
		},
	},

	mounted() {
		this.map = L.map(this.$refs.container, {
			center: INITIAL_VIEW.center,
			zoom: INITIAL_VIEW.zoom,
			// Leaflet's default puts the zoom control top-left, under Nextcloud's
			// app navigation toggle on narrow screens.
			zoomControl: false,
		})
		L.control.zoom({ position: 'topright' }).addTo(this.map)

		L.tileLayer(this.basemap.url, {
			attribution: this.attributionHtml,
			maxZoom: 19,
		})
			// Tiles failing is not an error the app should die on: the clinic may
			// be offline, or the tile host firewalled. Pan, zoom and everything
			// drawn on top keep working, so we note it and carry on.
			.on('tileerror', () => {
				this.tileFailed = true
			})
			.on('tileload', () => {
				this.tileLoaded = true
			})
			.addTo(this.map)

		this.markers = L.markerClusterGroup({ showCoverageOnHover: false })
		this.map.addLayer(this.markers)
		this.drawMarkers()

		this.map.on('click', (event) => {
			this.$emit('map-click', { lat: event.latlng.lat, lon: event.latlng.lng })
		})

		// A Leaflet map sized before its container settles renders grey. Nextcloud
		// animates the sidebar and navigation, so observe rather than guess.
		this.resizeObserver = new ResizeObserver(() => this.map.invalidateSize())
		this.resizeObserver.observe(this.$refs.container)
	},

	beforeUnmount() {
		this.resizeObserver?.disconnect()
		this.map?.remove()
	},

	methods: {
		/**
		 * Rebuild the whole marker layer rather than diffing it. One comuna is
		 * hundreds of markers, where clearing and re-adding costs nothing and a
		 * diff would be a second source of truth to keep correct.
		 *
		 * ponytail: full redraw; diff only if a real catastro makes it stutter.
		 */
		drawMarkers() {
			if (this.markers === null) {
				return
			}
			this.markers.clearLayers()

			this.mappable.forEach((feature) => {
				const [lon, lat] = this.anchorOf(feature.geometry)
				if (lat === null) {
					return
				}
				L.marker([lat, lon], { icon: this.iconFor(feature) })
					.on('click', () => this.$emit('select', feature.id))
					.addTo(this.markers)
			})
		},

		/**
		 * Where to put the pin. Only Points exist in this slice; Zones and Routes
		 * arrive with their own rendering, so their first vertex is a placeholder
		 * that keeps them visible rather than silently absent.
		 */
		anchorOf(geometry) {
			let node = geometry.coordinates
			while (Array.isArray(node) && Array.isArray(node[0])) {
				node = node[0]
			}
			return Array.isArray(node) && node.length >= 2 ? node : [null, null]
		},

		iconFor(feature) {
			const colour = this.subcategories.get(feature.subcategoryId)?.colour ?? UNKNOWN_COLOUR
			const selected = feature.id === this.selectedId

			return L.divIcon({
				className: 'territorio-pin',
				html: `<span class="territorio-pin__dot${selected ? ' territorio-pin__dot--selected' : ''}"`
					+ ` style="background:${escapeHtml(colour)}"></span>`,
				iconSize: [18, 18],
				iconAnchor: [9, 9],
			})
		},
	},
}
</script>

<style scoped lang="scss">
.territorio-map {
	display: flex;
	flex-direction: column;
	height: 100%;
	width: 100%;

	&__notice {
		margin: 12px 12px 0;
		flex: 0 0 auto;
	}

	&__canvas {
		flex: 1 1 auto;
		min-height: 0;
	}
}
</style>

<style lang="scss">
/* Not scoped: Leaflet builds the marker element itself, outside Vue's tree. */
.territorio-pin__dot {
	display: block;
	width: 18px;
	height: 18px;
	border-radius: 50%;
	border: 2px solid var(--color-main-background, #fff);
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);

	&--selected {
		outline: 3px solid var(--color-primary-element, #0b6bcb);
		outline-offset: 1px;
	}
}
</style>
