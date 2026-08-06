<template>
	<!--
		Lives inside the Datos column, not in its own navigation pane. A fourth
		column takes a fixed 300px from a screen that already has to fit a record
		list, a map and a sidebar — and the map, which is the point of the app,
		ends up the narrowest of them.

		Collapsing is a native <details>: no open/closed state to hold, no
		aria-expanded to keep in sync, and it works before the JS loads.
	-->
	<details class="territorio-capas" open>
		<summary class="territorio-capas__summary">
			Capas
			<span v-if="anythingHidden" class="territorio-capas__badge">
				{{ filter.hidden.size }} ocultas
			</span>
		</summary>

		<div class="territorio-capas__body">
			<NcButton v-if="anythingHidden"
				variant="tertiary"
				class="territorio-capas__reset"
				@click="$emit('reset')">
				Ver todo
			</NcButton>

			<!--
				Generated from the taxonomy, never from a hard-coded list: adding a
				Subcategory row is a data change and has to appear here without
				anyone touching this file.
			-->
			<details v-for="category in taxonomy" :key="category.id" class="territorio-capas__category">
				<summary class="territorio-capas__category-summary">
					<!-- Stops the click reaching <summary>, so ticking the box filters
					     instead of expanding the branch. -->
					<span class="territorio-capas__check" @click.stop>
						<NcCheckboxRadioSwitch :model-value="stateOf(category) !== 'none'"
							:indeterminate="stateOf(category) === 'some'"
							@update:model-value="$emit('toggle-category', category)" />
					</span>
					<span class="territorio-capas__swatch" :style="{ background: category.color }" />
					<span class="territorio-capas__name">{{ category.label }}</span>
				</summary>

				<div class="territorio-capas__children">
					<NcCheckboxRadioSwitch v-for="subcategory in category.subcategories"
						:key="subcategory.id"
						:model-value="isVisible(subcategory.id)"
						@update:model-value="$emit('toggle-subcategory', subcategory.id)">
						{{ subcategory.label }}
					</NcCheckboxRadioSwitch>
				</div>
			</details>
		</div>
	</details>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { categoryState, isFiltered, isSubcategoryVisible } from '../filters.js'

export default {
	name: 'CapasPanel',

	components: { NcButton, NcCheckboxRadioSwitch },

	props: {
		taxonomy: { type: Array, required: true },
		filter: { type: Object, required: true },
	},

	emits: ['toggle-category', 'toggle-subcategory', 'reset'],

	computed: {
		anythingHidden() {
			return isFiltered(this.filter)
		},
	},

	methods: {
		stateOf(category) {
			return categoryState(this.filter, category)
		},

		isVisible(subcategoryId) {
			return isSubcategoryVisible(this.filter, subcategoryId)
		},
	},
}
</script>

<style scoped>
.territorio-capas {
	border-bottom: 1px solid var(--color-border);
	flex: 0 0 auto;
	max-height: 45%;
	overflow-y: auto;
}

.territorio-capas__summary {
	cursor: pointer;
	padding: 10px 12px;
	font-weight: bold;
	position: sticky;
	top: 0;
	background: var(--color-main-background);
	z-index: 1;
}

.territorio-capas__badge {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin-inline-start: 6px;
}

.territorio-capas__body {
	padding: 0 12px 8px;
}

.territorio-capas__reset {
	margin-bottom: 4px;
}

.territorio-capas__category-summary {
	display: flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
	padding: 2px 0;
}

.territorio-capas__check {
	display: inline-flex;
}

.territorio-capas__swatch {
	display: inline-block;
	width: 12px;
	height: 12px;
	border-radius: 50%;
	flex: 0 0 auto;
}

.territorio-capas__name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.territorio-capas__children {
	padding-inline-start: 34px;
}
</style>
