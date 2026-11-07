import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { createLayerSource, LAYERS, normalise, urlFor } from '../../src/layers.js'

/**
 * The Live Layers seam (ADR-0025, issue #259): one pure module, the HTTP
 * client injected, everything else in the app thin above it. The wire
 * contract below is not a guess — every URL was probed against the live
 * geoportal during design, and the fixture is a response captured from the
 * real service (4 of the 81 establishments in that box), so the normaliser
 * meets the true property names, not a sketch of them.
 */

const fixture = JSON.parse(readFileSync(
	fileURLToPath(new URL('./fixtures/layers-salud-wfs.json', import.meta.url)),
	'utf8',
))

const BOUNDS = { south: -33.58, west: -70.68, north: -33.5, east: -70.6 }

/** The exact request the adapter builds, byte for byte, for the given page. */
const expectedUrl = ({ count = 1000, startIndex = 0 }) => 'https://geoportal.cl/geoserver/EstablecimientosdesaluddeChile2026/wfs'
	+ '?service=WFS&version=2.0.0&request=GetFeature'
	+ '&typeNames=EstablecimientosdesaluddeChile2026:establecimientos_de_salud_junio_2026'
	+ '&outputFormat=application/json&srsName=EPSG:4326'
	+ `&bbox=-70.68,-33.58,-70.6,-33.5,EPSG:4326&count=${count}&startIndex=${startIndex}`

/** A page the fake service answers with: a slice of the captured response. */
const pageOf = (features, totalFeatures) => ({ ...fixture, totalFeatures, features })

/** The captured page wearing ids the first page does not have. */
const pageTwo = () => fixture.features.map((feature, i) => ({ ...feature, id: 9000 + i }))

describe('the registry', () => {
	it('carries exactly the one tracer Layer, pinned', () => {
		expect(LAYERS).toHaveLength(1)

		const [salud] = LAYERS
		expect(salud.id).toBe('salud-junio-2026')
		expect(salud.adapter).toBe('wfs')
		expect(salud.kind).toBe('point')
		expect(salud.hue).toBe('salud')
		expect(salud.label).toBe('Establecimientos de salud (Junio 2026)')
		expect(salud.attribution).toContain('MINSAL')
	})

	it('is the vocabulary of #257: a Layer is not a Feature and never becomes one', () => {
		// The registry says nothing about records, ids in the Registry or
		// persistence: its entries describe where to LOOK, never what to keep.
		expect(Object.keys(LAYERS[0])).toEqual(expect.arrayContaining(['id', 'adapter', 'kind']))
		expect(Object.keys(LAYERS[0])).not.toContain('datasetId')
	})
})

describe('urlFor', () => {
	it('builds the live-probed salud URL byte for byte', () => {
		expect(urlFor(LAYERS[0], BOUNDS)).toBe(expectedUrl({}))
	})

	it('carries the page and asks GeoServer for EPSG:4326 — it answers 3857 otherwise', () => {
		expect(urlFor(LAYERS[0], BOUNDS, { count: 4, startIndex: 4 })).toBe(expectedUrl({ count: 4, startIndex: 4 }))
	})
})

describe('normalise', () => {
	it('reads the captured wire format: title, mapped fields, lat/lon in Leaflet order', () => {
		const [entry] = normalise(LAYERS[0], fixture)

		expect(entry.title).toBe('Centro Odontologico Uno Salud Dental Gran Avenida')
		expect(entry.at).toEqual([-33.504559, -70.655472])
		expect(entry.fields).toContainEqual(['Tipo', 'Clínica Dental'])
		expect(entry.fields).toContainEqual(['Comuna', 'San Miguel'])
		expect(entry.fields).toContainEqual(['Dirección', 'Avenida 5553'])
		expect(entry.fields).toContainEqual(['Dependencia', 'Privado'])
		expect(entry.fields).toContainEqual(['Nivel', 'Secundario'])
	})

	it('gives every point a stable id the cache can key on', () => {
		const entries = normalise(LAYERS[0], fixture)
		expect(new Set(entries.map((entry) => entry.id)).size).toBe(entries.length)
		expect(entries[0].id).toContain('salud-junio-2026')
	})

	it('drops a feature with no point rather than drawing a wrong place', () => {
		const broken = { ...fixture, features: [{ ...fixture.features[0], geometry: null }] }
		expect(normalise(LAYERS[0], broken)).toHaveLength(0)
	})
})

