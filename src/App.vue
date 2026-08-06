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
			selectedId: null,
			draft: null,
			saving: false,
			error: '',
		}
	},

	methods: {
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

				const existing = this.features.findIndex((feature) => feature.id === data.id)
				if (existing === -1) {
					this.features.unshift(data)
				} else {
					this.features.splice(existing, 1, data)
				}

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
