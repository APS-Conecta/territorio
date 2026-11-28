<template>
	<div class="territorio-indice" data-testid="indice">
		<div class="territorio-indice__head">
			<NcButton class="territorio-indice__back-btn"
				variant="tertiary"
				title="Volver al mapa"
				@click="$emit('back')">
				← Volver al mapa
			</NcButton>
			<h2>
				{{ rows.length }} {{ rows.length === 1 ? 'registro' : 'registros' }}
				<span v-if="filtered" class="territorio-indice__note">con los filtros aplicados</span>
			</h2>

			<!--
				Which columns to draw, as one checkbox each — folded behind a native
				<details> whose summary counts what is on, so eleven checkboxes stop
				being the first thing the pane shows. No configuration modal and no
				reordering (#105, out of scope): the whole control is still this one
				inline disclosure, where a person can see what it does to the table
				under it.
			-->
			<details class="territorio-indice__columns-details">
				<summary class="territorio-indice__columns-toggle">
					Columnas
					<span class="territorio-indice__columns-count">
						{{ shown.length }}/{{ COLUMNS.length }}
					</span>
				</summary>
				<div class="territorio-indice__columns">
					<NcCheckboxRadioSwitch v-for="column in COLUMNS"
						:key="column.key"
						type="checkbox"
						:model-value="!view.hidden.has(column.key)"
						@update:model-value="toggleColumn(column.key)">
						{{ column.label }}
					</NcCheckboxRadioSwitch>
				</div>
			</details>
		</div>

		<!--
			The same two empty states the Datos list draws, for the same reason:
			"nothing matched" is a different fact from "there is nothing here", and
			showing the second when the first is true reads as data loss.
		-->
		<NcEmptyContent v-if="rows.length === 0"
			:name="filtered ? 'Nada coincide con los filtros' : 'Todavía no hay registros'"
			:description="filtered
				? 'Ningún registro coincide con las capas activas ni con la búsqueda.'
				: 'Agregue el primer lugar del territorio desde la lista de Datos.'">
			<template #icon>
				<svg viewBox="0 0 24 24" width="64" height="64" aria-hidden="true">
					<path fill="currentColor" d="M3 5h18v2H3V5Zm0 6h18v2H3v-2Zm0 6h18v2H3v-2Z" />
				</svg>
			</template>
		</NcEmptyContent>

		<!--
			A native <table>: @nextcloud/vue ships no table component, and the
			browser already draws rows, columns and a header that a screen reader
			announces as one. `aria-sort` on the header cell is how the sort is
			announced — it is the platform's own attribute for exactly this.

			Drawn only while a column is on: unticking all eleven would otherwise
			leave a header with no cells and one blank row per record — `rows.length` is
			still what it was, so the empty state above does not catch it. The
			checkbox row stays, which is the way back.
		-->
		<table v-else-if="shown.length > 0" class="territorio-indice__table" data-testid="indice-table">
			<thead>
				<tr>
					<th v-for="column in shown"
						:key="column.key"
						:data-key="column.key"
						scope="col"
						:class="{ 'territorio-indice__cell--first': column.key === shown[0].key }"
						:aria-sort="sortedBy(column.key)">
						<!--
							A button, not a click handler on the <th>: a header that can
							only be reached with a mouse is a sort half the people using
							this app cannot perform, and the browser gives a button its
							focus ring, its Enter and its Space for nothing.
						-->
						<NcButton class="territorio-indice__sort"
							variant="tertiary-no-background"
							alignment="start"
							size="small"
							wide
							@click="sortBy(column.key)">
							{{ column.label }}
							<span class="territorio-indice__arrow" aria-hidden="true">{{ arrow(column.key) }}</span>
						</NcButton>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows"
					:key="row.id"
					:class="{ 'territorio-indice__row--selected': row.id === selectedId }"
					@click="$emit('select', row.id)">
					<!-- The first SHOWN cell carries the button, so the row opens from the
					     keyboard even when «Nombre» is hidden; the click on the <tr> stays. -->
					<td v-for="column in shown"
						:key="column.key"
						:data-key="column.key"
						:title="row.cells[column.key] || null"
						:class="{ 'territorio-indice__cell--first': column.key === shown[0].key }">
						<NcButton v-if="column.key === shown[0].key"
							class="territorio-indice__open"
							variant="tertiary-no-background"
							alignment="start"
							size="small"
							:aria-label="`Abrir registro «${row.cells[column.key]}»`">
							{{ row.cells[column.key] }}
						</NcButton>
						<template v-else>
							{{ row.cells[column.key] }}
						</template>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { COLUMNS, rowsFor, sortRows } from '../indice.js'
import { indexSubcategories } from '../taxonomy.js'

export default {
	name: 'IndexView',

	components: { NcButton, NcCheckboxRadioSwitch, NcEmptyContent },

	props: {
		features: { type: Array, required: true },
		taxonomy: { type: Array, required: true },
		divisions: { type: Array, default: () => [] },
		selectedId: { type: Number, default: null },
		view: { type: Object, required: true },
		filtered: { type: Boolean, default: false },
	},

	emits: ['select', 'update:view', 'back'],

	data() {
		return { COLUMNS }
	},

	computed: {
		shown() {
			return COLUMNS.filter((column) => !this.view.hidden.has(column.key))
		},

		subcategories() {
			return indexSubcategories(this.taxonomy)
		},

		rows() {
			return sortRows(
				rowsFor(this.features, this.subcategories, this.divisions),
				this.view.key,
				this.view.ascending,
			)
		},
	},

	methods: {
		sortBy(key) {
			this.$emit('update:view', {
				...this.view,
				key,
				ascending: this.view.key === key ? !this.view.ascending : true,
			})
		},

		toggleColumn(key) {
			const hidden = new Set(this.view.hidden)
			if (hidden.has(key)) {
				hidden.delete(key)
			} else {
				hidden.add(key)
			}

			this.$emit('update:view', { ...this.view, hidden })
		},

		sortedBy(key) {
			if (this.view.key !== key) {
				return 'none'
			}

			return this.view.ascending ? 'ascending' : 'descending'
		},

		arrow(key) {
			if (this.view.key !== key) {
				return ''
			}

			return this.view.ascending ? '▲' : '▼'
		},
	},
}
</script>

<style scoped>
.territorio-indice {
	padding: 16px 24px 32px;
	overflow: auto;
	height: 100%;
	background: var(--color-main-background);
}

.territorio-indice__back-btn {
	margin-bottom: 12px;
}

.territorio-indice__head h2 {
	font-size: 1.3em;
	margin-bottom: 12px;
	color: var(--color-main-text);
}

.territorio-indice__note {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-weight: normal;
	margin-inline-start: 6px;
}

.territorio-indice__columns-details {
	margin-bottom: 16px;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
}

.territorio-indice__columns-toggle {
	cursor: pointer;
	font-weight: 600;
	font-size: var(--font-size-small);
	color: var(--color-text-maxcontrast);
}

.territorio-indice__columns-count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	font-size: 0.85em;
	margin-inline-start: 6px;
}

