<template>
	<NcAppNavigation>
		<template #list>
			<div class="territorio-capas">
				<div class="territorio-capas__header">
					<h2>Capas</h2>
					<NcButton v-if="anythingHidden" variant="tertiary" @click="$emit('reset')">
						Ver todo
					</NcButton>
				</div>

				<!--
					Generated from the taxonomy, never from a hard-coded list: adding a
					Subcategory row is a data change, and it has to appear here without
					anyone touching this file.
				-->
				<div v-for="category in taxonomy" :key="category.id" class="territorio-capas__group">
					<NcCheckboxRadioSwitch :model-value="stateOf(category) !== 'none'"
						:indeterminate="stateOf(category) === 'some'"
						@update:model-value="$emit('toggle-category', category)">
						<span class="territorio-capas__swatch" :style="{ background: category.color }" />
						{{ category.label }}
					</NcCheckboxRadioSwitch>

					<div class="territorio-capas__children">
						<NcCheckboxRadioSwitch v-for="subcategory in category.subcategories"
							:key="subcategory.id"
							:model-value="isVisible(subcategory.id)"
							@update:model-value="$emit('toggle-subcategory', subcategory.id)">
							{{ subcategory.label }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>
			</div>
		</template>
	</NcAppNavigation>
</template>

<script>
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { categoryState, isSubcategoryVisible } from '../filters.js'

export default {
	name: 'CapasPanel',

	components: { NcAppNavigation, NcButton, NcCheckboxRadioSwitch },

	props: {
		taxonomy: { type: Array, required: true },
		filter: { type: Object, required: true },
	},

	emits: ['toggle-category', 'toggle-subcategory', 'reset'],

	computed: {
		anythingHidden() {
			return this.filter.hidden.size > 0
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
	padding: 0 12px 12px;
}

.territorio-capas__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.territorio-capas__header h2 {
	font-size: 1.1em;
	margin: 8px 0;
}

.territorio-capas__group {
	margin-bottom: 8px;
}

.territorio-capas__children {
	padding-inline-start: 22px;
}

.territorio-capas__swatch {
	display: inline-block;
	width: 12px;
	height: 12px;
	border-radius: 50%;
	margin-inline-end: 6px;
	vertical-align: baseline;
}
</style>
