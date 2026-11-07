/**
 * The Live Layers seam (ADR-0025, issue #259): one pure module, the HTTP
 * client injected, nothing else in the app knowing a URL.
 *
 * A Layer is fetched live from its public API at view time and never stored
 * (CONTEXT.md). Everything the promise of that sentence needs lives here —
 * the registry that says where to look, the adapter that says how to ask,
 * the source that remembers what one view already saw — and everything above
 * it (App.vue's axios binding, TerritorioMap's markers, the Capas panel's
 * toggles) stays thin wiring, per the house rule that modules carry no Vue
 * and no HTTP client of their own.
 *
 * The wire format is not a sketch: the URL below was probed against the live
 * geoportal during design and the fixture beside this module's tests is a
 * response captured from the real service. GeoServer answers 3857 unless
 * asked otherwise, so every request says `srsName=EPSG:4326`; the workspace
 * republishes in place (June replaced February under the same URL), which is
 * one reason nothing here is ever stored.
 */

/** The endpoint answers cross-origin and keyless; the browser goes direct. */
const GEOSERVER_ROOT = 'https://geoportal.cl/geoserver'

/**
 * How many features one page asks for. The largest of the ten services holds
 * 20,701 nationally, but a view is comuna-sized; the page needs to cover a
 * deliberately zoomed-out look at a whole region without the loop below
 * becoming one.
 */
const PAGE_SIZE = 1000

/**
 * The loop's backstop, sized for the real worst case and not for a view: the
 * largest service paged at PAGE_SIZE is 21 pages, so 24 covers every real
 * answer. A service that reaches the cap without saying it is done has broken
 * its own contract, and the load answers as a failure below — the points
 * already collected stay on the map, the badge goes up, and nothing is cached
 * as complete.
 */
const MAX_PAGES = 24

/**
 * The registry. Static on purpose (ADR-0025): the ten national services are
 * added by a release, not by a setting. An entry says where to look and what
 * a point says — never what to keep: a Layer has no records to file, so
 * there is no Dataset, no Subcategory, nothing of the Registry in here.
 */
export const LAYERS = [
	{
		id: 'salud-junio-2026',
		label: 'Establecimientos de salud (Junio 2026)',
		adapter: 'wfs',
		workspace: 'EstablecimientosdesaluddeChile2026',
		typeName: 'establecimientos_de_salud_junio_2026',
		hue: 'salud',
		kind: 'point',
		attribution: 'geoportal.cl — MINSAL · licencia desconocida',
		title: (properties) => properties.nombre,
		fields: (properties) => [
			['Tipo', properties.tipo],
			['Comuna', properties.nom_com],
			['Dependencia', properties.dep_adm],
			['Dirección', `${properties.via ?? ''} ${properties.numero ?? ''}`.trim()],
			['Nivel', properties.nivel],
		],
	},
]

/**
 * The request for one page of one Layer for one view, byte for byte.
 *
 * The bounds arrive in the shape TerritorioMap already emits as
 * `view-changed`, so nothing converts between two "where the map is" shapes.
 * The bbox is the WFS order — west, south, east, north — in the CRS the
 * request names, and the parameters keep one fixed order because the test
 * pins the string, not a sorted bag of parameters.
 *
 * @param {object} layer a registry entry
 * @param {{south: number, west: number, north: number, east: number}} bounds
 * @param {{count?: number, startIndex?: number}} [page] paging, for the source's loop
 * @return {string}
 */
export function urlFor(layer, bounds, page = {}) {
	const count = page.count ?? PAGE_SIZE
	const startIndex = page.startIndex ?? 0

	return `${GEOSERVER_ROOT}/${layer.workspace}/wfs`
		+ '?service=WFS&version=2.0.0&request=GetFeature'
		+ `&typeNames=${layer.workspace}:${layer.typeName}`
		+ '&outputFormat=application/json&srsName=EPSG:4326'
		+ `&bbox=${bounds.west},${bounds.south},${bounds.east},${bounds.north},EPSG:4326`
		+ `&count=${count}&startIndex=${startIndex}`
}

