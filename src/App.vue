<template>
	<NcContent app-name="territorio">
		<NcAppContent>
			<template #list>
				<FeatureList :features="features"
					:taxonomy="taxonomy"
					:selected-id="selectedId"
					@select="select"
					@create="startNew" />
			</template>

			<TerritorioMap :features="features"
				:taxonomy="taxonomy"
				:selected-id="selectedId"
				@select="select"
				@map-click="placeDraft" />
		</NcAppContent>

		<NcAppSidebar v-if="draft !== null"
			:name="draft.id === null ? 'Nuevo registro' : draft.name || 'Sin nombre'"
			@close="closeDraft">
			<FeatureDetail :draft="draft"
				:taxonomy="taxonomy"
				:saving="saving"
				:error="error"
				@save="save" />
		</NcAppSidebar>
	</NcContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcContent from '@nextcloud/vue/components/NcContent'
import FeatureDetail from './components/FeatureDetail.vue'
import FeatureList from './components/FeatureList.vue'
import TerritorioMap from './components/TerritorioMap.vue'
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
		FeatureDetail,
		FeatureList,
		NcAppContent,
		NcAppSidebar,
		NcContent,
		TerritorioMap,
	},

	data() {
		return {
			taxonomy: loadState('territorio', 'taxonomy'),
			features: loadState('territorio', 'features'),
			revision: loadState('territorio', 'revision'),
			selectedId: null,
			draft: null,
			saving: false,
			error: '',
			pollTimer: null,
			pollFailed: false,
		}
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

		mergeFeature(incoming) {
			const existing = this.features.findIndex((feature) => feature.id === incoming.id)
			if (existing === -1) {
				this.features.unshift(incoming)
			} else {
				this.features.splice(existing, 1, incoming)
			}
		},

		select(id) {
			this.selectedId = id
			const feature = this.features.find((candidate) => candidate.id === id)
			this.draft = feature === undefined ? null : this.draftFrom(feature)
			this.error = ''
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
