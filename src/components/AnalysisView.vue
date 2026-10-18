<template>
	<div class="territorio-analisis">
		<div class="territorio-analisis__header">
			<button type="button"
				class="territorio-analisis__back-btn"
				title="Volver al mapa"
				@click="$emit('back')">
				← Volver al mapa
			</button>
			<h2>
				{{ counts.total }} {{ counts.total === 1 ? 'registro' : 'registros' }}
				<span v-if="filtered" class="territorio-analisis__note">con los filtros aplicados</span>
			</h2>
		</div>

		<!--
			"las capas activas" alone became untrue the moment search could empty
			this too, and a message that names the wrong cause sends someone to the
			wrong control to fix it.
		-->
		<NcEmptyContent v-if="counts.total === 0"
			name="Nada que contar"
			description="Ningún registro coincide con las capas activas ni con la búsqueda.">
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
						<!--
							`category.color` is what countsFor RESOLVED, never the stored
							seed: a territorial division answers with the neutral, because
							its `#334155` measures 1.73:1 against the dark theme (#29). The
							Subcategory rows below read the same field for that reason —
							the chart has no second opinion about a Category's colour.
						-->
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
	name: 'AnalysisView',

	components: { NcEmptyContent },

	props: {
		// Already filtered by the caller. This view never filters anything itself
		// — that is what keeps its numbers from drifting away from the list and
		// the map, which all read the same set.
		counts: { type: Object, required: true },
		filtered: { type: Boolean, default: false },
	},

	emits: ['back'],

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
	background: #ffffff;
}

.territorio-analisis__header {
	margin-bottom: 20px;
}

.territorio-analisis__back-btn {
	background: #f1f3f4;
	border: 1px solid #dadce0;
	border-radius: 6px;
	font-size: 13px;
	font-weight: 500;
	color: #1a73e8;
	padding: 6px 12px;
	cursor: pointer;
	margin-bottom: 12px;
	display: inline-flex;
	align-items: center;
	transition: background 0.15s;
}

.territorio-analisis__back-btn:hover {
	background: #e8eaed;
}

.territorio-analisis h2 {
	font-size: 1.3em;
	margin: 0;
	color: #202124;
}

.territorio-analisis__note {
	color: #70757a;
	font-size: 0.85em;
	font-weight: normal;
	margin-inline-start: 6px;
}

.territorio-analisis__category {
	margin-bottom: 16px;
}

.territorio-analisis__row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 4px 0;
}

.territorio-analisis__row--child {
	padding-inline-start: 22px;
	font-size: 0.9em;
	color: #5f6368;
}

.territorio-analisis__label {
	flex: 0 0 240px;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 13px;
	font-weight: 500;
}

.territorio-analisis__swatch {
	display: inline-block;
	width: 12px;
	height: 12px;
	border-radius: 50%;
	margin-inline-end: 8px;
}

.territorio-analisis__track {
	flex: 1 1 auto;
	height: 14px;
	background: #f1f3f4;
	border-radius: 7px;
	overflow: hidden;
	min-width: 60px;
}

.territorio-analisis__fill {
	display: block;
	height: 100%;
	border-radius: 7px;
	transition: width 0.3s ease;
}

.territorio-analisis__value {
	flex: 0 0 3.5em;
	text-align: end;
	font-variant-numeric: tabular-nums;
	font-weight: 600;
	color: #202124;
}
</style>
