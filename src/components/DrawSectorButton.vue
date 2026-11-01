<template>
	<div class="territorio-draw-sector">
		<button type="button"
			class="territorio-draw-sector__button"
			:class="{ 'territorio-draw-sector__button--active': isDrawing }"
			:title="isDrawing ? 'Cancelar dibujo' : 'Dibujar un nuevo sector en el mapa'"
			@click="toggleDrawing">
			<span class="territorio-draw-sector__icon" aria-hidden="true">{{ isDrawing ? '⏹️' : '✏️' }}</span>
			<span class="territorio-draw-sector__text">
				{{ isDrawing ? 'Cancelar Dibujo' : 'Dibujar Sector' }}
			</span>
		</button>
		<details v-if="!isDrawing" ref="drawingOptions" class="territorio-draw-options">
			<summary aria-label="Más opciones de dibujo" title="Más opciones de dibujo">▾</summary>
			<button type="button"
				class="territorio-draw-area__button"
				@click="drawCustomArea">
				Dibujar área personalizada
			</button>
		</details>
	</div>
</template>

<script>
export default {
	name: 'DrawSectorButton',

	props: {
		isDrawing: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['draw-sector', 'draw-area', 'cancel-draw'],

	methods: {
		drawCustomArea() {
			this.$refs.drawingOptions.open = false
			this.$emit('draw-area')
		},

		toggleDrawing() {
			if (this.$refs.drawingOptions) this.$refs.drawingOptions.open = false
			if (this.isDrawing) {
				this.$emit('cancel-draw')
			} else {
				this.$emit('draw-sector')
			}
		},
	},
}
</script>

<style scoped>
/* Placed by App.vue's topbar; see .territorio-omnibox. */
.territorio-draw-sector {
	display: flex;
	gap: 6px;
	position: relative;
	z-index: 1000;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.territorio-draw-sector__button,
.territorio-draw-area__button,
.territorio-draw-options summary {
	display: flex;
	align-items: center;
	height: 48px;
	padding: 0 16px;
	background: var(--color-main-background, #fff);
	border: 1px solid #dadce0;
	border-radius: 8px;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-primary-element, #1a73e8);
	cursor: pointer;
	box-shadow: 0 2px 6px rgba(60, 64, 67, 0.15), 0 1px 2px rgba(60, 64, 67, 0.1);
	transition: background 0.15s, border-color 0.15s, box-shadow 0.15s, color 0.15s;
}

.territorio-draw-sector__button:hover {
	background: #f8f9fa;
	border-color: var(--color-primary-element, #1a73e8);
}

.territorio-draw-sector__button--active {
	background: #ea4335;
	border-color: #d93025;
	color: #ffffff;
	box-shadow: 0 2px 6px rgba(234, 67, 53, 0.35);
}

.territorio-draw-sector__button--active:hover {
	background: #d93025;
}

.territorio-draw-sector__icon {
	margin-right: 8px;
	font-size: 16px;
}
.territorio-draw-options {
	position: relative;
}

.territorio-draw-options summary {
	list-style: none;
	padding: 0 12px;
}

.territorio-draw-options[open] .territorio-draw-area__button {
	position: absolute;
	top: 54px;
	right: 0;
	white-space: nowrap;
}

.territorio-draw-options summary:focus-visible,
.territorio-draw-area__button:focus-visible,
.territorio-draw-sector__button:focus-visible {
	outline: 3px solid var(--color-primary-element, #1a73e8);
	outline-offset: 2px;
}
</style>
