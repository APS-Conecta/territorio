import { defineConfig } from 'vitest/config'

// One setup file: it makes any attempt to reach openstreetmap.org throw.
export default defineConfig({
	test: { setupFiles: ['./tests/js/setup-no-osm.js'] },
})
