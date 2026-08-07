<template>
	<NcDialog name="Exportar" size="normal" @closing="$emit('close')">
		<p class="territorio-export__count">
			Se exportarán <strong>{{ count }}</strong>
			{{ count === 1 ? 'registro' : 'registros' }}: lo que está viendo ahora,
			con las capas y la búsqueda aplicadas.
		</p>

		<fieldset class="territorio-export__formats">
			<legend>Formato</legend>
			<NcCheckboxRadioSwitch v-for="option in FORMATS"
				:key="option.value"
				:model-value="format"
				:value="option.value"
				name="territorio-export-format"
				type="radio"
				@update:model-value="format = $event">
				{{ option.label }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<!--
			Said before the export runs, not after — the point is that somebody
			expecting their phone numbers can still choose a different format. Same
			rule as the import dialog's KML notice, for the same reason.
		-->
		<NcNoteCard v-if="format === 'kml'" type="warning">
			Un archivo KML lleva solamente el nombre y la ubicación. No lleva
			teléfono, correo, persona de contacto, cargo, dirección, horario, notas,
			fuente ni categoría. Para un archivo completo, use GeoJSON.
		</NcNoteCard>

		<NcCheckboxRadioSwitch v-if="format !== 'kml'"
			:model-value="contacts"
			type="switch"
			@update:model-value="contacts = $event">
			Incluir datos de contacto
		</NcCheckboxRadioSwitch>

		<!--
			ADR-0006. The Registry holds the mobile numbers of identifiable people —
			presidentas and secretarios of juntas de vecinos — and this is where a
			coordination tool becomes a portable contact list. So the prompt is
			Nextcloud's own, it is asked for THIS export, and the export is recorded.
		-->
		<NcNoteCard v-if="contacts && format !== 'kml'" type="info">
			Este archivo lleva nombres, teléfonos y correos de personas. Se le pedirá
			su contraseña y quedará registrado quién exportó, cuándo y cuántos
			registros.
		</NcNoteCard>

		<NcNoteCard v-if="error !== ''" type="error">{{ error }}</NcNoteCard>

		<template #actions>
			<NcButton :disabled="working" @click="$emit('close')">
				Cancelar
			</NcButton>
			<NcButton :disabled="working || count === 0" variant="primary" @click="send">
				{{ working ? 'Exportando…' : 'Exportar' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { PwdConfirmationMode } from '@nextcloud/password-confirmation'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

const FORMATS = [
	{ value: 'geojson', label: 'GeoJSON — completo, se puede volver a importar' },
	{ value: 'csv', label: 'CSV — para planilla de cálculo' },
	{ value: 'kml', label: 'KML — solo nombre y ubicación, para Google Earth' },
]

export default {
	name: 'ExportDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard },

	props: {
		/** The ids on screen right now. The filter is personal and lives here. */
		ids: { type: Array, required: true },
	},

	emits: ['close'],

	data() {
		return {
			FORMATS,
			format: 'geojson',
			// Off by default. An export that carries nobody's telephone number is
			// the one somebody should have to ask for on purpose.
			contacts: false,
			working: false,
			error: '',
		}
	},

	computed: {
		count() {
			return this.ids.length
		},

		/**
		 * A KML carries no contact details whatever is asked for, so it never needs
		 * the confirmation and never goes to the confirmed route.
		 */
		carriesContacts() {
			return this.contacts && this.format !== 'kml'
		},
	},

	methods: {
		async send() {
			this.working = true
			this.error = ''

			try {
				const { data, headers } = await axios.post(
					generateUrl(this.carriesContacts
						? '/apps/territorio/export/contacts'
						: '/apps/territorio/export'),
					{ ids: this.ids, format: this.format },
					{
						// Blob, not JSON: this response is a file, and letting axios
						// parse it would turn a CSV into a string with the encoding
						// guessed and a KML into nothing useful.
						responseType: 'blob',
						// Nextcloud's own prompt, never a password field of ours
						// (ADR-0006), and Strict because the route is strict: the
						// confirmation has to be part of THIS request rather than a
						// note in the session from twenty minutes ago. The
						// interceptor that reads this is installed in main.js.
						...(this.carriesContacts
							? { confirmPassword: PwdConfirmationMode.Strict }
							: {}),
					},
				)

				this.save(data, headers['content-disposition'])
				this.$emit('close')
			} catch (exception) {
				// A cancelled password prompt rejects too, and telling somebody
				// "no se pudo exportar" when they decided not to is worse than
				// saying nothing.
				if (exception?.message !== 'Password confirmation cancelled') {
					this.error = exception.response?.data?.message
						?? 'No se pudo exportar. Inténtelo de nuevo.'
				}
			} finally {
				this.working = false
			}
		},

		/** Hand the file to the browser the only way a POST response can. */
		save(blob, disposition) {
			const url = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = this.filenameFrom(disposition)
			document.body.appendChild(link)
			link.click()
			link.remove()
			// Revoked, or the whole file stays in memory until the tab closes —
			// and a comuna's catastro is not a small string.
			URL.revokeObjectURL(url)
		},

		/** The server names the file; this is the fallback when it did not. */
		filenameFrom(disposition) {
			const match = /filename="?([^";]+)"?/.exec(disposition ?? '')

			return match?.[1] ?? `territorio.${this.format}`
		},
	},
}
</script>

<style scoped>
.territorio-export__count {
	margin-bottom: 12px;
}

.territorio-export__formats {
	border: 0;
	padding: 0;
	margin: 0 0 12px;
}

.territorio-export__formats legend {
	font-weight: bold;
	margin-bottom: 4px;
}
</style>
