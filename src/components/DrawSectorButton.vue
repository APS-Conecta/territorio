<template>
	<div class="territorio-draw-sector">
		<button type="button"
			class="territorio-draw-sector__button"
			:class="{ 'territorio-draw-sector__button--active': isDrawing }"
			:title="isDrawing ? 'Cancelar dibujo de sector' : 'Dibujar un nuevo sector en el mapa'"
			@click="toggleDrawing">
			<span class="territorio-draw-sector__icon" aria-hidden="true">{{ isDrawing ? '⏹️' : '✏️' }}</span>
			<span class="territorio-draw-sector__text">
				{{ isDrawing ? 'Cancelar Dibujo' : 'Dibujar Sector' }}
			</span>
		</button>
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

	emits: ['draw-sector', 'cancel-draw'],

	methods: {
		toggleDrawing() {
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
.territorio-draw-sector {
	position: absolute;
	top: 12px;
	left: 588px;
	z-index: 1000;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.territorio-draw-sector__button {
	display: flex;
	align-items: center;
	height: 48px;
	padding: 0 16px;
	background: #ffffff;
	border: 1px solid #dadce0;
	border-radius: 8px;
	font-size: 14px;
	font-weight: 600;
	color: #1a73e8;
	cursor: pointer;
	box-shadow: 0 2px 6px rgba(60, 64, 67, 0.15), 0 1px 2px rgba(60, 64, 67, 0.1);
	transition: background 0.15s, border-color 0.15s, box-shadow 0.15s, color 0.15s;
}

.territorio-draw-sector__button:hover {
	background: #f8f9fa;
	border-color: #1a73e8;
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
</style>
