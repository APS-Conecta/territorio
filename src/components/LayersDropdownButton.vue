<template>
	<div class="territorio-layers-dropdown">
		<NcButton class="territorio-layers-button"
			:variant="isOpen ? 'secondary' : 'tertiary'"
			:aria-expanded="isOpen ? 'true' : 'false'"
			aria-haspopup="true"
			@click="isOpen = !isOpen">
			<template #icon>
				<span aria-hidden="true">🗂️</span>
			</template>
			Tipo de Activo
			<span v-if="activeCategoryCount > 0" class="territorio-layers-button__count">
				({{ activeCategoryCount }})
			</span>
			<span class="territorio-layers-button__arrow" aria-hidden="true">{{ isOpen ? '▴' : '▾' }}</span>
		</NcButton>

		<!-- Dropdown Panel -->
		<div v-if="isOpen" class="territorio-layers-panel">
			<div class="territorio-layers-panel__header">
				<div class="territorio-layers-panel__title">
					Categorías de Activos
				</div>
				<div class="territorio-layers-panel__bulk">
					<NcButton class="territorio-layers-panel__action"
						variant="tertiary"
						size="small"
						@click="$emit('select-all')">
						Todas
					</NcButton>
					<NcButton class="territorio-layers-panel__action"
						variant="tertiary"
						size="small"
						@click="$emit('clear-all')">
						Limpiar
					</NcButton>
				</div>
			</div>

			<div class="territorio-layers-panel__list">
				<label v-for="cat in visibleCategories"
					:key="cat.id"
					class="territorio-layers-item">
					<input type="checkbox"
						class="territorio-layers-item__checkbox"
						:checked="isCategoryChecked(cat)"
						@change="onToggleCategory(cat, $event)">
					<span class="territorio-layers-item__dot" :style="{ backgroundColor: cat.color }" aria-hidden="true" />
					<span class="territorio-layers-item__label">{{ cat.label }}</span>
				</label>
			</div>

			<!-- Territorial Divisions Section -->
			<div class="territorio-layers-panel__divider" />
			<div class="territorio-layers-panel__header">
				<div class="territorio-layers-panel__title">
					División Territorial
				</div>
			</div>
			<div class="territorio-layers-panel__list">
				<label v-for="division in divisions"
					:key="division.id"
					class="territorio-layers-item">
					<input type="checkbox"
						class="territorio-layers-item__checkbox"
						:checked="!filter.hidden.has(division.id)"
						@change="$emit('toggle-division', division.id)">
					<span class="territorio-layers-item__label">{{ division.label }}</span>
				</label>
			</div>

			<!-- Footer Link to Taxonomy Drawer -->
			<div class="territorio-layers-panel__footer">
				<NcButton class="territorio-layers-panel__settings"
					variant="tertiary"
					alignment="start"
					wide
					@click="openManager">
					⚙️ Configurar taxonomía y capas
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'

import { divisionsOf, treeOf } from '../taxonomy.js'

export default {
	name: 'LayersDropdownButton',

	components: { NcButton },

	props: {
		taxonomy: {
			type: Array,
			default: () => [],
		},
		filter: {
			type: Object,
			default: () => ({ hidden: new Set(), query: '' }),
		},
	},

	emits: ['toggle-category', 'toggle-division', 'select-all', 'clear-all', 'open-taxonomy-drawer'],

	data() {
		return {
			isOpen: false,
		}
	},

	computed: {
		// The Category tree, and beside it one toggle per territorial division
		// (CONTEXT.md). The labels come from the taxonomy, so a clinic that
		// renamed its divisions sees its own words here.
		visibleCategories() {
			return treeOf(this.taxonomy)
		},

		divisions() {
			return divisionsOf(this.taxonomy)
		},

		activeCategoryCount() {
			return this.visibleCategories.filter(c => this.isCategoryChecked(c)).length
		},
	},

	mounted() {
		document.addEventListener('click', this.onDocumentClick)
	},

	beforeUnmount() {
		document.removeEventListener('click', this.onDocumentClick)
	},

	methods: {
		onDocumentClick(e) {
			if (!this.$el.contains(e.target)) {
				this.isOpen = false
			}
		},

		isCategoryChecked(cat) {
			if (!cat.subcategories || cat.subcategories.length === 0) {
				return true
			}
			return !cat.subcategories.every(sub => this.filter.hidden.has(sub.id))
		},

		onToggleCategory(cat, event) {
			this.$emit('toggle-category', cat)
			// A partly hidden category shows ticked and a click reveals the rest, so
			// the box ends where it started and Vue sees no change to patch: put the
			// DOM back to what the filter says once it has caught up.
			this.$nextTick(() => {
				event.target.checked = this.isCategoryChecked(cat)
			})
		},

		openManager() {
			this.isOpen = false
			this.$emit('open-taxonomy-drawer')
		},
	},
}
</script>

<style scoped>
/* Placed by App.vue's topbar; see .territorio-omnibox. */
.territorio-layers-dropdown {
	display: flex;
	align-items: center;
	height: 48px;
	padding: 0 6px;
	position: relative;
	z-index: 1000;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 2px 6px var(--color-box-shadow);
	box-sizing: border-box;
}

.territorio-layers-button__count {
	margin-inline-start: 6px;
}

.territorio-layers-button__arrow {
	margin-inline-start: 8px;
	font-size: 0.7rem;
}

.territorio-layers-panel {
	position: absolute;
	top: 100%;
	left: 0;
	margin-top: 6px;
	width: 290px;
	/* The footer link that opens the taxonomy drawer is the last thing in the
	   panel; with many categories it must scroll into reach, not off the screen. */
	max-height: calc(100vh - 180px);
	overflow-y: auto;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 16px var(--color-box-shadow);
	padding: 12px;
	box-sizing: border-box;
	z-index: 1001;
}

.territorio-layers-panel__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}

.territorio-layers-panel__title {
	font-size: 0.8rem;
	font-weight: 700;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	letter-spacing: 0.5px;
}

.territorio-layers-panel__bulk {
	display: flex;
	gap: 2px;
}

/* Beside the title, so the theme's small text and one grid step of padding. */
.territorio-layers-panel__bulk .territorio-layers-panel__action {
	--button-padding-default: var(--default-grid-baseline);
	font-size: var(--font-size-small);
}

.territorio-layers-panel__list {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.territorio-layers-item {
	display: flex;
	align-items: center;
	padding: 4px 6px;
	border-radius: var(--border-radius-small);
	cursor: pointer;
	user-select: none;
	transition: background 0.1s;
}

.territorio-layers-item:hover {
	background: var(--color-background-hover);
}

.territorio-layers-item__checkbox {
	margin-inline-end: 8px;
	cursor: pointer;
}

.territorio-layers-item__dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	margin-inline-end: 8px;
	flex-shrink: 0;
}

.territorio-layers-item__label {
	flex: 1;
	font-size: var(--font-size-small);
	color: var(--color-main-text);
}

.territorio-layers-panel__divider {
	height: 1px;
	background: var(--color-border);
	margin: 10px 0;
}

.territorio-layers-panel__footer {
	margin-top: 10px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}
</style>
