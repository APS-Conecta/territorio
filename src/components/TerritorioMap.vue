<template>
	<div class="territorio-map">
		<NcNoteCard v-if="basemapUnreachable && !basemapNoticeDismissed"
			type="warning"
			class="territorio-map__notice">
			No se pudo cargar el fondo de mapa. El mapa sigue disponible; solo falta
			la imagen de fondo.
			<NcButton variant="tertiary" @click="basemapNoticeDismissed = true">
				Ocultar
			</NcButton>
		</NcNoteCard>

		<NcNoteCard v-if="locateNotice"
			:type="locateNoticeType"
			class="territorio-map__notice"
			data-testid="locate-notice">
			{{ locateNotice }}
		</NcNoteCard>

		<div ref="container" class="territorio-map__canvas" data-testid="map-canvas" />

		<!--
			The map's own controls, mounted only once the map handle exists: the
			handle is deliberately non-reactive (created() below), and a child's
			mounted() runs BEFORE this component's own — so `mapReady` gates each
			child until this one has finished building the map it hands over.

			In this order on purpose: Leaflet stacks a corner's controls in the
			order they are added, which puts the street search under «¿Dónde
			estoy?».
		-->
		<LocateControl v-if="mapReady" :map="map" @notice="onLocateNotice" />
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import 'leaflet.markercluster'
import 'leaflet.markercluster/dist/MarkerCluster.css'
// `MarkerCluster.Default.css` deliberately NOT imported (#22). It is, in its
// entirety, the `marker-cluster-small|medium|large` count buckets — green under
// 10, yellow under 100, orange at 100+ — plus the geometry of the markup that
// carries them. `iconCreateFunction` below emits none of those classes, so the
// file is the only way a hue that means a COUNT could still reach the page.
// `MarkerCluster.css` stays: it is the zoom animation, which is not a colour.
import '@geoman-io/leaflet-geoman-free'
import '@geoman-io/leaflet-geoman-free/dist/leaflet-geoman.css'
import { makeOperable } from '../a11y.js'
import { addBasemap, OSM_ATTRIBUTION } from '../basemap.js'
import { glyphFor } from '../glyphs.js'
import { labelOptions, shapeStyle, showsGroundLabels } from '../marks.js'
import { diffLayers, pointSignature, shapeSignature } from '../mapdiff.js'
import { clusterIcon, isApproximate, pinIcon } from '../pins.js'
import LocateControl from './LocateControl.vue'
import { initialView } from '../extent.js'
import {
	boundsOf,
	boundsOfRecords,
	colourOf,
	indexSubcategories,
	UNIDAD_VECINAL_SLUG,
	UNKNOWN_COLOUR,
} from '../taxonomy.js'

/** Between overlayPane (400) and markerPane (600): above shapes, below pins. */
const PICKABLE_PANE = 'territorio-pickable'

