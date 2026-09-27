import { describe, expect, it, vi } from 'vitest'

/**
 * A catalogue chunk lost to one network blip is not lost for the session (L2-04).
 *
 * Its own file, not glyphs.test.js: that suite loads the real Maki package once
 * and reads it from every case, and this one needs a fresh module whose first
 * import REJECTS. `vi.resetModules()` gives it that module; `vi.doMock` makes
 * the chunk fail once and then unmocking lets the second ask succeed.
 */
describe('loadCatalogue after a failed chunk', () => {
	it('forgets the rejection so the next open asks again', async () => {
		vi.resetModules()
		vi.doMock('@mapbox/maki/browser.esm.js', () => {
			throw new Error('ChunkLoadError')
		})
		const { loadCatalogue, CATALOGUE } = await import('../../src/glyphs.js')

		await expect(loadCatalogue()).rejects.toThrow()
		expect(CATALOGUE).toEqual([])

		vi.doUnmock('@mapbox/maki/browser.esm.js')
		await expect(loadCatalogue()).resolves.toBe(CATALOGUE)
		expect(CATALOGUE.length).toBeGreaterThan(0)
	})
})
