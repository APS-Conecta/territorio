<template>
	<NcDialog name="Importar y deshacer"
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

			<!--
				Undo lives beside the thing it undoes. ADR-0009 says there is no
				backup feature and that reverting a run is the answer instead, so it
				has to be somewhere a person will actually find it.
			-->
			<section v-if="batches.length > 0" class="territorio-import__batches">
				<h4>Importaciones recientes</h4>
				<ul>
					<li v-for="batch in batches" :key="batch.id" class="territorio-import__batch">
						<span class="territorio-import__batch-what">
							<strong>{{ batch.isRevert ? 'Deshacer' : 'Importación' }} #{{ batch.id }}</strong>
							<span class="territorio-import__hint">
								{{ batch.startedAt }} · {{ batch.actor || 'el sistema' }}
								<template v-if="!batch.isRevert">
									· {{ batch.created }} creados, {{ batch.updated }} actualizados,
									{{ batch.skipped }} omitidos
								</template>
								<template v-else>
									· {{ batch.updated }} deshechos, {{ batch.skipped }} intactos
								</template>
							</span>
						</span>
						<NcButton v-if="!isUndone(batch)"
							variant="tertiary"
							:disabled="working"
							@click="undo(batch)">
							Deshacer
						</NcButton>
						<span v-else class="territorio-import__hint">ya deshecha</span>
					</li>
				</ul>
			</section>
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
			batches: [],
		}
	},

	mounted() {
		this.loadBatches()
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
		async loadBatches() {
			try {
				const { data } = await axios.get(generateUrl('/apps/territorio/import/batches'))
				this.batches = data
			} catch (exception) {
				console.warn('territorio: could not list import batches', exception)
			}
		},

		/** A batch is spent once something records having undone it. */
		isUndone(batch) {
			return this.batches.some((other) => other.revertsId === batch.id)
		},

		async undo(batch) {
			this.working = true
			this.error = ''
			this.result = null

			try {
				await axios.post(generateUrl(`/apps/territorio/import/${batch.id}/revert`))
				await this.loadBatches()
				this.$emit('imported')
			} catch (exception) {
				this.error = exception.response?.data?.message ?? 'No se pudo deshacer el lote.'
			} finally {
				this.working = false
			}
		},

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
				await this.loadBatches()
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
.territorio-import__batches {
	margin-top: 20px;
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}

.territorio-import__batches h4 {
	margin-bottom: 4px;
}

.territorio-import__batches ul {
	list-style: none;
	padding: 0;
	margin: 0;
	max-height: 180px;
	overflow-y: auto;
}

.territorio-import__batch {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 4px 0;
}

.territorio-import__batch-what {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

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
