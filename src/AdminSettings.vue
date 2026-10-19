<template>
	<div>
		<!--
			First, because nothing else in the app means much without it: a
			Territorio is a portion of exactly one Comuna and never reaches into a
			second (CONTEXT.md), so the install has to know which one it serves.
		-->
		<NcSettingsSection name="Comuna"
			description="La comuna que atiende este CESFAM. Una sola por instalación,
				identificada por su código CUT de cinco dígitos.
				De ella se importan las Unidades Vecinales, y un archivo de otra comuna
				se rechaza.">
			<NcNoteCard v-if="comunaFailed" type="error">
				{{ comunaFailed }}
			</NcNoteCard>

			<NcNoteCard v-else-if="comunaSaved" type="success">
				Guardado.
			</NcNoteCard>

			<NcNoteCard v-else-if="!comuna.chosen" type="warning">
				Todavía no se ha elegido comuna.
			</NcNoteCard>

			<NcTextField v-model="cut"
				label="Código CUT"
				placeholder="Cinco dígitos"
				inputmode="numeric"
				:disabled="savingComuna" />

			<NcTextField v-model="comunaName"
				label="Nombre (opcional)"
				placeholder="Nombre de la comuna"
				:disabled="savingComuna"
				class="territorio-admin__field" />

			<NcButton :disabled="savingComuna"
				variant="primary"
				class="territorio-admin__field"
				@click="saveComuna">
				Guardar
			</NcButton>
		</NcSettingsSection>

	<!--
		ADR-0006: "An administrator can read that list as a report." A route on its
		own does not make that true — nobody administering a clinic's Nextcloud is
		going to curl anything — so the report is here, where the other things only
		an administrator can see already are.
	-->
	<NcSettingsSection name="Exportaciones con datos de contacto"
		description="Quién ha exportado nombres, teléfonos o correos de personas, cuándo y cuántos registros. Este registro no se borra.">
		<NcNoteCard v-if="exportsFailed" type="error">
			No se pudo leer el registro de exportaciones.
			<NcButton variant="tertiary" @click="loadExports">
				Reintentar
			</NcButton>
		</NcNoteCard>

		<p v-else-if="exports.length === 0">
			Todavía nadie ha exportado datos de contacto.
		</p>

		<table v-else class="territorio-admin__exports">
			<thead>
				<tr>
					<th>Fecha</th>
					<th>Usuario</th>
					<th>Formato</th>
					<th>Registros</th>
					<th>Alcance</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="entry in exports" :key="entry.id">
					<td>{{ when(entry.exportedAt) }}</td>
					<td>{{ entry.actor }}</td>
					<td>{{ entry.format.toUpperCase() }}</td>
					<td>{{ entry.records }}</td>
					<td>{{ entry.scope }}</td>
				</tr>
			</tbody>
		</table>
	</NcSettingsSection>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { messageFrom, warnError } from './api.js'

export default {
	name: 'AdminSettings',

	components: { NcButton, NcNoteCard, NcSettingsSection, NcTextField },

	data() {
		const comuna = loadState('territorio', 'comuna')

		return {
			// The export log (ADR-0006). Fetched rather than shipped in the initial
			// state: it grows without bound, only an administrator ever reads it,
			// and putting it in every settings page load would make everyone else
			// pay for it.
			exports: [],
			exportsFailed: false,
			comuna,
			cut: comuna.cut,
			comunaName: comuna.name,
			savingComuna: false,
			comunaSaved: false,
			comunaFailed: '',
		}
	},

	mounted() {
		this.loadExports()
	},

	methods: {
		async loadExports() {
			this.exportsFailed = false
			try {
				const { data } = await axios.get(generateUrl('/apps/territorio/export/history'))
				this.exports = data
			} catch (error) {
				warnError('could not read the export log', error)
				this.exportsFailed = true
			}
		},

		/** The moment, in the reader's own locale rather than an ISO string. */
		when(moment) {
			return moment === null ? '' : new Date(moment).toLocaleString()
		},

		async saveComuna() {
			this.savingComuna = true
			this.comunaSaved = false
			this.comunaFailed = ''

			try {
				const { data } = await axios.put(
					generateUrl('/apps/territorio/settings/comuna'),
					{ cut: this.cut, name: this.comunaName },
				)
				this.comuna = data
				this.comunaSaved = true
			} catch (error) {
				// A 422 names the typo; anything else does not, and silence would
				// leave the page looking exactly as if nobody pressed the button.
				this.comunaFailed = await messageFrom(error,
					'No se pudo guardar. Revise la conexión e inténtelo de nuevo.')
			} finally {
				this.savingComuna = false
			}
		},
	},
}
</script>

<style scoped>
.territorio-admin__exports {
	width: 100%;
	margin-top: calc(var(--default-grid-baseline) * 3);
	border-collapse: collapse;
}

.territorio-admin__exports th,
.territorio-admin__exports td {
	text-align: start;
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}

.territorio-admin__field {
	margin-top: calc(var(--default-grid-baseline) * 3);
}
</style>
