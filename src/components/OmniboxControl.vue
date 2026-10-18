<template>
	<div class="territorio-omnibox" :class="{ 'territorio-omnibox--active': isFocused || query.trim() !== '' }">
		<div class="territorio-omnibox__bar">
			<div class="territorio-omnibox__icon" aria-hidden="true">
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
					<circle cx="11" cy="11" r="8" />
					<line x1="21" y1="21" x2="16.65" y2="16.65" />
				</svg>
			</div>

			<input ref="inputRef"
				v-model="query"
				type="search"
				class="territorio-omnibox__input"
				placeholder="Buscar lugares, activos o calles…"
				aria-label="Buscar lugares, activos o calles"
				role="combobox"
				:aria-expanded="showDropdown ? 'true' : 'false'"
				aria-autocomplete="list"
				@focus="isFocused = true"
				@blur="onBlur"
				@keydown.down.prevent="navigateResults(1)"
				@keydown.up.prevent="navigateResults(-1)"
				@keydown.enter.prevent="selectActiveResult"
				@keydown.esc="clearQuery">

			<button v-if="query.trim() !== ''"
				type="button"
				class="territorio-omnibox__clear"
				title="Limpiar búsqueda"
				aria-label="Limpiar búsqueda"
				@click="clearQuery">
				✕
			</button>
		</div>

		<!-- View Switcher Tabs -->
		<div class="territorio-omnibox__tabs" role="tablist">
			<button type="button"
				role="tab"
				class="territorio-omnibox__tab"
				:class="{ 'territorio-omnibox__tab--active': pane === 'mapa' }"
				:aria-selected="pane === 'mapa' ? 'true' : 'false'"
				@click="$emit('change-pane', 'mapa')">
				Mapa
			</button>
			<button type="button"
				role="tab"
				class="territorio-omnibox__tab"
				:class="{ 'territorio-omnibox__tab--active': pane === 'indice' }"
				:aria-selected="pane === 'indice' ? 'true' : 'false'"
				@click="$emit('change-pane', 'indice')">
				Índice
			</button>
			<button type="button"
				role="tab"
				class="territorio-omnibox__tab"
				:class="{ 'territorio-omnibox__tab--active': pane === 'analisis' }"
				:aria-selected="pane === 'analisis' ? 'true' : 'false'"
				@click="$emit('change-pane', 'analisis')">
				Análisis
			</button>
			<button type="button"
				role="tab"
				class="territorio-omnibox__tab"
				:class="{ 'territorio-omnibox__tab--active': pane === 'duplicados' }"
				:aria-selected="pane === 'duplicados' ? 'true' : 'false'"
				@click="$emit('change-pane', 'duplicados')">
				Posibles duplicados
			</button>
		</div>

		<!-- Unified Autocomplete Results Dropdown -->
		<div v-if="showDropdown" class="territorio-omnibox__dropdown" role="listbox">
			<div v-for="(result, index) in results"
				:key="result.kind + '-' + (result.id ?? result.name)"
				role="option"
				class="territorio-omnibox__item"
				:class="{ 'territorio-omnibox__item--highlighted': activeIndex === index }"
				:aria-selected="activeIndex === index ? 'true' : 'false'"
				@mousedown.prevent="chooseResult(result)">
				<div class="territorio-omnibox__item-icon" aria-hidden="true">
					<span v-if="result.kind === 'asset'">📍</span>
					<span v-else>🛣️</span>
				</div>
				<div class="territorio-omnibox__item-info">
					<div class="territorio-omnibox__item-title">
						{{ result.name }}
					</div>
					<div class="territorio-omnibox__item-subtitle">
						<template v-if="result.kind === 'asset'">
							<span class="territorio-omnibox__badge">{{ result.subcategoryLabel || result.categoryLabel }}</span>
							<span v-if="result.address" class="territorio-omnibox__addr"> · {{ result.address }}</span>
						</template>
						<template v-else>
							<span class="territorio-omnibox__street-hint">Calle o vía de la comuna</span>
						</template>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { matchOmnibox } from '../assets.js'

