<template>
	<div class="territorio-draw-sector">
		<NcButton class="territorio-draw-sector__button"
			:variant="isDrawing ? 'error' : 'tertiary'"
			:title="isDrawing ? 'Cancelar dibujo' : 'Dibujar un nuevo sector en el mapa'"
			@click="toggleDrawing">
			<template #icon>
				<span aria-hidden="true">{{ isDrawing ? '⏹️' : '✏️' }}</span>
			</template>
			{{ isDrawing ? 'Cancelar Dibujo' : 'Dibujar Sector' }}
		</NcButton>
		<details v-if="!isDrawing" ref="drawingOptions" class="territorio-draw-options">
			<summary aria-label="Más opciones de dibujo" title="Más opciones de dibujo">▾</summary>
			<NcButton class="territorio-draw-area__button"
				variant="tertiary"
				@click="drawCustomArea">
				Dibujar área personalizada
			</NcButton>
		</details>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'

export default {
	name: 'DrawSectorButton',

	components: { NcButton },

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
	align-items: center;
	gap: 2px;
	height: 48px;
	padding: 0 6px;
	position: relative;
	z-index: 1000;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 2px 6px var(--color-box-shadow);
	box-sizing: border-box;
}

.territorio-draw-options {
	position: relative;
}

.territorio-draw-options summary {
	display: flex;
	align-items: center;
	justify-content: center;
	min-width: var(--default-clickable-area);
	height: var(--default-clickable-area);
	border-radius: var(--border-radius-element);
	list-style: none;
	color: var(--color-main-text);
	cursor: pointer;
}

.territorio-draw-options summary:hover {
	background: var(--color-background-hover);
}

.territorio-draw-options summary:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

/* The one option, as a menu of the chip's own surface below it. */
.territorio-draw-options[open] .territorio-draw-area__button {
	position: absolute;
	top: calc(100% + 12px);
	right: -6px;
	white-space: nowrap;
	background: var(--color-main-background);
	box-shadow: 0 2px 6px var(--color-box-shadow);
}
</style>
