<template>
	<section class="territorio-picker" data-testid="boundary-picker">
		<h4>Límite</h4>

		<p class="territorio-picker__hint">
			Diga por dónde va el límite: haga clic en las calles, canales o vías del
			mapa, o búsquelas por nombre. Se puede ajustar a mano después.
		</p>

		<NcTextField :model-value="query"
			type="search"
			label="Buscar una calle, canal o vía"
			:show-trailing-button="query !== ''"
			trailing-button-label="Limpiar"
			@update:model-value="$emit('update:query', $event)"
			@trailing-button-click="$emit('update:query', '')" />

		<!--
			Every name that matches, never one of them. ADR-0007: "which Los Toros?"
			is settled by the person in one click rather than guessed — so two
			streets whose names look alike are two rows here, and the count says how
			much of the comuna each one is.
		-->
		<ul v-if="suggestions.length > 0" class="territorio-picker__suggestions">
			<li v-for="group in suggestions" :key="group.name">
				<NcButton variant="tertiary"
					:pressed="isPicked(group)"
					@click="$emit('toggle-group', group.ids)">
					{{ group.name }}
					<span class="territorio-picker__count">{{ group.ids.length }}</span>
				</NcButton>
			</li>
		</ul>

		<p v-else-if="query !== ''" class="territorio-picker__hint">
			Nada con ese nombre en lo que se ve. Mueva el mapa para cargar otra zona.
		</p>

		<!--
			The Limit in one sentence, which is the criterion: what somebody would
			say out loud when asked where the sector ends. Above the list of parts
			rather than instead of it — the sentence is the answer, the list is how
			it is edited.
		-->
		<p v-if="limitSaid !== ''" class="territorio-picker__limit">
			<strong>Va por</strong> {{ limitSaid }}.
		</p>

		<h5 v-if="limit.length > 0">Tramos del límite</h5>
		<ul v-if="limit.length > 0" class="territorio-picker__parts">
			<li v-for="row in limit" :key="row.name">
				<span>
					{{ row.name }}
					<!-- How much of the comuna that name stands for, like the
					     suggestions above: one street is dozens of separate ways. -->
					<span v-if="row.parts > 1" class="territorio-picker__count">{{ row.parts }}</span>
					<!-- And which rows follow nothing anybody could have picked. -->
					<span v-if="!row.picked" class="territorio-picker__count">a mano</span>
				</span>
				<NcButton variant="tertiary"
					:aria-label="`Quitar ${row.name} del límite`"
					@click="$emit('remove-part', row.name)">
					Quitar
				</NcButton>
			</li>
		</ul>

		<!--
			A limit runs along a canal, the edge of a park, or a line two teams
			agreed on years ago. None of that is in any dataset, so it is typed.
		-->
		<NcTextField :model-value="note"
			label="Agregar un tramo a mano"
			placeholder="por detrás del colegio"
			@update:model-value="note = $event"
			@keydown.enter.prevent="addNote" />
		<NcButton class="territorio-picker__generate"
			variant="tertiary"
			:disabled="note.trim() === ''"
			@click="addNote">
			Agregar tramo
		</NcButton>

		<h5 v-if="picked.length > 0">Elegido</h5>
		<ul v-if="picked.length > 0" class="territorio-picker__picked">
			<li v-for="group in picked" :key="group.name ?? group.ids[0]">
				<span>
					{{ group.name ?? 'Fuera de la vista' }}
					<span v-if="group.ids.length > 1" class="territorio-picker__count">
						{{ group.ids.length }}
					</span>
				</span>
				<NcButton variant="tertiary"
					:aria-label="`Quitar ${group.name ?? 'este tramo'} del límite`"
					@click="$emit('toggle-group', group.ids)">
					Quitar
				</NcButton>
			</li>
		</ul>

		<p v-else class="territorio-picker__hint">
			Todavía no ha elegido nada. Un Sector dibujado a mano es igual de válido.
		</p>

		<!--
			Only offered once there is something to build from, and never instead of
			drawing (ADR-0007). What comes out is a starting point: real limits run
			along a canal, the edge of a park, or a line two teams agreed on years
			ago, and none of that is in any dataset.
		-->
		<NcButton v-if="picked.length > 0"
			class="territorio-picker__generate"
			variant="secondary"
			:pressed="seeding"
			@click="$emit('generate')">
			{{ seeding ? 'Haga clic dentro del sector…' : 'Generar el polígono' }}
		</NcButton>

		<p v-if="picked.length > 0" class="territorio-picker__hint">
			Se puede ajustar arrastrando los vértices, y siempre se puede dibujar a
			mano en vez de generar.
		</p>
	</section>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'BoundaryPicker',

	components: { NcButton, NcTextField },

	props: {
		/** What typing offers, already grouped by name (see boundaries.js). */
		suggestions: { type: Array, default: () => [] },
		/** What has been chosen so far, grouped the same way. */
		picked: { type: Array, default: () => [] },
		pickedIds: { type: Array, default: () => [] },
		query: { type: String, default: '' },
		/** Waiting for the click that says which block is the Sector (issue #14). */
		seeding: { type: Boolean, default: false },
		/** The Limit as rows to show — one per name (issue #15). */
		limit: { type: Array, default: () => [] },
		/** The same thing as one sentence, built by the caller. */
		limitSaid: { type: String, default: '' },
	},

	emits: ['add-note', 'generate', 'remove-part', 'toggle-group', 'update:query'],

	data() {
		return { note: '' }
	},

	methods: {
		addNote() {
			this.$emit('add-note', this.note)
			this.note = ''
		},

		/** A whole street counts as picked only when every line of it is. */
		isPicked(group) {
			return group.ids.every((id) => this.pickedIds.includes(id))
		},
	},
}
</script>

<style scoped>
.territorio-picker {
	margin-top: 16px;
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}

.territorio-picker__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 8px 0;
}

.territorio-picker__suggestions,
.territorio-picker__parts,
.territorio-picker__picked {
	list-style: none;
	padding: 0;
	margin: 8px 0 0;
	max-height: 180px;
	overflow-y: auto;
}

.territorio-picker__parts li,
.territorio-picker__picked li {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 2px 0;
}

.territorio-picker__limit {
	margin: 8px 0;
	padding: 8px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.territorio-picker__generate {
	margin-top: 8px;
}

.territorio-picker__count {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin-inline-start: 6px;
}
</style>
