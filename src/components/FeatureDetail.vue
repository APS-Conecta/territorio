<template>
	<form class="territorio-detail" @submit.prevent="$emit('save')">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcTextField v-model="draft.name" label="Nombre" required />

		<NcSelect v-model="subcategory"
			class="territorio-detail__field"
			input-label="Categoría"
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
	</form>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const QUALITIES = [
	{ id: 'exacta', label: 'Exacta' },
	{ id: 'aproximada', label: 'Aproximada' },
]

export default {
	name: 'FeatureDetail',

	components: { NcButton, NcNoteCard, NcSelect, NcTextArea, NcTextField },

	props: {
		// The draft is owned by App.vue and mutated in place: the sidebar edits
		// one record at a time, and copying it back and forth would only add a
		// second place for the two to disagree.
		draft: { type: Object, required: true },
		taxonomy: { type: Array, required: true },
		saving: { type: Boolean, default: false },
		error: { type: String, default: '' },
	},

	emits: ['save'],

	computed: {
		subcategoryOptions() {
			return this.taxonomy.flatMap((category) => category.subcategories.map((subcategory) => ({
				id: subcategory.id,
				label: `${category.label} · ${subcategory.label}`,
			})))
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

.territorio-detail__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-bottom: 8px;
}
</style>
