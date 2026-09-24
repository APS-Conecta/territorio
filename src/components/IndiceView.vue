<template>
	<div class="territorio-indice" data-testid="indice">
		<div class="territorio-indice__head">
			<h2>
				{{ rows.length }} {{ rows.length === 1 ? 'registro' : 'registros' }}
				<span v-if="filtered" class="territorio-indice__note">con los filtros aplicados</span>
			</h2>

			<!--
				Which columns to draw, as one checkbox each. No configuration modal
				and no reordering (#105, out of scope): the whole control is this row,
				where a person can see what it does to the table under it.
			-->
			<div class="territorio-indice__columns">
				<NcCheckboxRadioSwitch v-for="column in COLUMNS"
					:key="column.key"
					type="checkbox"
					:model-value="!view.hidden.has(column.key)"
					@update:model-value="toggleColumn(column.key)">
					{{ column.label }}
				</NcCheckboxRadioSwitch>
			</div>
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

			Drawn only while a column is on: unticking all ten would otherwise leave
			a header with no cells and one blank row per record — `rows.length` is
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
						:aria-sort="sortedBy(column.key)">
						<!--
							A button, not a click handler on the <th>: a header that can
							only be reached with a mouse is a sort half the people using
							this app cannot perform, and the browser gives a button its
							focus ring, its Enter and its Space for nothing.
						-->
						<button type="button" class="territorio-indice__sort" @click="sortBy(column.key)">
							{{ column.label }}
							<span aria-hidden="true">{{ arrow(column.key) }}</span>
						</button>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows"
					:key="row.id"
					:class="{ 'territorio-indice__row--selected': row.id === selectedId }"
					@click="$emit('select', row.id)">
					<!--
						The first SHOWN cell carries the button, so the row is openable
						from the keyboard too. Not the «Nombre» cell: hiding Nombre is a
						first-class feature of this pane, and binding the only focusable
						control to a column a person can take away is how the table
						becomes mouse-only. The click on the <tr> stays, because a
						person reading across a row clicks wherever they are.
					-->
					<td v-for="column in shown" :key="column.key" :data-key="column.key">
						<button v-if="column.key === shown[0].key" type="button" class="territorio-indice__open">
							{{ row.cells[column.key] }}
						</button>
						<template v-else>{{ row.cells[column.key] }}</template>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { COLUMNS, rowsFor, sortRows } from '../indice.js'
import { indexSubcategories } from '../taxonomy.js'

export default {
	name: 'IndiceView',

	components: { NcCheckboxRadioSwitch, NcEmptyContent },

	props: {
		// Already filtered by the caller, exactly like AnalisisView's counts. This
		// view never filters anything itself — that is what keeps the table, the
		// map, the Análisis counts and Exportar describing one set (#105).
		features: { type: Array, required: true },
		taxonomy: { type: Array, required: true },
		// The territorial divisions the app holds, so a stored assignment
		// id can be shown as the name somebody would recognise — the same list the
		// sidebar resolves them through. `divisions` (L1-33), matching
		// taxonomy.js's `divisionsOf` vocabulary.
		divisions: { type: Array, default: () => [] },
		selectedId: { type: Number, default: null },
		// Which columns are hidden and how the rows are ordered, owned by App.vue
		// for the reason the filter is: this pane is rebuilt on every switch, and
		// state kept in here would not survive one. `hidden` and not `shown` is
		// filters.js's rule for its reason — a column added to COLUMNS is absent
		// from the set, so it appears on its own.
		view: { type: Object, required: true },
		// Whether anything is narrowing the set, so the heading and the empty state
		// can say which fact they are reporting.
		filtered: { type: Boolean, default: false },
	},

	emits: ['select', 'update:view'],

	data() {
		return { COLUMNS }
	},

	computed: {
		shown() {
			return COLUMNS.filter((column) => !this.view.hidden.has(column.key))
		},

		/**
		 * The taxonomy read the way every other surface reads it. Deriving it here
		 * is not a second source of truth: the taxonomy prop is the source and
		 * `indexSubcategories` is a pure reading of it.
		 */
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
		/** First click on a header sorts ascending; clicking the same one flips it. */
		sortBy(key) {
			// The immutable pattern — new object, always, never a mutation — is
			// the VIEW-state half of the two-way convention named in CONTEXT.md
			// (L1-30); the draft box is the other half and lives in FeatureDetail.
			this.$emit('update:view', {
				...this.view,
				key,
				ascending: this.view.key === key ? !this.view.ascending : true,
			})
		},

		toggleColumn(key) {
			// A new Set rather than a mutation, and a new object around it: the
			// state belongs to App.vue, and handing back the same object it gave us
			// is how a change arrives that nothing re-renders for.
			const hidden = new Set(this.view.hidden)
			if (hidden.has(key)) {
				hidden.delete(key)
			} else {
				hidden.add(key)
			}

			this.$emit('update:view', { ...this.view, hidden })
		},

		/** What `aria-sort` takes: the sorted column says how, the rest say nothing. */
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
}

.territorio-indice__head h2 {
	font-size: 1.2em;
	margin-bottom: 8px;
}

.territorio-indice__note {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-weight: normal;
	margin-inline-start: 6px;
}

.territorio-indice__columns {
	display: flex;
	flex-wrap: wrap;
	gap: 0 16px;
	margin-bottom: 12px;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
}

.territorio-indice__table {
	border-collapse: collapse;
	width: 100%;
	font-size: 0.9em;
}

/*
 * Stuck to the top of the scrolling pane: a catastro is hundreds of rows, and a
 * header that scrolls away turns the middle of the table into nine unlabelled
 * columns.
 */
.territorio-indice__table th {
	position: sticky;
	top: 0;
	z-index: 1;
	background: var(--color-main-background);
	text-align: start;
	white-space: nowrap;
	border-bottom: 2px solid var(--color-border-dark, var(--color-border));
}

.territorio-indice__table td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	max-width: 22em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.territorio-indice__table tbody tr:hover {
	background: var(--color-background-hover);
	cursor: pointer;
}

.territorio-indice__row--selected {
	background: var(--color-primary-element-light, var(--color-background-dark));
}

.territorio-indice__sort {
	background: none;
	border: none;
	padding: 6px 8px;
	font: inherit;
	font-weight: bold;
	color: inherit;
	cursor: pointer;
}

.territorio-indice__open {
	background: none;
	border: none;
	padding: 0;
	font: inherit;
	color: inherit;
	cursor: pointer;
	text-align: start;
}
</style>
