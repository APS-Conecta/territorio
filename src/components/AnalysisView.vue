<template>
	<div class="territorio-analisis">
		<header class="territorio-analisis__header">
			<div>
				<p class="territorio-analisis__eyebrow">Inventario del centro</p>
				<h2>Activos comunitarios</h2>
				<p class="territorio-analisis__muted">Explore los activos registrados. Los filtros del mapa no cambian este análisis.</p>
			</div>
			<div class="territorio-analisis__actions">
				<button type="button" @click="$emit('back')">Volver al mapa</button>
				<button type="button" :disabled="summary.total === 0" @click="$emit('export')">Exportar resultados</button>
				<button type="button" class="territorio-analisis__primary" :disabled="summary.total === 0" @click="$emit('show-map')">Ver en el mapa</button>
			</div>
		</header>

		<form class="territorio-analisis__filters" aria-label="Filtros de Análisis" @submit.prevent>
			<div class="territorio-analisis__filter-grid">
				<label class="territorio-analisis__search">
					<span>Buscar activos</span>
					<input type="search" :value="filter.query" placeholder="Nombre, dirección o contacto" @input="update('query', $event.target.value)">
				</label>
				<label>
					<span>Sector</span>
					<select :value="filter.sectorId" @change="update('sectorId', $event.target.value)">
						<option value="">Todos los sectores</option>
						<option v-for="sector in inventory.sectors" :key="sector.id" :value="sector.id">{{ sector.name }}</option>
					</select>
				</label>
				<label>
					<span>Categoría</span>
					<select :value="filter.categoryId" @change="update('categoryId', $event.target.value)">
						<option value="">Todas las categorías</option>
						<option v-for="category in inventory.categories" :key="category.id" :value="category.id">{{ category.label }}</option>
					</select>
				</label>
			</div>
			<details class="territorio-analisis__more">
				<summary>Más filtros<span v-if="advancedCount"> ({{ advancedCount }} activos)</span></summary>
				<div class="territorio-analisis__filter-grid territorio-analisis__filter-grid--more">
					<label>
						<span>Subcategoría</span>
						<select :value="filter.subcategoryId" @change="update('subcategoryId', $event.target.value)">
							<option value="">Todas las subcategorías</option>
							<optgroup v-for="category in subcategoryGroups" :key="category.id" :label="category.label">
								<option v-for="subcategory in category.subcategories" :key="subcategory.id" :value="subcategory.id">{{ subcategory.label }}</option>
							</optgroup>
						</select>
					</label>
					<label>
						<span>Unidad Vecinal</span>
						<select :value="filter.unidadVecinalId" @change="update('unidadVecinalId', $event.target.value)">
							<option value="">Todas las unidades</option>
							<option v-for="unidad in inventory.unidades" :key="unidad.id" :value="unidad.id">{{ unidad.name }}</option>
						</select>
					</label>
					<label>
						<span>Área personalizada</span>
						<select :value="filter.areaId" @change="update('areaId', $event.target.value)">
							<option value="">Todas las áreas</option>
							<option v-for="area in inventory.areas" :key="area.id" :value="area.id">{{ area.name }}</option>
						</select>
					</label>
					<label>
						<span>Tipo de activo</span>
						<select :value="filter.kind" @change="update('kind', $event.target.value)">
							<option value="">Todos los tipos</option>
							<option v-for="kind in kinds" :key="kind.id" :value="kind.id">{{ kind.label }}</option>
						</select>
					</label>
					<label>
						<span>Ubicación</span>
						<select :value="filter.location" @change="update('location', $event.target.value)">
							<option value="">Todas las ubicaciones</option>
							<option v-for="location in locations" :key="location.id" :value="location.id">{{ location.label }}</option>
						</select>
					</label>
				</div>
			</details>
			<div v-if="chips.length" class="territorio-analisis__chips" aria-label="Filtros aplicados">
				<button v-for="chip in chips" :key="chip.key" type="button" class="territorio-analisis__chip"
					:aria-label="`Quitar ${chip.label}`" @click="update(chip.key, '')">
					{{ chip.label }} <span aria-hidden="true">×</span>
				</button>
				<button type="button" @click="clear">Limpiar filtros</button>
			</div>
		</form>

		<p class="territorio-analisis__result-count" role="status" aria-live="polite" aria-atomic="true">
			<strong>{{ summary.total }}</strong> de {{ inventory.assets.length }} activos registrados
			<span v-if="chips.length" class="territorio-analisis__muted"> · con los filtros aplicados</span>
		</p>

		<div class="territorio-analisis__metrics">
			<button type="button" class="territorio-analisis__metric" :aria-pressed="filter.location === 'located'" @click="update('location', 'located')">
				<span>Con ubicación</span><strong>{{ summary.locations.located }}</strong><small>Ver activos ubicados</small>
			</button>
			<button type="button" class="territorio-analisis__metric" :aria-pressed="filter.location === 'missing'" @click="update('location', 'missing')">
				<span>Sin ubicación</span><strong>{{ summary.locations.missing }}</strong><small>Revisar registros</small>
			</button>
			<button type="button" class="territorio-analisis__metric" :aria-pressed="filter.location === 'outside'" @click="update('location', 'outside')">
				<span>Fuera de sectores conocidos</span><strong>{{ summary.locations.outside }}</strong><small>Con ubicación, fuera de sus límites</small>
			</button>
		</div>
		<p v-if="summary.locations.unassessed" class="territorio-analisis__muted">
			{{ summary.locations.unassessed }} activos tienen ubicación, pero no hay límites de sectores para compararlos.
		</p>

		<div v-if="summary.total === 0" class="territorio-analisis__empty">
			<h3>{{ inventory.assets.length ? 'Ningún activo coincide' : 'Todavía no hay activos registrados' }}</h3>
			<p>{{ inventory.assets.length ? 'Cambie o limpie los filtros para ampliar los resultados.' : 'Agregue activos desde el mapa o importe la planilla del centro.' }}</p>
			<button v-if="chips.length" type="button" @click="clear">Limpiar filtros</button>
		</div>
		<div v-else class="territorio-analisis__charts">
			<section class="territorio-analisis__chart" aria-labelledby="analysis-categories">
				<h3 id="analysis-categories">Por categoría</h3>
				<p class="territorio-analisis__muted">Seleccione una barra para filtrar.</p>
				<div v-for="category in shownCategories" :key="category.id" class="territorio-analisis__category">
					<button type="button" class="territorio-analisis__bar" :aria-pressed="String(filter.categoryId) === String(category.id)"
						:aria-label="`${category.label}: ${category.total} activos. Filtrar categoría`" @click="update('categoryId', String(category.id))">
						<span class="territorio-analisis__bar-label">{{ category.label }}</span>
						<strong>{{ category.total }}</strong>
						<span class="territorio-analisis__track" aria-hidden="true"><span :style="{ width: width(category.total, categoryMax), background: category.color }" /></span>
					</button>
					<details v-if="category.subcategories.filter(subcategory => subcategory.total > 0).length">
						<summary>Ver subcategorías</summary>
						<button v-for="subcategory in category.subcategories.filter(subcategory => subcategory.total > 0)" :key="subcategory.id" type="button"
							class="territorio-analisis__subcategory" :aria-pressed="String(filter.subcategoryId) === String(subcategory.id)"
							@click="selectSubcategory(category.id, subcategory.id)">
							<span>{{ subcategory.label }}</span><strong>{{ subcategory.total }}</strong>
						</button>
					</details>
				</div>
				<p v-if="summary.unclassified" class="territorio-analisis__muted">{{ summary.unclassified }} activos sin clasificación reconocible.</p>
			</section>
			<section class="territorio-analisis__chart" aria-labelledby="analysis-sectors">
				<h3 id="analysis-sectors">Por sector</h3>
				<p class="territorio-analisis__muted">Según la ubicación y los límites dibujados.</p>
				<button v-for="sector in summary.sectors" :key="sector.id" type="button" class="territorio-analisis__bar"
					:aria-pressed="String(filter.sectorId) === String(sector.id)" :aria-label="`${sector.label}: ${sector.total} activos. Filtrar sector`"
					@click="update('sectorId', String(sector.id))">
					<span class="territorio-analisis__bar-label">{{ sector.label }}</span><strong>{{ sector.total }}</strong>
					<span class="territorio-analisis__track" aria-hidden="true"><span :style="{ width: width(sector.total, sectorMax) }" /></span>
				</button>
				<p v-if="!summary.sectors.length" class="territorio-analisis__muted">Dibuje los sectores del centro para comparar su inventario.</p>
				<p v-else class="territorio-analisis__footnote">Un activo puede coincidir con varios sectores superpuestos. El total de activos lo cuenta una sola vez.</p>
			</section>
		</div>
		<p class="territorio-analisis__footnote">Estos datos describen el inventario registrado; no miden necesidades de la población ni garantizan que esté completo.</p>
	</div>
