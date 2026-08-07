<template>
	<form class="territorio-detail" @submit.prevent="$emit('save')">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcTextField v-model="draft.name" label="Nombre" required />

		<!-- Nothing constrains the name. A CESFAM may call its sectores "3",
		     "Rojo" or "Sector Sur", and Territorio does not know which. -->

		<NcSelect v-if="!isDivision"
			v-model="subcategory"
			class="territorio-detail__field"
			input-label="Subcategoría"
			:options="subcategoryOptions"
			:clearable="false"
			label="label" />

		<!-- Native colour input: the browser already has a picker, and it is one
		     line against a component to configure. -->
		<label v-if="isDivision" class="territorio-detail__field territorio-detail__colour">
			<span>Color</span>
			<input v-model="colour" type="color">
		</label>

		<p v-if="hasShape" class="territorio-detail__field territorio-detail__hint">
			El límite se dibuja en el mapa. Arrastre los vértices para ajustarlo;
			se pegan a los bordes ya dibujados.
		</p>

		<fieldset v-if="!hasShape" class="territorio-detail__field territorio-detail__coords">
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

		<!-- `type`, not `native-type`. In @nextcloud/vue 9 `type` IS the native
		     button type and `variant` carries the appearance; `nativeType` was the
		     v8 prop and no longer exists, so it fell through as an inert attribute
		     and left the button a type="button" that never submitted its form. -->
		<NcButton v-if="!retired"
			class="territorio-detail__field"
			variant="primary"
			:disabled="saving"
			type="submit">
			{{ saving ? 'Guardando…' : 'Guardar' }}
		</NcButton>

		<!-- Two-step, inline. Never a browser confirm(): it blocks the page, and
		     there is nothing irreversible to warn about anyway — retiring keeps
		     the record and its history (ADR-0005). Hidden once retired, or the
		     sidebar would offer to retire something already retired. -->
		<div v-if="draft.id !== null && !retired" class="territorio-detail__retire">
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

		<FeatureHistory v-if="draft.id !== null" :entries="history" :taxonomy="taxonomy" />
	</form>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FeatureHistory from './FeatureHistory.vue'
import { DEFAULT_SECTOR_COLOUR, divisionsOf, subcategoryOptions, treeOf } from '../taxonomy.js'

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
		retired: { type: Boolean, default: false },
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
		/**
		 * The tree only. A territorial division is not something a record is
		 * reclassified INTO — filing a colegio as a Sector would put it behind the
		 * Sectores toggle and colour it like a boundary.
		 */
		subcategoryOptions() {
			return subcategoryOptions(treeOf(this.taxonomy))
		},

		/** A Sector: it is what it is, so there is no Subcategoría to choose. */
		isDivision() {
			return divisionsOf(this.taxonomy)
				.some((division) => division.id === this.draft.subcategoryId)
		},

		/** Drawn on the map rather than typed as a coordinate. */
		hasShape() {
			return this.draft.geometry !== null && this.draft.geometry !== undefined
		},

		/**
		 * A colour input has no empty state — left with a null it shows black while
		 * the record would save with no colour at all, so what is on screen is not
		 * what is stored. Reading through the default keeps the two the same.
		 */
		colour: {
			get() {
				return this.draft.colour ?? DEFAULT_SECTOR_COLOUR
			},
			set(value) {
				this.draft.colour = value
			},
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

.territorio-detail__colour {
	display: flex;
	align-items: center;
	gap: 8px;
}

.territorio-detail__colour input {
	inline-size: 48px;
	block-size: 32px;
	padding: 2px;
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
