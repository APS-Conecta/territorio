<template>
	<NcContent app-name="territorio">
		<NcAppContent>
			<TerritorioMap />
		</NcAppContent>
	</NcContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import TerritorioMap from './components/TerritorioMap.vue'

export default {
	name: 'App',
	components: { NcAppContent, NcContent, TerritorioMap },
}
</script>

<style>
/* The map is the whole screen: give it every pixel NcAppContent has. */
#territorio .app-content-wrapper {
	height: 100%;
}
</style>
