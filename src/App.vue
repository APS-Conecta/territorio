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
						@select="select"
						@create="startNew"
						@update:show-retired="toggleRetired" />
				</div>
			</template>

			<div class="territorio-main">
				<div class="territorio-main__switch">
					<NcButton :pressed="pane === 'mapa'" @click="pane = 'mapa'">
						Mapa
					</NcButton>
					<NcButton :pressed="pane === 'analisis'" @click="pane = 'analisis'">
						Análisis
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
					@select="select"
					@map-click="placeDraft" />

				<AnalisisView v-if="pane === 'analisis'"
					:counts="counts"
					:filtered="isFiltered(filter)" />
			</div>
		</NcAppContent>

		<NcAppSidebar v-if="draft !== null"
			:name="draft.id === null ? 'Nuevo registro' : draft.name || 'Sin nombre'"
			@close="closeDraft">
			<NcNoteCard v-if="selectedIsRetired" type="warning">
				Este registro está retirado: no aparece en el mapa ni en la lista de activos.
				<NcButton class="territorio-restore" variant="primary" @click="restore">
					Restaurar
				</NcButton>
			</NcNoteCard>

			<FeatureDetail :draft="draft"
				:taxonomy="taxonomy"
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
import CapasPanel from './components/CapasPanel.vue'
import FeatureDetail from './components/FeatureDetail.vue'
import FeatureList from './components/FeatureList.vue'
import TerritorioMap from './components/TerritorioMap.vue'
import {
	countsFor,
	emptyFilter,
	filterFeatures,
	isFiltered,
	toggleCategory,
	toggleSubcategory,
} from './filters.js'
import { defaultSubcategoryId } from './taxonomy.js'

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
		CapasPanel,
		FeatureDetail,
		FeatureList,
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
			history: [],
			selectedId: null,
			draft: null,
			saving: false,
			error: '',
			pollTimer: null,
			pollFailed: false,
		}
	},

	computed: {
		/**
		 * The one filtered set. The map, the Datos list and Análisis all derive
		 * from this rather than each filtering for themselves — three filters is
		 * how a count ends up disagreeing with what is on screen.
		 */
		liveFiltered() {
			return filterFeatures(this.features, this.filter)
		},

		/** See listFor(): the retired list is a recovery screen, never filtered. */
		listed() {
			return listFor({
				features: this.features,
				retiredFeatures: this.retiredFeatures,
				showRetired: this.showRetired,
				filter: this.filter,
			})
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
	},

	mounted() {
		this.startPolling()
		document.addEventListener('visibilitychange', this.onVisibilityChange)
	},

	beforeUnmount() {
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

		select(id) {
			this.selectedId = id
			const feature = this.listed.find((candidate) => candidate.id === id)
			this.draft = feature === undefined ? null : this.draftFrom(feature)
			this.error = ''
			this.history = []
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
			this.selectedId = null
			this.error = ''
			this.draft = {
				id: null,
				name: '',
				subcategoryId: defaultSubcategoryId(this.taxonomy),
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
			const [lon, lat] = feature.geometry?.coordinates ?? [null, null]

			return {
				id: feature.id,
				name: feature.name,
				subcategoryId: feature.subcategoryId,
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

		/** Clicking the map fills in the open draft's coordinates. */
		placeDraft({ lat, lon }) {
			if (this.draft === null) {
				return
			}
			this.draft.lat = lat.toFixed(7)
			this.draft.lon = lon.toFixed(7)
		},

		closeDraft() {
			this.draft = null
			this.selectedId = null
			this.history = []
		},

		payload() {
			const lat = toNumber(this.draft.lat)
			const lon = toNumber(this.draft.lon)

			return {
				...this.draft,
				// GeoJSON orders positions [longitude, latitude] — the reverse of
				// how they are said out loud, and of the form's field order.
				geometry: lat === null || lon === null
					? null
					: { type: 'Point', coordinates: [lon, lat] },
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
				this.draft = this.draftFrom(data)
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

.territorio-main__switch {
	display: flex;
	gap: 8px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	flex: 0 0 auto;
}
</style>
