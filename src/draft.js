/**
 * The Feature↔draft↔payload mapping, out of App.vue (L1-26).
 *
 * Server-contract rules with documented drift-bug history (#48, #49) sat in
 * the one file the js suite cannot load (imports.test.js: "nothing in this
 * repo loads a component"). Here they are a pure module: no Vue, no axios,
 * only the taxonomy and limit helpers it always consumed.
 *
 * `toNumber` moves with payload() — it is the Chilean decimal-comma rule the
 * server side of the contract never sees, and it had exactly one caller.
 */
import { sectorFields } from './limit.js'
import { isSectorSubcategory } from './taxonomy.js'

/** Chilean keyboards produce a decimal comma; Leaflet and GeoJSON want a dot. */
const toNumber = (value) => {
	const text = String(value ?? '').trim().replace(',', '.')
	if (text === '') {
		return null
	}
	const parsed = Number(text)
	return Number.isFinite(parsed) ? parsed : null
}

export function blankDraft() {
	return {
		id: null,
		name: '',
		subcategoryId: null,
		// Set only for a drawn shape. A Place carries lat/lon instead, which
		// is what the form offers when this is null.
		geometry: null,
		colour: null,
		sectorId: null,
		unidadVecinalId: null,
		lat: '',
		lon: '',
		locationQuality: 'exacta',
		address: '',
		phone: '',
		email: '',
		contactPerson: '',
		contactRole: '',
		openingHours: '',
		notes: '',
		sourceRef: '',
	}
}

/**
 * A stored record as the form edits it.
 *
 * The classification question (`isSector`) is asked against the taxonomy the
 * record's Subcategory lives in — hence the `taxonomy` argument: the mapping
 * takes its inputs as arguments a test can supply.
 */
export function draftFrom(feature, taxonomy) {
	const isPoint = feature.geometry?.type === 'Point'
	const [lon, lat] = isPoint ? feature.geometry.coordinates : [null, null]

	return {
		id: feature.id,
		name: feature.name,
		subcategoryId: feature.subcategoryId,
		geometry: isPoint ? null : (feature.geometry ?? null),
		colour: feature.colour ?? null,
		// Read-only, shown rather than edited (ADR-0008). Carried on the draft
		// so the sidebar can name them without asking the server again.
		sectorId: feature.sectorId ?? null,
		unidadVecinalId: feature.unidadVecinalId ?? null,
		// Which Dataset this record came from, or null for one made here.
		// Read-only too, and carried for one reason: a Sector's provenance
		// sentence must not call an imported boundary hand-drawn, and the
		// two look identical in history (issue #50, ADR-0018). Only an
		// import sets it, so it is the record's own answer.
		datasetId: feature.datasetId ?? null,
		// A Sector opened for editing gets the picker too, not only one being
		// drawn — and it comes back with what it was built from (issue #15).
		//
		// `picked` is derived from the Limit rather than stored twice: the
		// Limit is the record, and the ticks in the picker are a view of it.
		// The geometries are NOT reloaded — they arrive as the map brings
		// each line into view — so a Limit reopened and saved untouched keeps
		// its names either way.
		...(isSectorSubcategory(taxonomy, feature.subcategoryId)
			? sectorFields(feature.limit ?? [])
			: {}),
		lat: lat === null ? '' : String(lat),
		lon: lon === null ? '' : String(lon),
		// Revisited for #23, now that the map draws the Exactitud, and kept.
		//
		// `sin_coordenada` is derived from having no geometry and never
		// chosen (`LocationQuality`, `FeatureService::applyGeometry`), so the
		// picker offers two options and NcSelect already falls back to
		// «Exacta» when it is handed a third. The draft has to agree with
		// what the sidebar is showing, because `payload()` spreads the whole
		// draft: left as `sin_coordenada`, the first coordinate a person types
		// into this record would be posted alongside a quality the server
		// refuses outright — «Exactitud desconocida» — while the screen said
		// «Exacta». The record that keeps no coordinate is unaffected either
		// way: an empty geometry re-derives `sin_coordenada` on the server.
		//
		// #83 gave the Exactitud a door of its own, but not to this caller:
		// it opens only when `geometry` is absent from the request, and
		// `payload()` always sends the key.
		locationQuality: feature.locationQuality === 'sin_coordenada'
			? 'exacta'
			: (feature.locationQuality ?? 'exacta'),
		address: feature.address ?? '',
		phone: feature.phone ?? '',
		email: feature.email ?? '',
		contactPerson: feature.contactPerson ?? '',
		contactRole: feature.contactRole ?? '',
		openingHours: feature.openingHours ?? '',
		notes: feature.notes ?? '',
		sourceRef: feature.sourceRef ?? '',
	}
}

/**
 * The draft as the wire wants it.
 *
 * The Sector-only limit rule and the drawn-shape-wins rule are the contract's
 * own: see the comments where each is applied.
 */
export function payload(draft, taxonomy) {
	const lat = toNumber(draft.lat)
	const lon = toNumber(draft.lon)

	// The URL addresses the record, so the body never says `id`: NC 34's
	// dispatcher binds body parameters onto controller arguments by name,
	// and an integer `id` colliding with the route's `string $id` is a
	// TypeError on the invocation line — a bare 400 no log at Warning
	// level ever explains. The draft keeps its id; only the wire is silent.
	// eslint-disable-next-line no-unused-vars -- omitting the key is the point
	const { id, limit, ...record } = draft

	return {
		...record,
		colour: draft.colour ?? '',
		// Sent only for something that is a Sector RIGHT NOW. `draft.limit`
		// is set when the editor opens and survives a change of
		// Subcategory, so a Sector re-classified as a colegio would
		// otherwise post a Limit onto a record that cannot have one —
		// `limit` is destructured out of `record` above precisely so this
		// guard governs the only copy that travels.
		...(limit === undefined || !isSectorSubcategory(taxonomy, draft.subcategoryId)
			? {}
			: { limit }),
		// A drawn shape wins over the coordinate fields, which the form does
		// not even show for one. GeoJSON orders positions [longitude,
		// latitude] — the reverse of how they are said out loud, and of the
		// form's field order.
		geometry: draft.geometry ?? (lat === null || lon === null
			? null
			: { type: 'Point', coordinates: [lon, lat] }),
	}
}
