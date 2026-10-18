<template>
	<NcContent app-name="territorio">
		<NcAppContent>
			<ErrorBoundary>
				<div class="territorio-app-viewport">
					<!-- Top floating Omnibox bar and action controls (Google Maps command strip) -->
					<div class="territorio-topbar">
						<OmniboxControl
							:model-value="omniboxQuery"
							:assets="liveFiltered"
							:streets="streets"
							:subcategories="subcategories"
							:pane="pane"
							@update:model-value="onOmniboxInput"
							@select-asset="onSelectAsset"
							@select-street="goToStreet"
							@change-pane="pane = $event" />

						<div v-show="pane === 'mapa'" class="territorio-topbar__actions">
							<LayersDropdownButton
								:taxonomy="taxonomy"
								:filter="filter"
								@toggle-category="onToggleCategory"
								@toggle-division="onToggleSubcategory"
								@select-all="showAllCategories"
								@clear-all="hideAllCategories"
								@open-taxonomy-drawer="showTaxonomyDrawer = true" />

							<DrawSectorButton
								:is-drawing="isDrawingSector"
								@draw-sector="toggleDrawSector"
								@cancel-draw="cancelDrawSector" />
						</div>
					</div>

					<!-- 100% Canvas Map -->
					<!--
						v-show, not v-if: tearing the Leaflet map down and rebuilding it on
						every switch would throw away the viewport the user had panned to.
						The ResizeObserver inside it re-measures when it comes back.

						The map only ever shows what is live. Retired records are browsable
						in the list so they can be restored, never drawn.
					-->
					<div v-show="pane === 'mapa'" class="territorio-map-container">
						<TerritorioMap
							ref="mapComponent"
							:features="liveFiltered"
							:taxonomy="taxonomy"
							:divisions="divisions"
							:selected-id="selectedId"
							:draft-geometry="draftGeometry"
							:draft-colour="draft?.colour ?? null"
							:draft-subcategory-id="draft?.subcategoryId ?? null"
							:draft-name="draft?.name ?? ''"
							:placing="placing"
							:cadastre-lines="editingLimit ? cadastreLines : []"
							:picked-ids="draft?.picked ?? []"
							@pick="togglePicked"
							@view-changed="onViewChanged"
							@select="select"
							@map-click="placeDraft"
							@draw="startSector"
							@reshape="reshapeDraft"
							@draw-mode-change="isDrawingSector = $event" />
					</div>

					<!-- 408px Left Docked Panel (Collapsible Google Maps Left Sheet) -->
					<aside
						v-show="pane === 'mapa'"
						class="territorio-docked-panel"
						:class="{ 'territorio-docked-panel--collapsed': !panelOpen }"
						aria-label="Panel de activos comunitarios">
						<button
							type="button"
							class="territorio-docked-panel__toggle"
							:title="panelOpen ? 'Ocultar panel lateral' : 'Mostrar panel lateral'"
							:aria-label="panelOpen ? 'Ocultar panel lateral' : 'Mostrar panel lateral'"
							:aria-expanded="panelOpen ? 'true' : 'false'"
							@click="panelOpen = !panelOpen">
							<span class="territorio-docked-panel__chevron" aria-hidden="true">{{ panelOpen ? '◀' : '▶' }}</span>
						</button>

						<div class="territorio-docked-panel__inner">
							<div class="territorio-docked-panel__spacer" />

							<!-- When a draft is open (Inspecting or Editing an Asset or Sector) -->
							<div v-if="draft !== null" class="territorio-docked-panel__sheet">
								<!--
									The second boundary (L0-02). FeatureDetail and BoundaryPicker draw
									geometry- and limit-derived drafts, and until now a render error there
									blanked the whole page. Around the CONTENT only: the topbar, the
									map and the panel's toggle stay up for a crashed record.
								-->
								<ErrorBoundary>
									<NcNoteCard v-if="selectedIsRetired" type="warning" class="territorio-restore-card">
										Este registro está retirado: no aparece en el mapa ni en la lista de activos.
										<NcButton class="territorio-restore" variant="primary" @click="restore">
											Restaurar
										</NcButton>
									</NcNoteCard>

									<FeatureDetail
										:draft="draft"
										:taxonomy="taxonomy"
										:divisions="divisions"
										:features="features"
										:saving="saving"
										:error="error"
										:history="history"
										:history-failed="historyFailed"
										:retired="selectedIsRetired"
										@save="save"
										@retire="retire"
										@retry="loadHistory(selectedId)"
										@center="centerSelected"
										@cancel="closeDraft">
										<!--
											The record first, the Limit editor after it and INSIDE it (issue #35).
											The other way round the editor's suggestions, picked groups and limit
											sentence pushed every one of the form's controls down, and «Retirar del
											mapa» — the last of them — ended 721px below the bottom of a 620px
											screen on a real Sector. The Limit editor is also the half that grows
											without bound, so it belongs where growing costs nothing.

											Inside, because `position: sticky` is bounded by its containing block.
											The action group's containing block is FeatureDetail's <form>; with the
											editor a SIBLING of that form the group stopped sticking the moment the
											form's own height had scrolled past, and the browser gate measured the
											retire button at y=-420 on a 620px screen. It is the same record
											either way: the Limit is part of the draft Guardar saves.
										-->
										<BoundaryPicker
											v-if="editingLimit"
											:suggestions="boundarySuggestions"
											:picked-ids="draft.picked"
											:query="boundaryQuery"
											:seeding="seeding"
											:can-generate="canGenerate"
											:limit="groups(draft.limit, goneIds)"
											:limit-said="readable(draft.limit)"
											:failed="boundariesFailed"
											@retry="loadBoundaries"
											@add-note="addLimitNote"
											@remove-part="removeLimitPart"
											@generate="startSeeding"
											@toggle-group="togglePickedGroup"
											@update:query="boundaryQuery = $event" />
									</FeatureDetail>
								</ErrorBoundary>
							</div>

							<!-- When no draft is open: FeatureList with Assets Directory -->
							<div v-else class="territorio-docked-panel__list">
								<!--
									A refusal that happens before there is a draft has nowhere else to
									go: the panel that normally shows `error` only exists while one is
									open, so a failure in startSector() was written to a component that
									was not mounted and the drawn polygon vanished with no explanation.
								-->
								<NcNoteCard
									v-if="error !== ''"
									type="error"
									class="territorio-main__error">
									{{ error }}
								</NcNoteCard>

								<FeatureList
									:features="listed"
									:taxonomy="taxonomy"
									:divisions="divisions"
									:selected-id="selectedId"
									:show-retired="showRetired"
									:retired-failed="retiredFailed"
									:query="filter.query"
									@select="selectAndPan"
									@create="startNew"
									@import="importing = true"
									@export="exporting = true"
									@update:show-retired="toggleRetired"
									@retry="loadRetired"
									@update:query="search" />
							</div>
						</div>
					</aside>

					<!-- Slide-over Taxonomy Drawer (replacing old permanent column) -->
					<TaxonomyDrawer
						v-if="showTaxonomyDrawer"
						:taxonomy="taxonomy"
						:filter="filter"
						:error="taxonomyError"
						:notice="taxonomyNotice"
						@close="closeTaxonomyDrawer"
						@clear-taxonomy-message="clearTaxonomyMessage"
						@toggle-category="onToggleCategory"
						@toggle-subcategory="onToggleSubcategory"
						@rename="rename"
						@choose-glyph="chooseGlyph"
						@add-category="addCategory"
						@add-subcategory="addSubcategory"
						@remove="removeFromTaxonomy"
						@reset="resetFilter" />

					<!-- Full-screen Views for Other Panes -->
					<!--
						v-if, like Análisis and the queue beside it, and NOT the map's
						v-show. The Índice's column checkboxes are labelled «Nombre»,
						«Teléfono», «Correo» — the record form's own words — so a pane left
						mounted behind the map would put two controls with one accessible
						name in the page at once, and a screen reader would read the record's
						name field and a column switch as the same thing. What survives the
						rebuild is `indice` below, which is where it belongs anyway.

						The filtered LIVE Registry, like the map: `listed` answers with the
						retired list alone once that toggle is on (ADR-0005), and a table of
						retired records would disagree with both the map and what Exportar
						would carry.
					-->
					<div v-if="pane === 'indice'" class="territorio-fullscreen-view">
						<IndiceView
							v-model:view="indice"
							:features="liveFiltered"
							:taxonomy="taxonomy"
							:divisions="divisions"
							:selected-id="selectedId"
							:filtered="isFiltered(filter)"
							@select="selectAndPan"
							@back="pane = 'mapa'" />
					</div>

					<div v-if="pane === 'analisis'" class="territorio-fullscreen-view">
						<AnalisisView
							:counts="counts"
							:filtered="isFiltered(filter)"
							@back="pane = 'mapa'" />
					</div>

					<!--
						Unfiltered on purpose, unlike everything beside it. The Capas panel
						and the search are about what someone is looking at; the queue is
						about what is waiting to be decided, and hiding half a pair behind a
						filter would leave a card with one side and no question.
					-->
					<div v-if="pane === 'duplicados'" class="territorio-fullscreen-view">
						<!-- resolve() writes `error`, and the panel that shows it is hidden here. -->
						<NcNoteCard v-if="error !== ''" type="error" class="territorio-main__error">
							{{ error }}
						</NcNoteCard>
						<DuplicatesView
							:pairs="duplicates"
							:features="features"
							:taxonomy="taxonomy"
							:busy="resolving"
							:failed="duplicatesFailed"
							@merge="mergePair"
							@dismiss="dismissPair"
							@retry="loadDuplicates"
							@show="selectAndPan"
							@back="pane = 'mapa'" />
					</div>
				</div>
			</ErrorBoundary>
		</NcAppContent>

		<ImportDialog v-if="importing" @close="importing = false" @imported="afterImport" />

		<!--
			Given the ids on screen, not a filter. The View is personal (CONTEXT.md)
			and lives in this browser, so what a person is looking at is something
			only this component knows — asking the server to reconstruct it would be
			a second implementation of "the filtered set", able to disagree with the
			one the person can see.
		-->
		<ExportDialog
			v-if="exporting"
			:ids="exportableIds"
			:scope="exportScope"
			@close="exporting = false" />
	</NcContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AnalisisView from './components/AnalisisView.vue'
import BoundaryPicker from './components/BoundaryPicker.vue'
import DrawSectorButton from './components/DrawSectorButton.vue'
import DuplicatesView from './components/DuplicatesView.vue'
import ErrorBoundary from './components/ErrorBoundary.vue'
import ExportDialog from './components/ExportDialog.vue'
import FeatureDetail from './components/FeatureDetail.vue'
import FeatureList from './components/FeatureList.vue'
import ImportDialog from './components/ImportDialog.vue'
import IndiceView from './components/IndiceView.vue'
import LayersDropdownButton from './components/LayersDropdownButton.vue'
import OmniboxControl from './components/OmniboxControl.vue'
import TaxonomyDrawer from './components/TaxonomyDrawer.vue'
import TerritorioMap from './components/TerritorioMap.vue'
import { messageFrom, safeError, warnError } from './api.js'
import { suggestions } from './boundaries.js'
import { mergeInto } from './composables/useChanges.js'
import {
	addLimitNote,
	keepPickedGeometry,
	recordLimit,
	removeLimitPart,
	togglePicked,
	togglePickedGroup,
} from './composables/useLimitEditor.js'
import { blankDraft, draftFrom, payload } from './draft.js'
import { groups, readable, sectorFields } from './limit.js'
import { canPolygonize, faceAround } from './sectors.js'
import { viewTargetOf } from './assets.js'
import { prefetchStreets } from './streets.js'
import {
	countsFor,
	emptyFilter,
	featureById,
	filterFeatures,
	forgetRemoved,
	hideTree,
	isFiltered,
	listFor,
	showTree,
	toggleCategory,
	toggleSubcategory,
} from './filters.js'
import {
	DEFAULT_SECTOR_COLOUR,
	defaultSubcategoryId,
	divisionsOf,
	indexSubcategories,
	sectorSubcategoryId,
} from './taxonomy.js'

/**
 * ADR-0002: seconds of latency is fine for territorial editing, and this instance
 * has no push backend. Five seconds at twenty open maps is four requests a second,
 * most of them answered by one indexed read.
 */
const POLL_INTERVAL_MS = 5000

/** `/categories/{slug}` or `/categories/{slug}/subcategories/{slug}` — the two ids every taxonomy write addresses. */
const categoryUrl = (categorySlug, subcategorySlug = null) => {
	const category = `/apps/territorio/categories/${encodeURIComponent(categorySlug)}`
	return subcategorySlug === null
		? category
		: `${category}/subcategories/${encodeURIComponent(subcategorySlug)}`
}

export default {
	name: 'App',

	components: {
		AnalisisView,
		BoundaryPicker,
		DrawSectorButton,
		DuplicatesView,
		ErrorBoundary,
		ExportDialog,
		FeatureDetail,
		FeatureList,
		ImportDialog,
		IndiceView,
		LayersDropdownButton,
		NcAppContent,
		NcButton,
		NcContent,
		NcNoteCard,
		OmniboxControl,
		TaxonomyDrawer,
		TerritorioMap,
	},

	data() {
		return {
			taxonomy: loadState('territorio', 'taxonomy'),
			features: loadState('territorio', 'features'),
			revision: loadState('territorio', 'revision'),
			retiredFeatures: [],
			showRetired: false,
			// The View is personal (CONTEXT.md): this never leaves the browser and
			// changing it changes nothing for anyone else.
			filter: emptyFilter(),
			// Which pane fills the main area. Named `pane`, not `view`, because
			// View is the glossary's word for the whole personal on-screen state —
			// of which this is one part, alongside the filter above.
			pane: 'mapa',
			panelOpen: true,
			// What the search box shows. It follows the keystroke at once, while
			// `filter.query` waits out search()'s debounce: bound straight to
			// `filter.query`, the box would hand back the stale text and eat a
			// character on every re-render.
			omniboxQuery: '',
			showTaxonomyDrawer: false,
			isDrawingSector: false,
			streets: [],
			// The Índice's own View — which columns it draws and in what order
			// (#105). Here and not in the component for the reason the filter is
			// here: the View is personal (CONTEXT.md) and lives in this browser, and
			// the pane itself is rebuilt on every switch. HIDDEN columns rather than
			// shown ones, filters.js's rule: a column added to COLUMNS is absent
			// from the set, so it appears on its own.
			indice: { hidden: new Set(), key: 'name', ascending: true },
			// Pairs of records that might be one place (issue #16). Ids only: both
			// sides of a pending pair are visible, so `features` already holds them
			// and a second copy could only disagree with the first.
			duplicates: [],
			// The pair being resolved right now, so its buttons cannot be pressed
			// twice while the request is in flight.
			resolving: null,
			exporting: false,
			// Waiting for the click that says which enclosed block is the Sector
			// (issue #14). Only the person knows: four streets crossing make
			// several blocks and the picked lines say nothing about which one.
			seeding: false,
			importing: false,
			history: [],
			selectedId: null,
			draft: null,
			saving: false,
			error: '',
			// Separate from `error`: that one renders in the panel's list and only
			// while no record is open, and the Capas panel — where a rename or an
			// add is refused — is the taxonomy drawer, open on its own terms.
			taxonomyError: '',
			// Not an error, and it must not read as one (ADR-0017): what a
			// Category on the neutral means, said once, where it was added.
			taxonomyNotice: '',
			pollTimer: null,
			pollFailed: false,
			// The three reads that can fail while their surface stays up: a failed
			// load is not an empty list (L1-28), so each surface says it could not
			// load and offers Reintentar, rather than claiming nothing is there.
			historyFailed: false,
			duplicatesFailed: false,
			retiredFailed: false,
			boundariesFailed: false,
			// Loaded only while a Sector's Limit is being built, and thrown away
			// when it is not: a comuna is around eleven thousand lines (issue #12).
			// `cadastreLines` (L1-33): the street lines a Limit runs along, fetched
			// from /boundaries — «divisions» are the Sectores and Unidades
			// Vecinales themselves, and neither word may stand in for the other.
			cadastreLines: [],
			// Which of the Limit's cited ids are no longer live Boundary features
			// (issue #51). It has to come from the server: this list only ever holds
			// what is in view and never a retired row, so "not here" means
			// "off-screen OR gone" and the browser cannot tell those apart.
			goneIds: [],
			boundaryQuery: '',
			view: null,
			viewTimer: null,
			searchTimer: null,
		}
	},

	computed: {
		/**
		 * The one filtered set. The map, the Datos list and Análisis all derive
		 * from this rather than each filtering for themselves — three filters is
		 * how a count ends up disagreeing with what is on screen.
		 */
		liveFiltered() {
			return filterFeatures(this.features, this.filter, this.subcategories)
		},

		/**
		 * The Subcategory index the filtering needs: search matches against
		 * "Category · Subcategory", and `filters.js` stays pure by being handed the
		 * index rather than importing the taxonomy itself.
		 *
		 * The components that draw labels and swatches derive their own from the
		 * same `taxonomy` prop. That is not a second source of truth — the taxonomy
		 * is the source, and `indexSubcategories` is a pure reading of it.
		 */
		subcategories() {
			return indexSubcategories(this.taxonomy)
		},

		/** See listFor(): Capas never touches the retired list, but a search does. */
		listed() {
			return listFor({
				features: this.features,
				retiredFeatures: this.retiredFeatures,
				showRetired: this.showRetired,
				filter: this.filter,
				subcategories: this.subcategories,
			})
		},

		/**
		 * What an export would carry: the live filtered set — the same set the
		 * map and the Índice draw, whatever the retired toggle is saying. Under
		 * «Ver retirados» the Datos list shows retired records instead (#130),
		 * but an export is not asking for what used to be there.
		 *
		 * No retired filter is needed: `liveFiltered` derives from `features`,
		 * and `mergeInto` moves a record out of `features` the moment it is
		 * retired, so the set updates on its own. The server refuses retired ids
		 * anyway (`FeatureMapper::findByIds`, ADR-0005).
		 */
		exportableIds() {
			return this.liveFiltered.map((feature) => feature.id)
		},

		/**
		 * How the screen was narrowed, as a sentence for the export log (ADR-0006).
		 *
		 * Written here because this is where the filter lives, and written as
		 * words because an administrator reading the report months later needs to
		 * know what left the building, not to reconstruct a filter object.
		 */
		exportScope() {
			const said = []
			if (this.filter.query !== '') {
				said.push(`búsqueda: "${this.filter.query}"`)
			}
			if (isFiltered({ ...this.filter, query: '' })) {
				said.push('con capas filtradas')
			}

			return said.length === 0 ? 'todo el Registro' : said.join(', ')
		},

		/**
		 * Always the live Registry, never the retired list. ADR-0005 keeps retired
		 * records out of every default read, and a total that silently counted
		 * them would be wrong in a way nobody on screen could see.
		 */
		counts() {
			return countsFor(this.liveFiltered, this.taxonomy)
		},

		/**
		 * Whether the open record is one of the retired ones — which is what puts
		 * the restore banner up, and what tells the detail pane not to offer to
		 * retire it again.
		 *
		 * Not gated on the toggle. `retiredFeatures` is never cleared once loaded,
		 * so a record retired by a colleague can be opened from the duplicate queue
		 * with the toggle off — and gating on it meant that record showed no banner
		 * and a live «Guardar». Membership is the whole question.
		 */
		selectedIsRetired() {
			return this.retiredFeatures.some((feature) => feature.id === this.selectedId)
		},

		/**
		 * The Sectores and Unidades Vecinales the app holds, so the sidebar can turn
		 * a stored assignment id into the name somebody would recognise. Named
		 * `divisions`, matching taxonomy.js's `divisionsOf` vocabulary (L1-33) —
		 * «boundary» names the /boundaries resource alone.
		 */
		divisions() {
			const ids = new Set(divisionsOf(this.taxonomy).map((division) => division.id))

			return this.features.filter((feature) => ids.has(feature.subcategoryId))
		},

		/**
		 * Whether a Sector is open and its Limit is being built (ADR-0007).
		 *
		 * The one moment the Boundary features are wanted, and the reason they are
		 * absent from every other read.
		 */
		editingLimit() {
			return this.draft !== null && this.draft.picked !== undefined
		},

		/**
		 * Whether «Generar» can do anything, which is not the same as whether
		 * anything is picked. A reopened Sector has picks and no shapes behind
		 * them (issue #49), and offering a button that can only fail is worse than
		 * not offering it: ADR-0007 keeps hand-drawing first-class either way.
		 */
		canGenerate() {
			return this.draft?.picked !== undefined
				&& canPolygonize(this.draft.picked, this.draft.pickedGeometry)
		},

		boundarySuggestions() {
			return suggestions(this.cadastreLines, this.boundaryQuery)
		},

		/** The shape the map should draw editable — a Sector being drawn or adjusted. */
		draftGeometry() {
			return this.draft?.geometry ?? null
		},

		/**
		 * The next click on the map belongs to the draft rather than to whatever
		 * it lands on — a Sector or a Unidad Vecinal would otherwise take it to
		 * select themselves.
		 *
		 * Two reasons for that, and they are different things: a draft with no
		 * geometry is waiting for its coordinate, and a Sector being built is
		 * waiting for the click that says which block it is (issue #14). Both want
		 * the same thing from the map, which is why they share one flag; anything
		 * that replaces the draft clears the second (see stopSeeding).
		 */
		placing() {
			return (this.draft !== null && this.draft.geometry === null) || this.seeding
		},
	},

	watch: {
		// The list's own search box, «Ver todo» and a reset change the query too.
		'filter.query'(query) {
			this.omniboxQuery = query
		},
	},

	mounted() {
		this.loadDuplicates()
		this.startPolling()
		this.prefetchBoundaryStreets()
		document.addEventListener('visibilitychange', this.onVisibilityChange)
	},

	beforeUnmount() {
		clearTimeout(this.viewTimer)
		clearTimeout(this.searchTimer)
		this.stopPolling()
		document.removeEventListener('visibilitychange', this.onVisibilityChange)
	},

	methods: {
		isFiltered,
		groups,
		readable,

		resetFilter() {
			this.filter = emptyFilter()
		},

		onToggleCategory(category) {
			this.filter = toggleCategory(this.filter, category)
		},

		onToggleSubcategory(subcategoryId) {
			this.filter = toggleSubcategory(this.filter, subcategoryId)
		},

		showAllCategories() {
			this.filter = showTree(this.filter, this.taxonomy)
		},

		hideAllCategories() {
			this.filter = hideTree(this.filter, this.taxonomy)
		},

		closeTaxonomyDrawer() {
			this.showTaxonomyDrawer = false
			this.clearTaxonomyMessage()
		},

		onOmniboxInput(query) {
			this.omniboxQuery = query
			this.search(query)
		},

		onSelectAsset(result) {
			this.selectAndPan(result.id)
		},

		/**
		 * Typing narrows the same set the toggles narrow. A new object rather than
		 * a mutation, like every other filter change, so nothing downstream has to
		 * wonder who else might be holding it.
		 *
		 * Debounced (~150ms, L1-36): every keystroke rebuilt the whole marker and
		 * shape layer groups — the watcher's own comment counted the cost — and a
		 * burst collapses to one redraw. Toggles stay immediate: they are discrete
		 * clicks, and the same timer would only add latency to them.
		 */
		search(query) {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(() => {
				this.filter = { ...this.filter, query }
			}, 150)
		},

		startPolling() {
			if (this.pollTimer === null && !document.hidden) {
				this.pollTimer = setInterval(this.poll, POLL_INTERVAL_MS)
			}
		},

		stopPolling() {
			clearInterval(this.pollTimer)
			this.pollTimer = null
		},

		/**
		 * A hidden tab costs the server the same as a visible one and shows the
		 * user nothing, so it stops entirely — and catches up in one request when
		 * it comes back, rather than waiting out the interval.
		 */
		onVisibilityChange() {
			if (document.hidden) {
				this.stopPolling()
				return
			}
			this.poll()
			this.startPolling()
		},

		/**
		 * Ask what has changed since our cursor and merge it (ADR-0002).
		 *
		 * Deliberately does not touch an open draft: someone half-way through
		 * typing an address must not have it replaced under them by a colleague's
		 * save. The list and the map update; the form the user is holding does not.
		 */
		async poll() {
			// Not awaited by the timer, so a slow response can land after a later
			// one and move the cursor backwards. Harmless: the only consequence is
			// that a record arrives twice, and mergeFeature is idempotent.
			try {
				const { data } = await axios.get(
					generateUrl('/apps/territorio/changes'),
					{ params: { since: this.revision } },
				)
				this.revision = data.revision
				data.features.forEach((incoming) => this.mergeFeature(incoming))
				this.pollFailed = false
			} catch (exception) {
				// One line per outage, not one every five seconds — a clinic that
				// loses its connection for an hour would otherwise fill the console.
				if (!this.pollFailed) {
					this.pollFailed = true
					warnError('change polling failed, will keep trying', exception)
				}
			}
		},

		/**
		 * Apply one record from the change feed to whichever list it belongs in.
		 * The decision core is `mergeInto` (useChanges.js, L1-31) — vitest-reachable;
		 * `this` supplies the four fields it reads: features, retiredFeatures,
		 * selectedId, showRetired.
		 */
		mergeFeature(incoming) {
			const { features, retiredFeatures, closeOpen } = mergeInto(this, incoming)
			this.features = features
			this.retiredFeatures = retiredFeatures
			if (closeOpen) {
				this.closeDraft()
			}
		},

		select(id) {
			this.stopSeeding()
			this.selectedId = id
			const feature = featureById(this.features, this.retiredFeatures, id)
			this.draft = feature === undefined ? null : draftFrom(feature, this.taxonomy)
			this.error = ''
			this.history = []
			this.historyFailed = false
			this.boundaryQuery = ''
			this.cadastreLines = []
			this.goneIds = []
			if (this.editingLimit) {
				this.loadBoundaries()
			}
			if (feature !== undefined) {
				this.loadHistory(id)
			}
		},

		async selectAndPan(id) {
			this.select(id)
			this.panelOpen = true
			// From the Índice or the duplicates queue the record opens in the panel
			// and the map is hidden (`v-show`) at zero size, where Leaflet cannot
			// compute a view. Back to the map first, and move it once it is laid out.
			if (this.pane !== 'mapa') {
				this.pane = 'mapa'
				await this.$nextTick()
			}
			const feature = featureById(this.features, this.retiredFeatures, id)
			// A record has GeoJSON `geometry`, not `lat`/`lon`, and may have none:
			// then it is selected and the map stays where it is.
			const target = viewTargetOf(feature?.geometry)
			const map = this.$refs.mapComponent
			if (target === null || !map) {
				return
			}
			if (target.kind === 'point') {
				map.panToLocation(target.lat, target.lon, 17)
			} else {
				map.fitStreetBounds(target.bounds)
			}
		},

		centerSelected(coords) {
			if (this.$refs.mapComponent) {
				const lat = coords?.lat ?? this.draft?.lat
				const lon = coords?.lon ?? this.draft?.lon
				if (lat && lon) {
					this.$refs.mapComponent.panToLocation(lat, lon, 17)
				}
			}
		},

		goToStreet(street) {
			if (!street || !this.$refs.mapComponent) {
				return
			}
			const bounds = [[street.south, street.west], [street.north, street.east]]
			this.$refs.mapComponent.fitStreetBounds(bounds)
		},

		toggleDrawSector() {
			if (this.$refs.mapComponent) {
				this.$refs.mapComponent.toggleDrawPolygon()
			}
		},

		cancelDrawSector() {
			if (this.isDrawingSector && this.$refs.mapComponent) {
				this.$refs.mapComponent.toggleDrawPolygon()
			}
		},

		prefetchBoundaryStreets() {
			prefetchStreets(async () => {
				if (this.streets.length === 0) {
					try {
						const { data } = await axios.get(generateUrl('/apps/territorio/boundaries/names'))
						this.streets = data
					} catch {
						// Non-critical background prefetch
					}
				}
			})
		},

		async loadHistory(id) {
			this.historyFailed = false
			try {
				const { data } = await axios.get(
					generateUrl(`/apps/territorio/features/${id}/history`),
				)
				// Ignore a response that arrived after the user moved on, or the
				// sidebar shows one record's details beside another's history.
				if (this.selectedId === id) {
					this.history = data
				}
			} catch (exception) {
				warnError('could not load the history', exception)
				this.historyFailed = true
			}
		},

		async toggleRetired(showRetired) {
			this.showRetired = showRetired
			this.closeDraft()
			if (showRetired) {
				await this.loadRetired()
			}
		},

		/**
		 * The outstanding pairs (issue #16).
		 *
		 * Not polled. Pairs appear when somebody imports and are resolved by the
		 * person looking at them, so a five-second poll would spend a request every
		 * five seconds on a number that changes a few times a month. It is loaded
		 * on open and after each of those two events instead.
		 */
		async loadDuplicates() {
			this.duplicatesFailed = false
			try {
				const { data } = await axios.get(generateUrl('/apps/territorio/duplicates'))
				this.duplicates = data.pairs
			} catch (exception) {
				// The queue failing to load must not take the Registry down with it.
				warnError('could not load the duplicate queue', exception)
				this.duplicatesFailed = true
			}
		},

		/** An import is the one moment new pairs appear. */
		async afterImport() {
			await Promise.all([this.poll(), this.loadDuplicates()])
		},

		/**
		 * Keep one of two records and retire the other, pointing it at the survivor.
		 *
		 * The whole exchange is reloaded rather than patched locally: a merge
		 * retires a record, which the change feed will deliver anyway, and guessing
		 * at that here would mean two descriptions of the same event that can
		 * disagree.
		 */
		async mergePair({ pairId, survivorId }) {
			await this.resolve(pairId, `/apps/territorio/duplicates/${pairId}/merge`, { survivorId })
		},

		async dismissPair(pairId) {
			await this.resolve(pairId, `/apps/territorio/duplicates/${pairId}/dismiss`, {})
		},

		/** Both decisions post, then refetch; only the URL and the body differ. */
		async resolve(pairId, url, body) {
			this.resolving = pairId
			try {
				await axios.post(generateUrl(url), body)
				// Independent endpoints, nothing consumes the other's result — so
				// the resolving flag the buttons wait on no longer spans two serial
				// round trips (L1-35).
				await Promise.all([this.poll(), this.loadDuplicates()])
				this.error = ''
			} catch (exception) {
				this.error = await messageFrom(exception, 'No se pudo resolver el par. Inténtelo de nuevo.')
			} finally {
				this.resolving = null
			}
		},

		async loadRetired() {
			this.retiredFailed = false
			try {
				const { data } = await axios.get(generateUrl('/apps/territorio/features/retired'))
				this.retiredFeatures = data
			} catch (exception) {
				warnError('could not load retired records', exception)
				this.retiredFailed = true
			}
		},

		/** Retiring keeps the record; it only leaves the live set (ADR-0005). */
		async retire() {
			const id = this.draft.id
			try {
				const { data } = await axios.delete(generateUrl(`/apps/territorio/features/${id}`))
				this.mergeFeature(data)
				this.closeDraft()
			} catch (exception) {
				this.error = await messageFrom(exception, 'No se pudo retirar el registro.')
			}
		},

		/**
		 * Rename a Category, or a Subcategory of one (#41).
		 *
		 * Addressed by slug: it is the identifier the label does not take with it
		 * when it moves, and the one every record is already filed under.
		 *
		 * The server answers with the whole Category tree and it is swapped in
		 * here, which is the "without a reload" criterion: the Capas panel, the
		 * Subcategoría picker, Análisis and the map's colours all read this one
		 * property, so they move together and none of them holds a second copy
		 * that can disagree.
		 *
		 * Nothing else is refetched. A rename does not advance the Revision
		 * cursor — see the dated correction on ADR-0002 — so a colleague's browser
		 * reads the new label on its next load.
		 */
		async rename({ categorySlug, subcategorySlug, label }) {
			await this.writeTree('put', categoryUrl(categorySlug, subcategorySlug), { label },
				'No se pudo renombrar. Revise la conexión e inténtelo de nuevo.')
		},

		/**
		 * The one door every taxonomy write goes through (L0-05).
		 *
		 * The whole tree comes back and is swapped in, which is what puts the
		 * change on the map pins, in the Datos list and in the Capas legend at
		 * once — all three read it out of this one property through
		 * `indexSubcategories()`, so none of them holds a copy that can disagree.
		 * Nothing else is refetched and the Revision cursor is not advanced (see
		 * the dated correction on ADR-0002).
		 *
		 * A 422 carries a message naming what to fix — a rename's clash, a
		 * removal's refusal with how many records and where to see them
		 * (ADR-0016) — and `messageFrom` says it as is; anything else gets the
		 * caller's fallback, which must not pretend to know more.
		 *
		 * @return {?Array} the new tree, or null when the write was refused
		 */
		async writeTree(method, url, body, fallback) {
			try {
				const { data } = await axios[method](generateUrl(url), body)
				this.taxonomy = data
				this.clearTaxonomyMessage()
				return data
			} catch (exception) {
				this.taxonomyError = await messageFrom(exception, fallback)
				return null
			}
		},

		/**
		 * Choose the pictogram a Category is drawn with (#46).
		 *
		 * The same `PUT /categories/{slug}` a rename uses, carrying `glyph` and no
		 * `label`: both edit the Category, and the server acts on the key that is
		 * actually in the body. An empty `glyph` clears the choice.
		 *
		 * The whole tree comes back and is swapped in exactly as a rename's is,
		 * which is what puts the new mark on the map pin, in the Datos list and in
		 * the Capas legend at once — all three read it out of this one property
		 * through `indexSubcategories()`, so none of them holds a copy that can
		 * disagree. Nothing else is refetched and the Revision cursor is not
		 * advanced, for the reason `rename()` gives.
		 */
		async chooseGlyph({ categorySlug, glyph }) {
			await this.writeTree('put', categoryUrl(categorySlug), { glyph },
				'No se pudo cambiar el pictograma. Revise la conexión e inténtelo de nuevo.')
		},

		/**
		 * Add a Category (#45), with the pictogram picked for it (#46).
		 *
		 * The label and the mark go up. The server derives the slug and ASSIGNS the
		 * colour from the vetted spare list (ADR-0017), which is why there is no
		 * colour input here or anywhere else: the constraints — 3:1 against white
		 * and against the dark theme, and separation from every other Category
		 * under three dichromacies — cannot be satisfied by eye, and an input that
		 * accepts a failing value is worse than no input. The pictogram is the
		 * opposite half of that same ADR: no software guesses which mark means
		 * «comité de vivienda», so it is the one thing a person answers. Empty
		 * when nothing was picked, and then the Category draws the neutral.
		 *
		 * The answer is the whole tree, swapped in exactly as a rename's is, which
		 * is what puts the new Category in the Capas panel, in the Subcategoría
		 * picker and in Análisis at once. The same ponytail ceiling
		 * `addSubcategory()` names applies unchanged — a colleague's open browser
		 * learns about it on their next load.
		 *
		 * When no spare is left the server hands back the neutral, and that is the
		 * whole of the signal: `DEFAULT_SECTOR_COLOUR` on a Category means the app
		 * had no distinguishable colour to give. Nothing extra travels on the
		 * response to say so, because a flag would be a second description of the
		 * same fact that could disagree with the colour it describes.
		 *
		 * It is a notice and not an error. ADR-0013 holds that hue only groups
		 * Categories into families and the pictogram is what identifies them, so a
		 * Category without a distinct hue is doing what that ADR says every
		 * Category does anyway — and a refusal-coloured note card over a Category
		 * that was created correctly would send somebody looking for the failure.
		 */
		async addCategory({ label, glyph }) {
			const before = this.taxonomy
			const data = await this.writeTree('post', '/apps/territorio/categories', { label, glyph },
				'No se pudo agregar la categoría. Revise la conexión e inténtelo de nuevo.')
			if (data === null) {
				return
			}

			const added = data.find((category) => !before
				.some((existing) => existing.id === category.id))
			if (added?.color === DEFAULT_SECTOR_COLOUR) {
				this.taxonomyNotice = `Se agregó «${added.label}», pero ya no quedan colores `
					+ 'que se distingan entre sí: se muestra en gris y se reconoce por su '
					+ 'pictograma, como todas las categorías.'
			}
		},

		/**
		 * Remove a Category, or a Subcategory of one (#47).
		 *
		 * Addressed by slug like every other write on this panel, and with no
		 * body: ADR-0016 refuses a Category that still holds records rather than
		 * emptying or retiring them, so there is nothing for the browser to
		 * choose and no `?force` to send.
		 *
		 * The answer is the whole tree, swapped in exactly as a rename's is —
		 * which is what takes the removed row out of the Capas panel, the
		 * Subcategoría picker and Análisis at once with no reload.
		 *
		 * `forgetRemoved()` is the part a swap alone does not do. The filter is
		 * this browser's own and records what is HIDDEN, so a Subcategory that
		 * was ticked off and then removed leaves an id naming nothing: the
		 * «N ocultas» badge counts it and «Ver todo» stays offering to reveal it.
		 *
		 * The records are not refetched, and they do not need to be: a removal
		 * only gets this far when nothing is filed under the row, so no Feature
		 * in `features` can be pointing at it. The refusal is what guarantees
		 * that — which is the same reason nothing here has to worry about
		 * FeatureList drawing «Clasificación desconocida».
		 *
		 * The same ponytail ceiling `addSubcategory()` names applies unchanged: a
		 * removal does not advance the Revision cursor (ADR-0002's counter hangs
		 * on a Feature id and a Category is not a Feature), so a colleague's open
		 * browser still lists the removed row until it reloads. Nothing can be
		 * filed under it there either — the server refuses a Feature carrying a
		 * Subcategory id that is gone — so what they see is a stale panel, not a
		 * record going astray.
		 */
		async removeFromTaxonomy({ categorySlug, subcategorySlug }) {
			const data = await this.writeTree('delete', categoryUrl(categorySlug, subcategorySlug), undefined,
				'No se pudo quitar. Revise la conexión e inténtelo de nuevo.')
			if (data !== null) {
				this.filter = forgetRemoved(this.filter, data)
			}
		},

		/** One message at a time, and never one left over from something else. */
		clearTaxonomyMessage() {
			this.taxonomyError = ''
			this.taxonomyNotice = ''
		},

		/**
		 * Add a Subcategory under a Category (#44).
		 *
		 * Only a label goes up: the slug is derived by the server, because it is
		 * the identifier every record is filed under and nobody should be able to
		 * type one that collides or reads as something else (#40).
		 *
		 * The answer is the whole tree, swapped in here exactly as a rename's is
		 * — which is what puts the new Subcategory in the Capas panel and in the
		 * Subcategoría picker at once, with no reload and no second copy that can
		 * disagree.
		 *
		 * ponytail: nothing tells the browsers that are already open. Adding does
		 * not advance the Revision cursor, for the same reason a rename does not
		 * (the dated correction on ADR-0002: the counter hangs on a Feature id and
		 * a Subcategory is not a Feature) — but a rename only leaves a colleague
		 * reading a stale label, and this leaves them with no entry for the new id
		 * at all. `poll()` delivers the first record filed under it within seconds,
		 * `indexSubcategories()` has no key for it, and FeatureList then draws it
		 * as «Clasificación desconocida» in UNKNOWN_COLOUR — the pair this repo
		 * chose to mean a broken reference — until that browser reloads.
		 * The ceiling is accepted for the thin create path; the upgrade is a GET on
		 * the taxonomy and a refetch from `poll()` when an incoming Feature carries
		 * a subcategoryId `subcategories` does not hold. That is a route, a
		 * controller and their tests — a slice of its own, not a few lines here:
		 * the taxonomy has no GET today, it is only ever served in initial state.
		 *
		 * Nothing here can name `division_territorial`: the «+» is only drawn for
		 * the Categories the `treeOf` filter in taxonomy.js keeps, and that one is
		 * not among them (a territorial division is drawn on the map, not typed —
		 * CONTEXT.md). The route itself still accepts it, deliberately: refusing it
		 * server-side would contradict #44's own criterion («under any Category»)
		 * on the say-so of a lander rather than a decision, and the damage a
		 * hand-posted row does is bounded and visible — the `divisionsOf` filter
		 * would draw one more toggle beside the tree, filtering a division nothing
		 * is filed under. Worth an issue, not a silent narrowing of the API.
		 */
		async addSubcategory({ categorySlug, label }) {
			await this.writeTree('post', `${categoryUrl(categorySlug)}/subcategories`, { label },
				'No se pudo agregar la subcategoría. Revise la conexión e inténtelo de nuevo.')
		},

		async restore() {
			const id = this.draft.id
			try {
				const { data } = await axios.post(
					generateUrl(`/apps/territorio/features/${id}/restore`),
				)
				this.mergeFeature(data)
				this.closeDraft()
			} catch (exception) {
				this.error = await messageFrom(exception, 'No se pudo restaurar el registro.')
			}
		},

		startNew() {
			this.stopSeeding()
			this.selectedId = null
			this.error = ''
			this.draft = { ...blankDraft(), subcategoryId: defaultSubcategoryId(this.taxonomy) }
			this.panelOpen = true
		},

		/**
		 * A Sector someone has just drawn (ADR-0007's fallback path, which is not
		 * second-class): the shape is already agreed, so all that is left is a name
		 * and a colour.
		 */
		startSector(geometry) {
			this.stopSeeding()
			const subcategoryId = sectorSubcategoryId(this.taxonomy)
			if (subcategoryId === null) {
				// The seed that files sectores runs on install and on every upgrade,
				// so this means the install is broken, not that the user did anything
				// wrong. Saying so beats a 422 about a subcategory id of null.
				this.error = 'Falta la subcategoría "Sector". Actualice la app e inténtelo de nuevo.'
				return
			}

			this.selectedId = null
			this.error = ''
			this.draft = {
				...blankDraft(),
				subcategoryId,
				geometry,
				colour: DEFAULT_SECTOR_COLOUR,
				// Only a Sector draft carries these, and carrying them is what opens
				// the picker (issue #13). #15 is what makes them outlive the draft.
				// One shape, from one place — see sectorFields() for the two ways
				// these have drifted apart before.
				...sectorFields(),
			}
			this.boundaryQuery = ''
			this.cadastreLines = []
			this.goneIds = []
			this.panelOpen = true
			this.loadBoundaries()
		},

		/** Clicking a line on the map is the same pick as choosing its name. */
		togglePicked(id) {
			togglePicked(this.draft, this.cadastreLines, id)
		},

		/** A whole named group, as the picker's checkbox means it. */
		togglePickedGroup(ids) {
			togglePickedGroup(this.draft, this.cadastreLines, ids)
		},

		/**
		 * The Limit follows what is picked, keeping what is already written down.
		 * The rule lives in useLimitEditor.js (L1-31), beside its tests.
		 */
		recordLimit() {
			recordLimit(this.draft, this.cadastreLines)
		},

		/** A stretch that follows nothing anybody could pick. */
		addLimitNote(text) {
			addLimitNote(this.draft, text)
		},

		/**
		 * One name dropped from the Limit, and everything it stood for unpicked.
		 * The by-name-not-by-row reasoning lives in useLimitEditor.js (L1-31).
		 */
		removeLimitPart(row) {
			removeLimitPart(this.draft, this.cadastreLines, row)
		},

		/**
		 * Keep the shapes of the picked lines on the draft. The
		 * shapes-survive-the-pan rule (#49) lives in useLimitEditor.js (L1-31).
		 */
		keepPickedGeometry(ids) {
			keepPickedGeometry(this.draft, this.cadastreLines, ids)
		},

		/**
		 * The map moved, so what is on screen changed.
		 *
		 * Remembered even when nothing is being edited: opening the editor then
		 * loads what is already in view rather than waiting for the first pan.
		 */
		onViewChanged(view) {
			this.view = view
			if (!this.editingLimit) {
				return
			}

			// Settled, not while moving. A person panning across a comuna fires this
			// repeatedly, and asking the server on each one is both wasteful and the
			// window where rebuilding the layer collides with the map still redrawing
			// itself.
			clearTimeout(this.viewTimer)
			this.viewTimer = setTimeout(() => this.loadBoundaries(), 250)
		},

		/**
		 * Fetch the lines a Limit could follow, for the view we are looking at.
		 *
		 * Only ever while the editor is open. They are excluded from every other
		 * read on purpose, and loading them wholesale is what that exclusion
		 * exists to prevent.
		 */
		async loadBoundaries() {
			// Re-checked here and not only where it was armed: between the two the
			// editor may have closed.
			if (this.view === null || !this.editingLimit) {
				return
			}

			this.boundariesFailed = false
			try {
				const { data } = await axios.get(generateUrl('/apps/territorio/boundaries'), {
					// The ids the draft cites ride along on a request that already goes
					// out, because they are the only ones the answer can be about.
					params: { ...this.view, ids: this.draft.picked.join(',') },
				})
				this.cadastreLines = data.features
				// Never merged into `cadastreLines`: that one array feeds the
				// autocomplete, the clickable layer on the map and keepPickedGeometry,
				// so a gone line with a name and a shape would be pickable again.
				this.goneIds = data.gone
				// A reopened Sector knows WHICH lines its Limit runs along and not
				// what they look like — `draftFrom` has only the stored Limit to go
				// on. Every line that comes into view fills its own shape back in,
				// so panning over the limit makes «Generar» possible again without
				// re-picking anything (issue #49). Safe precisely because
				// canPolygonize demands all of them: a half-filled cache is not
				// ready, never a smaller Sector.
				if (this.editingLimit) {
					this.keepPickedGeometry(this.draft.picked)
				}
			} catch (exception) {
				// Said in the picker (L0-04): an empty picker over a failed load
				// read as a comuna with no streets, and Generar stayed disabled
				// with nothing to explain why.
				warnError('could not load the boundary features', exception)
				this.boundariesFailed = true
			}
		},

		/**
		 * Build the Sector from the picked lines and the block the person clicks.
		 *
		 * Two steps rather than one because the seed cannot be guessed. The centre
		 * of the picked lines is not inside the Sector when its limit runs along
		 * an L of two avenues, and picking a face for somebody is exactly the kind
		 * of quiet decision ADR-0007 refuses.
		 */
		startSeeding() {
			this.seeding = true
			// Deliberately NOT written to `error`. It renders red, and "click
			// inside the sector" is an instruction rather than a refusal — the
			// picker's own button already says it, in the place the person is
			// looking, and saying it twice in the wrong colour is worse than
			// saying it once in the right one.
			this.error = ''
		},

		/**
		 * The click that names the block, and the polygon that comes of it.
		 *
		 * A failure here is ordinary rather than exceptional: the lines someone
		 * picked genuinely may not close, because a real limit runs along things
		 * no dataset holds. So it says so and leaves everything as it was —
		 * hand-drawing is still there, and it is still the way ADR-0007 expects
		 * most limits to be finished.
		 */
		seedSector({ lat, lon }) {
			this.seeding = false
			// A draft can be closed or replaced between pressing the button and
			// clicking the map, and a Place draft has nothing picked at all.
			if (this.draft?.picked === undefined) {
				return
			}

			// Asked before polygonizing, because the two failures need different
			// words: lines that do not close is something the person can fix by
			// picking more, and shapes this draft never held is not.
			if (!canPolygonize(this.draft.picked, this.draft.pickedGeometry)) {
				this.error = 'Todavía no se conoce el trazado de todas las calles del límite. '
					+ 'Acerque el mapa a esas calles para cargarlas, o dibuje el sector a mano.'
				return
			}

			// From what was picked, not from what is on screen — see
			// keepPickedGeometry(). The two disagree the moment the map moves.
			// No filter: the guard above has already refused anything short of every
			// picked line, so a hole here would be one this cannot see.
			const picked = this.draft.picked.map((id) => this.draft.pickedGeometry[id])
			const face = faceAround(picked, [lon, lat])

			if (face === null) {
				this.error = 'Las calles elegidas no cierran alrededor de ese punto. '
					+ 'Elija más tramos, haga clic en otro lugar, o dibuje el sector a mano.'
				return
			}

			// Straight into the ordinary draft, so every vertex is draggable from
			// this moment on and nothing downstream knows this shape was generated.
			this.draft.geometry = face
			this.error = ''
		},

		/** A dragged vertex, straight from the map. */
		reshapeDraft(geometry) {
			if (this.draft !== null) {
				this.draft.geometry = geometry
			}
		},

		/**
		 * Anything that replaces the draft cancels a seed in progress.
		 *
		 * Left true, the next map click would be read as a seed for a draft that
		 * never asked for one — reaching for `picked` on a Place, which does not
		 * have it — and until then every click on a Sector or a boundary line
		 * would be swallowed, because `placing` is what tells the map those
		 * clicks are the draft's.
		 */
		stopSeeding() {
			this.seeding = false
		},

		/**
		 * Clicking the map fills in the open draft's coordinates — unless the draft
		 * is a drawn shape, which has no single coordinate to fill in and is edited
		 * by dragging its vertices instead.
		 */
		placeDraft({ lat, lon }) {
			// The seed comes first: while the editor is waiting for one, that click
			// is what it is for and not a coordinate for the record.
			if (this.seeding) {
				this.seedSector({ lat, lon })
				return
			}

			if (this.draft === null || this.draft.geometry !== null) {
				return
			}

			this.draft.lat = lat.toFixed(7)
			this.draft.lon = lon.toFixed(7)
		},

		closeDraft() {
			// A load armed while editing must not land after the editor closed and
			// fetch three thousand rows for nobody.
			clearTimeout(this.viewTimer)
			this.draft = null
			this.selectedId = null
			this.history = []
			// Unloaded on leaving the editor, which is the other half of loading
			// them only inside it: eleven thousand lines are not the map.
			this.cadastreLines = []
			this.goneIds = []
			this.boundaryQuery = ''
			// Or the next draft opens already waiting for a click nobody asked for.
			this.stopSeeding()
		},

		async save() {
			this.saving = true
			this.error = ''

			try {
				const isNew = this.draft.id === null
				const { data } = isNew
					? await axios.post(generateUrl('/apps/territorio/features'),
						payload(this.draft, this.taxonomy))
					: await axios.put(
						generateUrl(`/apps/territorio/features/${this.draft.id}`),
						payload(this.draft, this.taxonomy),
					)

				// Read before the merge, never after: mergeFeature() can close the
				// draft, and this line used to run on the null it left behind — so a
				// save the server had already accepted ended in the catch, telling
				// the user to check their connection.
				const picked = this.draft.picked
				const pickedGeometry = this.draft.pickedGeometry
				this.mergeFeature(data)
				// The cursor is deliberately NOT advanced here. Jumping it to our
				// own Revision skips every change that landed between our last poll
				// and this save — a colleague's edit at Revision 11 is lost for good
				// once our save banks 12. Letting the next poll re-deliver our own
				// record costs one merge, and mergeFeature is idempotent.
				this.selectedId = data.id
				// Carried across the save. draftFrom() rebuilds the draft from what
				// the server returned, and the server does not know about picks yet —
				// so without this every line someone chose vanished when they pressed
				// Guardar, silently.
				//
				// ponytail: they live in the draft and die with it, so closing the
				// sidebar still loses them. #15 is what gives a Limit somewhere to be.
				this.draft = draftFrom(data, this.taxonomy)
				if (picked !== undefined) {
					this.draft.picked = picked
					// The shapes too, not only the ids. Carrying half of it left the
					// draft able to say WHICH lines were picked and unable to say what
					// they look like, so Generar failed on a Sector whose lines had
					// just been chosen — and blamed the lines (issue #49).
					this.draft.pickedGeometry = pickedGeometry
				}
				// This save wrote a Revision, and the sidebar is still showing the
				// ones from before it. That was merely incomplete while history was
				// a list nobody reads twice; it is wrong now that a Sector states
				// how its line came to be from those same entries (issue #50) — a
				// polygon generated a second ago would go on calling itself
				// hand-drawn until somebody closed the record and opened it again.
				this.loadHistory(data.id)
			} catch (exception) {
				// 422 carries a message naming what to fix; anything else does not.
				this.error = await messageFrom(exception,
					'No se pudo guardar. Revise la conexión e inténtelo de nuevo.')
				safeError('could not save the feature', exception)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.territorio-restore {
	margin-top: 8px;
}

.territorio-restore-card {
	margin: 12px;
}

.territorio-app-viewport {
	position: relative;
	width: 100%;
	height: 100%;
	min-height: 0;
	overflow: hidden;
}

.territorio-map-container {
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	z-index: 1;
}

.territorio-topbar {
	position: absolute;
	top: 12px;
	left: 12px;
	right: 12px;
	// Above the full-screen views (1100), so the search box's results are not
	// hidden behind the Índice it is typed over.
	z-index: 1200;
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: 12px;
	// The strip itself lets clicks through to the map between its controls; the
	// controls take them back. Without this the search box and its tabs inherited
	// `none` and a click on them landed on the panel underneath.
	pointer-events: none;

	> * {
		pointer-events: auto;
	}
}

.territorio-topbar__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	pointer-events: auto;
}

/* 408px Left Docked Panel */
.territorio-docked-panel {
	position: absolute;
	top: 0;
	bottom: 0;
	left: 0;
	width: 408px;
	max-width: calc(100vw - 48px);
	background: var(--color-main-background, #ffffff);
	box-shadow: 0 1px 3px rgba(60, 64, 67, 0.3), 0 4px 8px 3px rgba(60, 64, 67, 0.15);
	z-index: 900;
	display: flex;
	flex-direction: column;
	transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
	transform: translateX(0);

	&--collapsed {
		transform: translateX(-100%);
	}
}

.territorio-docked-panel__toggle {
	position: absolute;
	top: 50%;
	right: -24px;
	transform: translateY(-50%);
	width: 24px;
	height: 48px;
	background: var(--color-main-background, #ffffff);
	border: 1px solid var(--color-border, #dadce0);
	border-left: none;
	border-radius: 0 8px 8px 0;
	box-shadow: 2px 1px 4px rgba(60, 64, 67, 0.15);
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 0;
	color: var(--color-text-maxcontrast, #70757a);
	z-index: 901;
	transition: background-color 0.15s ease, color 0.15s ease;

	&:hover {
		background: var(--color-background-hover, #f8f9fa);
		color: var(--color-main-text, #202124);
	}
}

.territorio-docked-panel__chevron {
	font-size: 11px;
	line-height: 1;
}

.territorio-docked-panel__inner {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
	overflow: hidden;
}

// Clears the search box and its view tabs, which float over the panel's top
// (12px offset + 48px bar + 48px tabs + 12px of air).
.territorio-docked-panel__spacer {
	height: 124px;
	flex: 0 0 124px;
}

.territorio-docked-panel__sheet,
.territorio-docked-panel__list {
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
}

// FeatureList's root carries Nextcloud's own `.app-content-list`, which the core
// stylesheet pins to 300px: the list was built for a column of that width, and
// here it has the panel's 408.
.territorio-docked-panel__list :deep(.app-content-list) {
	width: 100%;
	max-width: none;
	flex: 1 1 auto;
	border-inline-end: none;
}

.territorio-main__error {
	margin: 12px 12px 0;
	flex: 0 0 auto;
}

/* Fullscreen Views (Índice, Análisis, Duplicados) */
// Starts below the topbar, not under it: the search box and its tabs are the way
// back from here, and a view that covered them (z-index 1100 over the topbar's
// 1000) left nothing to click but the view's own «Volver».
.territorio-fullscreen-view {
	position: absolute;
	top: 124px;
	left: 0;
	width: 100%;
	height: calc(100% - 124px);
	z-index: 1100;
	background: var(--color-main-background, #ffffff);
	overflow-y: auto;
}
</style>
