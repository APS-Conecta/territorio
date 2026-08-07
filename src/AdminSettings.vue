<template>
	<div>
		<!--
			First, because nothing else in the app means much without it: a
			Territorio is a portion of exactly one Comuna and never reaches into a
			second (CONTEXT.md), so the install has to know which one it serves.
		-->
		<NcSettingsSection name="Comuna"
			description="La comuna que atiende este CESFAM. Una sola por instalación,
				identificada por su código CUT de cinco dígitos — La Florida es 13110.
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
				placeholder="13110"
				inputmode="numeric"
				:disabled="savingComuna" />

			<NcTextField v-model="comunaName"
				label="Nombre (opcional)"
				placeholder="La Florida"
				:disabled="savingComuna"
				class="territorio-admin__field" />

			<NcButton :disabled="savingComuna"
				variant="primary"
				class="territorio-admin__field"
				@click="saveComuna">
				Guardar
			</NcButton>
		</NcSettingsSection>

	<NcSettingsSection name="Fondo de mapa"
		description="De dónde vienen las imágenes de fondo del mapa. Por omisión se usan
			las teselas públicas de OpenStreetMap, que requieren acceso a internet desde
			cada equipo. Un CESFAM sin internet puede apuntar esta dirección a su propio
			servidor de teselas.">
		<NcNoteCard v-if="rejected" type="warning">
			Esa dirección no es una URL de teselas válida, así que el mapa sigue usando
			OpenStreetMap. Debe empezar por http:// o https:// e incluir
			<code>{z}</code>, <code>{x}</code> e <code>{y}</code>.
		</NcNoteCard>

		<NcNoteCard v-else-if="failed" type="error">
			No se pudo guardar. Revise la conexión e inténtelo de nuevo.
		</NcNoteCard>

		<NcNoteCard v-else-if="saved" type="success">
			Guardado.
		</NcNoteCard>

		<NcTextField v-model="url"
			label="Dirección de las teselas"
			placeholder="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
			:disabled="saving" />

		<NcTextField v-model="attribution"
			label="Atribución"
			placeholder="© OpenStreetMap contributors"
			:disabled="saving"
			class="territorio-admin__field" />

		<NcButton :disabled="saving"
			variant="primary"
			class="territorio-admin__field"
			@click="save">
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

export default {
	name: 'AdminSettings',

	components: { NcButton, NcNoteCard, NcSettingsSection, NcTextField },

	data() {
		// These are the stored values, not the resolved ones: a URL Territorio
		// declined must stay in the box that produced it, next to the warning
		// saying why the map is still on OpenStreetMap. An empty box means unset.
		const basemap = loadState('territorio', 'basemap')
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
			url: basemap.url,
			attribution: basemap.attribution,
			saving: false,
			saved: false,
			rejected: basemap.rejected,
			failed: false,
		}
	},

	mounted() {
		this.loadExports()
	},

	methods: {
		async loadExports() {
			try {
				const { data } = await axios.get(generateUrl('/apps/territorio/export/history'))
				this.exports = data
			} catch (error) {
				console.error('territorio: could not read the export log', error)
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
				this.comunaFailed = error.response?.data?.message
					?? 'No se pudo guardar. Revise la conexión e inténtelo de nuevo.'
			} finally {
				this.savingComuna = false
			}
		},

		async save() {
			this.saving = true
			this.saved = false
			this.rejected = false
			this.failed = false

			try {
				const { data } = await axios.put(
					generateUrl('/apps/territorio/settings/basemap'),
					{ url: this.url, attribution: this.attribution },
				)
				// The server reports what the setting resolves to, not what we
				// sent, so a URL it will not put in a policy is visible here
				// rather than later as a blank map.
				this.rejected = !data.accepted
				this.saved = data.accepted
			} catch (error) {
				// Without this, a 500 or a dropped connection leaves every flag
				// false and the page looks exactly as if nobody pressed the button.
				console.error('territorio: could not save the basemap setting', error)
				this.failed = true
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.territorio-admin__exports {
	width: 100%;
	margin-top: 12px;
	border-collapse: collapse;
}

.territorio-admin__exports th,
.territorio-admin__exports td {
	text-align: start;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}

.territorio-admin__field {
	margin-top: 12px;
}
</style>
