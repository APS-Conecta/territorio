<template>
	<NcAppContentList>
		<div class="territorio-list__header">
			<span class="territorio-list__count">
				{{ features.length }} {{ features.length === 1 ? 'registro' : 'registros' }}
			</span>
			<NcButton variant="primary" @click="$emit('create')">
				Agregar
			</NcButton>
		</div>

		<NcEmptyContent v-if="features.length === 0"
			name="Todavía no hay registros"
			description="Agregue el primer lugar del territorio: un colegio, una plaza, una junta de vecinos.">
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
				<span class="territorio-list__swatch" :style="{ background: colourOf(feature) }" />
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

const UNCLASSIFIED_COLOUR = '#6b7280'

export default {
	name: 'FeatureList',

	components: { NcAppContentList, NcButton, NcEmptyContent, NcListItem },

	props: {
		features: { type: Array, required: true },
		taxonomy: { type: Array, required: true },
		selectedId: { type: Number, default: null },
	},

	emits: ['select', 'create'],

	computed: {
		/** Subcategory id → { label, colour }, built once instead of per row. */
		subcategories() {
			const index = new Map()
			this.taxonomy.forEach((category) => {
				category.subcategories.forEach((subcategory) => {
					index.set(subcategory.id, {
						label: `${category.label} · ${subcategory.label}`,
						colour: category.color,
					})
				})
			})
			return index
		},
	},

	methods: {
		subcategoryLabel(feature) {
			return this.subcategories.get(feature.subcategoryId)?.label ?? 'Sin clasificar'
		},

		colourOf(feature) {
			return this.subcategories.get(feature.subcategoryId)?.colour ?? UNCLASSIFIED_COLOUR
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

.territorio-list__count {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.territorio-list__swatch {
	display: block;
	width: 16px;
	height: 16px;
	border-radius: 50%;
}
</style>
