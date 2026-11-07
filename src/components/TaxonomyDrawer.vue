<template>
	<div class="territorio-taxonomy-drawer" role="dialog" aria-modal="true" aria-label="Administrar Taxonomía y Capas">
		<div class="territorio-taxonomy-drawer__overlay" @click="$emit('close')" />
		<div class="territorio-taxonomy-drawer__panel">
			<div class="territorio-taxonomy-drawer__header">
				<div class="territorio-taxonomy-drawer__title">
					<h3>Taxonomía y Capas</h3>
				</div>
				<button type="button"
					class="territorio-taxonomy-drawer__close"
					title="Cerrar panel de taxonomía"
					aria-label="Cerrar"
					@click="$emit('close')">
					✕
				</button>
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
import CapasPanel from './CapasPanel.vue'

export default {
	name: 'TaxonomyDrawer',

	components: { CapasPanel },

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
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.territorio-taxonomy-drawer__overlay {
	position: absolute;
	inset: 0;
	background: rgba(0, 0, 0, 0.4);
	animation: fadeIn 0.2s ease;
}

.territorio-taxonomy-drawer__panel {
	position: relative;
	width: 380px;
	max-width: 90vw;
	height: 100%;
	background: #ffffff;
	box-shadow: -4px 0 16px rgba(0, 0, 0, 0.2);
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
	border-bottom: 1px solid #ebebeb;
}

.territorio-taxonomy-drawer__title h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	color: #202124;
}

.territorio-taxonomy-drawer__close {
	background: none;
	border: none;
	font-size: 16px;
	color: #5f6368;
	cursor: pointer;
	padding: 6px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
}

.territorio-taxonomy-drawer__close:hover {
	background: #f1f3f4;
	color: #202124;
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
