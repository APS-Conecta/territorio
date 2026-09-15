<template>
	<form class="territorio-detail" @submit.prevent="$emit('save')">
		<!--
			Saving and retiring, in one group, first in the form and stuck there
			(issue #35). Retiring used to be the last control of a form whose height
			is the Limit's, so on a real Sector it was 721px below the fold and was
			reported as not existing at all. Sticky rather than merely first, because
			"first" is only above the fold until somebody scrolls.

			Inside the form on purpose: Guardar stays a real `type="submit"`, which is
			the thing that was broken for eight slices, and the confirm step keeps
			working exactly as it did beside it.
		-->
		<div v-if="!retired" class="territorio-detail__actions">
			<!-- `type`, not `native-type`. In @nextcloud/vue 9 `type` IS the native
			     button type and `variant` carries the appearance; `nativeType` was the
			     v8 prop and no longer exists, so it fell through as an inert attribute
			     and left the button a type="button" that never submitted its form. -->
			<NcButton variant="primary" :disabled="saving" type="submit">
				{{ saving ? 'Guardando…' : 'Guardar' }}
			</NcButton>

			<!-- Two-step, inline. Never a browser confirm(): it blocks the page, and
			     there is nothing irreversible to warn about anyway — retiring keeps
			     the record and its history (ADR-0005). The two steps matter more now
			     that the destructive button sits beside the primary one, so they stay
			     exactly as they were. Nothing to retire until the record exists. -->
			<template v-if="draft.id !== null">
				<NcButton v-if="!confirmingRetire"
					class="territorio-detail__retire"
					variant="tertiary"
					@click="confirmingRetire = true">
					Retirar del mapa
				</NcButton>

				<template v-else>
					<p class="territorio-detail__hint territorio-detail__confirm">
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
			</template>
		</div>

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

		<!-- Provenance, never a warning (issue #50). The Limit records intent and
		     the geometry records the agreed line: they stop matching the moment
		     somebody drags a vertex, and that is expected rather than drift to be
		     corrected (CONTEXT.md). So it reads as a plain hint like the one above
		     it — no colour, no icon, nothing offering to put anything right. -->
		<p v-if="boundaryProvenance"
			class="territorio-detail__field territorio-detail__hint"
			data-testid="boundary-provenance">
			{{ boundaryProvenance }}
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

		<!--
			Shown, never editable (ADR-0008). Both are derived from where the record
			is: correcting one means correcting a boundary, which is the honest fix,
			and a box to type into here would be a promise the app cannot keep.
		-->
		<p v-if="!isDivision" class="territorio-detail__field territorio-detail__derived">
			<span>Sector: <strong>{{ sectorName }}</strong></span>
			<span>Unidad Vecinal: <strong>{{ unidadVecinalName }}</strong></span>
			<span class="territorio-detail__hint">
				Se deducen de la ubicación. Para corregirlos, corrija el límite.
			</span>
		</p>

		<NcTextField v-model="draft.address" class="territorio-detail__field" label="Dirección" />
		<NcTextField v-model="draft.phone" class="territorio-detail__field" label="Teléfono" />
		<NcTextField v-model="draft.email" class="territorio-detail__field" label="Correo" />
		<NcTextField v-model="draft.contactPerson" class="territorio-detail__field" label="Persona de contacto" />
		<NcTextField v-model="draft.contactRole" class="territorio-detail__field" label="Cargo" />
		<NcTextField v-model="draft.openingHours" class="territorio-detail__field" label="Horario" />
		<NcTextField v-model="draft.sourceRef" class="territorio-detail__field" label="Fuente" />
		<NcTextArea v-model="draft.notes" class="territorio-detail__field" label="Notas" />

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
import { provenance } from '../limit.js'
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
		// The Sectores and Unidades Vecinales the app holds, so a stored id can be
		// shown as the name a person would recognise.
		boundaries: { type: Array, default: () => [] },
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

		/** What the record's Sector is called, or that it is in none. */
		sectorName() {
			return this.nameOf(this.draft.sectorId)
		},

		unidadVecinalName() {
			return this.nameOf(this.draft.unidadVecinalId)
		},

		/**
		 * How this Sector's line came to be, in one sentence (issue #50).
		 *
		 * Sectores only, and `draft.limit` is what says so — App.vue puts the
		 * Limit on a draft exactly when the record is a Sector, and it is already
		 * the app's own test for it (`editingLimit`, `payload()`). A Place's
		 * history carries geometry edits too, and reading them the same way would
		 * tell somebody a clinic was drawn by hand.
		 *
		 * `datasetId` decides the one thing history cannot: an imported Sector's
		 * creation records `geometry` and no Limit, byte for byte a hand-drawn
		 * one's, so without it a boundary the municipality published was told to
		 * the user as drawn by hand (issue #50, ADR-0018). A record being made has
		 * no Dataset and no `datasetId` on its draft at all, hence the truthiness
		 * test rather than a comparison against null.
		 */
		boundaryProvenance() {
			return this.draft.limit === undefined
				? ''
				: provenance(this.history, Boolean(this.draft.datasetId))
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
		/**
		 * A boundary's name, from the records the app already holds. "Sin asignar"
		 * covers both real answers — no coordinate, and a coordinate outside every
		 * boundary — because from here they are the same fact: nothing to show.
		 */
		nameOf(id) {
			if (id === null || id === undefined) {
				return 'sin asignar'
			}

			return this.boundaries.find((feature) => feature.id === id)?.name ?? 'sin asignar'
		},

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

.territorio-detail__derived {
	display: flex;
	flex-direction: column;
	gap: 2px;
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

/*
 * Stuck under the sidebar header. `.app-sidebar` is the element that scrolls —
 * the header scrolls with the content — so `top: 0` pins this bar to the top of
 * the sidebar as soon as the header has passed it, and the form's height stops
 * deciding whether a person can reach the controls (issue #35). Same shape as
 * the sticky summary in CapasPanel.vue.
 *
 * The negative inline margin cancels the form's 4px padding, so nothing scrolls
 * past in the gutters either side of an opaque bar.
 */
.territorio-detail__actions {
	position: sticky;
	top: 0;
	z-index: 1;
	margin: 0 -4px 4px;
	padding: 8px 4px;
	background: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

/*
 * Destructive, and not the primary action: an outline in the error colour beside
 * the filled Guardar. `tertiary` is transparent with a transparent border, so
 * colouring the border is what draws the outline.
 *
 * Selected through the group rather than on its own: NcButton's own
 * `.button-vue--tertiary[data-v-…]` has the same specificity as a bare scoped
 * class, so which one won would come down to the order style-loader happened to
 * inject them in — an outline that is there or not there depending on the build.
 */
.territorio-detail__actions .territorio-detail__retire {
	border-color: var(--color-error);
	color: var(--color-error);
}

/*
 * Its own row, so the promise reads as a sentence rather than as a label
 * squeezed between two buttons. The gap already separates the rows, so it drops
 * the hint's bottom margin.
 *
 * Selected through the group for the same reason the retire button is: the
 * paragraph carries `territorio-detail__hint` too, and under `scoped` a bare
 * class ties with it on specificity — `.territorio-detail__hint` is declared
 * after this rule and would win the tie, leaving `margin-bottom: 0` dead.
 */
.territorio-detail__actions .territorio-detail__confirm {
	flex-basis: 100%;
	margin-bottom: 0;
}

.territorio-detail__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-bottom: 8px;
}
</style>
