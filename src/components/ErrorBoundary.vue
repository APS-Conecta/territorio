<!--
	The last thing between a render error and a blank page.

	App.vue mounts the map with `v-show`, never `v-if`, so the Leaflet instance
	survives a pane switch and keeps the viewport the user panned to. That is the
	right call and it is also what makes the blast radius of a throwing pane the
	whole app: the map is mounted from first paint whichever pane is showing, so an
	error while drawing it takes the switch, the Índice and Análisis down with it,
	before anyone has chosen anything. Vue's default for an unhandled render error
	is a page with nothing on it, which looks exactly like the instance being down —
	and this app draws geometry a clinic supplied, which is the part most likely to
	arrive in a shape nobody anticipated.

	Two boundaries, not one per pane: around `.territorio-main`, and around the
	sidebar's content (L0-02) — the two subtrees that render stored geometry. The
	Capas panel and the Datos list are in NcAppContent's `#list` slot, outside
	both, so they keep working as navigation while a column shows the fallback —
	that is the whole reason the boundaries go there and not around NcContent.

	Reintentar clears the error and re-renders the slot (L0-06): most render
	errors here come from one record's shape, and the person has usually moved
	on from it by the time they click. In a development build the message is
	shown under the sentence; in production it stays in the console, where the
	propagation below puts it.

	It does NOT stop propagation. `errorCaptured` returning `false` would keep the
	error from ever reaching Vue's own handler, so the fallback below would render
	and the console would stay clean — the app would look handled and be
	undiagnosable, which is a worse state than the crash it replaces. Returning
	nothing renders the fallback AND lets the error travel.

	What it does not catch, said out loud so nobody reads it as more than it is:
	`errorCaptured` sees render, watcher and lifecycle errors in the components
	below it. It does not see a throw inside a DOM event handler or a rejected
	promise, which is where a good deal of the Leaflet and GeoMan surface lives —
	a click handler that throws still reaches the console and still leaves the map
	as it was. This turns a blank page into a sentence; it is not a safety net for
	the whole app.
-->
<template>
	<NcEmptyContent v-if="error"
		name="No pudimos mostrar esta sección"
		:description="description">
		<template #action>
			<NcButton @click="error = null">
				Reintentar
			</NcButton>
		</template>
		<template #icon>
			<svg viewBox="0 0 24 24" width="64" height="64" aria-hidden="true">
				<path fill="currentColor"
					d="M12 2 1 21h22L12 2Zm0 4 7.5 13h-15L12 6Zm-1 5v4h2v-4h-2Zm0 5v2h2v-2h-2Z" />
			</svg>
		</template>
	</NcEmptyContent>
	<slot v-else />
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

const SENTENCE = 'Algo falló al dibujar esta página. Recargar suele resolverlo. '
	+ 'El mapa y los datos siguen guardados.'

export default {
	name: 'ErrorBoundary',

	components: { NcButton, NcEmptyContent },

	data() {
		return { error: null }
	},

	computed: {
		/** The sentence, plus the actual message where a developer is reading. */
		description() {
			// `process.env.NODE_ENV` is webpack's mode, substituted at build time.
			return process.env.NODE_ENV === 'production' || !this.error?.message
				? SENTENCE
				: `${SENTENCE} (${this.error.message})`
		},
	},

	// No `return false`: see the comment at the top of this file. The fallback
	// renders either way; what the return value decides is whether anyone ever
	// finds out why.
	errorCaptured(err) {
		this.error = err
	},
}
</script>
