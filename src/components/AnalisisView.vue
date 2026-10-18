<template>
	<AnalysisView v-bind="$props" @back="$emit('back')" />
</template>

<script>
import AnalysisView from './AnalysisView.vue'

export default {
	name: 'AnalisisView',
	components: { AnalysisView },
	props: {
		counts: { type: Object, required: true },
		filtered: { type: Boolean, default: false },
	},
	emits: ['back'],
}
</script>