</template>

<script>
import { ANALYSIS_KINDS, ANALYSIS_LOCATIONS, analysisChips, emptyAnalysisFilter } from '../analytics.js'

export default {
	name: 'AnalysisView',
	props: {
		inventory: { type: Object, required: true },
		summary: { type: Object, required: true },
		filter: { type: Object, required: true },
	},
	emits: ['update:filter', 'back', 'show-map', 'export'],
	computed: {
		kinds() { return ANALYSIS_KINDS },
		locations() { return ANALYSIS_LOCATIONS },
		chips() { return analysisChips(this.inventory, this.filter) },
		advancedCount() { return this.chips.filter(chip => !['query', 'categoryId', 'sectorId'].includes(chip.key)).length },
		subcategoryGroups() {
			return this.inventory.categories.filter(category => !this.filter.categoryId || String(category.id) === String(this.filter.categoryId))
		},
		shownCategories() { return this.summary.categories.filter(category => category.total > 0) },
		categoryMax() { return Math.max(1, ...this.summary.categories.map(category => category.total)) },
		sectorMax() { return Math.max(1, ...this.summary.sectors.map(sector => sector.total)) },
	},
	methods: {
		update(key, value) {
			const filter = { ...this.filter, [key]: value }
			if (key === 'categoryId') filter.subcategoryId = ''
			this.$emit('update:filter', filter)
		},
		selectSubcategory(categoryId, subcategoryId) {
			this.$emit('update:filter', { ...this.filter, categoryId: String(categoryId), subcategoryId: String(subcategoryId) })
		},
		clear() { this.$emit('update:filter', emptyAnalysisFilter()) },
		width(total, max) { return `${total / max * 100}%` },
	},
}
</script>