export default {
	name: 'OmniboxControl',

	props: {
		modelValue: {
			type: String,
			default: '',
		},
		pane: {
			type: String,
			default: 'mapa',
		},
		assets: {
			type: Array,
			default: () => [],
		},
		streets: {
			type: Array,
			default: () => [],
		},
		subcategories: {
			type: Map,
			default: () => new Map(),
		},
	},

	emits: ['update:modelValue', 'change-pane', 'select-asset', 'select-street', 'clear'],

	data() {
		return {
			isFocused: false,
			activeIndex: -1,
		}
	},

	computed: {
		query: {
			get() {
				return this.modelValue
			},
			set(val) {
				this.$emit('update:modelValue', val)
				this.activeIndex = -1
			},
		},

		results() {
			if (!this.query || this.query.trim() === '') {
				return []
			}
			return matchOmnibox(this.query, {
				assets: this.assets,
				streets: this.streets,
				subcategories: this.subcategories,
				limit: 10,
			})
		},

		showDropdown() {
			return this.isFocused && this.results.length > 0
		},
	},

	methods: {
		onBlur() {
			// Short timeout so mousedown can fire
			setTimeout(() => {
				this.isFocused = false
			}, 200)
		},

		clearQuery() {
			this.query = ''
			this.activeIndex = -1
			this.$emit('clear')
			this.$refs.inputRef?.focus()
		},

		navigateResults(delta) {
			if (!this.showDropdown) {
				return
			}
			const total = this.results.length
			this.activeIndex = (this.activeIndex + delta + total) % total
		},

		selectActiveResult() {
			if (this.activeIndex >= 0 && this.activeIndex < this.results.length) {
				this.chooseResult(this.results[this.activeIndex])
			}
		},

		chooseResult(result) {
			this.isFocused = false
			if (result.kind === 'asset') {
				this.$emit('select-asset', result)
			} else {
				this.$emit('select-street', result)
			}
		},
	},
}
</script>

<style scoped>
.territorio-omnibox {
	position: absolute;
	top: 12px;
	left: 12px;
	z-index: 1000;
	width: 392px;
	background: #ffffff;
	border-radius: 8px;
	box-shadow: 0 2px 6px rgba(60, 64, 67, 0.3), 0 1px 2px rgba(60, 64, 67, 0.15);
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
	box-sizing: border-box;
}

.territorio-omnibox__bar {
	display: flex;
	align-items: center;
	height: 48px;
	padding: 0 12px;
	border-bottom: 1px solid #ebebeb;
}

.territorio-omnibox__icon {
	display: flex;
	align-items: center;
	color: #5f6368;
	margin-right: 12px;
}

.territorio-omnibox__input {
	flex: 1;
	border: none;
	outline: none;
	font-size: 15px;
	color: #202124;
	background: transparent;
	padding: 0;
}

.territorio-omnibox__input::placeholder {
	color: #70757a;
}

.territorio-omnibox__clear {
	background: none;
	border: none;
	cursor: pointer;
	color: #70757a;
	font-size: 14px;
	padding: 6px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
}

.territorio-omnibox__clear:hover {
	background: #f1f3f4;
	color: #202124;
}

.territorio-omnibox__tabs {
	display: flex;
	align-items: center;
	padding: 4px 8px 6px;
	gap: 4px;
	background: #f8f9fa;
	border-bottom-left-radius: 8px;
	border-bottom-right-radius: 8px;
}

.territorio-omnibox__tab {
	background: none;
	border: none;
	font-size: 12px;
	font-weight: 500;
	color: #5f6368;
	padding: 4px 8px;
	border-radius: 12px;
	cursor: pointer;
	transition: background 0.15s, color 0.15s;
}

.territorio-omnibox__tab:hover {
	background: #e8eaed;
	color: #202124;
}

.territorio-omnibox__tab--active {
	background: #e8f0fe;
	color: #1a73e8;
	font-weight: 600;
}

.territorio-omnibox__dropdown {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	margin-top: 4px;
	background: #ffffff;
	border-radius: 8px;
	box-shadow: 0 4px 12px rgba(60, 64, 67, 0.25);
	max-height: 380px;
	overflow-y: auto;
	z-index: 1001;
	padding: 4px 0;
}

.territorio-omnibox__item {
	display: flex;
	align-items: center;
	padding: 10px 14px;
	cursor: pointer;
	border-bottom: 1px solid #f1f3f4;
	transition: background 0.1s;
}

.territorio-omnibox__item:last-child {
	border-bottom: none;
}

.territorio-omnibox__item:hover,
.territorio-omnibox__item--highlighted {
	background: #f1f3f4;
}

.territorio-omnibox__item-icon {
	margin-right: 12px;
	font-size: 16px;
	display: flex;
	align-items: center;
}

.territorio-omnibox__item-info {
	flex: 1;
	min-width: 0;
}

.territorio-omnibox__item-title {
	font-size: 14px;
	font-weight: 500;
	color: #202124;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.territorio-omnibox__item-subtitle {
	font-size: 12px;
	color: #70757a;
	margin-top: 2px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.territorio-omnibox__badge {
	background: #e8f0fe;
	color: #1a73e8;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 11px;
	font-weight: 500;
}

.territorio-omnibox__street-hint {
	font-style: italic;
	color: #5f6368;
}
</style>
