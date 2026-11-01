<template>
	<section class="territorio-ods-review" aria-label="Revisión de planilla ODS">
		<h3>Revisar antes de importar</h3>
		<p>No se ha guardado nada. Seleccione las filas que desea importar. Las celdas opcionales vacías conservan los datos existentes.</p>
		<div class="territorio-ods-review__summary" aria-live="polite">
			<span>{{ totals.new }} nuevos</span><span>{{ totals.update }} actualizaciones</span>
			<span>{{ totals.invalid }} con errores</span><span>{{ totals.candidates }} posibles duplicados</span>
		</div>
		<div class="territorio-ods-review__actions">
			<button type="button" :disabled="disabled" @click="selectSafe">Seleccionar válidos sin posibles duplicados</button>
			<button type="button" :disabled="disabled" @click="change([])">Omitir todos</button>
		</div>
		<div class="territorio-ods-review__rows">
			<article v-for="row in preview.rows" :key="row.row" class="territorio-ods-review__row">
				<label class="territorio-ods-review__choice">
					<input type="checkbox" :checked="!!decision(row.row)" :disabled="disabled || row.status === 'invalid'" @change="toggle(row, $event.target.checked)">
					<strong>Fila {{ row.row }} · {{ row.name || 'Sin nombre' }}</strong>
					<span>{{ statusLabel(row.status) }}</span>
				</label>
				<p v-for="error in row.errors" :key="error" class="territorio-ods-review__error">{{ error }}</p>
				<div v-if="row.status === 'invalid'" class="territorio-ods-review__classification">
					<label :for="`ods-classification-${row.row}`">Corregir clasificación de fila {{ row.row }}</label>
					<select :id="`ods-classification-${row.row}`" v-model="corrections[row.row]" :disabled="disabled">
						<option value="">Elegir categoría y subcategoría</option>
						<option v-for="choice in preview.classifications" :key="choice.id" :value="choice.id">{{ choice.label }}</option>
					</select>
					<button type="button" :disabled="disabled || !corrections[row.row]" @click="$emit('correct-classification', { row: row.row, subcategoryId: Number(corrections[row.row]) })">Aplicar clasificación</button>
				</div>
				<div v-if="row.candidates.length" class="territorio-ods-review__warning">
					<strong>Posibles duplicados — compruebe antes de importar</strong>
					<ul><li v-for="candidate in row.candidates" :key="candidate">{{ candidate }}</li></ul>
					<label v-if="decision(row.row)">
						<input type="checkbox" :checked="decision(row.row).confirmDuplicates" :disabled="disabled" @change="updateDecision(row.row, { confirmDuplicates: $event.target.checked })">
						Revisé las coincidencias y quiero importar esta fila como {{ row.status === 'new' ? 'un activo distinto' : 'una actualización del ID indicado' }}.
					</label>
				</div>
				<details v-if="row.status !== 'invalid'">
					<summary>{{ row.status === 'update' ? 'Ver cambios y campos opcionales' : 'Ver información a importar' }}</summary>
					<dl class="territorio-ods-review__changes">
						<template v-for="change in row.changes" :key="change.field">
							<dt>{{ fieldLabel(change.field) }}</dt>
							<dd><span v-if="row.status === 'update'">{{ display(change.from) }} → </span>{{ display(change.to) }}</dd>
						</template>
					</dl>
					<fieldset v-if="row.status === 'update' && decision(row.row)">
						<legend>Borrar información existente (opcional)</legend>
						<label v-for="field in preview.clearable" :key="field" class="territorio-ods-review__clear">
							<input type="checkbox" :checked="decision(row.row).clear.includes(field)" :disabled="disabled" @change="clearField(row.row, field, $event.target.checked)">
							{{ fieldLabel(field) }}
						</label>
					</fieldset>
				</details>
			</article>
		</div>
		<p aria-live="polite"><strong>{{ selectedCounts.new }} por añadir · {{ selectedCounts.update }} por actualizar · {{ preview.rows.length - modelValue.length }} por omitir</strong></p>
		<p v-if="needsReview" class="territorio-ods-review__warning">Confirme la revisión de los posibles duplicados seleccionados antes de importar.</p>
	</section>
