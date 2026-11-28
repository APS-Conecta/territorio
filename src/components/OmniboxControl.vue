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
				:aria-controls="showDropdown ? listId : null"
				:aria-activedescendant="activeOptionId"
				@focus="onFocus"
				@blur="onBlur"
				@keydown.down.prevent="navigateResults(1)"
				@keydown.up.prevent="navigateResults(-1)"
				@keydown.enter.prevent="selectActiveResult"
				@keydown.esc="clearQuery">

			<NcButton v-if="query.trim() !== ''"
				class="territorio-omnibox__clear"
				variant="tertiary"
				title="Limpiar búsqueda"
				aria-label="Limpiar búsqueda"
				@click="clearQuery">
				<template #icon>
					<span aria-hidden="true">✕</span>
				</template>
			</NcButton>
		</div>

		<!-- View Switcher Tabs -->
		<div class="territorio-omnibox__tabs" role="tablist">
			<NcButton role="tab"
				class="territorio-omnibox__tab"
				:class="{ 'territorio-omnibox__tab--active': pane === 'mapa' }"
				:variant="pane === 'mapa' ? 'secondary' : 'tertiary'"
				size="small"
				:aria-selected="pane === 'mapa' ? 'true' : 'false'"
				@click="$emit('change-pane', 'mapa')">
				Mapa
			</NcButton>
			<NcButton role="tab"
				class="territorio-omnibox__tab"
				:class="{ 'territorio-omnibox__tab--active': pane === 'indice' }"
				:variant="pane === 'indice' ? 'secondary' : 'tertiary'"
				size="small"
				:aria-selected="pane === 'indice' ? 'true' : 'false'"
				@click="$emit('change-pane', 'indice')">
				Índice
			</NcButton>
			<NcButton role="tab"
				class="territorio-omnibox__tab"
				:class="{ 'territorio-omnibox__tab--active': pane === 'analisis' }"
				:variant="pane === 'analisis' ? 'secondary' : 'tertiary'"
				size="small"
				:aria-selected="pane === 'analisis' ? 'true' : 'false'"
				@click="$emit('change-pane', 'analisis')">
				Análisis
			</NcButton>
			<NcButton role="tab"
				class="territorio-omnibox__tab"
				:class="{ 'territorio-omnibox__tab--active': pane === 'duplicados' }"
				:variant="pane === 'duplicados' ? 'secondary' : 'tertiary'"
				size="small"
				:aria-selected="pane === 'duplicados' ? 'true' : 'false'"
				@click="$emit('change-pane', 'duplicados')">
				Posibles duplicados
			</NcButton>
		</div>

		<!-- Said when the dropdown has nothing to offer: loading, failed, no streets imported, no such street. -->
		<p class="territorio-omnibox__status" role="status">{{ status }}</p>

		<!-- Unified Autocomplete Results Dropdown -->
		<div v-if="showDropdown" :id="listId" class="territorio-omnibox__dropdown" role="listbox" aria-label="Resultados de búsqueda">
			<div v-for="(result, index) in results"
				:key="result.kind + '-' + (result.id ?? result.name) + '-' + index"
				:id="`${listId}-option-${index}`"
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
							<span class="territorio-omnibox__street-hint">{{ streetDetail(result) }}</span>
						</template>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'

import { matchOmnibox } from '../assets.js'
import { hintFor, placeDetail, searchStatus } from '../streets.js'

