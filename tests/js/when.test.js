// Before any Date is made: the instants below are only wrong in a zone that is not UTC.
process.env.TZ = 'America/Santiago'

import { describe, expect, it } from 'vitest'
import { when } from '../../src/when.js'

/**
 * The one formatter for a stored instant (#211): the history panel, the Importar
 * batch list and the admin export log all say a moment through it.
 *
 * The server sends the instant with its UTC offset; what a person reads is
 * their own wall clock. In Santiago, summer time from 6 September 2026, a
 * 17:30 UTC save happened at 14:30.
 */
describe('when', () => {
	it('says an instant sent in UTC as the local time it was', () => {
		expect(when('2026-10-27T17:30:00+00:00')).toBe(new Date(2026, 9, 27, 14, 30).toLocaleString())
	})

	it('says nothing for a moment that was never recorded', () => {
		expect(when(null)).toBe('')
	})
})
