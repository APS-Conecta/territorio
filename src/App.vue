<template>
	<NcContent app-name="territorio">
		<NcAppContent>
			<ErrorBoundary>
				<div class="territorio-app-viewport">
					<!-- Top floating Omnibox bar and action controls (Google Maps command strip) -->
					<div class="territorio-topbar">
						<OmniboxControl
							:query="filter.query"
							:features="liveFiltered"
							:streets="streets"
							:active-pane="pane"
							:duplicates-count="duplicates.length"
							:panel-open="panelOpen"
							@update:query="search"
							@select-feature="selectAndPan"
							@select-street="goToStreet"
							@switch-view="pane = $event"
							@toggle-panel="panelOpen = !panelOpen" />

						<div v-show="pane === 'mapa'" class="territorio-topbar__actions">
							<LayersDropdownButton
								:taxonomy="taxonomy"
								:filter="filter"
								@toggle-category="onToggleCategory"
								@toggle-subcategory="onToggleSubcategory"
								@reset="resetFilter"
								@open-config="showTaxonomyDrawer = true" />

							<DrawSectorButton
								:active="isDrawingSector"
								@toggle="toggleDrawSector"
								@cancel="cancelDrawSector" />
						</div>
					</div>

					<!-- 100% Canvas Map -->
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
							@select="selectAndPan"
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
							</div>

							<!-- When no draft is open: FeatureList with Assets Directory -->
							<div v-else class="territorio-docked-panel__list">
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
						:open="showTaxonomyDrawer"
						:taxonomy="taxonomy"
						:filter="filter"
						:error="taxonomyError"
						:notice="taxonomyNotice"
						@close="showTaxonomyDrawer = false"
						@toggle-category="onToggleCategory"
						@toggle-subcategory="onToggleSubcategory"
						@rename="rename"
						@choose-glyph="chooseGlyph"
						@add-category="addCategory"
						@add-subcategory="addSubcategory"
						@remove="removeFromTaxonomy"
						@clear-taxonomy-message="clearTaxonomyMessage"
						@reset="resetFilter" />

					<!-- Full-screen Views for Other Panes -->
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

					<div v-if="pane === 'duplicados'" class="territorio-fullscreen-view">
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
import { prefetchStreets } from './streets.js'
import {
	countsFor,
	emptyFilter,
	featureById,
	filterFeatures,
	forgetRemoved,
	isFiltered,
	listFor,
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
			// Which pane fills the main area.
			pane: 'mapa',
			panelOpen: true,
			showTaxonomyDrawer: false,
			isDrawingSector: false,
			streets: [],
			// The Índice's own View
			indice: { hidden: new Set(), key: 'name', ascending: true },
			// Pairs of records that might be one place
			duplicates: [],
			// The pair being resolved right now
			resolving: null,
			exporting: false,
			seeding: false,
			importing: false,
			history: [],
			selectedId: null,
			draft: null,
			saving: false,
			error: '',
			taxonomyError: '',
			taxonomyNotice: '',
			pollTimer: null,
			pollFailed: false,
			historyFailed: false,
			duplicatesFailed: false,
			retiredFailed: false,
			boundariesFailed: false,
			cadastreLines: [],
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
		 * from this rather than each filtering for themselves.
		 */
		liveFiltered() {
			return filterFeatures(this.features, this.filter, this.subcategories)
		},

		/**
		 * The Subcategory index the filtering needs.
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

		exportableIds() {
			return this.liveFiltered.map((feature) => feature.id)
		},

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

		counts() {
			return countsFor(this.liveFiltered, this.taxonomy)
		},

		selectedIsRetired() {
			return this.retiredFeatures.some((feature) => feature.id === this.selectedId)
		},

		divisions() {
			const ids = new Set(divisionsOf(this.taxonomy).map((division) => division.id))

			return this.features.filter((feature) => ids.has(feature.subcategoryId))
		},

		editingLimit() {
			return this.draft !== null && this.draft.picked !== undefined
		},

		canGenerate() {
			return this.draft?.picked !== undefined
				&& canPolygonize(this.draft.picked, this.draft.pickedGeometry)
		},

		boundarySuggestions() {
			return suggestions(this.cadastreLines, this.boundaryQuery)
		},

		draftGeometry() {
			return this.draft?.geometry ?? null
		},

		placing() {
			return (this.draft !== null && this.draft.geometry === null) || this.seeding
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

		onVisibilityChange() {
			if (document.hidden) {
				this.stopPolling()
				return
			}
			this.poll()
			this.startPolling()
		},

		async poll() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/territorio/changes'),
					{ params: { since: this.revision } },
				)
				this.revision = data.revision
				data.features.forEach((incoming) => this.mergeFeature(incoming))
				this.pollFailed = false
			} catch (exception) {
				if (!this.pollFailed) {
					this.pollFailed = true
					warnError('change polling failed, will keep trying', exception)
				}
			}
		},

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

		selectAndPan(id) {
			this.select(id)
			this.panelOpen = true
			const feature = featureById(this.features, this.retiredFeatures, id)
			if (feature && feature.lat && feature.lon && this.$refs.mapComponent) {
				this.$refs.mapComponent.panToLocation(feature.lat, feature.lon, 17)
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

		async loadDuplicates() {
			this.duplicatesFailed = false
			try {
				const { data } = await axios.get(generateUrl('/apps/territorio/duplicates'))
				this.duplicates = data.pairs
			} catch (exception) {
				warnError('could not load the duplicate queue', exception)
				this.duplicatesFailed = true
			}
		},

		async afterImport() {
			await Promise.all([this.poll(), this.loadDuplicates()])
		},

		async mergePair({ pairId, survivorId }) {
			await this.resolve(pairId, `/apps/territorio/duplicates/${pairId}/merge`, { survivorId })
		},

		async dismissPair(pairId) {
			await this.resolve(pairId, `/apps/territorio/duplicates/${pairId}/dismiss`, {})
		},

		async resolve(pairId, url, body) {
			this.resolving = pairId
			try {
				await axios.post(generateUrl(url), body)
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

		async rename({ categorySlug, subcategorySlug, label }) {
			await this.writeTree('put', categoryUrl(categorySlug, subcategorySlug), { label },
				'No se pudo renombrar. Revise la conexión e inténtelo de nuevo.')
		},

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

		async chooseGlyph({ categorySlug, glyph }) {
			await this.writeTree('put', categoryUrl(categorySlug), { glyph },
				'No se pudo cambiar el pictograma. Revise la conexión e inténtelo de nuevo.')
		},

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

		async removeFromTaxonomy({ categorySlug, subcategorySlug }) {
			const data = await this.writeTree('delete', categoryUrl(categorySlug, subcategorySlug), undefined,
				'No se pudo quitar. Revise la conexión e inténtelo de nuevo.')
			if (data !== null) {
				this.filter = forgetRemoved(this.filter, data)
			}
		},

		clearTaxonomyMessage() {
			this.taxonomyError = ''
			this.taxonomyNotice = ''
		},

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

		startSector(geometry) {
			this.stopSeeding()
			const subcategoryId = sectorSubcategoryId(this.taxonomy)
			if (subcategoryId === null) {
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
				...sectorFields(),
			}
			this.boundaryQuery = ''
			this.cadastreLines = []
			this.goneIds = []
			this.panelOpen = true
			this.loadBoundaries()
		},

		togglePicked(id) {
			togglePicked(this.draft, this.cadastreLines, id)
		},

		togglePickedGroup(ids) {
			togglePickedGroup(this.draft, this.cadastreLines, ids)
		},

		recordLimit() {
			recordLimit(this.draft, this.cadastreLines)
		},

		addLimitNote(text) {
			addLimitNote(this.draft, text)
		},

		removeLimitPart(row) {
			removeLimitPart(this.draft, this.cadastreLines, row)
		},

		keepPickedGeometry(ids) {
			keepPickedGeometry(this.draft, this.cadastreLines, ids)
		},

		onViewChanged(view) {
			this.view = view
			if (!this.editingLimit) {
				return
			}

			clearTimeout(this.viewTimer)
			this.viewTimer = setTimeout(() => this.loadBoundaries(), 250)
		},

		async loadBoundaries() {
			if (this.view === null || !this.editingLimit) {
				return
			}

			this.boundariesFailed = false
			try {
				const { data } = await axios.get(generateUrl('/apps/territorio/boundaries'), {
					params: { ...this.view, ids: this.draft.picked.join(',') },
				})
				this.cadastreLines = data.features
				this.goneIds = data.gone
				if (this.editingLimit) {
					this.keepPickedGeometry(this.draft.picked)
				}
			} catch (exception) {
				warnError('could not load the boundary features', exception)
				this.boundariesFailed = true
			}
		},

		startSeeding() {
			this.seeding = true
			this.error = ''
		},

		seedSector({ lat, lon }) {
			this.seeding = false
			if (this.draft?.picked === undefined) {
				return
			}

			if (!canPolygonize(this.draft.picked, this.draft.pickedGeometry)) {
				this.error = 'Todavía no se conoce el trazado de todas las calles del límite. '
					+ 'Acerque el mapa a esas calles para cargarlas, o dibuje el sector a mano.'
				return
			}

			const picked = this.draft.picked.map((id) => this.draft.pickedGeometry[id])
			const face = faceAround(picked, [lon, lat])

			if (face === null) {
				this.error = 'Las calles elegidas no cierran alrededor de ese punto. '
					+ 'Elija más tramos, haga clic en otro lugar, o dibuje el sector a mano.'
				return
			}

			this.draft.geometry = face
			this.error = ''
		},

		reshapeDraft(geometry) {
			if (this.draft !== null) {
				this.draft.geometry = geometry
			}
		},

		stopSeeding() {
			this.seeding = false
		},

		placeDraft({ lat, lon }) {
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
			clearTimeout(this.viewTimer)
			this.draft = null
			this.selectedId = null
			this.history = []
			this.cadastreLines = []
			this.goneIds = []
			this.boundaryQuery = ''
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

				const picked = this.draft.picked
				const pickedGeometry = this.draft.pickedGeometry
				this.mergeFeature(data)
				this.selectedId = data.id
				this.draft = draftFrom(data, this.taxonomy)
				if (picked !== undefined) {
					this.draft.picked = picked
					this.draft.pickedGeometry = pickedGeometry
				}
				this.loadHistory(data.id)
			} catch (exception) {
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
	z-index: 1000;
	display: flex;
	align-items: center;
	gap: 12px;
	pointer-events: none;
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

.territorio-docked-panel__spacer {
	height: 64px;
	flex: 0 0 64px;
}

.territorio-docked-panel__sheet,
.territorio-docked-panel__list {
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
}

.territorio-main__error {
	margin: 12px 12px 0;
	flex: 0 0 auto;
}

/* Fullscreen Views (Índice, Análisis, Duplicados) */
.territorio-fullscreen-view {
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	z-index: 1100;
	background: var(--color-main-background, #ffffff);
	overflow-y: auto;
}
</style>
