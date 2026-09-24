import { describe, expect, it, vi } from 'vitest'
import { messageFrom, safeError, warnError } from '../../src/api.js'

describe('messageFrom', () => {
	it('reads the server\'s Spanish sentence when there is one', async () => {
		const exception = { response: { data: { message: 'El registro necesita un nombre.' } } }
		await expect(messageFrom(exception, 'fallback')).resolves.toBe('El registro necesita un nombre.')
	})

	it('falls back — never invents — when nothing Spanish arrived', async () => {
		await expect(messageFrom({}, 'No se pudo guardar.')).resolves.toBe('No se pudo guardar.')
		await expect(messageFrom({ response: { data: {} } }, 'No se pudo guardar.')).resolves.toBe('No se pudo guardar.')
	})

	it('reads a Blob body — the export\'s error path', async () => {
		const blob = new Blob([JSON.stringify({ message: 'Formato desconocido: xyz' })])
		await expect(messageFrom({ response: { data: blob } }, 'x')).resolves.toBe('Formato desconocido: xyz')
		await expect(messageFrom({ response: { data: new Blob(['not json']) } }, 'x')).resolves.toBe('x')
	})
})

describe('safeError / warnError', () => {
	it('logs the label, the message and the status — never the object', () => {
		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		const exception = { message: 'Request failed', response: { status: 500 } }

		safeError('could not save the feature', exception)

		// THE pin (L1-25): both arguments are STRINGS — an object carrying
		// config.data must never reach the console, so the non-string list is
		// empty, always.
		expect(error).toHaveBeenCalledOnce()
		const logged = error.mock.calls[0]
		expect(logged.filter((argument) => typeof argument !== 'string'), 'a non-string argument reached the console').toEqual([])
		expect(logged.some((a) => a === exception)).toBe(false)
		expect(logged[0]).toContain('territorio: could not save the feature (500)')
		expect(logged.at(-1)).toBe('Request failed')

		error.mockRestore()
	})

	it('warns the same shape on the read paths', () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const exception = { message: 'Network Error' }

		warnError('could not load the history', exception)

		expect(warn).toHaveBeenCalledOnce()
		const logged = warn.mock.calls[0]
		expect(logged.filter((argument) => typeof argument !== 'string')).toEqual([])
		expect(logged[0]).toContain('territorio: could not load the history (sin respuesta)')
		expect(logged.at(-1)).toBe('Network Error')

		warn.mockRestore()
	})
})
