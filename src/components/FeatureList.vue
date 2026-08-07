<template>
	<NcAppContentList>
		<div class="territorio-list__header">
			<span class="territorio-list__count">
				{{ features.length }} {{ features.length === 1 ? 'registro' : 'registros' }}
			</span>
			<div class="territorio-list__actions">
				<NcButton variant="tertiary"
					:pressed="showRetired"
					@click="$emit('update:showRetired', !showRetired)">
					{{ showRetired ? 'Ver activos' : 'Ver retirados' }}
				</NcButton>
				<!-- Undo lives in here too, so the label says so: someone who did
				     not run the import has no other cue where it is. -->
				<NcButton variant="tertiary" @click="$emit('import')">
					Importar / deshacer
				</NcButton>
				<NcButton v-if="!showRetired" variant="primary" @click="$emit('create')">
					Agregar
				</NcButton>
			</div>
		</div>

		<!--
			type="search" rather than a plain text field: the browser draws its own
			clear button and handles Escape, which is two behaviours not written here.
		-->
		<div class="territorio-list__search">
			<NcTextField :model-value="query"
				type="search"
				label="Buscar"
				:show-trailing-button="query !== ''"
				trailing-button-label="Limpiar la búsqueda"
				@update:model-value="$emit('update:query', $event)"
				@trailing-button-click="$emit('update:query', '')" />
		</div>

		<!--
			Three empty states, not two. "Nothing matched" is a different fact from
			"there is nothing here", and showing the second when the first is true
			reads as data loss.
		-->
		<NcEmptyContent v-if="features.length === 0 && query !== ''"
			name="Nada coincide con la búsqueda"
			:description="`Ningún registro contiene «${query}». Pruebe con menos palabras, o limpie la búsqueda.`">
			<template #icon>
				<svg viewBox="0 0 24 24" width="64" height="64" aria-hidden="true">
					<path fill="currentColor"
						d="M9.5 3A6.5 6.5 0 0 1 16 9.5c0 1.61-.59 3.09-1.56 4.23l.27.27h.79l5 5-1.5 1.5-5-5v-.79l-.27-.27A6.52 6.52 0 0 1 9.5 16 6.5 6.5 0 0 1 3 9.5 6.5 6.5 0 0 1 9.5 3Zm0 2C7 5 5 7 5 9.5S7 14 9.5 14 14 12 14 9.5 12 5 9.5 5Z" />
				</svg>
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="features.length === 0"
			:name="showRetired ? 'No hay registros retirados' : 'Todavía no hay registros'"
			:description="showRetired
				? 'Lo que se retira del mapa aparece aquí y se puede restaurar.'
				: 'Agregue el primer lugar del territorio: un colegio, una plaza, una junta de vecinos.'">
			<template #icon>
				<svg viewBox="0 0 24 24" width="64" height="64" aria-hidden="true">
					<path fill="currentColor"
						d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5Z" />
				</svg>
			</template>
		</NcEmptyContent>

		<NcListItem v-for="feature in features"
			:key="feature.id"
			:name="feature.name"
			:active="feature.id === selectedId"
			:force-display-actions="false"
			@click="$emit('select', feature.id)">
			<template #icon>
				<span class="territorio-list__swatch" :style="{ background: swatchFor(feature) }" />
			</template>
			<template #subname>
				{{ subcategoryLabel(feature) }}<span v-if="feature.geometry === null"> · sin coordenada</span>
			</template>
		</NcListItem>
	</NcAppContentList>
</template>

<script>
import NcAppContentList from '@nextcloud/vue/components/NcAppContentList'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { colourOf, indexSubcategories } from '../taxonomy.js'

export default {
	name: 'FeatureList',

	components: { NcAppContentList, NcButton, NcEmptyContent, NcListItem, NcTextField },

	props: {
		features: { type: Array, required: true },
		taxonomy: { type: Array, required: true },
		selectedId: { type: Number, default: null },
		showRetired: { type: Boolean, default: false },
		// Owned by App.vue and part of the one filter, so the map and Análisis
		// narrow with the list rather than beside it.
		query: { type: String, default: '' },
	},

	emits: ['select', 'create', 'import', 'update:showRetired', 'update:query'],

	computed: {
		subcategories() {
			return indexSubcategories(this.taxonomy)
		},
	},

	methods: {
		/**
		 * The fallback deliberately does NOT read "Sin clasificar": that is a real
		 * seeded Category, and using its name here would make a broken reference
		 * indistinguishable from a record someone correctly left unclassified.
		 */
		subcategoryLabel(feature) {
			return this.subcategories.get(feature.subcategoryId)?.label ?? 'Clasificación desconocida'
		},

		/** The record's own colour if it has one — which is how sectores differ. */
		swatchFor(feature) {
			return colourOf(feature, this.subcategories)
		},
	},
}
</script>

<style scoped>
.territorio-list__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.territorio-list__actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.territorio-list__count {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.territorio-list__search {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.territorio-list__swatch {
	display: block;
	width: 16px;
	height: 16px;
	border-radius: 50%;
}
</style>