export default {
	name: 'OmniboxControl',

	components: { NcButton },

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
		/**
		 * The comuna's Unidades Vecinales, whatever Capas is hiding: what tells the
		 * rows of one name apart («Pasaje Dos» is sixteen rows), so it must not
		 * change with the filter.
		 */
		unidades: {
			type: Array,
			default: () => [],
		},
		/** Whether the streets are on their way, failed, or have arrived (streets.js, searchStatus). */
		streetsLoading: { type: Boolean, default: false },
		streetsFailed: { type: Boolean, default: false },
		streetsLoaded: { type: Boolean, default: false },
	},

	emits: ['update:modelValue', 'change-pane', 'select-asset', 'select-street', 'clear'],

	data() {
		return {
			isFocused: false,
			activeIndex: -1,
			/** The id of the listbox this combobox owns: what aria-controls names, and every option's id builds on. */
			listId: 'territorio-omnibox-list',
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
				// Choosing a result drops `isFocused` while the input keeps the real
				// focus, so a second search typed without leaving the box would find
				// results and show none. Typing is the proof it is focused.
				this.isFocused = true
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

		/** What to say when the list is empty and a street is why. */
		status() {
			if (this.results.length > 0 || this.query.trim() === '') {
				return ''
			}

			return searchStatus({
				loading: this.streetsLoading,
				failed: this.streetsFailed,
				places: this.streetsLoaded ? this.streets : null,
				query: this.query,
				matches: [],
			})
		},

		showDropdown() {
			return this.isFocused && this.results.length > 0
		},

		/**
		 * The option the arrows have highlighted, for a screen reader to announce
		 * (#213): focus stays in the input, so aria-activedescendant is the only
		 * pointer to the row Enter would take. Nothing highlighted, or the list
		 * closed (blur keeps activeIndex) — no attribute at all, never a dangling id.
		 */
		activeOptionId() {
			if (!this.showDropdown || this.activeIndex < 0) {
				return null
			}
			return `${this.listId}-option-${this.activeIndex}`
		},
	},

	methods: {
		/** «Villa Los Quillayes · 3 tramos»: where a street is and how much of it there is. */
		streetDetail(street) {
			return placeDetail(hintFor(street, this.unidades), street.count)
		},

		onBlur() {
			// Short timeout so mousedown can fire. Cleared by the next focus, or an
			// early blur would hide the list a person has just come back to.
			clearTimeout(this.blurTimer)
			this.blurTimer = setTimeout(() => {
				this.isFocused = false
			}, 200)
		},

		onFocus() {
			clearTimeout(this.blurTimer)
			this.isFocused = true
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
/*
 * Placed by App.vue's topbar, not by itself: it used to be `absolute` at a fixed
 * `top`/`left`, which stacked on the topbar's own offset and sat the control
 * where the layers menu and «Dibujar Sector» were also positioned by hand.
 */
.territorio-omnibox {
	position: relative;
	flex: 0 1 392px;
	z-index: 1000;
	width: 392px;
	max-width: 100%;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: var(--border-radius-large);
	box-shadow: 0 2px 6px var(--color-box-shadow);
	box-sizing: border-box;
}

.territorio-omnibox__bar {
	display: flex;
	align-items: center;
	height: 48px;
	padding: 0 6px 0 12px;
	border-bottom: 1px solid var(--color-border);
}

.territorio-omnibox__icon {
	display: flex;
	align-items: center;
	color: var(--color-text-maxcontrast);
	margin-inline-end: 12px;
}

.territorio-omnibox__input {
	flex: 1;
	border: none;
	outline: none;
	font-size: var(--default-font-size);
	color: var(--color-main-text);
	background: transparent;
	padding: 0;
}

.territorio-omnibox__input::placeholder {
	color: var(--color-text-maxcontrast);
}

.territorio-omnibox__tabs {
	display: flex;
	align-items: center;
	padding: 4px 8px 6px;
	gap: 4px;
	background: var(--color-background-hover);
	border-bottom-left-radius: var(--border-radius-large);
	border-bottom-right-radius: var(--border-radius-large);
}

/* Four tabs in the box's 392px: the theme's small text and one grid step of padding. */
.territorio-omnibox__tabs .territorio-omnibox__tab {
	--button-padding-default: var(--default-grid-baseline);
	font-size: var(--font-size-small);
}

.territorio-omnibox__dropdown {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	margin-top: 4px;
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 12px var(--color-box-shadow);
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
	border-bottom: 1px solid var(--color-border);
	transition: background 0.1s;
}

.territorio-omnibox__item:last-child {
	border-bottom: none;
}

.territorio-omnibox__item:hover,
.territorio-omnibox__item--highlighted {
	background: var(--color-background-hover);
}

.territorio-omnibox__item-icon {
	margin-inline-end: 12px;
	font-size: 1.1rem;
	display: flex;
	align-items: center;
}

.territorio-omnibox__item-info {
	flex: 1;
	min-width: 0;
}

.territorio-omnibox__item-title {
	font-weight: 500;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.territorio-omnibox__item-subtitle {
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.territorio-omnibox__badge {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	padding: 1px 6px;
	border-radius: var(--border-radius-small);
	font-size: 0.75rem;
	font-weight: 500;
}

.territorio-omnibox__status {
	margin: 0;
	padding: 8px 16px;
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
}

.territorio-omnibox__status:empty {
	display: none;
}

.territorio-omnibox__street-hint {
	font-style: italic;
}
</style>