describe('createLayerSource', () => {
	/** A fake network: answers by URL, records every request it saw. A null
	 * answer is a dead service — the fetch itself fails. */
	const fakeService = (pages) => {
		const seen = []
		const fetchJson = async (url) => {
			seen.push(url)
			const answer = pages.shift()
			if (answer === undefined || answer === null) {
				throw new Error('sin respuesta')
			}
			return answer
		}
		return { seen, fetchJson }
	}

	it('fetches once for the first view, then not again inside the cached box', async () => {
		const { seen, fetchJson } = fakeService([pageOf(fixture.features, 4)])
		const source = createLayerSource(LAYERS[0], fetchJson)

		const first = await source.load(BOUNDS)
		expect(seen).toHaveLength(1)
		expect(first.ok).toBe(true)
		expect(first.points).toHaveLength(4)

		// Panning inside what was fetched: the map moves, the network does not.
		await source.load({ south: -33.56, west: -70.66, north: -33.52, east: -70.62 })
		expect(seen).toHaveLength(1)
	})

	it('refetches the union when the view grows, so the box only covers fetched ground', async () => {
		const { seen, fetchJson } = fakeService([
			pageOf(fixture.features, 4),
			pageOf(pageTwo(), 4),
		])
		const source = createLayerSource(LAYERS[0], fetchJson)
		await source.load(BOUNDS)

		// Wider than before: the new request carries the UNION of both views,
		// not just the new one — the box is about to claim it, so the wire
		// must have covered it.
		await source.load({ south: -33.6, west: -70.7, north: -33.48, east: -70.58 })
		expect(seen).toHaveLength(2)
		// The grown view swallows the first, so the union IS it — every corner
		// of the box the cache is about to claim was on the wire.
		expect(seen[1]).toContain('bbox=-70.7,-33.6,-70.58,-33.48')

		// And the union now covers the first view too — still two requests.
		await source.load(BOUNDS)
		expect(seen).toHaveLength(2)
	})

	it('leaves no cached hole under two diagonal pans', async () => {
		// Two views overlapping only diagonally: the union's far corners were
		// in NEITHER view, and a cache that remembered the union without
		// fetching it would later serve those corners as complete emptiness.
		const { seen, fetchJson } = fakeService([
			pageOf(fixture.features, 4),
			pageOf(pageTwo(), 4),
		])
		const source = createLayerSource(LAYERS[0], fetchJson)

		await source.load({ south: -33.58, west: -70.68, north: -33.5, east: -70.6 })
		await source.load({ south: -33.54, west: -70.64, north: -33.46, east: -70.56 })
		expect(seen).toHaveLength(2)
		// The union of the two diagonal views, both corners inside.
		expect(seen[1]).toContain('bbox=-70.68,-33.58,-70.56,-33.46')

		// The far corner the union added: free, because it was truly fetched.
		await source.load({ south: -33.58, west: -70.64, north: -33.52, east: -70.58 })
		expect(seen).toHaveLength(2)
	})

	it('pages a truncated answer with startIndex until the service says it is done', async () => {
		const { seen, fetchJson } = fakeService([
			pageOf(fixture.features, 8),
			pageOf(pageTwo(), 8),
		])
		const source = createLayerSource(LAYERS[0], fetchJson)

		const result = await source.load(BOUNDS)

		expect(seen).toHaveLength(2)
		expect(seen[1]).toContain('startIndex=4')
		expect(result.points).toHaveLength(8)
	})

	it('refuses to cache a service that never says it is done', async () => {
		// Every page reports a total far beyond what it sends, forever: the
		// loop stops at its backstop, the load answers as a failure with the
		// points it did collect, and the box is not extended — so the same
		// view asked again goes back to the wire rather than being served as
		// complete.
		const endless = Array.from({ length: 50 }, () => pageOf(pageTwo(), 100000))
		const { seen, fetchJson } = fakeService(endless)
		const source = createLayerSource(LAYERS[0], fetchJson)

		const first = await source.load(BOUNDS)
		expect(first.ok).toBe(false)
		expect(first.points.length).toBeGreaterThan(0)
		expect(seen).toHaveLength(24)

		await source.load(BOUNDS)
		expect(seen).toHaveLength(48)
	})

	it('answers a typed failure, keeps the last good points, and recovers on the next view', async () => {
		const recoveredPage = fixture.features.map((feature, i) => ({ ...feature, id: 9100 + i }))
		const { fetchJson } = fakeService([pageOf(fixture.features, 4), null, pageOf(recoveredPage, 4)])
		const source = createLayerSource(LAYERS[0], fetchJson)
		await source.load(BOUNDS)

		// The grown view finds the service dead.
		const failure = await source.load({ south: -33.6, west: -70.7, north: -33.48, east: -70.58 })
		expect(failure.ok).toBe(false)
		expect(failure.points).toHaveLength(4) // the map keeps its last good points

		// And the view after that finds it alive again.
		const recovered = await source.load({ south: -33.62, west: -70.72, north: -33.46, east: -70.56 })
		expect(recovered.ok).toBe(true)
		expect(recovered.points).toHaveLength(8)
	})

	it('never runs two fetches at once: a second load waits for the first', async () => {
		const { seen, fetchJson } = fakeService([pageOf(fixture.features, 4), pageOf(pageTwo(), 4)])
		const source = createLayerSource(LAYERS[0], fetchJson)

		const [first, second] = await Promise.all([
			source.load(BOUNDS),
			source.load(BOUNDS),
		])

		// Two callers, one page on the wire: the second saw the first's answer.
		expect(seen).toHaveLength(1)
		expect(second.points).toEqual(first.points)
	})
})
