import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { fold } from '../../src/aps/filters.js'

const corpus = JSON.parse(readFileSync(fileURLToPath(new URL('../../src/aps/fold-corpus.json', import.meta.url))))

describe('aps-common fold corpus (the cross-language contract)', () => {
	it('folds every es-CL corpus entry like the PHP suite does', () => {
		for (const { input, folded } of corpus) {
			expect(fold(input)).toBe(folded)
		}
	})
})
