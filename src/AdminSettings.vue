<template>
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

		return {
			url: basemap.url,
			attribution: basemap.attribution,
			saving: false,
			saved: false,
			rejected: basemap.rejected,
			failed: false,
		}
	},

	methods: {
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
.territorio-admin__field {
	margin-top: 12px;
}
</style>
