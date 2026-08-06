<template>
	<div class="territorio-map">
		<NcNoteCard v-if="tilesUnavailable"
			type="warning"
			class="territorio-map__notice">
			{{ t('No se pudo cargar el fondo de mapa. El mapa sigue disponible; solo falta la imagen de fondo.') }}
		</NcNoteCard>

		<div ref="container" class="territorio-map__canvas" data-testid="map-canvas" />
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

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

	data() {
		return {
			basemap: loadState('territorio', 'basemap'),
			tilesUnavailable: false,
			map: null,
			resizeObserver: null,
		}
	},

	computed: {
		/**
		 * The default basemap may credit OpenStreetMap with a real link, because
		 * that markup is ours. Anything an administrator typed is text.
		 */
		attributionHtml() {
			return this.basemap.isDefault
				? OSM_ATTRIBUTION
				: escapeHtml(this.basemap.attribution)
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
			// drawn on top keep working, so we say so once and carry on.
			.on('tileerror', () => {
				this.tilesUnavailable = true
			})
			.addTo(this.map)

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
		t(text) {
			return text
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
