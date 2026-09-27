<template>
	<section class="territorio-history">
		<h4>Historial</h4>

		<!-- Three states, not two (L1-28): a history that failed to load is not a
		     history that says nothing happened — «Sin movimientos» over a network
		     failure read as the record having no past. -->
		<p v-if="failed" class="territorio-history__empty">
			No se pudo cargar el historial.
			<button type="button" class="territorio-history__retry" @click="$emit('retry')">
				Reintentar
			</button>
		</p>

		<p v-else-if="entries.length === 0" class="territorio-history__empty">
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
						<span class="territorio-history__label">{{ FIELDS[field] || field }}:</span>

						<!-- A creation has no previous value, so it is listed rather than
						     shown as a change from nothing. Rendering "(vacío) → x" for
						     every field on every new record is noise that buries the
						     edits people actually care about. -->
						<span v-if="entry.action === 'created'">{{ format(field, change.to) }}</span>
						<template v-else>
							<span class="territorio-history__from">{{ format(field, change.from) }}</span>
							<span class="territorio-history__arrow" aria-hidden="true">→</span>
							<span>{{ format(field, change.to) }}</span>
						</template>
					</li>
				</ul>
			</li>
		</ol>
	</section>
</template>

<script>
import { nameOf } from '../boundaries.js'
import { QUALITY_LABELS } from '../indice.js'
import { readable } from '../limit.js'
import { indexSubcategories } from '../taxonomy.js'

/**
 * The Limit's parts out of what history recorded.
 *
 * History stores what the column stores, which is a JSON string. A malformed one
 * means something wrote nonsense into it — this panel is the wrong place to
 * argue about that, so it says nothing rather than throwing inside a render.
 */
const parts = (value) => {
	try {
		const decoded = JSON.parse(value)
		return Array.isArray(decoded) ? decoded : []
	} catch {
		return []
	}
}

const ACTIONS = {
	created: 'Creado',
	updated: 'Editado',
	retired: 'Retirado',
	restored: 'Restaurado',
	merged: 'Fusionado',
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
	colour: 'Color',
	limit: 'Límite',
	// Derived, and in history because ADR-0008 says a reassignment must be
	// visible like any other change. The value stored is a record's id;
	// `format()` says the name through the same resolver the sidebar's derived
	// Sector/UV line uses, so the entry reads «Sector: Sur → Norte» (L1-07).
	sectorId: 'Sector',
	unidadVecinalId: 'Unidad Vecinal',
}

export default {
	name: 'FeatureHistory',

	props: {
		entries: { type: Array, required: true },
		taxonomy: { type: Array, default: () => [] },
		/** The territorial divisions, so a stored Sector/UV id is said by name. */
		divisions: { type: Array, default: () => [] },
		failed: { type: Boolean, default: false },
	},

	emits: ['retry'],

	data() {
		return { ACTIONS, FIELDS }
	},

	computed: {
		subcategories() {
			return indexSubcategories(this.taxonomy)
		},
	},

	methods: {
		/**
		 * History stores what the column stored, which is not what a person should
		 * read: a Subcategory is an id, a location is a GeoJSON document, and an
		 * exactness is a slug. Rendering them raw is how "Subcategoría: 20" and a
		 * wall of JSON end up in the sidebar.
		 */
		format(field, value) {
			if (value === null || value === undefined || value === '') {
				return '(vacío)'
			}

			if (field === 'subcategoryId') {
				return this.subcategories.get(value)?.label ?? `#${value}`
			}

			// The sidebar's own wording for an id nothing resolves, one copy (boundaries.js).
			if (field === 'sectorId' || field === 'unidadVecinalId') {
				return nameOf(this.divisions, value)
			}

			if (field === 'geometry') {
				return this.coordinates(value)
			}

			if (field === 'locationQuality') {
				return QUALITY_LABELS[value] ?? value
			}

			// Stored as JSON, said as a sentence. Left raw it would be the wall of
			// JSON this method exists to prevent, truncated at sixty characters —
			// which is worse than saying nothing, because it looks like data.
			if (field === 'limit') {
				return readable(parts(value)) || '(vacío)'
			}

			const text = String(value)
			return text.length > 60 ? text.slice(0, 60) + '…' : text
		},

		/** A point as a person would read it: latitude, then longitude. */
		coordinates(geometry) {
			try {
				const parsed = typeof geometry === 'string' ? JSON.parse(geometry) : geometry
				const [lon, lat] = parsed?.coordinates ?? []
				if (typeof lat !== 'number' || typeof lon !== 'number') {
					return 'definida'
				}
				return `${lat.toFixed(5)}, ${lon.toFixed(5)}`
			} catch {
				return 'definida'
			}
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

/* A link-styled button — the empty-state pattern the basemap banner uses. */
.territorio-history__retry {
	background: none;
	border: none;
	padding: 0;
	margin-inline-start: 6px;
	font: inherit;
	color: var(--color-primary-element);
	cursor: pointer;
	text-decoration: underline;
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
	margin-inline-end: 8px;
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

/*
 * Spacing is CSS, not whitespace in the template: Vue's compiler condenses
 * whitespace-only text nodes between elements away, which is what glued
 * "Nombre:" to its value in the first place.
 */
.territorio-history__changes li {
	margin-bottom: 2px;
	line-height: 1.5;
}

.territorio-history__label {
	font-weight: bold;
	margin-inline-end: 6px;
}

.territorio-history__from {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.territorio-history__arrow {
	margin-inline: 6px;
	color: var(--color-text-maxcontrast);
}
</style>
