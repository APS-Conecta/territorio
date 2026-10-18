<template>
	<div class="territorio-layers-dropdown">
		<button type="button"
			class="territorio-layers-button"
			:class="{ 'territorio-layers-button--open': isOpen }"
			:aria-expanded="isOpen ? 'true' : 'false'"
			aria-haspopup="true"
			@click="isOpen = !isOpen">
			<span class="territorio-layers-button__icon" aria-hidden="true">🗂️</span>
			<span class="territorio-layers-button__text">Tipo de Activo</span>
			<span v-if="activeCategoryCount > 0" class="territorio-layers-button__count">
				({{ activeCategoryCount }})
			</span>
			<span class="territorio-layers-button__arrow" aria-hidden="true">{{ isOpen ? '▴' : '▾' }}</span>
		</button>

		<!-- Dropdown Panel -->
		<div v-if="isOpen" class="territorio-layers-panel">
			<div class="territorio-layers-panel__header">
				<div class="territorio-layers-panel__title">
					Categorías de Activos
				</div>
				<div class="territorio-layers-panel__bulk">
					<button type="button"
						class="territorio-layers-panel__action"
						@click="$emit('select-all')">
						Todas
					</button>
					<span class="territorio-layers-panel__sep">·</span>
					<button type="button"
						class="territorio-layers-panel__action"
						@click="$emit('clear-all')">
						Limpiar
					</button>
				</div>
			</div>

			<div class="territorio-layers-panel__list">
				<label v-for="cat in visibleCategories"
					:key="cat.id"
					class="territorio-layers-item">
					<input type="checkbox"
						class="territorio-layers-item__checkbox"
						:checked="isCategoryChecked(cat)"
						@change="$emit('toggle-category', cat)">
					<span class="territorio-layers-item__dot" :style="{ backgroundColor: cat.color }" aria-hidden="true" />
					<span class="territorio-layers-item__label">{{ cat.label }}</span>
					<span v-if="counts[cat.slug] != null" class="territorio-layers-item__badge">
						{{ counts[cat.slug] }}
					</span>
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
				<label class="territorio-layers-item">
					<input type="checkbox"
						class="territorio-layers-item__checkbox"
						:checked="showSectores"
						@change="$emit('toggle-division', 'sector')">
					<span class="territorio-layers-item__label">Sectores CESFAM</span>
				</label>
				<label class="territorio-layers-item">
					<input type="checkbox"
						class="territorio-layers-item__checkbox"
						:checked="showUnidadesVecinales"
						@change="$emit('toggle-division', 'unidad-vecinal')">
					<span class="territorio-layers-item__label">Unidades Vecinales</span>
				</label>
			</div>

			<!-- Footer Link to Taxonomy Drawer -->
			<div class="territorio-layers-panel__footer">
				<button type="button"
					class="territorio-layers-panel__settings"
					@click="openManager">
					⚙️ Configurar taxonomía y capas
				</button>
			</div>
		</div>
	</div>
</template>

<script>
export default {
	name: 'LayersDropdownButton',

	props: {
		taxonomy: {
			type: Array,
			default: () => [],
		},
		filter: {
			type: Object,
			default: () => ({ hidden: new Set(), query: '' }),
		},
		counts: {
			type: Object,
			default: () => ({}),
		},
		showSectores: {
			type: Boolean,
			default: true,
		},
		showUnidadesVecinales: {
			type: Boolean,
			default: true,
		},
	},

	emits: ['toggle-category', 'toggle-division', 'select-all', 'clear-all', 'open-taxonomy-drawer'],

	data() {
		return {
			isOpen: false,
		}
	},

	computed: {
		visibleCategories() {
			return this.taxonomy.filter(c => c.slug !== 'division-territorial')
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

		openManager() {
			this.isOpen = false
			this.$emit('open-taxonomy-drawer')
		},
	},
}
</script>

<style scoped>
.territorio-layers-dropdown {
	position: absolute;
	top: 12px;
	left: 414px;
	z-index: 1000;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.territorio-layers-button {
	display: flex;
	align-items: center;
	height: 48px;
	padding: 0 16px;
	background: #ffffff;
	border: 1px solid #dadce0;
	border-radius: 8px;
	font-size: 14px;
	font-weight: 500;
	color: #3c4043;
	cursor: pointer;
	box-shadow: 0 2px 6px rgba(60, 64, 67, 0.15), 0 1px 2px rgba(60, 64, 67, 0.1);
	transition: background 0.15s, border-color 0.15s, box-shadow 0.15s;
}

.territorio-layers-button:hover {
	background: #f8f9fa;
	border-color: #c6c9ce;
}

.territorio-layers-button--open {
	background: #e8f0fe;
	border-color: #1a73e8;
	color: #1a73e8;
}

.territorio-layers-button__icon {
	margin-right: 8px;
	font-size: 16px;
}

.territorio-layers-button__text {
	font-weight: 600;
}

.territorio-layers-button__count {
	margin-left: 6px;
	font-size: 13px;
	color: #1a73e8;
	font-weight: 600;
}

.territorio-layers-button__arrow {
	margin-left: 8px;
	font-size: 10px;
	color: #70757a;
}

.territorio-layers-panel {
	position: absolute;
	top: 100%;
	left: 0;
	margin-top: 6px;
	width: 290px;
	background: #ffffff;
	border-radius: 8px;
	box-shadow: 0 4px 16px rgba(60, 64, 67, 0.25);
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
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
	color: #5f6368;
	letter-spacing: 0.5px;
}

.territorio-layers-panel__bulk {
	font-size: 12px;
}

.territorio-layers-panel__action {
	background: none;
	border: none;
	color: #1a73e8;
	font-size: 12px;
	font-weight: 500;
	cursor: pointer;
	padding: 0;
}

.territorio-layers-panel__action:hover {
	text-decoration: underline;
}

.territorio-layers-panel__sep {
	margin: 0 4px;
	color: #dadce0;
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
	border-radius: 4px;
	cursor: pointer;
	user-select: none;
	transition: background 0.1s;
}

.territorio-layers-item:hover {
	background: #f1f3f4;
}

.territorio-layers-item__checkbox {
	margin-right: 8px;
	cursor: pointer;
}

.territorio-layers-item__dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	margin-right: 8px;
	flex-shrink: 0;
}

.territorio-layers-item__label {
	flex: 1;
	font-size: 13px;
	color: #202124;
}

.territorio-layers-item__badge {
	font-size: 11px;
	color: #70757a;
	background: #f1f3f4;
	padding: 1px 6px;
	border-radius: 10px;
}

.territorio-layers-panel__divider {
	height: 1px;
	background: #ebebeb;
	margin: 10px 0;
}

.territorio-layers-panel__footer {
	margin-top: 10px;
	padding-top: 8px;
	border-top: 1px solid #ebebeb;
}

.territorio-layers-panel__settings {
	width: 100%;
	background: none;
	border: none;
	color: #1a73e8;
	font-size: 13px;
	font-weight: 500;
	cursor: pointer;
	text-align: left;
	padding: 4px 6px;
	border-radius: 4px;
}

.territorio-layers-panel__settings:hover {
	background: #e8f0fe;
}
</style>
