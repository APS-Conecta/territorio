<template>
	<div class="territorio-taxonomy-drawer" role="dialog" aria-modal="true" aria-label="Administrar Taxonomía y Capas">
		<div class="territorio-taxonomy-drawer__overlay" @click="$emit('close')" />
		<div class="territorio-taxonomy-drawer__panel">
			<div class="territorio-taxonomy-drawer__header">
				<div class="territorio-taxonomy-drawer__title">
					<h3>Taxonomía y Capas</h3>
				</div>
				<NcButton class="territorio-taxonomy-drawer__close"
					variant="tertiary"
					title="Cerrar panel de taxonomía"
					aria-label="Cerrar"
					@click="$emit('close')">
					✕
				</NcButton>
			</div>
			<div class="territorio-taxonomy-drawer__body">
				<CapasPanel :taxonomy="taxonomy"
					:filter="filter"
					:error="error"
					:notice="notice"
					@clear-taxonomy-message="$emit('clear-taxonomy-message')"
					@toggle-category="$emit('toggle-category', $event)"
					@toggle-subcategory="$emit('toggle-subcategory', $event)"
					@reset="$emit('reset')"
					@rename="$emit('rename', $event)"
					@add-category="$emit('add-category', $event)"
					@add-subcategory="$emit('add-subcategory', $event)"
					@remove="$emit('remove', $event)"
					@choose-glyph="$emit('choose-glyph', $event)" />
			</div>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import CapasPanel from './CapasPanel.vue'

export default {
	name: 'TaxonomyDrawer',

	components: { CapasPanel, NcButton },

	props: {
		taxonomy: { type: Array, required: true },
		filter: { type: Object, required: true },
		error: { type: String, default: '' },
		notice: { type: String, default: '' },
	},

	emits: [
		'close',
		'clear-taxonomy-message',
		'toggle-category',
		'toggle-subcategory',
		'reset',
		'rename',
		'add-category',
		'add-subcategory',
		'remove',
		'choose-glyph',
	],
}
</script>

<style scoped>
.territorio-taxonomy-drawer {
	position: fixed;
	inset: 0;
	z-index: 2000;
	display: flex;
	justify-content: flex-end;
}

.territorio-taxonomy-drawer__overlay {
	position: absolute;
	inset: 0;
	background: var(--color-box-shadow);
	animation: fadeIn 0.2s ease;
}

.territorio-taxonomy-drawer__panel {
	position: relative;
	width: 380px;
	max-width: 90vw;
	height: 100%;
	background: var(--color-main-background);
	box-shadow: -4px 0 16px var(--color-box-shadow);
	display: flex;
	flex-direction: column;
	z-index: 1;
	animation: slideLeft 0.25s ease;
}

.territorio-taxonomy-drawer__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
}

.territorio-taxonomy-drawer__title h3 {
	margin: 0;
	font-size: calc(var(--default-font-size) * 1.1);
	font-weight: 600;
	color: var(--color-main-text);
}

.territorio-taxonomy-drawer__body {
	flex: 1;
	overflow-y: auto;
	padding: 12px 16px 32px;
}

@keyframes fadeIn {
	from { opacity: 0; }
	to { opacity: 1; }
}

@keyframes slideLeft {
	from { transform: translateX(100%); }
	to { transform: translateX(0); }
}
</style>
