/**
 * Makes any unit test that tries to reach openstreetmap.org throw.
 *
 * A test that scrapes the tile service is how an application lands on
 * OpenStreetMap's blocked list, so the guard sits at the three doors a request
 * can use instead of trusting every test to mock correctly. Hosts match on the
 * name itself or a subdomain, so `notopenstreetmap.org` is left alone.
 *
 * The XHR and image doors only exist under a DOM environment; here they are
 * patched when present and skipped when not.
 */
const isOsm = (url) => {
	try {
		const { hostname } = new URL(String(url?.url ?? url), 'http://localhost')

		// A trailing dot is the same host, fully qualified, and a valid way in.
		const host = hostname.replace(/\.$/, '')

		return host === 'openstreetmap.org' || host.endsWith('.openstreetmap.org')
	} catch {
		return false
	}
}

const refusal = (url) => new Error(`Unit tests must not reach openstreetmap.org (${url?.url ?? url})`)

const refuse = (url) => {
	throw refusal(url)
}

export function blockOsm(target) {
	const { fetch, XMLHttpRequest, HTMLImageElement } = target

	if (fetch) {
		// A rejection and not a throw, because that is how the real fetch fails.
		target.fetch = (input, ...rest) => (isOsm(input) ? Promise.reject(refusal(input)) : fetch(input, ...rest))
		// Lets a test confirm the guard is installed BEFORE it calls fetch, so a
		// dropped setup file fails the test instead of hitting the network.
		target.fetch.__osmGuard = true
	}

	if (XMLHttpRequest) {
		const open = XMLHttpRequest.prototype.open
		XMLHttpRequest.prototype.open = function (method, url, ...rest) {
			return isOsm(url) ? refuse(url) : open.call(this, method, url, ...rest)
		}
	}

	const src = HTMLImageElement && Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'src')
	if (src?.set) {
		Object.defineProperty(HTMLImageElement.prototype, 'src', {
			...src,
			set(value) {
				return isOsm(value) ? refuse(value) : src.set.call(this, value)
			},
		})
	}
}

blockOsm(globalThis)
