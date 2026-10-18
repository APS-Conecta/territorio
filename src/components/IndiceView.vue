<template>
	<IndexView v-bind="$props"
		@select="$emit('select', $event)"
		@update:view="$emit('update:view', $event)"
		@back="$emit('back')" />
</template>

<script>
import IndexView from './IndexView.vue'

export default {
	name: 'IndiceView',
	components: { IndexView },
	props: {
		features: { type: Array, required: true },
		taxonomy: { type: Array, required: true },
		divisions: { type: Array, default: () => [] },
		selectedId: { type: Number, default: null },
		view: { type: Object, required: true },
		filtered: { type: Boolean, default: false },
	},
	emits: ['select', 'update:view', 'back'],
}
</script>
