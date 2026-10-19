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

		return hostname === 'openstreetmap.org' || hostname.endsWith('.openstreetmap.org')
	} catch {
		return false
	}
}

const refuse = (url) => {
	throw new Error(`Unit tests must not reach openstreetmap.org (${url?.url ?? url})`)
}

export function blockOsm(target) {
	const { fetch, XMLHttpRequest, HTMLImageElement } = target

	if (fetch) {
		target.fetch = (input, ...rest) => (isOsm(input) ? refuse(input) : fetch(input, ...rest))
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
