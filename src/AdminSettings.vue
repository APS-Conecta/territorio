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
		const basemap = loadState('territorio', 'basemap')

		return {
			// Blank rather than the default URL: an empty box means "unset", and
			// pre-filling it would make clearing the field impossible to express.
			url: basemap.isDefault ? '' : basemap.url,
			attribution: basemap.isDefault ? '' : basemap.attribution,
			saving: false,
			saved: false,
			rejected: false,
		}
	},

	methods: {
		async save() {
			this.saving = true
			this.saved = false
			this.rejected = false

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
