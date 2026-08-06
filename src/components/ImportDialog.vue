<template>
	<NcDialog name="Importar GeoJSON"
		size="normal"
		:open="true"
		@update:open="$emit('close')">
		<div class="territorio-import">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcNoteCard v-else-if="result" type="success">
				{{ summary }}
			</NcNoteCard>

			<p v-else class="territorio-import__hint">
				El archivo debe ser un FeatureCollection de GeoJSON. Se revisa entero antes
				de escribir nada: si una fila es inválida, no se importa ninguna.
			</p>

			<NcTextField v-model="dataset"
				class="territorio-import__field"
				label="Conjunto de datos"
				placeholder="laflorida"
				:disabled="working" />

			<label class="territorio-import__field territorio-import__file">
				<span>Archivo</span>
				<input type="file"
					accept=".geojson,.json,application/geo+json,application/json"
					:disabled="working"
					@change="pick">
			</label>

			<p v-if="file" class="territorio-import__hint">
				{{ file.name }} · {{ Math.round(file.size / 1024) }} KB
			</p>
		</div>

		<template #actions>
			<NcButton :disabled="!canImport" variant="primary" @click="send">
				{{ working ? 'Importando…' : 'Importar' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'ImportDialog',

	components: { NcButton, NcDialog, NcNoteCard, NcTextField },

	emits: ['close', 'imported'],

	data() {
		return {
			dataset: '',
			file: null,
			working: false,
			error: '',
			result: null,
		}
	},

	computed: {
		canImport() {
			return !this.working && this.file !== null && this.dataset.trim() !== ''
		},

		/**
		 * Every number the batch recorded, including the ones nobody asked for.
		 * "18 duplicados en el archivo" and "309 aún no soportados" are the counts
		 * that explain why 865 features became 538 records — without them the
		 * import looks like it quietly lost a third of the file.
		 */
		summary() {
			const r = this.result
			return `${r.created} creados, ${r.updated} actualizados, ${r.unchanged} sin cambios, `
				+ `${r.skipped} omitidos por edición local, ${r.duplicates} duplicados en el archivo, `
				+ `${r.unsupported} aún no soportados.`
		},
	},

	methods: {
		pick(event) {
			this.file = event.target.files?.[0] ?? null
			this.error = ''
			this.result = null
		},

		async send() {
			this.working = true
			this.error = ''
			this.result = null

			const body = new FormData()
			body.append('file', this.file)
			body.append('dataset', this.dataset.trim())

			try {
				const { data } = await axios.post(generateUrl('/apps/territorio/import'), body)
				this.result = data
				// The list and the map catch up through the change cursor rather
				// than being told separately — one mechanism, not two (ADR-0002).
				this.$emit('imported')
			} catch (exception) {
				this.error = exception.response?.data?.message
					?? 'No se pudo importar el archivo.'
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped>
.territorio-import {
	padding: 0 12px 8px;
}

.territorio-import__field {
	margin-top: 12px;
}

.territorio-import__file {
	display: block;
}

.territorio-import__file span {
	display: block;
	margin-bottom: 4px;
}

.territorio-import__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 8px;
}
</style>
