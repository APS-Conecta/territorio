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
				GeoJSON (un FeatureCollection), KML, KMZ o CSV. Se revisa entero antes de
				escribir nada: si una fila es inválida, no se importa ninguna.
			</p>

			<!--
				Said before the import runs, not after. A MyMaps export carries no
				ExtendedData at all — checked against the real 865-placemark file —
				so a person who expects their phone numbers to arrive needs to know
				that while they can still choose a different file.
			-->
			<NcNoteCard v-if="isKmlFamily" type="warning" class="territorio-import__field">
				KML y KMZ traen menos que GeoJSON. De este archivo se importan la
				geometría, el nombre, la carpeta (como categoría) y la descripción
				(como notas).
				<strong>No trae teléfono, correo, persona de contacto, horario ni fuente</strong>:
				esos campos quedan vacíos y se pueden completar después, registro a registro.
			</NcNoteCard>

			<!--
				Said before the import runs, for the same reason the KML notice is:
				a CSV is for a spreadsheet somebody else sent, and this app's own CSV
				export is "lossy on purpose and in one direction only" (issue #17) —
				it has no id column, its «Clasificación» is one label rather than the
				two slugs, and its contact cells carry the apostrophe that stops a
				spreadsheet evaluating them. Somebody who exports and edits in Excel
				needs to read that here, not discover it from a Dataset full of
				duplicates.
			-->
			<NcNoteCard v-if="isCsv" type="warning" class="territorio-import__field">
				De un CSV se leen las columnas Nombre, Categoría, Subcategoría, Latitud,
				Longitud, Dirección, Teléfono, Correo, Persona de contacto, Cargo, Horario,
				Notas, Fuente y Ubicación; las demás se ignoran.
				<strong>El CSV que exporta esta aplicación no vuelve a entrar</strong>: es
				para leer, ordenar e imprimir. Para volver a importar lo que salió de aquí,
				exporte GeoJSON.
			</NcNoteCard>

			<NcTextField v-model="dataset"
				class="territorio-import__field"
				label="Conjunto de datos"
				placeholder="laflorida"
				:disabled="working" />

			<label class="territorio-import__field territorio-import__file">
				<span>Archivo</span>
				<input ref="archivo"
					type="file"
					accept=".geojson,.json,.kml,.kmz,.csv,application/geo+json,application/json,application/vnd.google-earth.kml+xml,application/vnd.google-earth.kmz,text/csv"
					:disabled="working"
					@change="pick">
			</label>

			<!--
				The same import, from a file the clinic already has (#104):
				«re-importar el mismo archivo sin volver a subirlo». It is not a
				second source of truth — the Registry is that, and a local edit
				still outranks anything a re-import carries (ADR-0003).
			-->
			<NcButton class="territorio-import__field"
				variant="tertiary"
				:disabled="working"
				@click="pickFromFiles">
				Desde archivos de Nextcloud
			</NcButton>

			<p v-if="path" class="territorio-import__hint">
				{{ path }} · ya está en sus archivos de Nextcloud
			</p>

			<p v-else-if="file" class="territorio-import__hint">
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
								{{ batch.dataset }} · {{ batch.startedAt }} · {{ batch.actor || 'el sistema' }}
								<template v-if="!batch.isRevert">
									· {{ batch.created }} creados, {{ batch.updated }} actualizados,
									{{ batch.skipped }} omitidos
								</template>
								<template v-else>
									· {{ batch.updated }} deshechos, {{ batch.skipped }} intactos
								</template>
							</span>
						</span>
						<NcButton v-if="!batch.undone"
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
			// The path of a file already in Files, when that is where this import
			// is coming from (#104). Exactly one of `path` and `file` is ever set.
			path: '',
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
			return !this.working && this.chosenName !== '' && this.dataset.trim() !== ''
		},

		/**
		 * The name of whichever file is chosen, lowercased, however it was
		 * chosen — the browser's for an upload, the last segment of the path for
		 * one already in Files.
		 *
		 * One place, because the server has one too: `ImportService` dispatches
		 * on the suffix of the name it is given, so a KML picked from Files is
		 * read as a KML and warned about as one (#104).
		 */
		chosenName() {
			return (this.path.split('/').pop() || this.file?.name || '').toLowerCase().trim()
		},

		/**
		 * Whether the chosen file is a KML or the same KML in an archive, which
		 * decides whether the lossiness notice is shown. By extension, like the
		 * server: the same rule in both places, so what the notice warns about is
		 * what the import will do.
		 *
		 * A KMZ is unzipped and handed to the KML reader (#103), so it carries
		 * exactly as little — a notice that appeared for one and not the other
		 * would promise a person that zipping their MyMaps export brings their
		 * telephone numbers back.
		 */
		isKmlFamily() {
			return this.chosenName.endsWith('.kml') || this.chosenName.endsWith('.kmz')
		},

		/** The same, for the spreadsheet a clinic sends (#101). */
		isCsv() {
			return this.chosenName.endsWith('.csv')
		},

		/**
		 * Every number the batch recorded, including the ones nobody asked for.
		 * "18 duplicados en el archivo" and "309 con geometría no soportada" are
		 * the counts that explain why 865 features became 538 records — without
		 * them the import looks like it quietly lost a third of the file.
		 *
		 * Not "aún no soportados" any more: the Registry now stores every shape it
		 * ever intends to, so what this counts is a MultiPolygon or a
		 * GeometryCollection, and nothing is waiting to make those work.
		 */
		summary() {
			const r = this.result
			return `${r.created} creados, ${r.updated} actualizados, ${r.unchanged} sin cambios, `
				+ `${r.skipped} omitidos por edición local, ${r.duplicates} duplicados en el archivo, `
				+ `${r.unsupported} con geometría no soportada.`
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
			this.path = ''
			this.error = ''
			this.result = null
		},

		/**
		 * The platform's own file picker, which hands back a PATH — nothing is
		 * read in the browser and nothing is uploaded again (#104).
		 *
		 * ponytail: `OC.dialogs.filepicker`, which Nextcloud 34 still ships and
		 * this app needs no new dependency for. `NcFilePicker` in the installed
		 * @nextcloud/vue wraps an `<input type="file">` and emits browser File
		 * objects, which is the upload above wearing a different coat; the
		 * upgrade path, if core ever drops `OC.dialogs`, is `@nextcloud/dialogs`.
		 *
		 * No mimetype filter: Files guesses a `.geojson` as JSON or as plain
		 * text depending on how it arrived, and filtering on the guess would
		 * hide the very file the person came to import. The suffix picks the
		 * reader, here as on the server.
		 */
		pickFromFiles() {
			if (!window.OC?.dialogs?.filepicker) {
				this.error = 'Esta versión de Nextcloud no ofrece el selector de archivos.'
				return
			}

			window.OC.dialogs.filepicker(
				'Elegir un archivo para importar',
				(path) => {
					this.path = path
					this.file = null
					// The native input keeps displaying the name it was given, so
					// leaving it alone puts a filename on screen beside a hint
					// naming a different file — and only the hint is true about
					// what will be imported. `pick()` clears `path` the same way.
					if (this.$refs.archivo) {
						this.$refs.archivo.value = ''
					}
					this.error = ''
					this.result = null
				},
				false,
				[],
				true,
			)
		},

		async send() {
			this.working = true
			this.error = ''
			this.result = null

			const body = new FormData()
			// One request either way: the server reads the bytes from the path
			// when there is one, and everything after that is the same import.
			if (this.path) {
				body.append('path', this.path)
			} else {
				body.append('file', this.file)
			}
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