/**
 * A GeoJSON FeatureCollection into the one shape the map and the popup read.
 *
 * The geometry is reversed from the wire ([lon, lat]) into Leaflet's order
 * ([lat, lon]), every point keeps a stable id the cache keys on, and a
 * feature without a point is dropped rather than drawn somewhere wrong. Null
 * fields fall away: the popup shows what the service said, not a row of
 * «null» — the wire carries nulls by design (a posta has no «nivel» to name).
 *
 * @param {object} layer a registry entry
 * @param {{features: Array}} collection the GeoJSON the service answered
 * @return {Array<{id: string, title: string, fields: Array<[string, string]>, at: [number, number]}>}
 */
export function normalise(layer, collection) {
	return (collection.features ?? []).flatMap((feature, index) => {
		const { coordinates, type } = feature.geometry ?? {}
		if (type !== 'Point' || !Array.isArray(coordinates)) {
			return []
		}
		const [lon, lat] = coordinates
		const properties = feature.properties ?? {}

		return [{
			id: `${layer.id}:${feature.id ?? properties.id_orig ?? index}`,
			title: layer.title(properties),
			fields: layer.fields(properties).filter(([, value]) => value !== null && value !== undefined && value !== ''),
			at: [lat, lon],
		}]
	})
}

const contains = (box, bounds) => box !== null
	&& bounds.west >= box.west && bounds.east <= box.east
	&& bounds.south >= box.south && bounds.north <= box.north

const unionOf = (a, b) => a === null ? { ...b } : {
	west: Math.min(a.west, b.west),
	south: Math.min(a.south, b.south),
	east: Math.max(a.east, b.east),
	north: Math.max(a.north, b.north),
}

/**
 * One Layer's session cache: what the view has already paid for.
 *
 * The box is the union of every view fetched so far — and the union is what
 * is FETCHED, not merely remembered: two diagonal pans leave a corner of the
 * union's rectangle that no view ever asked for, so the grown load requests
 * the union itself and the box then covers only ground the wire has covered.
 * Panning back inside the box is free; a genuinely bigger view grows it.
 *
 * A page that reports more than it sent is followed until it says it is done,
 * paged by the wire's own count. A dead service — or one that never says it
 * is done (MAX_PAGES) — is a typed failure that keeps the last good points on
 * the map, and the box is not extended by a failure, so the next view
 * retries rather than caching a hole.
 *
 * Nothing survives the page: App.vue drops the whole source when a Layer is
 * toggled off, which is what "turned off, the map returns to exactly what it
 * showed" costs in state (nothing).
 *
 * @param {object} layer a registry entry
 * @param {(url: string) => Promise<object>} fetchJson the HTTP client, injected
 * @return {{load: (bounds: object) => Promise<{ok: boolean, points: Array, cached?: boolean}>}}
 */
export function createLayerSource(layer, fetchJson) {
	const points = new Map()
	let box = null
	let inflight = null

	async function fetchAll(target) {
		const collected = []
		let startIndex = 0
		let truncated = false

		for (let page = 0; page < MAX_PAGES; page += 1) {
			const body = await fetchJson(urlFor(layer, target, { startIndex }))
			const entries = normalise(layer, body)
			collected.push(...entries)

			// Page by what the wire sent, not by what survived normalising:
			// a dropped feature still advances the service's window.
			startIndex += (body.features ?? []).length
			const total = body.totalFeatures ?? startIndex
			if (entries.length === 0 || startIndex >= total) {
				break
			}
			if (page === MAX_PAGES - 1) {
				truncated = true
			}
		}

		for (const entry of collected) {
			points.set(entry.id, entry)
		}

		if (!truncated) {
			box = target
		}
		return { ok: !truncated, points: [...points.values()] }
	}

	async function load(bounds) {
		if (contains(box, bounds)) {
			return { ok: true, cached: true, points: [...points.values()] }
		}

		// Single flight: a second load while one is running waits for it,
		// then answers from the box if the first view already covers it, and
		// only otherwise goes to the wire itself. Two move-ends in a
		// debounced window must not become two fetches for the same view.
		if (inflight !== null) {
			await inflight.catch(() => {})
			if (contains(box, bounds)) {
				return { ok: true, cached: true, points: [...points.values()] }
			}
		}

		// The union, not the view: what the box will claim must be what was
		// fetched (see the docblock).
		inflight = fetchAll(unionOf(box, bounds))
		try {
			return await inflight
		} catch {
			return { ok: false, points: [...points.values()] }
		} finally {
			inflight = null
		}
	}

	return { load }
}
