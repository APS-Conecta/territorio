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
import { boundsOf, colourOf, indexSubcategories, UNKNOWN_COLOUR } from '../taxonomy.js'

/**
 * OpenStreetMap's own credit, as markup, from here and never from configuration.
 * Leaflet writes attribution with innerHTML, so a stored string is escaped
 * before it goes anywhere near the control (see attributionHtml).
 */
const OSM_ATTRIBUTION = '© <a href="https://www.openstreetmap.org/copyright"'
	+ ' target="_blank" rel="noreferrer noopener">OpenStreetMap</a> contributors'

/**
 * Where the map opens before there is anything to open on.
 *
 * Once the comuna's Unidades Vecinales are imported (issue #9) the map fits
 * their extent instead, so a clinic sees its own territory rather than a guess
 * at central Santiago. This stays for the install that has not imported them
 * yet — an empty map somewhere plausible beats an empty map over the ocean.
 */
const INITIAL_VIEW = { center: [-33.52, -70.58], zoom: 12 }

/** The division whose boundaries describe the comuna (CONTEXT.md). */
const COMUNA_EXTENT = 'unidad_vecinal'

/** Between overlayPane (400) and markerPane (600): above shapes, below pins. */
const PICKABLE_PANE = 'territorio-pickable'

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
		// The Boundary features on screen while a Sector is being built, and which
		// of them are picked. Empty at every other moment: a comuna is around
		// eleven thousand lines and they are not the map (issue #12).
		boundaries: { type: Array, default: () => [] },
		pickedIds: { type: Array, default: () => [] },
		// Whether the open draft is waiting for a coordinate. While it is, a click
		// anywhere places that coordinate — including on top of a Sector or a
		// Unidad Vecinal, which otherwise swallow the click to select themselves.
		placing: { type: Boolean, default: false },
	},

	emits: ['select', 'map-click', 'draw', 'reshape', 'pick', 'view-changed'],

	/**
	 * The Leaflet handles, deliberately NOT in data().
	 *
	 * Vue 3 makes everything data() returns deeply reactive, which means every
	 * Leaflet object put there is handed back as a Proxy — and Leaflet's event
	 * bus identifies a listener by its context object. A layer registered raw
	 * and unregistered through a proxy (or the reverse) leaves a handler on the
	 * map pointing at a layer whose `_map` is already null, and the next zoom
	 * calls it: "Cannot read properties of null (reading 'latLngToLayerPoint')".
	 * The map stops drawing from there on.
	 *
	 * That error came back four times under three different guesses at its
	 * cause. This is the cause. Nothing here is ever rendered — the template is
	 * one div — so none of it needed to be reactive in the first place, and an
	 * instance property is both the fix and less work than the proxy was.
	 */
	created() {
		this.map = null
		this.markers = null
		this.outlines = null
		this.draftLayer = null
		this.pickable = null
		this.resizeObserver = null
	},

	data() {
		return {
			basemap: loadState('territorio', 'basemap'),
			tileFailed: false,
			tileLoaded: false,
			// The reshape we last told the parent about, cleared as soon as it comes
			// back: redrawing on our own edit would tear the layer out from under the
			// hand still dragging its vertex.
			//
			// ponytail: compares the serialised geometry, which is exact here because
			// both sides are the same numbers from the same layer. It is only ever
			// allowed to skip ONE redraw — holding it would silence a later, real
			// change that happens to look identical, which is precisely what
			// reopening a saved Sector produces.
			reported: '',
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
			// Our own reshape, coming back around; skipped once and then forgotten.
			// Everything else — a draft opened, closed, or reopened later — is a real
			// change, including one that stringifies to exactly the same thing.
			if (JSON.stringify(geometry) === this.reported) {
				this.reported = ''
				return
			}
			this.drawDraft()
			this.drawShapes()
		},
		draftColour() {
			this.draftLayer?.setStyle(this.styleFor(this.draftColour ?? UNKNOWN_COLOUR, true))
		},
		// Not deep: this array is replaced wholesale by a fetch, never edited in
		// place, and walking a few thousand geometries on every change to notice
		// that costs more than the redraw.
		boundaries() {
			this.drawPickable()
		},
		pickedIds() {
			this.drawPickable()
		},
	},

	mounted() {
		this.map = L.map(this.$refs.container, {
			center: INITIAL_VIEW.center,
			zoom: INITIAL_VIEW.zoom,
			// Leaflet's default puts the zoom control top-left, under Nextcloud's
			// app navigation toggle on narrow screens.
			zoomControl: false,
			// No animated zoom. This was a guess at the null-map error — the third
			// of three — and it is kept now that created() has the actual cause,
			// because it is still true that this map rebuilds layer groups from
			// three separate causes (a five-second poll, a filter change, and the
			// Limit picker reloading as the view moves) and an animated zoom is the
			// widest window for a rebuild to land in. A smooth zoom is worth less
			// than the fourth occurrence would cost to find.
			zoomAnimation: false,
		})
		L.control.zoom({ position: 'topright' }).addTo(this.map)

		// A pane of its own for the lines a Limit can follow, above the Sectores
		// and below the pins. Without it they share overlayPane with the Sector
		// polygons, which are translucent-FILLED and so clickable across their
		// whole area — and drawShapes() re-adds those on every poll, putting them
		// back on top. A click meant for a street then hit the Sector, selected it,
		// and took everything picked so far with it.
		this.map.createPane(PICKABLE_PANE);
		this.map.getPane(PICKABLE_PANE).style.zIndex = 450

		// Open on the comuna this install serves, measured from the Unidades
		// Vecinales it imported — they are ordinary Features, so the client already
		// holds them and no round trip is needed to find out where we are.
		//
		// Set BEFORE a single layer is added, and without animation. A zoom that
		// happens while layers exist runs Leaflet's animation over each of them,
		// and any redraw landing inside that flight leaves it holding a layer whose
		// map is already gone. Moving the view first means there is nothing to
		// animate over.
		const comuna = boundsOf(this.features, this.subcategories, COMUNA_EXTENT)
		if (comuna !== null) {
			this.map.fitBounds(comuna, { padding: [16, 16], animate: false })
		}

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
		this.setUpDrawing()
		this.drawMarkers()
		this.drawShapes()
		this.drawDraft()

		this.map.on('click', (event) => {
			this.$emit('map-click', { lat: event.latlng.lat, lon: event.latlng.lng })
		})

		// The editor asks for the Boundary features on screen, so it has to know
		// when "on screen" changes. moveend covers panning and zooming both.
		this.map.on('moveend', () => this.announceView())
		this.announceView()

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
			if (this.map === null) {
				return
			}

			// A NEW cluster group each time, swapped in whole, rather than emptying
			// the live one. Removing markers from a group the map is still using
			// left Leaflet repositioning a marker whose map was already gone
			// ("latLngToLayerPoint of null") and the pins never came back. The old
			// group is discarded in one move instead, which it cannot be caught
			// half-way through.
			//
			// ponytail: still a full rebuild, now of a throwaway group. Diff the
			// markers if a real catastro makes this stutter — it no longer breaks.
			const next = L.markerClusterGroup({ showCoverageOnHover: false, animate: false })

			this.points.forEach((feature) => {
				const [lon, lat] = feature.geometry.coordinates
				// snapIgnore keeps the pins out of geoman's snap list. A Sector's
				// limit runs along boundaries — a street, a river, another Sector —
				// never through the middle of a school, and without this a vertex
				// dropped near one jumps onto it.
				L.marker([lat, lon], { icon: this.iconFor(feature), snapIgnore: true })
					.on('click', () => this.$emit('select', feature.id))
					.addTo(next)
			})

			const previous = this.markers
			this.markers = next
			this.map.addLayer(next)
			if (previous !== null) {
				this.map.removeLayer(previous)
			}
		},

		/**
		 * The Sectores and other Zones, redrawn wholesale like the markers.
		 *
		 * The one being edited is skipped: the draft layer is already showing that
		 * shape, and drawing both would leave the user dragging the vertices of one
		 * polygon while looking at another sitting exactly on top of it.
		 */
		drawShapes() {
			if (this.map === null) {
				return
			}

			// Swapped whole, for the reason drawMarkers() swaps: emptying a group the
			// map is still using leaves Leaflet holding layers whose map is gone.
			const next = L.featureGroup()

			this.shapes.forEach((feature) => {
				if (this.draftGeometry !== null && feature.id === this.selectedId) {
					return
				}

				const layer = L.GeoJSON.geometryToLayer(feature.geometry)
				layer.setStyle(this.styleFor(
					colourOf(feature, this.subcategories),
					feature.id === this.selectedId,
				))
				layer.bindTooltip(feature.name)
				layer.on('click', (event) => {
					// While a draft is waiting for its coordinate, the click belongs to
					// the map: let it through untouched. Once a comuna's Unidades
					// Vecinales are imported they tile the whole view, so swallowing
					// the click here would make it impossible to place a record by
					// clicking — and worse, it would select a cadastre boundary and
					// hand the next save to that instead.
					if (this.placing) {
						return
					}

					// Otherwise a path click reaches the map too, and the map click is
					// "put the open draft here" — so selecting a Sector would also move
					// whatever record was open.
					L.DomEvent.stopPropagation(event)
					this.$emit('select', feature.id)
				})
				next.addLayer(layer)
			})

			const previous = this.outlines
			this.outlines = next
			this.map.addLayer(next)
			if (previous !== null) {
				this.map.removeLayer(previous)
			}
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

			// Geoman's own layer is dropped and the shape comes back as the draft
			// layer, drawn from the geometry the parent now holds. One round trip
			// instead of two layers to keep in step — and if the parent refuses the
			// draft, nothing is left stranded on the map.
			this.map.on('pm:create', ({ layer }) => {
				const geometry = layer.toGeoJSON().geometry
				layer.remove()
				this.$emit('draw', geometry)
			})
		},

		/** Which part of the comuna is on screen, for whoever needs to load it. */
		announceView() {
			const bounds = this.map.getBounds()

			this.$emit('view-changed', {
				south: bounds.getSouth(),
				west: bounds.getWest(),
				north: bounds.getNorth(),
				east: bounds.getEast(),
			})
		},

		/**
		 * The lines a Limit can follow, thin and grey until picked.
		 *
		 * Swapped whole like every other group here: emptying one the map is still
		 * using leaves Leaflet holding layers whose map is already gone.
		 */
		drawPickable() {
			if (this.map === null) {
				return
			}

			const next = L.featureGroup()

			this.boundaries.forEach((boundary) => {
				// Lines only. A Boundary Dataset can hold a Point — a KML import will
				// put one there — and geometryToLayer hands back a Marker, which has
				// no setStyle and would take the whole layer down with it.
				if (boundary.geometry?.type !== 'LineString' && boundary.geometry?.type !== 'MultiLineString') {
					return
				}

				const picked = this.pickedIds.includes(boundary.id)
				const layer = L.GeoJSON.geometryToLayer(boundary.geometry, {
					pane: PICKABLE_PANE,
					// Out of geoman's snap list, for the reason the pins are: a few
					// thousand polylines in it makes dragging a vertex a computation
					// rather than a gesture. Snapping to a picked line belongs with the
					// polygonizing (#14), where there are a handful rather than all of
					// them.
					snapIgnore: true,
				})
				layer.setStyle({
					color: picked ? '#c2410c' : '#64748b',
					weight: picked ? 5 : 3,
					opacity: picked ? 1 : 0.55,
				})
				layer.bindTooltip(boundary.name)
				layer.on('click', (event) => {
					// While a draft is waiting for a coordinate the click is the map's,
					// exactly as it is for a Sector's fill — a street lying across the
					// spot must not swallow it.
					if (this.placing) {
						return
					}

					// Otherwise the picker owns it: unstopped, the map would ALSO read
					// it as "put the open draft here".
					L.DomEvent.stopPropagation(event)
					this.$emit('pick', boundary.id)
				})
				next.addLayer(layer)
			})

			const previous = this.pickable
			this.pickable = next
			this.map.addLayer(next)
			if (previous !== null) {
				this.map.removeLayer(previous)
			}
		},

		/**
		 * Draw the open draft's shape, dashed, with its vertices draggable.
		 *
		 * The layer is built fresh every time rather than kept and patched, so
		 * there is exactly one draft layer with exactly one set of handlers on it —
		 * a layer adopted twice would report every drag twice.
		 */
		drawDraft() {
			this.draftLayer?.remove()
			this.draftLayer = null

			if (this.draftGeometry === null || this.map === null) {
				return
			}

			const layer = L.GeoJSON.geometryToLayer(this.draftGeometry).addTo(this.map)
			layer.setStyle(this.styleFor(this.draftColour ?? UNKNOWN_COLOUR, true))
			layer.pm.enable({ allowSelfIntersection: false })
			// pm:edit covers a dragged vertex, pm:update the end of a move.
			layer.on('pm:edit pm:update', () => this.report(layer))
			this.draftLayer = layer
		},

		report(layer) {
			const geometry = layer.toGeoJSON().geometry
			this.reported = JSON.stringify(geometry)
			this.$emit('reshape', geometry)
		},

		/**
		 * A light translucent fill under a clear outline: a Sector has to be
		 * readable as an area without hiding the basemap or the records inside it.
		 */
		styleFor(colour, selected = false) {
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
