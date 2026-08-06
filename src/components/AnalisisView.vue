<template>
	<div class="territorio-analisis">
		<h2>
			{{ counts.total }} {{ counts.total === 1 ? 'registro' : 'registros' }}
			<span v-if="filtered" class="territorio-analisis__note">con los filtros aplicados</span>
		</h2>

		<NcEmptyContent v-if="counts.total === 0"
			name="Nada que contar"
			description="Ningún registro coincide con las capas activas.">
			<template #icon>
				<svg viewBox="0 0 24 24" width="64" height="64" aria-hidden="true">
					<path fill="currentColor" d="M4 20h16v-2H4v2Zm2-4h3V8H6v8Zm5 0h3V4h-3v12Zm5 0h3v-5h-3v5Z" />
				</svg>
			</template>
		</NcEmptyContent>

		<template v-else>
			<section v-for="category in shown" :key="category.id" class="territorio-analisis__category">
				<div class="territorio-analisis__row">
					<span class="territorio-analisis__label">
						<span class="territorio-analisis__swatch" :style="{ background: category.color }" />
						{{ category.label }}
					</span>
					<span class="territorio-analisis__track">
						<span class="territorio-analisis__fill"
							:style="{ width: width(category.total), background: category.color }" />
					</span>
					<span class="territorio-analisis__value">{{ category.total }}</span>
				</div>

				<div v-for="subcategory in withAny(category)"
					:key="subcategory.id"
					class="territorio-analisis__row territorio-analisis__row--child">
					<span class="territorio-analisis__label">{{ subcategory.label }}</span>
					<span class="territorio-analisis__track">
						<span class="territorio-analisis__fill"
							:style="{ width: width(subcategory.total), background: category.color, opacity: 0.55 }" />
					</span>
					<span class="territorio-analisis__value">{{ subcategory.total }}</span>
				</div>
			</section>

			<p v-if="counts.unclassified > 0" class="territorio-analisis__note">
				{{ counts.unclassified }} sin clasificación reconocible.
			</p>
		</template>
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

export default {
	name: 'AnalisisView',

	components: { NcEmptyContent },

	props: {
		// Already filtered by the caller. This view never filters anything itself
		// — that is what keeps its numbers from drifting away from the list and
		// the map, which all read the same set.
		counts: { type: Object, required: true },
		filtered: { type: Boolean, default: false },
	},

	computed: {
		/** Empty categories are dropped from the chart but not from the legend. */
		shown() {
			return this.counts.categories.filter((category) => category.total > 0)
		},

		widest() {
			return Math.max(1, ...this.counts.categories.map((category) => category.total))
		},
	},

	methods: {
		withAny(category) {
			return category.subcategories.filter((subcategory) => subcategory.total > 0)
		},

		width(total) {
			return `${(total / this.widest) * 100}%`
		},
	},
}
</script>

<style scoped>
.territorio-analisis {
	padding: 16px 24px 32px;
	overflow-y: auto;
	height: 100%;
}

.territorio-analisis h2 {
	font-size: 1.2em;
	margin-bottom: 16px;
}

.territorio-analisis__note {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-weight: normal;
	margin-inline-start: 6px;
}

.territorio-analisis__category {
	margin-bottom: 14px;
}

.territorio-analisis__row {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 2px 0;
}

.territorio-analisis__row--child {
	padding-inline-start: 18px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.territorio-analisis__label {
	flex: 0 0 220px;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.territorio-analisis__swatch {
	display: inline-block;
	width: 12px;
	height: 12px;
	border-radius: 50%;
	margin-inline-end: 6px;
}

.territorio-analisis__track {
	flex: 1 1 auto;
	height: 12px;
	background: var(--color-background-dark);
	border-radius: 6px;
	overflow: hidden;
	min-width: 40px;
}

.territorio-analisis__fill {
	display: block;
	height: 100%;
	border-radius: 6px;
}

.territorio-analisis__value {
	flex: 0 0 3.5em;
	text-align: end;
	font-variant-numeric: tabular-nums;
}
</style>