</template>

<script>
const LABELS = { name: 'Nombre', subcategoryId: 'Clasificación', geometry: 'Coordenadas', address: 'Dirección', phone: 'Teléfono', email: 'Correo', contactPerson: 'Persona de contacto', contactRole: 'Cargo', openingHours: 'Horario', notes: 'Notas', sourceRef: 'Fuente', locationQuality: 'Ubicación' }

export default {
	name: 'OdsReview',
	props: {
		preview: { type: Object, required: true },
		modelValue: { type: Array, required: true },
		disabled: { type: Boolean, default: false },
	},
	emits: ['update:modelValue', 'correct-classification'],
	data() { return { corrections: {} } },
	computed: {
		totals() {
			return this.preview.rows.reduce((counts, row) => {
				counts[row.status]++
				if (row.candidates.length) counts.candidates++
				return counts
			}, { new: 0, update: 0, invalid: 0, candidates: 0 })
		},
		selectedCounts() {
			return this.preview.rows.filter(row => this.decision(row.row)).reduce((counts, row) => {
				counts[row.status]++
				return counts
			}, { new: 0, update: 0 })
		},
		needsReview() {
			return this.preview.rows.some(row => row.candidates.length && this.decision(row.row) && !this.decision(row.row).confirmDuplicates)
		},
	},
	methods: {
		decision(number) { return this.modelValue.find(value => value.row === number) },
		change(value) { this.$emit('update:modelValue', value) },
		toggle(row, checked) {
			this.change(checked ? [...this.modelValue, { row: row.row, clear: [], confirmDuplicates: false }] : this.modelValue.filter(value => value.row !== row.row))
		},
		selectSafe() {
			this.change(this.preview.rows.filter(row => row.status !== 'invalid' && !row.candidates.length).map(row => ({ row: row.row, clear: [], confirmDuplicates: false })))
		},
		updateDecision(number, updates) {
			this.change(this.modelValue.map(value => value.row === number ? { ...value, ...updates } : value))
		},
		clearField(number, field, checked) {
			const fields = this.decision(number).clear
			this.updateDecision(number, { clear: checked ? [...fields, field] : fields.filter(value => value !== field) })
		},
		fieldLabel(field) { return LABELS[field] || field },
		statusLabel(status) { return { new: 'Nuevo', update: 'Actualización', invalid: 'Requiere corrección' }[status] },
		display(value) {
			if (value === null || value === '') return 'Sin información'
			if (typeof value === 'object') return value.coordinates?.join(', ') || JSON.stringify(value)
			return value
		},
	},
}
</script>

<style scoped>
.territorio-ods-review { margin-top: 20px; }
.territorio-ods-review__classification { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 12px 0; }
.territorio-ods-review__classification select { max-width: 100%; }
.territorio-ods-review__summary, .territorio-ods-review__actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; }
.territorio-ods-review__summary span { padding: 4px 10px; border-radius: 16px; background: var(--color-background-dark); }
.territorio-ods-review__rows { max-height: 45vh; overflow-y: auto; }
.territorio-ods-review__row { border: 1px solid var(--color-border); border-radius: 8px; padding: 12px; margin-bottom: 8px; }
.territorio-ods-review__choice { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; min-height: 44px; }
.territorio-ods-review__choice strong { flex: 1; overflow-wrap: anywhere; }
.territorio-ods-review__error { color: var(--color-error-text, var(--color-text)); }
.territorio-ods-review__warning { padding: 8px; border-inline-start: 3px solid var(--color-warning); margin: 8px 0; }
.territorio-ods-review__warning ul { padding-inline-start: 20px; }
.territorio-ods-review__changes { display: grid; grid-template-columns: minmax(100px, 1fr) 2fr; gap: 4px 12px; margin: 12px 0; }
.territorio-ods-review__changes dd { margin: 0; overflow-wrap: anywhere; white-space: pre-wrap; }
.territorio-ods-review__clear { display: inline-flex; gap: 4px; align-items: center; min-height: 44px; margin-inline-end: 12px; }
.territorio-ods-review summary { cursor: pointer; padding: 10px 0; }
</style>