<style scoped>
.territorio-analisis {
	box-sizing: border-box;
	height: 100%;
	overflow-y: auto;
	padding: 24px clamp(16px, 3vw, 48px) 40px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.territorio-analisis__header { display: flex; justify-content: space-between; align-items: start; gap: 20px; flex-wrap: wrap; margin-bottom: 24px; }
.territorio-analisis__eyebrow { font-size: 13px; font-weight: 600; color: var(--color-text-maxcontrast); margin: 0 0 4px; }
.territorio-analisis h2 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.territorio-analisis h3 { font-size: 18px; font-weight: 600; margin: 0 0 8px; }
.territorio-analisis p { line-height: 1.5; }
.territorio-analisis__muted, .territorio-analisis__footnote { color: var(--color-text-maxcontrast); }
.territorio-analisis__footnote { font-size: 13px; margin-top: 20px; }
.territorio-analisis__actions { display: flex; gap: 8px; flex-wrap: wrap; }
.territorio-analisis button { min-height: 44px; margin: 0; padding: 8px 12px; border: 1px solid var(--color-border); border-radius: 8px; background: var(--color-main-background); color: var(--color-main-text); cursor: pointer; white-space: normal; }
.territorio-analisis button:hover { background: var(--color-background-hover); }
.territorio-analisis button:focus-visible, .territorio-analisis summary:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 3px; }
.territorio-analisis button:disabled { opacity: .5; cursor: default; }
.territorio-analisis button[aria-pressed="true"] { border-color: var(--color-primary-element); background: var(--color-primary-element-light); }
.territorio-analisis button.territorio-analisis__primary { background: var(--color-primary-element); color: var(--color-primary-element-text); border-color: var(--color-primary-element); }
.territorio-analisis__filters { padding: 16px; border: 1px solid var(--color-border); border-radius: 12px; }
.territorio-analisis__filter-grid { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 12px; }
.territorio-analisis__filter-grid--more { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-top: 12px; }
.territorio-analisis label { display: flex; flex-direction: column; gap: 4px; min-width: 0; font-size: 13px; font-weight: 600; }
.territorio-analisis input, .territorio-analisis select { box-sizing: border-box; width: 100%; min-width: 0; max-width: 100%; min-height: 44px; margin: 0; padding: 8px 10px; border: 1px solid var(--color-border); border-radius: 6px; color: var(--color-main-text); background: var(--color-main-background); font-size: 14px; }
.territorio-analisis__more { margin-top: 8px; }
.territorio-analisis summary { cursor: pointer; padding: 10px 0; font-size: 13px; font-weight: 600; }
.territorio-analisis__chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.territorio-analisis button.territorio-analisis__chip { display: inline-flex; gap: 12px; align-items: center; font-size: 13px; border-radius: 22px; background: var(--color-background-dark); text-align: start; }
.territorio-analisis__result-count { margin: 24px 0 16px; font-size: 16px; }
.territorio-analisis__result-count strong { font-size: 24px; }
.territorio-analisis__metrics { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.territorio-analisis button.territorio-analisis__metric { display: flex; flex-direction: column; align-items: start; gap: 4px; padding: 16px; text-align: start; }
.territorio-analisis__metric strong { font-size: 28px; line-height: 1.25; font-variant-numeric: tabular-nums; }
.territorio-analisis__metric small { color: var(--color-text-maxcontrast); font-weight: normal; }
.territorio-analisis__charts { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 24px; }
.territorio-analisis__chart { border: 1px solid var(--color-border); border-radius: 12px; padding: 20px; min-width: 0; }
.territorio-analisis__chart > .territorio-analisis__muted { font-size: 13px; margin-bottom: 16px; }
.territorio-analisis__category { margin-bottom: 12px; }
.territorio-analisis button.territorio-analisis__bar { display: grid; grid-template-columns: 1fr auto; gap: 8px 12px; padding: 10px; text-align: start; width: 100%; margin-bottom: 6px; border-color: transparent; }
.territorio-analisis__bar-label { overflow-wrap: anywhere; }
.territorio-analisis__bar strong { font-variant-numeric: tabular-nums; }
.territorio-analisis__track { grid-column: 1 / -1; display: block; height: 8px; background: var(--color-background-dark); border-radius: 4px; overflow: hidden; }
.territorio-analisis__track > span { display: block; height: 100%; border-radius: inherit; background: var(--color-primary-element); }
.territorio-analisis__category details { padding-inline-start: 10px; }
.territorio-analisis button.territorio-analisis__subcategory { display: flex; justify-content: space-between; gap: 12px; width: 100%; text-align: start; border-color: transparent; font-size: 13px; }
.territorio-analisis__empty { text-align: center; padding: 40px 16px; }
@media (max-width: 700px) {
	.territorio-analisis__filter-grid { grid-template-columns: 1fr 1fr; }
	.territorio-analisis__search { grid-column: 1 / -1; }
	.territorio-analisis__charts { grid-template-columns: 1fr; }
	.territorio-analisis__metrics { grid-template-columns: 1fr; }
	.territorio-analisis button.territorio-analisis__metric { display: grid; grid-template-columns: 1fr auto; }
	.territorio-analisis__metric strong { grid-column: 2; grid-row: 1 / 3; }
	.territorio-analisis__chart { padding: 14px; }
}
@media (max-width: 420px) {
	.territorio-analisis__filter-grid--more { grid-template-columns: 1fr; }
}
</style>
