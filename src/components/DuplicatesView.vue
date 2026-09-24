<template>
	<div class="territorio-duplicados">
		<h2>
			{{ pairs.length }}
			{{ pairs.length === 1 ? 'par por revisar' : 'pares por revisar' }}
		</h2>

		<!-- Three states, not two (L1-28): a queue that failed to load is not a
		     queue with nothing in it — «Nada por revisar» over a network failure
		     read as the pairs having been resolved. -->
		<NcEmptyContent v-if="failed && pairs.length === 0"
			name="No se pudo cargar la cola"
			description="Revise la conexión e inténtelo de nuevo.">
			<template #icon>
				<svg viewBox="0 0 24 24" width="64" height="64" aria-hidden="true">
					<path fill="currentColor" d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z" />
				</svg>
			</template>
			<template #action>
				<NcButton @click="$emit('retry')">
					Reintentar
				</NcButton>
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="pairs.length === 0"
			name="Nada por revisar"
			description="No hay registros que parezcan ser el mismo lugar.">
			<template #icon>
				<svg viewBox="0 0 24 24" width="64" height="64" aria-hidden="true">
					<path fill="currentColor" d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z" />
				</svg>
			</template>
		</NcEmptyContent>

		<!--
			Nothing is merged for anybody. Each card asks one question and offers
			three answers, and two of them keep both records — because a wrong merge
			is the failure nobody notices until they phone the wrong place.
		-->
		<section v-for="pair in pairs" :key="pair.id" class="territorio-duplicados__pair">
			<p class="territorio-duplicados__why">{{ WHY[pair.reason] ?? pair.reason }}</p>

			<div class="territorio-duplicados__sides">
				<article v-for="side in sidesOf(pair)"
					:key="side.id"
					class="territorio-duplicados__side">
					<h3>{{ side.name }}</h3>
					<p v-if="side.address" class="territorio-duplicados__detail">{{ side.address }}</p>
					<p v-if="side.phone" class="territorio-duplicados__detail">{{ side.phone }}</p>
					<p class="territorio-duplicados__detail">{{ labelFor(side) }}</p>

					<NcButton variant="secondary"
						:disabled="busy === pair.id"
						@click="$emit('show', side.id)">
						Abrir ficha
					</NcButton>
					<NcButton variant="primary"
						:disabled="busy === pair.id"
						@click="$emit('merge', { pairId: pair.id, survivorId: side.id })">
						Conservar este
					</NcButton>
				</article>
			</div>

			<NcButton variant="tertiary"
				:disabled="busy === pair.id"
				@click="$emit('dismiss', pair.id)">
				No son duplicados
			</NcButton>
		</section>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { indexSubcategories } from '../taxonomy.js'

/** Why a pair is here, said as the question the reviewer is answering. */
const WHY = {
	coordenada: 'Están en la misma coordenada.',
	nombre: 'Tienen nombres parecidos y están a menos de una cuadra.',
}

export default {
	name: 'DuplicatesView',

	components: { NcButton, NcEmptyContent },

	props: {
		/** Pairs of ids, as the server sends them: it never repeats record data. */
		pairs: { type: Array, required: true },
		/** Every visible record, which is where both sides of a pending pair are. */
		features: { type: Array, required: true },
		taxonomy: { type: Array, required: true },
		/** The pair currently being resolved, so its buttons cannot be pressed twice. */
		busy: { type: Number, default: null },
		// A failed load is not an empty queue (L1-28).
		failed: { type: Boolean, default: false },
	},

	emits: ['merge', 'dismiss', 'retry', 'show'],

	data() {
		return { WHY }
	},

	computed: {
		byId() {
			return new Map(this.features.map((feature) => [feature.id, feature]))
		},

		/** Already "Category · Subcategory" — the same index the list renders from. */
		subcategories() {
			return indexSubcategories(this.taxonomy)
		},
	},

	methods: {
		/**
		 * The two records, or fewer if one has just been resolved away.
		 *
		 * Filtered rather than assumed: a colleague merging the same pair a second
		 * before this poll arrived leaves an id here with nothing behind it, and
		 * rendering a blank card is worse than rendering one side.
		 */
		sidesOf(pair) {
			return [pair.firstId, pair.secondId]
				.map((id) => this.byId.get(id))
				.filter((side) => side !== undefined)
		},

		labelFor(feature) {
			return this.subcategories.get(feature.subcategoryId)?.label ?? 'Clasificación desconocida'
		},
	},
}
</script>

<style scoped>
.territorio-duplicados {
	padding: 12px 16px;
	overflow-y: auto;
}

.territorio-duplicados__pair {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	margin-bottom: 12px;
}

.territorio-duplicados__why {
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px;
}

.territorio-duplicados__sides {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	margin-bottom: 8px;
}

.territorio-duplicados__side {
	flex: 1 1 220px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
}

.territorio-duplicados__side h3 {
	font-size: 1em;
	margin: 0 0 4px;
}

.territorio-duplicados__detail {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0 0 4px;
}
</style>