.territorio-indice__columns {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 16px;
	margin-top: 8px;
}

.territorio-indice__table {
	border-collapse: collapse;
	width: 100%;
	font-size: var(--default-font-size);
}

.territorio-indice__table th {
	position: sticky;
	top: 0;
	z-index: 1;
	background: var(--color-main-background);
	text-align: start;
	white-space: nowrap;
	border-bottom: 2px solid var(--color-border-dark);
	color: var(--color-text-maxcontrast);
}

.territorio-indice__table td {
	padding: 8px 10px;
	border-bottom: 1px solid var(--color-border);
	max-width: 22em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	color: var(--color-main-text);
}

.territorio-indice__cell--first {
	position: sticky;
	left: 0;
	background: var(--color-main-background);
}

.territorio-indice__table th.territorio-indice__cell--first {
	z-index: 2;
}

.territorio-indice__table tbody tr:hover .territorio-indice__cell--first {
	background: var(--color-background-hover);
}

.territorio-indice__row--selected .territorio-indice__cell--first {
	background: var(--color-primary-element-light);
}

.territorio-indice__table tbody tr:hover {
	background: var(--color-background-hover);
	cursor: pointer;
}

.territorio-indice__row--selected {
	background: var(--color-primary-element-light);
}

/* The record's name, in the theme's primary colour: it is the way into the record. */
.territorio-indice__open {
	color: var(--color-primary-element);
}
</style>
