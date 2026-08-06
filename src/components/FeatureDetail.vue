<template>
	<form class="territorio-detail" @submit.prevent="$emit('save')">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcTextField v-model="draft.name" label="Nombre" required />

		<NcSelect v-model="subcategory"
			class="territorio-detail__field"
			input-label="Subcategoría"
			:options="subcategoryOptions"
			:clearable="false"
			label="label" />

		<fieldset class="territorio-detail__field territorio-detail__coords">
			<legend>Ubicación</legend>
			<p class="territorio-detail__hint">
				Haga clic en el mapa para situar el registro, o escriba las coordenadas.
				Un registro sin coordenadas es válido: aparece en la lista y no en el mapa.
			</p>
			<div class="territorio-detail__coords-row">
				<NcTextField v-model="draft.lat" label="Latitud" inputmode="decimal" />
				<NcTextField v-model="draft.lon" label="Longitud" inputmode="decimal" />
			</div>
			<NcButton v-if="hasCoordinates"
				class="territorio-detail__field"
				@click="clearCoordinates">
				Quitar coordenadas
			</NcButton>

			<NcSelect v-if="hasCoordinates"
				v-model="quality"
				class="territorio-detail__field"
				input-label="Exactitud"
				:options="qualityOptions"
				:clearable="false"
				label="label" />
		</fieldset>

		<NcTextField v-model="draft.address" class="territorio-detail__field" label="Dirección" />
		<NcTextField v-model="draft.phone" class="territorio-detail__field" label="Teléfono" />
		<NcTextField v-model="draft.email" class="territorio-detail__field" label="Correo" />
		<NcTextField v-model="draft.contactPerson" class="territorio-detail__field" label="Persona de contacto" />
		<NcTextField v-model="draft.contactRole" class="territorio-detail__field" label="Cargo" />
		<NcTextField v-model="draft.openingHours" class="territorio-detail__field" label="Horario" />
		<NcTextField v-model="draft.sourceRef" class="territorio-detail__field" label="Fuente" />
		<NcTextArea v-model="draft.notes" class="territorio-detail__field" label="Notas" />

		<NcButton class="territorio-detail__field"
			variant="primary"
			:disabled="saving"
			native-type="submit">
			{{ saving ? 'Guardando…' : 'Guardar' }}
		</NcButton>

		<!-- Two-step, inline. Never a browser confirm(): it blocks the page, and
		     there is nothing irreversible to warn about anyway — retiring keeps
		     the record and its history (ADR-0005). -->
		<div v-if="draft.id !== null" class="territorio-detail__retire">
			<NcButton v-if="!confirmingRetire" variant="tertiary" @click="confirmingRetire = true">
				Retirar del mapa
			</NcButton>

			<template v-else>
				<p class="territorio-detail__hint">
					Se retira del mapa y de la lista. No se borra: conserva su historial y
					se puede restaurar.
				</p>
				<NcButton variant="error" @click="$emit('retire')">
					Retirar
				</NcButton>
				<NcButton variant="tertiary" @click="confirmingRetire = false">
					Cancelar
				</NcButton>
			</template>
		</div>

		<FeatureHistory v-if="draft.id !== null" :entries="history" />
	</form>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FeatureHistory from './FeatureHistory.vue'
import { subcategoryOptions } from '../taxonomy.js'

// Mirrors LocationQuality in PHP. "Absent" is missing on purpose: it is derived
// from having no geometry, and these options only appear when there is one.
const QUALITIES = [
	{ id: 'exacta', label: 'Exacta' },
	{ id: 'aproximada', label: 'Aproximada' },
]

export default {
	name: 'FeatureDetail',

	components: { FeatureHistory, NcButton, NcNoteCard, NcSelect, NcTextArea, NcTextField },

	props: {
		history: { type: Array, default: () => [] },
		// The draft is owned by App.vue and mutated in place: the sidebar edits
		// one record at a time, and copying it back and forth would only add a
		// second place for the two to disagree.
		draft: { type: Object, required: true },
		taxonomy: { type: Array, required: true },
		saving: { type: Boolean, default: false },
		error: { type: String, default: '' },
	},

	emits: ['save', 'retire'],

	data() {
		return { confirmingRetire: false }
	},

	watch: {
		// Selecting a different record must not leave the previous one's confirm
		// step armed — the next click would retire something nobody meant to.
		'draft.id'() {
			this.confirmingRetire = false
		},
	},

	computed: {
		subcategoryOptions() {
			return subcategoryOptions(this.taxonomy)
		},

		qualityOptions() {
			return QUALITIES
		},

		subcategory: {
			get() {
				return this.subcategoryOptions.find((option) => option.id === this.draft.subcategoryId) ?? null
			},
			set(option) {
				this.draft.subcategoryId = option?.id ?? null
			},
		},

		quality: {
			get() {
				return QUALITIES.find((option) => option.id === this.draft.locationQuality) ?? QUALITIES[0]
			},
			set(option) {
				this.draft.locationQuality = option?.id ?? 'exacta'
			},
		},

		hasCoordinates() {
			return String(this.draft.lat).trim() !== '' && String(this.draft.lon).trim() !== ''
		},
	},

	methods: {
		clearCoordinates() {
			this.draft.lat = ''
			this.draft.lon = ''
		},
	},
}
</script>

<style scoped>
.territorio-detail {
	padding: 0 4px 16px;
}

.territorio-detail__field {
	margin-top: 12px;
}

.territorio-detail__coords {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 8px 12px 12px;
}

.territorio-detail__coords legend {
	padding: 0 4px;
	font-weight: bold;
}

.territorio-detail__coords-row {
	display: flex;
	gap: 8px;
}

.territorio-detail__retire {
	margin-top: 24px;
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.territorio-detail__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-bottom: 8px;
}
</style>
