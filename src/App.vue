<template>
	<NcContent app-name="territorio">
		<NcAppContent>
			<template #list>
				<!--
					Three columns, not four: list · map · sidebar. A dedicated Capas
					column costs a fixed 300px and leaves the map — the point of the
					app — as the narrowest pane on a laptop.
				-->
				<div class="territorio-column">
					<CapasPanel :taxonomy="taxonomy"
						:filter="filter"
						@toggle-category="onToggleCategory"
						@toggle-subcategory="onToggleSubcategory"
						@reset="resetFilter" />

					<FeatureList :features="listed"
						:taxonomy="taxonomy"
						:selected-id="selectedId"
						:show-retired="showRetired"
						:query="filter.query"
						@select="select"
						@create="startNew"
						@import="importing = true"
						@export="exporting = true"
						@update:show-retired="toggleRetired"
						@update:query="search" />
				</div>
			</template>

			<div class="territorio-main">
				<!--
					A refusal that happens before there is a draft has nowhere else to
					go: the sidebar that normally shows `error` only exists while one is
					open, so a failure in startSector() was written to a component that
					was not mounted and the drawn polygon vanished with no explanation.
				-->
				<NcNoteCard v-if="error !== '' && draft === null"
					type="error"
					class="territorio-main__error">
					{{ error }}
				</NcNoteCard>

				<div class="territorio-main__switch">
					<NcButton :pressed="pane === 'mapa'" @click="pane = 'mapa'">
						Mapa
					</NcButton>
					<NcButton :pressed="pane === 'analisis'" @click="pane = 'analisis'">
						Análisis
					</NcButton>
					<!--
						The count sits on the button because that is the only place it
						gets read. A queue nobody can see the size of is a queue nobody
						opens (issue #16), and it is hidden when empty so that it reads
						as news rather than furniture.
					-->
					<NcButton :pressed="pane === 'duplicados'" @click="pane = 'duplicados'">
						Posibles duplicados<span v-if="duplicates.length > 0"> ({{ duplicates.length }})</span>
					</NcButton>
				</div>

				<!--
					v-show, not v-if: tearing the Leaflet map down and rebuilding it on
					every switch would throw away the viewport the user had panned to.
					The ResizeObserver inside it re-measures when it comes back.

					The map only ever shows what is live. Retired records are browsable
					in the list so they can be restored, never drawn.
				-->
				<TerritorioMap v-show="pane === 'mapa'"
					:features="liveFiltered"
					:taxonomy="taxonomy"
					:selected-id="selectedId"
					:draft-geometry="draftGeometry"
					:draft-colour="draft?.colour ?? null"
					:placing="placing"
					:boundaries="editingLimit ? boundaryFeatures : []"
					:picked-ids="draft?.picked ?? []"
					@pick="togglePicked"
					@view-changed="onViewChanged"
					@select="select"
					@map-click="placeDraft"
					@draw="startSector"
					@reshape="reshapeDraft" />

				<AnalisisView v-if="pane === 'analisis'"
					:counts="counts"
					:filtered="isFiltered(filter)" />

				<!--
					Unfiltered on purpose, unlike everything beside it. The Capas panel
					and the search are about what someone is looking at; the queue is
					about what is waiting to be decided, and hiding half a pair behind a
					filter would leave a card with one side and no question.
				-->
				<DuplicatesView v-if="pane === 'duplicados'"
					:pairs="duplicates"
					:features="features"
					:taxonomy="taxonomy"
					:busy="resolving"
					@merge="mergePair"
					@dismiss="dismissPair"
					@show="select" />
			</div>
		</NcAppContent>

		<ImportDialog v-if="importing" @close="importing = false" @imported="afterImport" />

		<!--
			Given the ids on screen, not a filter. The View is personal (CONTEXT.md)
			and lives in this browser, so what a person is looking at is something
			only this component knows — asking the server to reconstruct it would be
			a second implementation of "the filtered set", able to disagree with the
			one the person can see.
		-->
		<ExportDialog v-if="exporting"
			:ids="exportableIds"
			:scope="exportScope"
			@close="exporting = false" />

		<NcAppSidebar v-if="draft !== null"
			:name="draft.id === null ? 'Nuevo registro' : draft.name || 'Sin nombre'"
			@close="closeDraft">
			<NcNoteCard v-if="selectedIsRetired" type="warning">
				Este registro está retirado: no aparece en el mapa ni en la lista de activos.
				<NcButton class="territorio-restore" variant="primary" @click="restore">
					Restaurar
				</NcButton>
			</NcNoteCard>

			<BoundaryPicker v-if="editingLimit"
					:suggestions="boundarySuggestions"
					:picked="pickedBoundaries"
					:picked-ids="draft.picked"
					:query="boundaryQuery"
					:seeding="seeding"
					@generate="startSeeding"
					@toggle-group="togglePickedGroup"
					@update:query="boundaryQuery = $event" />

				<FeatureDetail :draft="draft"
				:taxonomy="taxonomy"
				:boundaries="boundaries"
				:saving="saving"
				:error="error"
				:history="history"
				:retired="selectedIsRetired"
				@save="save"
				@retire="retire" />
		</NcAppSidebar>
	</NcContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AnalisisView from './components/AnalisisView.vue'
import BoundaryPicker from './components/BoundaryPicker.vue'
import CapasPanel from './components/CapasPanel.vue'
import DuplicatesView from './components/DuplicatesView.vue'
import FeatureDetail from './components/FeatureDetail.vue'
import FeatureList from './components/FeatureList.vue'
import ExportDialog from './components/ExportDialog.vue'
import ImportDialog from './components/ImportDialog.vue'
import TerritorioMap from './components/TerritorioMap.vue'
import { pickedGroups, suggestions, toggleGroup, togglePick } from './boundaries.js'
import { faceAround } from './sectors.js'
import {
	countsFor,
	emptyFilter,
	filterFeatures,
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

/** Chilean keyboards produce a decimal comma; Leaflet and GeoJSON want a dot. */
const toNumber = (value) => {
	const text = String(value ?? '').trim().replace(',', '.')
	if (text === '') {
		return null
	}
	const parsed = Number(text)
	return Number.isFinite(parsed) ? parsed : null
}

export default {
	name: 'App',

	components: {
		AnalisisView,
		BoundaryPicker,
		CapasPanel,
		DuplicatesView,
		FeatureDetail,
		ExportDialog,
		FeatureList,
		ImportDialog,
		NcAppContent,
		NcAppSidebar,
		NcButton,
		NcContent,
		NcNoteCard,
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
			pollTimer: null,
			pollFailed: false,
			// Loaded only while a Sector's Limit is being built, and thrown away
			// when it is not: a comuna is around eleven thousand lines (issue #12).
			boundaryFeatures: [],
			boundaryQuery: '',
			view: null,
			viewTimer: null,
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
		 * What an export would carry: the list as shown, minus retired records.
		 *
		 * `listed` is the honest answer to "what is on screen", and it includes
		 * retired ones when somebody has ticked to see them. An export is not
		 * asking for what used to be there, and the server leaves them out anyway
		 * (ADR-0005) — sending them would make the dialog promise a count it
		 * cannot deliver.
		 */
		exportableIds() {
			return this.listed
				.filter((feature) => !feature.retired)
				.map((feature) => feature.id)
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

		selectedIsRetired() {
			return this.showRetired
				&& this.retiredFeatures.some((feature) => feature.id === this.selectedId)
		},

		/**
		 * The Sectores and Unidades Vecinales the app holds, so the sidebar can turn
		 * a stored assignment id into the name somebody would recognise.
		 */
		boundaries() {
			const divisions = new Set(divisionsOf(this.taxonomy).map((division) => division.id))

			return this.features.filter((feature) => divisions.has(feature.subcategoryId))
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

		boundarySuggestions() {
			return suggestions(this.boundaryFeatures, this.boundaryQuery)
		},

		pickedBoundaries() {
			return pickedGroups(this.boundaryFeatures, this.draft?.picked ?? [])
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

	mounted() {
		this.loadDuplicates()
		this.startPolling()
		document.addEventListener('visibilitychange', this.onVisibilityChange)
	},

	beforeUnmount() {
		clearTimeout(this.viewTimer)
		this.stopPolling()
		document.removeEventListener('visibilitychange', this.onVisibilityChange)
	},

	methods: {
		isFiltered,

		resetFilter() {
			this.filter = emptyFilter()
		},

		onToggleCategory(category) {
			this.filter = toggleCategory(this.filter, category)
		},

		onToggleSubcategory(subcategoryId) {
			this.filter = toggleSubcategory(this.filter, subcategoryId)
		},

		/**
		 * Typing narrows the same set the toggles narrow. A new object rather than
		 * a mutation, like every other filter change, so nothing downstream has to
		 * wonder who else might be holding it.
		 */
		search(query) {
			this.filter = { ...this.filter, query }
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
					console.warn('territorio: change polling failed, will keep trying', exception)
				}
			}
		},

		/**
		 * Apply one record from the change feed to whichever list it belongs in.
		 *
		 * A retirement reaches us as an ordinary change carrying retired:true;
		 * moving the record between the two lists here is what takes it off
		 * everyone else's map. Both lists are kept, not just the live one —
		 * otherwise a colleague's restore leaves a stale row in the retired list
		 * still offering to restore what is already back.
		 */
		mergeFeature(incoming) {
			const from = incoming.retired ? this.features : this.retiredFeatures
			const into = incoming.retired ? this.retiredFeatures : this.features

			const stale = from.findIndex((feature) => feature.id === incoming.id)
			if (stale !== -1) {
				from.splice(stale, 1)
			}

			const existing = into.findIndex((feature) => feature.id === incoming.id)
			if (existing === -1) {
				into.unshift(incoming)
			} else {
				into.splice(existing, 1, incoming)
			}

			// The sidebar may now be showing a record that just changed sides.
			if (this.selectedId === incoming.id && incoming.retired !== this.showRetired) {
				this.closeDraft()
			}
		},

		/**
		 * A Sector, and not merely any territorial division.
		 *
		 * A Unidad Vecinal is read from the national cadastre and never drawn
		 * (CONTEXT.md), so it has no Limit to say anything about — opening the
		 * picker over one would offer to edit a boundary this app does not own.
		 */
		isSector(subcategoryId) {
			return subcategoryId === sectorSubcategoryId(this.taxonomy)
		},

		select(id) {
			this.stopSeeding()
			this.selectedId = id
			const feature = this.listed.find((candidate) => candidate.id === id)
			this.draft = feature === undefined ? null : this.draftFrom(feature)
			this.error = ''
			this.history = []
			this.boundaryQuery = ''
			this.boundaryFeatures = []
			if (this.editingLimit) {
				this.loadBoundaries()
			}
			if (feature !== undefined) {
				this.loadHistory(id)
			}
		},

		async loadHistory(id) {
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
				console.warn('territorio: could not load the history', exception)
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
			try {
				const { data } = await axios.get(generateUrl('/apps/territorio/duplicates'))
				this.duplicates = data.pairs
			} catch (exception) {
				// The queue failing to load must not take the Registry down with it.
				console.warn('territorio: could not load the duplicate queue', exception)
			}
		},

		/** An import is the one moment new pairs appear. */
		async afterImport() {
			await this.poll()
			await this.loadDuplicates()
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
				await this.poll()
				await this.loadDuplicates()
				this.error = ''
			} catch (exception) {
				this.error = exception.response?.data?.message
					?? 'No se pudo resolver el par. Inténtelo de nuevo.'
			} finally {
				this.resolving = null
			}
		},

		async loadRetired() {
			try {
				const { data } = await axios.get(generateUrl('/apps/territorio/features/retired'))
				this.retiredFeatures = data
			} catch (exception) {
				console.warn('territorio: could not load retired records', exception)
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
				this.error = exception.response?.data?.message ?? 'No se pudo retirar el registro.'
			}
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
				this.error = exception.response?.data?.message ?? 'No se pudo restaurar el registro.'
			}
		},

		startNew() {
			this.stopSeeding()
			this.selectedId = null
			this.error = ''
			this.draft = { ...this.blankDraft(), subcategoryId: defaultSubcategoryId(this.taxonomy) }
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
				...this.blankDraft(),
				subcategoryId,
				geometry,
				colour: DEFAULT_SECTOR_COLOUR,
				// Only a Sector draft carries these, and carrying them is what opens
				// the picker (issue #13). #15 is what makes them outlive the draft.
				picked: [],
			}
			this.boundaryQuery = ''
			this.boundaryFeatures = []
			this.loadBoundaries()
		},

		/** Clicking a line on the map is the same pick as choosing its name. */
		togglePicked(id) {
			if (this.draft?.picked !== undefined) {
				this.draft.picked = togglePick(this.draft.picked, id)
				this.keepPickedGeometry([id])
			}
		},

		togglePickedGroup(ids) {
			if (this.draft?.picked !== undefined) {
				this.draft.picked = toggleGroup(this.draft.picked, ids)
				this.keepPickedGeometry(ids)
			}
		},

		/**
		 * Keep the shape of a line the moment it is picked, on the draft.
		 *
		 * `boundaryFeatures` is what is ON SCREEN: it is replaced wholesale every
		 * time the map settles, and capped at three thousand rows against a
		 * comuna's eleven thousand. Building a Sector from four avenues means
		 * panning along them, and zooming out to click inside the block refetches
		 * — so a line picked five minutes ago is very often not in that list any
		 * more.
		 *
		 * Re-deriving the geometries from it at generate time therefore dropped
		 * picks silently, and the two ways that shows are both bad: "las calles no
		 * cierran" about lines that do, or a LARGER face accepted as the Sector
		 * because the line that would have closed the small one was missing. The
		 * second is silent and wrong, which is the kind this app has been bitten
		 * by before.
		 */
		keepPickedGeometry(ids) {
			const kept = { ...(this.draft.pickedGeometry ?? {}) }
			const onScreen = new Map(this.boundaryFeatures.map((each) => [each.id, each]))

			ids.forEach((id) => {
				if (!this.draft.picked.includes(id)) {
					// Unpicked: forget it, or a line removed on purpose would keep
					// shaping the polygon.
					delete kept[id]
					return
				}
				const geometry = onScreen.get(id)?.geometry
				if (geometry !== undefined) {
					kept[id] = geometry
				}
			})

			this.draft.pickedGeometry = kept
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

			try {
				const { data } = await axios.get(generateUrl('/apps/territorio/boundaries'), {
					params: this.view,
				})
				this.boundaryFeatures = data
			} catch (exception) {
				console.warn('territorio: could not load the boundary features', exception)
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

			// From what was picked, not from what is on screen — see
			// keepPickedGeometry(). The two disagree the moment the map moves.
			const picked = this.draft.picked
				.map((id) => this.draft.pickedGeometry?.[id])
				.filter((geometry) => geometry !== undefined)
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

		blankDraft() {
			return {
				id: null,
				name: '',
				subcategoryId: null,
				// Set only for a drawn shape. A Place carries lat/lon instead, which
				// is what the form offers when this is null.
				geometry: null,
				colour: null,
				sectorId: null,
				unidadVecinalId: null,
				lat: '',
				lon: '',
				locationQuality: 'exacta',
				address: '',
				phone: '',
				email: '',
				contactPerson: '',
				contactRole: '',
				openingHours: '',
				notes: '',
				sourceRef: '',
			}
		},

		draftFrom(feature) {
			const isPoint = feature.geometry?.type === 'Point'
			const [lon, lat] = isPoint ? feature.geometry.coordinates : [null, null]

			return {
				id: feature.id,
				name: feature.name,
				subcategoryId: feature.subcategoryId,
				geometry: isPoint ? null : (feature.geometry ?? null),
				colour: feature.colour ?? null,
				// Read-only, shown rather than edited (ADR-0008). Carried on the draft
				// so the sidebar can name them without asking the server again.
				sectorId: feature.sectorId ?? null,
				unidadVecinalId: feature.unidadVecinalId ?? null,
				// A Sector opened for editing gets the picker too, not only one being
				// drawn. Until #15 stores a Limit there is nothing to load into it,
				// so it starts empty either way.
				...(this.isSector(feature.subcategoryId) ? { picked: [] } : {}),
				lat: lat === null ? '' : String(lat),
				lon: lon === null ? '' : String(lon),
				locationQuality: feature.locationQuality === 'sin_coordenada'
					? 'exacta'
					: (feature.locationQuality ?? 'exacta'),
				address: feature.address ?? '',
				phone: feature.phone ?? '',
				email: feature.email ?? '',
				contactPerson: feature.contactPerson ?? '',
				contactRole: feature.contactRole ?? '',
				openingHours: feature.openingHours ?? '',
				notes: feature.notes ?? '',
				sourceRef: feature.sourceRef ?? '',
			}
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
			this.boundaryFeatures = []
			this.boundaryQuery = ''
			// Or the next draft opens already waiting for a click nobody asked for.
			this.stopSeeding()
		},

		payload() {
			const lat = toNumber(this.draft.lat)
			const lon = toNumber(this.draft.lon)

			return {
				...this.draft,
				colour: this.draft.colour ?? '',
				// A drawn shape wins over the coordinate fields, which the form does
				// not even show for one. GeoJSON orders positions [longitude,
				// latitude] — the reverse of how they are said out loud, and of the
				// form's field order.
				geometry: this.draft.geometry ?? (lat === null || lon === null
					? null
					: { type: 'Point', coordinates: [lon, lat] }),
			}
		},

		async save() {
			this.saving = true
			this.error = ''

			try {
				const isNew = this.draft.id === null
				const { data } = isNew
					? await axios.post(generateUrl('/apps/territorio/features'), this.payload())
					: await axios.put(
						generateUrl(`/apps/territorio/features/${this.draft.id}`),
						this.payload(),
					)

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
				const picked = this.draft.picked
				this.draft = this.draftFrom(data)
				if (picked !== undefined) {
					this.draft.picked = picked
				}
			} catch (exception) {
				// 422 carries a message naming what to fix; anything else does not.
				this.error = exception.response?.data?.message
					?? 'No se pudo guardar. Revise la conexión e inténtelo de nuevo.'
				console.error('territorio: could not save the feature', exception)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.territorio-restore {
	margin-top: 8px;
}

.territorio-column {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
}

/*
 * NcAppContentList expects to own its column's height. Inside this flex column
 * it shares with Capas, so it has to be told to take what is left and scroll —
 * min-height: 0 is what actually allows a flex child to shrink below its content.
 */
.territorio-column :deep(.app-content-list) {
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
}

.territorio-main {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
}

.territorio-main__error {
	margin: 12px 12px 0;
	flex: 0 0 auto;
}

.territorio-main__switch {
	display: flex;
	gap: 8px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	flex: 0 0 auto;
}
</style>