export default {
	name: 'TerritorioMap',

	components: { LocateControl, NcButton, NcNoteCard },

	props: {
		features: { type: Array, required: true },
		taxonomy: { type: Array, required: true },
		selectedId: { type: Number, default: null },
		// The shape the open draft carries, drawn dashed and with its vertices
		// draggable. Owned by App.vue: this component renders it and reports the
		// user's edits back, so there is one geometry and not two.
		draftGeometry: { type: Object, default: null },
		draftColour: { type: String, default: null },
		// What the drafted record is FILED as, so the draft layer draws it the way
		// drawShapes would have. `draftFrom` copies the geometry of any opened
		// shape record onto the draft (App.vue), so this is a Unidad Vecinal
		// whenever one is opened from the list — and a cadastre boundary does not
		// become figure by being looked at (#21). Null for a brand-new drawing,
		// which nothing has filed yet. Taken from the draft rather than looked up
		// in `features`, for the reason `draftColour` is: `features` is the
		// FILTERED set, and a Capas toggle must not change how a shape is drawn.
		draftSubcategoryId: { type: Number, default: null },
		// And what it is CALLED, for the same reason and from the same place. The
		// draft layer replaces the opened record on screen (drawShapes skips it),
		// so without this an open Sector was the one Sector on the map with no
		// permanent label — «a Sector carries a permanent label at its centroid»
		// (#21) being false for precisely the record being worked on.
		draftName: { type: String, default: '' },
		// Cadastre street lines, loaded only while a Sector's Limit is being
		// built — NOT the divisions; «boundary» names the /boundaries resource
		// alone (L1-33). Empty at every other moment: a comuna is around eleven
		// thousand lines and they are not the map (issue #12).
		cadastreLines: { type: Array, default: () => [] },
		pickedIds: { type: Array, default: () => [] },
		// Whether the open draft is waiting for a coordinate. While it is, a click
		// anywhere places that coordinate — including on top of a Sector or a
		// Unidad Vecinal, which otherwise swallow the click to select themselves.
		placing: { type: Boolean, default: false },
	},

	emits: ['select', 'map-click', 'draw', 'reshape', 'pick', 'view-changed', 'draw-mode-change'],

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
		// The drawn layers by record id, beside the signature they were drawn
		// from: what drawMarkers/drawShapes diff against and what they remove
		// through. Non-reactive for the reason every handle here is — a proxied
		// Leaflet layer is the original bug this block exists to prevent.
		this.pointLayers = new Map()
		this.shapeLayers = new Map()
		this.draftLayer = null
		this.pickable = null
		this.resizeObserver = null
	},

	data() {
		return {
			// The Comuna this install serves and its extent (ADR-0021), for the opening view.
			comuna: loadState('territorio', 'comuna'),
			// The basemap's latest reachability verdict, announced by basemap.js.
			// Only «no tile has ever loaded» is a verdict, a mid-session blip is
			// retryTile's business and not the banner's. The map, the pins and
			// everything drawn on top keep working whichever way it points.
			basemapUnreachable: false,
			// Whether the person has already read the basemap warning and sent
			// it away. The verdict itself stays in `basemapUnreachable` — the
			// map keeps working either way, and a banner that outlives its
			// reading crowds the canvas for the rest of the session.
			basemapNoticeDismissed: false,
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

			// The locate control's notices, kept here because this template is
			// the only place they are drawn; the control itself (and its
			// `locating` flag) lives in LocateControl.vue and emits them.
			locateNotice: '',
			locateNoticeType: 'warning',
			// Flipped at the END of mounted(), once `map` exists: gates
			// <LocateControl v-if="mapReady"> — see the template.
			mapReady: false,
		}
	},

	computed: {
		/** Shared with the list, so a marker can never disagree with its legend. */
		subcategories() {
			return indexSubcategories(this.taxonomy)
		},

		/** The drafted record's Subcategory slug; null for a new drawing. */
		draftSlug() {
			return this.subcategories.get(this.draftSubcategoryId)?.slug ?? null
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
		// Not deep, for the reason `boundaries()` below gives: `mappable` filters a
		// prop that is itself a filter, so both hand back a new array on every
		// recompute and a shallow watcher already sees every change. `mergeFeature`
		// replaces records wholesale and nothing in src/ edits one in place, so the
		// deep traverse only walked every position of every polygon — on every poll
		// merge, every Capas toggle, and every keystroke in the search box.
		mappable() {
			this.drawMarkers()
			this.drawShapes()
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
			this.draftLayer?.setStyle(shapeStyle(this.draftSlug, this.draftColour ?? UNKNOWN_COLOUR, true))
		},
		// Not deep: this array is replaced wholesale by a fetch, never edited in
		// place, and walking a few thousand geometries on every change to notice
		// that costs more than the redraw.
		cadastreLines() {
			this.drawPickable()
		},
		pickedIds() {
			this.drawPickable()
		},
	},

	mounted() {
		this.map = L.map(this.$refs.container, {
			// Leaflet's default puts the zoom control top-left, under Nextcloud's
			// app navigation toggle on narrow screens.
			zoomControl: false,
			// No animated zoom. This was a guess at the null-map error — the third
			// of three — and it is kept now that created() has the actual cause,
			// because it is still true that layers join and leave these groups
			// from three separate causes (a five-second poll, a filter change, and
			// the Limit picker reloading as the view moves) and an animated zoom
			// is the widest window for a layer swap to land in. A smooth zoom is
			// worth less than the fourth occurrence would cost to find.
			zoomAnimation: false,
		})
		// Leaflet's own titles are English, and they are the only text on the map
		// a screen reader reads out of a control rather than out of a record.
		L.control.zoom({
			position: 'topright',
			zoomInTitle: 'Acercar',
			zoomOutTitle: 'Alejar',
		}).addTo(this.map)

		// A pane of its own for the lines a Limit can follow, above the Sectores
		// and below the pins. Without it they share overlayPane with the Sector
		// polygons, which are translucent-FILLED and so clickable across their
		// whole area — and drawShapes() re-adds those on every poll, putting them
		// back on top. A click meant for a street then hit the Sector, selected it,
		// and took everything picked so far with it.
		this.map.createPane(PICKABLE_PANE);
		this.map.getPane(PICKABLE_PANE).style.zIndex = 450

		// Open on the Comuna this install serves (ADR-0021): its extent when the CUT is
		// in the table, otherwise the Unidades Vecinales it imported — ordinary Features,
		// so the client already holds them — otherwise wherever its records are, otherwise
		// the middle of the country. Nothing here restricts where a person may pan afterwards.
		//
		// Set BEFORE a single layer is added, and without animation. A zoom that
		// happens while layers exist runs Leaflet's animation over each of them,
		// and any redraw landing inside that flight leaves it holding a layer whose
		// map is already gone. Moving the view first means there is nothing to
		// animate over. The map is created without a view, so this call is also what
		// gives the layers added below something to join.
		// The Unidad Vecinal is the division whose boundaries describe the comuna
		// (CONTEXT.md).
		const { bounds } = initialView({
			extent: this.comuna.extent,
			unitBounds: boundsOf(this.features, this.subcategories, UNIDAD_VECINAL_SLUG),
			recordBounds: boundsOfRecords(this.features),
		})
		this.map.fitBounds(bounds, { padding: [16, 16], animate: false })

		addBasemap(this.map, {
			attribution: OSM_ATTRIBUTION,
			onLoaded: () => { this.basemapUnreachable = false },
			onFailed: () => { this.basemapUnreachable = true },
		})

		// Shapes live in Leaflet's overlayPane and markers in its markerPane, which
		// is what puts a Sector's fill beneath the pins standing on it. Nothing
		// here sets a z-index: the panes already order themselves.
		//
		// The two persistent groups, created and map-added HERE — before the
		// first draw — and kept for the page's life: every later redraw diffs
		// into them instead of swapping in a new one. Map-added first because a
		// layer's element exists only once its group is on the map (`_initPath`
		// runs on add), and drawShapes hands that element to makeOperable the
		// moment it adds a shape.
		this.markers = L.markerClusterGroup({
			showCoverageOnHover: false,
			animate: false,
			// An arrow rather than the method itself: markercluster calls this
			// with the cluster as its only argument and no `this` of ours.
			iconCreateFunction: (cluster) => this.clusterIconFor(cluster),
		})
		this.map.addLayer(this.markers)
		this.outlines = L.featureGroup()
		this.map.addLayer(this.outlines)

		this.setUpDrawing()
		this.drawMarkers()
		this.drawShapes()
		this.drawDraft()

		this.map.on('click', (event) => this.emitMapClick({ lat: event.latlng.lat, lon: event.latlng.lng }))

		// The editor asks for the Boundary features on screen, so it has to know
		// when "on screen" changes. moveend covers panning and zooming both.
		this.map.on('moveend', () => this.announceView())
		this.announceView()

		this.map.on('zoomend', () => this.updateGroundLabels())
		this.updateGroundLabels()

		// A Leaflet map sized before its container settles renders grey. Nextcloud
		// animates the sidebar and navigation, so observe rather than guess.
		this.resizeObserver = new ResizeObserver(() => this.map.invalidateSize())
		this.resizeObserver.observe(this.$refs.container)

		// Last, so every handle above exists before the child mounts for it.
		this.mapReady = true
	},

	beforeUnmount() {
		this.resizeObserver?.disconnect()
		// The geolocation watch is stopped in LocateControl's own beforeUnmount
		// — the control that started it owns stopping it.
		this.map?.remove()
	},

	methods: {
		/**
		 * The only place `map-click` is emitted: by a map click, and by a pin click while placing.
		 *
		 * @param {{lat: number, lon: number}} coordinate where the click lands
		 */
		emitMapClick({ lat, lon }) {
			this.$emit('map-click', { lat, lon })
		},

		/**
		 * The registries' signature half, as diffLayers wants it: the rows hold
		 * the live layer beside the signature it was drawn from, and the diff
		 * sees only the strings.
		 */
		signaturesOf(registry) {
			return new Map([...registry].map(([id, row]) => [id, row.signature]))
		},

		/**
		 * Draw the markers by diff: a record whose drawn inputs are unchanged
		 * keeps its marker, and only added, changed and removed records touch
		 * the cluster group.
		 *
		 * The whole-group swap this replaced was a workaround from when reactive
		 * Leaflet handles could leave a listener pointing at a layer whose map
		 * was already gone — see created() for the history. The handles are
		 * non-reactive by construction now, so removing from the group the map
		 * is still using is safe, and the five-second poll stops rebuilding
		 * hundreds of identical markers on every delivery.
		 */
		drawMarkers() {
			if (this.map === null) {
				return
			}

			const next = this.points.map((feature) => ({
				id: feature.id,
				signature: pointSignature(feature, this),
				feature,
			}))
			const { added, changed, removed } = diffLayers(this.signaturesOf(this.pointLayers), next)

			// Changed is remove-then-add, never an in-place setIcon: the
			// `territorio` payload on marker.options is every cluster bubble's only
			// view of its records (clusterIconFor below), and rebuilding the marker
			// keeps that payload single-sourced — one builder, one shape of marker,
			// whether the record is new or merely different.
			const dropped = [...removed, ...changed.map(({ id }) => id)]
			if (dropped.length > 0) {
				this.markers.removeLayers(dropped.map((id) => this.pointLayers.get(id).layer))
				dropped.forEach((id) => this.pointLayers.delete(id))
			}

			const fresh = [...added, ...changed].map(({ id, signature, feature }) => {
				const [lon, lat] = feature.geometry.coordinates
				// snapIgnore keeps the pins out of geoman's snap list. A Sector's
				// limit runs along boundaries — a street, a river, another Sector —
				// never through the middle of a school, and without this a vertex
				// dropped near one jumps onto it.
				//
				// `territorio` rides along because a cluster is handed its markers
				// and never their Features: `getAllChildMarkers()` returns
				// `L.Marker`s, so what the bubble has to say about the records
				// under it has to be on the marker by the time it is clustered.
				const marker = L.marker([lat, lon], {
					icon: this.iconFor(feature),
					snapIgnore: true,
					territorio: {
						colour: colourOf(feature, this.subcategories),
						approximate: isApproximate(feature),
					},
				}).on('click', () => {
					// The shapes' `placing` guard, for a pin (#196). A Marker does not
					// bubble its click to the map, so it hands the click to the map's
					// path itself. The stored coordinate, not the event's: Enter on a
					// focused pin arrives here as a click with no latlng.
					if (this.placing) {
						this.emitMapClick({ lat, lon })
						return
					}
					this.$emit('select', feature.id)
				})

				return [id, { layer: marker, signature }]
			})
			if (fresh.length > 0) {
				// addLayers, the bulk door: the first draw is one call and a poll
				// that adds three records does not re-cluster per marker.
				this.markers.addLayers(fresh.map(([, row]) => row.layer))
				fresh.forEach(([id, row]) => this.pointLayers.set(id, row))
			}
		},

		/**
		 * The Sectores and other Zones, drawn by diff like the markers.
		 *
		 * The one being edited is never in the diff's next set — excluded below —
		 * so opening a draft on a drawn shape takes the map's copy down as a
		 * removal and closing the draft brings it back as an add. The draft
		 * layer is already showing that shape, and drawing both would leave the
		 * user dragging the vertices of one polygon while looking at another
		 * sitting exactly on top of it.
		 */
		drawShapes() {
			if (this.map === null) {
				return
			}

			const next = this.shapes
				.filter((feature) => this.draftGeometry === null || feature.id !== this.selectedId)
				.map((feature) => ({
					id: feature.id,
					signature: shapeSignature(feature, this),
					feature,
				}))
			const { added, changed, removed } = diffLayers(this.signaturesOf(this.shapeLayers), next)

			const dropped = [...removed, ...changed.map(({ id }) => id)]
			if (dropped.length > 0) {
				dropped.forEach((id) => {
					this.outlines.removeLayer(this.shapeLayers.get(id).layer)
					this.shapeLayers.delete(id)
				})
			}

			;[...added, ...changed].forEach(({ id, signature, feature }) => {
				// What a record IS decides how it is drawn: a Unidad Vecinal is read
				// from the cadastre and never drawn by the clinic, so it is ground
				// (#21). The Subcategory slug is the stable identifier — ids differ per
				// install — and the index already carries it.
				const slug = this.subcategories.get(feature.subcategoryId)?.slug ?? null
				const layer = L.GeoJSON.geometryToLayer(feature.geometry)
				layer.setStyle(shapeStyle(
					slug,
					colourOf(feature, this.subcategories),
					feature.id === this.selectedId,
				))
				// A node, not a string. Leaflet's DivOverlay assigns string content with
				// innerHTML, so a stored name was parsed as markup — and while CSP stops
				// script and every exfiltration channel here, nothing covers navigation
				// (issue #62). A text node cannot be parsed at all.
				//
				// A division's label is permanent and at its centroid, which is what
				// identifies a Sector now that its colour is only a wash: Leaflet 1.9
				// falls through to a real area-weighted centroid for `direction:
				// 'center'`, so this costs no plugin and no geometry of our own.
				layer.bindTooltip(document.createTextNode(feature.name), labelOptions(slug))
				// One function for both ways in. A keydown that emitted `select`
				// itself would go round the `placing` guard below, which is exactly
				// the guard that keeps a click during placement belonging to the map —
				// and with it the protection of whatever draft is open.
				const select = (event) => {
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
				}

				layer.on('click', select)
				this.outlines.addLayer(layer)
				this.shapeLayers.set(id, { layer, signature })

				// Leaflet gives a Marker a tab stop and a Path nothing, so this is the
				// only way a Sector is reachable without a mouse (issue #25).
				//
				// The element exists here because the persistent group joined the map
				// in mounted(), before the first draw: `_initPath` runs the moment a
				// layer is added to an on-map group, so getElement() answers the path
				// as soon as addLayer returns.
				//
				// Nothing to unbind when a layer leaves: makeOperable binds to the
				// element, the element leaves the DOM with its layer, and a layer is
				// built and bound exactly once — a changed shape gets a NEW layer,
				// never a patched one. Do not add a teardown for it.
				makeOperable(layer.getElement?.(), feature.name, select)
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
			// No Geoman toolbar: it offered one button, Polygon, beside
			// DrawSectorButton, which arms the same tool through toggleDrawPolygon().
			// Drawing, snapping and reshaping are the map's own and need no toolbar.

			// Geoman's own layer is dropped and the shape comes back as the draft
			// layer, drawn from the geometry the parent now holds. One round trip
			// instead of two layers to keep in step — and if the parent refuses the
			// draft, nothing is left stranded on the map.
			this.map.on('pm:drawstart', () => {
				this.$emit('draw-mode-change', true)
			})
			this.map.on('pm:drawend', () => {
				this.$emit('draw-mode-change', false)
			})
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
		 * Swapped whole, on purpose, unlike the diffed markers and shapes: this
		 * group is only mounted while a Limit is being built and is replaced on
		 * every pan, so there is no standing set to diff against — and emptying
		 * one the map is still using leaves Leaflet holding layers whose map is
		 * already gone.
		 */
		drawPickable() {
			if (this.map === null) {
				return
			}

			const next = L.featureGroup()

			this.cadastreLines.forEach((boundary) => {
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
				// A node, not a string — see drawShapes. This is the path that matters
				// most: these names come out of an imported cadastre, not from anyone here.
				layer.bindTooltip(document.createTextNode(boundary.name))
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
			// Unbind BEFORE removing, or the layer reports its shape on the way
			// out and hands it to whatever draft is open by then.
			//
			// Geoman binds `remove` to `disable()` (leaflet-geoman.js:13837), and
			// `disable()` fires `pm:update` when a vertex was dragged
			// (:13850, guarded by `_layerEdited`). So closing Sector A after
			// dragging one of its vertices ran report(A) during teardown, emitted
			// `reshape` with A's polygon, and App.vue wrote it onto the draft that
			// had already become record B — which then saved B with A's geometry,
			// silently, because save() prefers a drawn shape over typed
			// coordinates. It also poisoned `reported`, swallowing the next real
			// redraw.
			this.draftLayer?.off('pm:edit pm:update')
			this.draftLayer?.remove()
			this.draftLayer = null

			if (this.draftGeometry === null || this.map === null) {
				return
			}

			const layer = L.GeoJSON.geometryToLayer(this.draftGeometry).addTo(this.map)
			// Drawn as what it IS, not as what is being done to it. A new polygon is
			// filed as nothing yet and reads as figure — the definition of curated
			// (CONTEXT.md) — but an opened Unidad Vecinal is on this layer too, and
			// it stays ground (#21).
			layer.setStyle(shapeStyle(this.draftSlug, this.draftColour ?? UNKNOWN_COLOUR, true))
			// And labelled as what it IS too, for the same reason: a text node and
			// the same options drawShapes would have used, so an open division keeps
			// the permanent centroid label its neighbours are still showing. Empty
			// for a polygon being drawn, which has no name yet — `bindTooltip` on an
			// empty node draws nothing, and `labelOptions` answers `{}` for anything
			// that is not a division anyway.
			if (this.draftName !== '') {
				layer.bindTooltip(document.createTextNode(this.draftName), labelOptions(this.draftSlug))
			}
			// ponytail: geoman is enabled for whatever is on this layer, so an opened
			// Unidad Vecinal gets draggable vertices and save() writes the result —
			// on a record CONTEXT.md says the clinic only reads. It predates #21 (the
			// draft has always copied any opened shape's geometry) and it is a
			// write-permission question, not a figure-and-ground one: issue #109.
			layer.pm.enable({ allowSelfIntersection: false })
			// pm:edit covers a dragged vertex, pm:update the end of a move.
			layer.on('pm:edit pm:update', () => this.report(layer))
			this.draftLayer = layer
		},

		/** The locate control's notices arrive here; the template above draws them. */
		onLocateNotice({ text, type }) {
			this.locateNotice = text
			this.locateNoticeType = type
		},

		report(layer) {
			const geometry = layer.toGeoJSON().geometry
			this.reported = JSON.stringify(geometry)
			this.$emit('reshape', geometry)
		},

		/**
		 * Hide the Unidad Vecinal labels at a zoom where they would not fit.
		 *
		 * A class on the map container rather than binding and unbinding tooltips:
		 * the answer to ~50-100 labels is cartography (marks.js), and rebuilding
		 * layers on every zoom is exactly the window the four workarounds in this
		 * file exist to keep shut. CSS already knows how to hide something.
		 *
		 * ponytail: every ground label is still built and repositioned while it is
		 * hidden — a comuna of a hundred Unidades Vecinales is a hundred divs
		 * Leaflet moves on each zoom. Bind them on crossing the threshold instead
		 * if a real catastro makes this stutter.
		 */
		updateGroundLabels() {
			this.map.getContainer().classList.toggle(
				'territorio-map__canvas--far',
				!showsGroundLabels(this.map.getZoom()),
			)
		},

		/**
		 * A record's pin: the pure markup+size comes from pins.js (L1-32) and this
		 * wraps it in the one Leaflet shape — the builders answer markup so the js
		 * suite can reach them, and `L.divIcon` stays here, beside the map. The
		 * anchor is derived from the size the builder answers, per the
		 * CLUSTER_DISC lesson below: a mark resized without its anchor draws every
		 * marker off its coordinate in silence.
		 */
		iconFor(feature) {
			const category = this.subcategories.get(feature.subcategoryId)
			const { html, size } = pinIcon({
				colour: colourOf(feature, this.subcategories),
				glyph: glyphFor(category?.categorySlug, category?.categoryGlyph),
				selected: feature.id === this.selectedId,
				approximate: isApproximate(feature),
			})

			return L.divIcon({
				className: 'territorio-pin',
				html,
				iconSize: [size, size],
				iconAnchor: [size / 2, size / 2],
			})
		},

		/**
		 * What a cluster bubble says: the Categories under it, and the count.
		 *
		 * Not the size. markercluster's own icon buckets on `getChildCount()`
		 * into three hues, so green meant «Deporte y recreación» at one zoom and
		 * "more than 100 records" at the next — one channel carrying two
		 * unrelated meanings, on a map where hue is the channel that groups
		 * Categories (ADR-0013). The count is written in the middle in the
		 * theme's own ink, which is where it always was and the reason hue never
		 * needed to carry it.
		 *
		 * A ring segmented by Category rather than the majority glyph: a majority
		 * glyph cannot tell a cluster of one Category from a mixed one, which is
		 * the criterion #22 is judged on. `@kalisio/leaflet.donutcluster` does
		 * this in 459 lines and an undeclared `window._`; this is the hook
		 * markercluster already offered.
		 *
		 * The colours are escaped for the same reason the pin's is: a Category
		 * colour is something a person typed into a picker, and this is an HTML
		 * string, not a DOM node.
		 */
		clusterIconFor(cluster) {
			// Read straight off the marker, with no fallback: `drawMarkers` is the
			// only thing that adds a marker to this group and it hangs `territorio`
			// on every one, so a marker without it is a state this component owns
			// outright. A `??` here would have stood in for a bug it cannot happen
			// without, and would have drawn a plausible bubble over it.
			const members = cluster.getAllChildMarkers().map((marker) => marker.options.territorio)
			const { html } = clusterIcon(members, cluster.getChildCount())

			return L.divIcon({
				className: 'territorio-cluster',
				html,
				// 36, where a pin is 26: a bubble has to read as more than one
				// record before the count inside it is read at all. Written here
				// and in the stylesheet, so the gate measures what was drawn
				// against `CLUSTER_DISC` and derives this anchor from that same
				// measurement — #37's lesson, that a mark resized without its
				// anchor draws every marker off its coordinate in silence.
				iconSize: [36, 36],
				iconAnchor: [18, 18],
			})
		},

		/**
		 * Smoothly pan and zoom to a specific coordinate (used by Omnibox and FeatureDetail).
		 *
		 * @param {number} lat Latitude
		 * @param {number} lon Longitude
		 * @param {number} [zoom=17] Target zoom level
		 */
		panToLocation(lat, lon, zoom = 17) {
			if (this.map === null || lat === undefined || lon === undefined) {
				return
			}
			// Re-measure first: the map may have just come back from `v-show`.
			this.map.invalidateSize()
			this.map.flyTo([lat, lon], Math.max(this.map.getZoom(), zoom), {
				animate: true,
				duration: 0.8,
			})
		},

		/**
		 * Fit the map viewport to a bounding box (e.g. from an OSM street or polygon).
		 *
		 * @param {Array|Object} bounds Bounding box representation [[south, west], [north, east]] or Leaflet LatLngBounds
		 */
		fitStreetBounds(bounds) {
			if (this.map === null || !bounds) {
				return
			}
			this.map.invalidateSize()
			// No closer than 17: a pasaje fitted to its own box would zoom to a house.
			this.map.fitBounds(bounds, {
				maxZoom: 17,
				padding: [48, 48],
				animate: true,
				duration: 0.8,
			})
		},

		/**
		 * Arm or disarm Geoman polygon drawing programmatically (from DrawSectorButton).
		 */
		toggleDrawPolygon() {
			if (!this.map || !this.map.pm) {
				return
			}
			if (this.map.pm.globalDrawModeEnabled()) {
				this.map.pm.disableDraw()
			} else {
				this.map.pm.enableDraw('Polygon', {
					snappable: true,
					snapDistance: 20,
					allowSelfIntersection: false,
				})
			}
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
/* Not scoped: Leaflet builds these elements itself, outside Vue's tree. */

/*
 * The basemap recedes, so that what the clinic drew reads as figure over it
 * (docs/design/map-legibility.md § 2). One line and no plugin.
 *
 * `saturate` and not `grayscale` or `brightness`: the CSS saturate matrix is
 * luminance-preserving, so every contrast ratio § 2 measured against a tile —
 * the Unidad Vecinal hairline at 1.35–1.49:1, the Sector wash at 3.04:1 — still
 * holds after it. A brightness filter would move all of them at once.
 */
.leaflet-tile-pane {
	filter: saturate(0.55);
}

/*
 * A label sits ON a tile, and the tile is raster OpenStreetMap in BOTH themes.
 * So these colours are literal and never Nextcloud variables: a dark-theme
 * label of #ebebeb over OSM land #f2efe9 renders at 1.04:1 — invisible.
 * Label colour follows the tile (map-legibility § 2).
 *
 * Qualified with .leaflet-tooltip rather than left alone, so this wins over
 * Leaflet's own tooltip rules whichever order the bundler emits them in.
 */
.leaflet-tooltip.territorio-label {
	background: rgba(255, 255, 255, 0.78);
	border: none;
	box-shadow: none;
	color: #1a1a1a;
	font-size: 12px;
	font-weight: 600;
	padding: 1px 5px;
}

/* Ground: quieter than the Sector above it, and no plate behind it — a hundred
   white boxes would be the noise the figure/ground rule is removing. */
.leaflet-tooltip.territorio-label--ground {
	background: none;
	color: #4c4c4c;
	font-size: 11px;
	font-weight: 400;
}

/* Below the zoom where a Unidad Vecinal is wide enough to hold its name, fifty
   of them at once are a sheet of text rather than a map. See marks.js. */
.territorio-map__canvas--far .territorio-label--ground {
	display: none;
}

/*
 * One ring, worn by the two things this map singles out: the shape the keyboard
 * is on, and the pin that is selected. The theme's own primary element colour,
 * so it flips with the theme instead of being a fixed colour that only works in
 * one; the hex is the fallback for a theme that does not set the variable. Only
 * the offsets differ, so only the offsets are written twice.
 */
$focus-ring: 3px solid var(--color-primary-element, #0b6bcb);

/*
 * The ring every pin dot and cluster bubble wears against the basemap. One
 * fixed dark ink, not a theme variable: the tiles are light in both themes —
 * §2's label rule (docs/design/map-legibility.md) binds a ring sitting on
 * those tiles as surely as a label — and the white this replaces measured
 * 1.15–1.69:1 against them, under the 3:1 contour floor of WCAG 1.4.11 in
 * both themes. The ink clears 11.18:1 at its floor (raster water) and 3:1
 * over every legal fill — measured, not asserted, by tools/ramp-check.py,
 * which fails if this declaration or its wearers drift.
 */
$pin-ring: 2px solid #171717;

/*
 * Where the keyboard currently is, on a map made of shapes a mouse could always
 * find and nothing else could (issue #25).
 *
 * `:focus-visible` rather than `:focus`, so a shape a person clicked does not
 * also grow a ring. The offset is what keeps it off the shape's own stroke: a
 * Sector is drawn in a Category colour chosen by the clinic, and a ring sitting
 * directly on it could be the same colour as the line underneath.
 */
.leaflet-interactive:focus-visible {
	outline: $focus-ring;
	outline-offset: 2px;
}

.territorio-pin__dot {
	display: flex;
	align-items: center;
	justify-content: center;
	/*
	 * Written onto the element by `iconFor()` from `PIN_DOT_SIZE` — which is
	 * where the measurement and the reason for it are (marks.js).
	 *
	 * No fallback value. `iconFor()` is the only thing that builds a
	 * `.territorio-pin__dot`, so a missing property is not a pin drawn slightly
	 * wrong, it is this component having stopped building its own markup — and a
	 * dot that silently kept its old size would hide that from the browser gate,
	 * which measures the rendered box.
	 */
	width: var(--territorio-pin);
	height: var(--territorio-pin);
	border-radius: 50%;
	border: $pin-ring;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);

	/*
	 * White, and not a theme variable. The ramp was validated against white
	 * specifically — every Category clears 3:1 against it (WCAG 1.4.11,
	 * tools/ramp-check.py) — and the dot's fill is a data colour that does not
	 * change with the theme, so a glyph that did would stop clearing on one of
	 * them. The mark's own size comes from `PIN_GLYPH_SIZE` (marks.js), which
	 * #46's picker previews at; the ring stays, because it is what keeps a dot
	 * off fill-vs-tint contrast over a tinted Sector
	 * (docs/design/map-legibility.md §1.2).
	 */
	svg {
		width: var(--territorio-pin-mark);
		height: var(--territorio-pin-mark);
		fill: #fff;
		flex: 0 0 auto;
	}

	&--selected {
		outline: $focus-ring;
		outline-offset: 1px;
	}

	/*
	 * «Roughly here» (#23). The ring is already round every pin, so this costs
	 * no footprint at all — which is what settled it against a halo and an edge
	 * badge, with #37 simultaneously raising the pin to 26px and La Florida
	 * holding ~865 places.
	 *
	 * A dash and not an opacity: opacity is hue and alpha, which is the one
	 * channel a dichromacy takes away and the one this map already overloads
	 * (ADR-0013). A dash is a pattern, it survives both themes because the ring
	 * is one fixed dark ink on a basemap that is light in both themes (§2's
	 * label rule), and it already means exactly this across the app — the app
	 * is not asserting this precisely (ADR-0015), as a Unidad Vecinal's hairline
	 * does.
	 */
	&--approximate {
		border-style: dashed;
	}
}

/*
 * A cluster says which Categories are under it, never how many (#22).
 *
 * markercluster's own icon buckets on `getChildCount()` into three hues, so
 * green meant «Deporte y recreación» at one zoom and "over 100 records" at the
 * next. `MarkerCluster.Default.css` — which is nothing but those buckets — is
 * therefore not imported, and `iconCreateFunction` builds this instead.
 */
.territorio-cluster__ring {
	display: flex;
	align-items: center;
	justify-content: center;
	/*
	 * 36px against the pin's 26px: a bubble has to read as more than one record
	 * before anybody reads the number in it. The conic gradient paints the whole
	 * disc and the count below covers the middle, leaving a 4px band of Category
	 * — (36 - 2·2 border - 24 count) / 2. The gate measures that subtraction on
	 * the rendered bubble, so whichever of the three moves, the band going is
	 * what goes red.
	 */
	width: 36px;
	height: 36px;
	border-radius: 50%;
	border: $pin-ring;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);

	/* The same dash, for the same reason, when any record under it is approximate. */
	&--approximate {
		border-style: dashed;
	}
}

.territorio-cluster__count {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	/*
	 * The theme's own ink on the theme's own paper, which is the pair that is
	 * guaranteed readable in both themes — and is not a hue that could be read
	 * as a Category. This is where the count always was; hue never needed to
	 * carry it, which is the whole of the defect.
	 */
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	font-size: 12px;
	font-weight: 600;
	line-height: 1;
	/* Lining, tabular figures: 8 and 88 must not jump in a 24px disc. */
	font-variant-numeric: tabular-nums lining-nums;
}
</style>
