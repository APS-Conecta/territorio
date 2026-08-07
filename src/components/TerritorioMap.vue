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
import '@geoman-io/leaflet-geoman-free'
import '@geoman-io/leaflet-geoman-free/dist/leaflet-geoman.css'
import { colourOf, indexSubcategories } from '../taxonomy.js'

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
		// The shape the open draft carries, drawn dashed and with its vertices
		// draggable. Owned by App.vue: this component renders it and reports the
		// user's edits back, so there is one geometry and not two.
		draftGeometry: { type: Object, default: null },
		draftColour: { type: String, default: null },
	},

	emits: ['select', 'map-click', 'draw', 'reshape'],

	data() {
		return {
			basemap: loadState('territorio', 'basemap'),
			tileFailed: false,
			tileLoaded: false,
			map: null,
			markers: null,
			outlines: null,
			draftLayer: null,
			// What we last told the parent. A reshape comes straight back as a new
			// draftGeometry, and redrawing on it would tear the layer out from under
			// the hand still dragging its vertex.
			reported: '',
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

		/** A single location gets a pin… */
		points() {
			return this.mappable.filter((feature) => feature.geometry.type === 'Point')
		},

		/** …and anything with an extent — a Sector, a route — is drawn as itself. */
		shapes() {
			return this.mappable.filter((feature) => feature.geometry.type !== 'Point')
		},
	},

	watch: {
		mappable: {
			handler() {
				this.drawMarkers()
				this.drawShapes()
			},
			deep: true,
		},
		selectedId() {
			this.drawMarkers()
			this.drawShapes()
		},
		draftGeometry(geometry) {
			// Our own reshape, coming back around. Everything else — a draft opened,
			// closed, or saved and re-read from the server — is a real change.
			if (JSON.stringify(geometry) === this.reported) {
				return
			}
			this.drawDraft()
			this.drawShapes()
		},
		draftColour() {
			this.draftLayer?.setStyle(this.styleFor({ colour: this.draftColour }, true))
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

		// Shapes live in Leaflet's overlayPane and markers in its markerPane, which
		// is what puts a Sector's fill beneath the pins standing on it. Nothing
		// here sets a z-index: the panes already order themselves.
		this.outlines = L.featureGroup().addTo(this.map)
		this.markers = L.markerClusterGroup({ showCoverageOnHover: false })
		this.map.addLayer(this.markers)
		this.setUpDrawing()
		this.drawMarkers()
		this.drawShapes()
		this.drawDraft()

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

			this.points.forEach((feature) => {
				const [lon, lat] = feature.geometry.coordinates
				L.marker([lat, lon], { icon: this.iconFor(feature) })
					.on('click', () => this.$emit('select', feature.id))
					.addTo(this.markers)
			})
		},

		/**
		 * The Sectores and other Zones, redrawn wholesale like the markers.
		 *
		 * The one being edited is skipped: the draft layer is already showing that
		 * shape, and drawing both would leave the user dragging the vertices of one
		 * polygon while looking at another sitting exactly on top of it.
		 */
		drawShapes() {
			if (this.outlines === null) {
				return
			}
			this.outlines.clearLayers()

			this.shapes.forEach((feature) => {
				if (this.draftGeometry !== null && feature.id === this.selectedId) {
					return
				}

				const layer = L.GeoJSON.geometryToLayer(feature.geometry)
				layer.setStyle(this.styleFor(feature, feature.id === this.selectedId))
				layer.bindTooltip(feature.name)
				layer.on('click', (event) => {
					// A path click reaches the map too, and the map click is "put the
					// open draft here" — so selecting a Sector would also move a record.
					L.DomEvent.stopPropagation(event)
					this.$emit('select', feature.id)
				})
				this.outlines.addLayer(layer)
			})
		},

		/**
		 * Turn on drawing and snapping.
		 *
		 * Only the polygon tool is offered. Geoman brings a whole toolbar —
		 * circles, rectangles, cut, rotate — and every one of those is a shape the
		 * Registry has no meaning for. Deleting is off too: retiring is how a record
		 * leaves the map (ADR-0005), and a rubbish-bin button beside it would be a
		 * second, silent way that skips the history.
		 */
		setUpDrawing() {
			this.map.pm.setLang('es')
			// Snapping is what makes adjacent sectores share an exact edge instead of
			// leaving slivers between them. It works against whatever vector layers
			// are on the map, so it will cover the Unidad Vecinal boundaries too once
			// they are imported — they are Features drawn by drawShapes like any other.
			this.map.pm.setGlobalOptions({
				snappable: true,
				snapDistance: 20,
				allowSelfIntersection: false,
			})
			this.map.pm.addControls({
				position: 'topright',
				drawMarker: false,
				drawCircle: false,
				drawCircleMarker: false,
				drawPolyline: false,
				drawRectangle: false,
				drawText: false,
				editMode: false,
				dragMode: false,
				cutPolygon: false,
				removalMode: false,
				rotateMode: false,
			})

			// Geoman hands over the finished layer; it becomes the draft layer as it
			// is, so the shape someone just drew stays on screen and stays editable
			// while they name it.
			this.map.on('pm:create', ({ layer }) => {
				this.adoptDraft(layer)
				this.report('draw', layer)
			})
		},

		/** Draw the open draft's shape, dashed, with its vertices draggable. */
		drawDraft() {
			this.draftLayer?.remove()
			this.draftLayer = null

			if (this.draftGeometry === null || this.map === null) {
				return
			}

			this.adoptDraft(L.GeoJSON.geometryToLayer(this.draftGeometry).addTo(this.map))
		},

		adoptDraft(layer) {
			if (layer !== this.draftLayer) {
				this.draftLayer?.remove()
			}
			this.draftLayer = layer

			layer.setStyle(this.styleFor({ colour: this.draftColour }, true))
			layer.pm.enable({ allowSelfIntersection: false })
			// Both events: pm:edit covers a dragged vertex, pm:update the end of a
			// move. Reporting twice is harmless — the parent just stores it again.
			layer.on('pm:edit pm:update', () => this.report('reshape', layer))
		},

		report(event, layer) {
			const geometry = layer.toGeoJSON().geometry
			this.reported = JSON.stringify(geometry)
			this.$emit(event, geometry)
		},

		/**
		 * A light translucent fill under a clear outline: a Sector has to be
		 * readable as an area without hiding the basemap or the records inside it.
		 */
		styleFor(feature, selected = false) {
			const colour = colourOf(feature, this.subcategories)

			return {
				color: colour,
				weight: selected ? 4 : 2,
				opacity: 1,
				fillColor: colour,
				fillOpacity: 0.15,
				dashArray: selected ? '6 4' : null,
			}
		},

		iconFor(feature) {
			const colour = colourOf(feature, this.subcategories)
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
