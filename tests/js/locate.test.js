import { describe, expect, it } from 'vitest'
import {
	ACCURACY_LIMIT_M,
	accuracyLabel,
	isFixTrustworthy,
	locateErrorMessage,
} from '../../src/locate.js'

/**
 * «¿Dónde estoy?» — putting the person holding the phone on the map.
 *
 * The reason this needs judgement rather than just a marker: this app decides
 * which Sector and which Unidad Vecinal a point falls in, and those boundaries
 * are streets apart. A browser fix indoors is routinely accurate to hundreds of
 * metres, which lands in the wrong Sector while looking exactly as confident as
 * a good one. So the accuracy is not decoration — it decides whether the fix may
 * be read as «I am here» at all.
 */

describe('isFixTrustworthy', () => {
	it('accepts a fix precise enough to tell one Sector from the next', () => {
		expect(isFixTrustworthy(8)).toBe(true)
		expect(isFixTrustworthy(ACCURACY_LIMIT_M)).toBe(true)
	})

	it('refuses a fix that could be on either side of a boundary', () => {
		// A block in La Florida is well under this. A fix this loose says the
		// comuna, not the corner.
		expect(isFixTrustworthy(ACCURACY_LIMIT_M + 1)).toBe(false)
		expect(isFixTrustworthy(2000)).toBe(false)
	})

	it('treats a missing or nonsense accuracy as untrustworthy', () => {
		// Not as perfect. A reading with no accuracy attached is the one case
		// where believing it costs the most.
		for (const value of [undefined, null, NaN, Infinity, -1, 'muy preciso']) {
			expect(isFixTrustworthy(value)).toBe(false)
		}
	})
})

describe('accuracyLabel', () => {
	it('reads in metres while metres are meaningful', () => {
		expect(accuracyLabel(12)).toBe('±12 m')
		expect(accuracyLabel(12.7)).toBe('±13 m')
	})

	it('switches to kilometres rather than printing four digits of metres', () => {
		expect(accuracyLabel(1500)).toBe('±1,5 km')
		expect(accuracyLabel(12000)).toBe('±12 km')
	})

	it('says nothing rather than something false when there is no accuracy', () => {
		expect(accuracyLabel(undefined)).toBe('')
		expect(accuracyLabel(NaN)).toBe('')
	})
})

describe('locateErrorMessage', () => {
	it('tells a person what to do when they refused the permission', () => {
		// Code 1 is PERMISSION_DENIED. The browser's own message is in English and
		// says nothing about where the switch is.
		expect(locateErrorMessage(1)).toMatch(/permiso/i)
	})

	it('distinguishes «no pude» from «tardé demasiado»', () => {
		expect(locateErrorMessage(2)).not.toBe(locateErrorMessage(3))
		expect(locateErrorMessage(2)).toMatch(/\S/)
		expect(locateErrorMessage(3)).toMatch(/\S/)
	})

	it('still says something for a code nobody has seen', () => {
		expect(locateErrorMessage(99)).toMatch(/\S/)
		expect(locateErrorMessage(undefined)).toMatch(/\S/)
	})

	it('is written in Spanish, like everything else a person reads here', () => {
		for (const code of [1, 2, 3, 99]) {
			expect(locateErrorMessage(code)).not.toMatch(/\b(the|error|failed|denied)\b/i)
		}
	})

	it('addresses the person as usted, the way the rest of the app does', () => {
		// «Haga clic dentro del sector…» sets the register. The first draft of
		// these messages was voseo — «Podés», «probá», «Volvé» — which is not how
		// anybody is addressed in a Chilean clinic, and nothing else in the repo
		// would have caught it.
		for (const code of [1, 2, 3, 99]) {
			expect(locateErrorMessage(code)).not.toMatch(/\b(pod[eé]s|ten[eé]s|volv[eé]|prob[aá]|deb[eé]s|sos)\b/i)
		}
	})
})
