<template>
	<section class="territorio-history">
		<h4>Historial</h4>

		<p v-if="entries.length === 0" class="territorio-history__empty">
			Sin movimientos registrados.
		</p>

		<ol v-else class="territorio-history__list">
			<li v-for="entry in entries" :key="entry.revision" class="territorio-history__entry">
				<span class="territorio-history__action" :class="'is-' + entry.action">
					{{ ACTIONS[entry.action] || entry.action }}
				</span>
				<span class="territorio-history__who">
					{{ entry.actor || 'el sistema' }} · {{ entry.changedAt }}
				</span>
				<ul v-if="entry.changes" class="territorio-history__changes">
					<li v-for="(change, field) in entry.changes" :key="field">
						<strong>{{ FIELDS[field] || field }}:</strong>
						<span class="territorio-history__from">{{ display(change.from) }}</span>
						→
						<span>{{ display(change.to) }}</span>
					</li>
				</ul>
			</li>
		</ol>
	</section>
</template>

<script>
const ACTIONS = {
	created: 'Creado',
	updated: 'Editado',
	retired: 'Retirado',
	restored: 'Restaurado',
}

const FIELDS = {
	name: 'Nombre',
	subcategoryId: 'Subcategoría',
	geometry: 'Ubicación',
	address: 'Dirección',
	phone: 'Teléfono',
	email: 'Correo',
	contactPerson: 'Persona de contacto',
	contactRole: 'Cargo',
	locationQuality: 'Exactitud',
	openingHours: 'Horario',
	notes: 'Notas',
	sourceRef: 'Fuente',
}

export default {
	name: 'FeatureHistory',

	props: {
		entries: { type: Array, required: true },
	},

	data() {
		return { ACTIONS, FIELDS }
	},

	methods: {
		/**
		 * History is append-only and holds whatever the field held, including
		 * nothing — and "vacío" has to be distinguishable from a literal empty
		 * string, or a cleared phone number reads as no change at all.
		 */
		display(value) {
			if (value === null || value === undefined || value === '') {
				return '(vacío)'
			}
			return typeof value === 'string' && value.length > 60
				? value.slice(0, 60) + '…'
				: String(value)
		},
	},
}
</script>

<style scoped>
.territorio-history {
	margin-top: 24px;
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
}

.territorio-history__empty,
.territorio-history__who {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.territorio-history__list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.territorio-history__entry {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.territorio-history__action {
	font-weight: bold;
	margin-inline-end: 6px;
}

.territorio-history__action.is-retired {
	color: var(--color-error);
}

.territorio-history__action.is-restored {
	color: var(--color-success);
}

.territorio-history__changes {
	margin: 6px 0 0;
	padding-inline-start: 16px;
	font-size: 0.9em;
}

.territorio-history__from {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}
</style>
