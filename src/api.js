/**
 * The client's one error shape (L1-25, L1-29).
 *
 * `messageFrom` is the idiom thirteen catch blocks hand-rolled — the server's
 * Spanish sentence when there is one, a fallback that does not pretend
 * otherwise when there is not — INCLUDING the Blob case: a response requested
 * as a Blob arrives as one in the error body too, `data.message` is undefined
 * there, and the subtlety existed only because the shape was never factored.
 *
 * `safeError` is the console's half of the same rule: an AxiosError serialises
 * its `config.data` — the request body — and a failed Guardar was putting the
 * record's clinical contact data into devtools for the page session. It logs a
 * stable label plus `exception.message` and the HTTP status, never the object.
 */

/**
 * The message a person should read, or `fallback` — as an awaitable because
 * the Blob path is.
 *
 * @param {unknown} exception whatever axios threw
 * @param {string} fallback the sentence for “no response, or none in Spanish”
 * @return {Promise<string>}
 */
export async function messageFrom(exception, fallback) {
	const data = exception?.response?.data

	if (typeof Blob !== 'undefined' && data instanceof Blob) {
		try {
			return JSON.parse(await data.text()).message ?? fallback
		} catch {
			// Not JSON, not readable, or no response at all — a dropped
			// connection looks like this.
			return fallback
		}
	}

	return data?.message ?? fallback
}

/**
 * `console.warn` with nothing that carries a request body — the read paths
 * that fail softly. Same body as `safeError`; one flag rather than two keeps
 * the adopters honest (L1-25).
 *
 * @param {string} label what failed, e.g. 'could not load the history'
 * @param {unknown} exception whatever axios threw
 */
export function warnError(label, exception) {
	const status = exception?.response?.status
	console.warn(`territorio: ${label} (${status ?? 'sin respuesta'})`,
		exception?.message ?? String(exception))
}

/**
 * `console.error` with nothing that carries a request body.
 *
 * The label is the stable half a person greps for; `exception.message` and the
 * status are the diagnosis. Never the exception object: serialised, it is the
 * request the person just made — names, phones, emails.
 *
 * @param {string} label what failed, e.g. 'could not save the feature'
 * @param {unknown} exception whatever axios threw
 */
export function safeError(label, exception) {
	const status = exception?.response?.status
	console.error(`territorio: ${label} (${status ?? 'sin respuesta'})`,
		exception?.message ?? String(exception))
}
