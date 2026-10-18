/**
 * Pure functions for Community Assets (Activos Comunitarios) management,
 * proactive duplicate prevention, filtering, sorting, and Omnibox search.
 *
 * Adheres to DRY, KISS, YAGNI, and SOLID.
 */
import { fold } from './filters.js'
import { textMatches } from './aps/filters.js'
import { boundsOfGeometry } from './taxonomy.js'

/** Metres per degree of latitude in Chile (matches BoundingBox::METRES_PER_DEGREE in backend). */
export const METRES_PER_DEGREE = 111320.0

/** Threshold in metres where two pins are considered the exact same physical place. */
export const SAME_PLACE_METRES = 25.0

/** Threshold in metres where an alike name is considered a likely duplicate candidate. */
export const SAME_BLOCK_METRES = 150.0

/** Name similarity threshold percentage (matches DuplicateFinder::ALIKE in backend). */
export const ALIKE_THRESHOLD = 0.85

/**
 * Calculates equirectangular distance in metres between two coordinate pairs.
 * Fast, pure, and accurate within millimetres over short distances.
 *
 * @param {number|null} lat1
 * @param {number|null} lon1
 * @param {number|null} lat2
 * @param {number|null} lon2
 * @return {number} Distance in metres, or Infinity if coordinates are invalid.
 */
export function metresBetween(lat1, lon1, lat2, lon2) {
	if (lat1 == null || lon1 == null || lat2 == null || lon2 == null) {
		return Infinity
	}
	const nLat1 = Number(lat1)
	const nLon1 = Number(lon1)
	const nLat2 = Number(lat2)
	const nLon2 = Number(lon2)

	if (Number.isNaN(nLat1) || Number.isNaN(nLon1) || Number.isNaN(nLat2) || Number.isNaN(nLon2)) {
		return Infinity
	}

	const dLat = (nLat2 - nLat1) * METRES_PER_DEGREE
	const meanLatRad = ((nLat1 + nLat2) / 2) * (Math.PI / 180)
	const dLon = (nLon2 - nLon1) * METRES_PER_DEGREE * Math.cos(meanLatRad)

	return Math.hypot(dLat, dLon)
}

/**
 * Folds accents, converts to lowercase, strips punctuation and collapses whitespace.
 *
 * @param {string|null} value
 * @return {string}
 */
export function normalizeName(value) {
	if (value == null) {
		return ''
	}
	return fold(value)
		.replace(/[.,/#!$%^&*;:{}=\-_`~()?[\]]/g, ' ')
		.replace(/\s+/g, ' ')
		.trim()
}

/**
 * Computes Levenshtein edit distance between two strings using two-row memory buffer.
 *
 * @param {string} a
 * @param {string} b
 * @return {number}
 */
export function levenshteinDistance(a, b) {
	if (a === b) {
		return 0
	}
	if (a.length === 0) {
		return b.length
	}
	if (b.length === 0) {
		return a.length
	}

	let prev = Array.from({ length: b.length + 1 }, (_, i) => i)
	let curr = new Array(b.length + 1)

	for (let i = 0; i < a.length; i++) {
		curr[0] = i + 1
		for (let j = 0; j < b.length; j++) {
			const cost = a[i] === b[j] ? 0 : 1
			curr[j + 1] = Math.min(
				curr[j] + 1, // insertion
				prev[j + 1] + 1, // deletion
				prev[j] + cost, // substitution
			)
		}
		const temp = prev
		prev = curr
		curr = temp
	}

	return prev[b.length]
}

/**
 * Checks whether two names are alike (same place written differently).
 * Mirrors backend DuplicateFinder::alike:
 * 1. Folded equality.
 * 2. Substring containment ("CESFAM Bellavista" vs "CESFAM Bellavista (SSMSO)").
 * 3. Levenshtein similarity >= ALIKE_THRESHOLD.
 *
 * @param {string|null} one
 * @param {string|null} other
 * @return {boolean}
 */
export function alike(one, other) {
	const a = normalizeName(one)
	const b = normalizeName(other)

	if (a === '' || b === '') {
		return false
	}
	if (a === b) {
		return true
	}
	if (a.includes(b) || b.includes(a)) {
		return true
	}

	const maxLen = Math.max(a.length, b.length)
	const distance = levenshteinDistance(a, b)
	const similarity = 1 - (distance / maxLen)

	return similarity >= ALIKE_THRESHOLD
}

/**
 * Extracts latitude and longitude from feature or draft object.
 * Supports flat { lat, lon } or GeoJSON geometry { coordinates: [lon, lat] }.
 */
function extractCoordinates(item) {
	if (!item) {
		return { lat: null, lon: null }
	}
	// A draft's coordinates are text fields, and '' is a record that has none.
	if (item.lat != null && item.lon != null && item.lat !== '' && item.lon !== '') {
		return { lat: item.lat, lon: item.lon }
	}
	if (item.geometry?.type === 'Point' && Array.isArray(item.geometry.coordinates)) {
		return { lat: item.geometry.coordinates[1], lon: item.geometry.coordinates[0] }
	}
	return { lat: null, lon: null }
}

/**
 * Finds if a draft place has a potential duplicate among existing features.
 *
 * @param {object} draft The asset being created or edited.
 * @param {Array<object>} existingFeatures Existing features in the database/store.
 * @param {object} options Threshold options.
 * @return {object|null} Candidate match { feature, reason, distance } or null.
 */
export function findDuplicateCandidate(draft, existingFeatures = [], options = {}) {
	const samePlace = options.samePlace ?? SAME_PLACE_METRES
	const sameBlock = options.sameBlock ?? SAME_BLOCK_METRES

	const draftCoords = extractCoordinates(draft)
	if (draftCoords.lat == null || draftCoords.lon == null) {
		return null
	}

	const draftName = draft.name ?? ''

	for (const feature of existingFeatures) {
		// Ignore self comparison when editing
		if (draft.id != null && feature.id === draft.id) {
			continue
		}
		// Ignore retired records
		if (feature.retired) {
			continue
		}

		const featCoords = extractCoordinates(feature)
		if (featCoords.lat == null || featCoords.lon == null) {
			continue
		}

		const distance = metresBetween(draftCoords.lat, draftCoords.lon, featCoords.lat, featCoords.lon)

		// 1. Same coordinate (physical collision)
		if (distance <= samePlace) {
			return {
				feature,
				reason: 'coordenada',
				distance,
			}
		}

		// 2. Same block and alike name
		if (distance <= sameBlock && alike(draftName, feature.name)) {
			return {
				feature,
				reason: 'nombre',
				distance,
			}
		}
	}

	return null
}

/**
 * Returns true if the draft has a potential duplicate.
 */
export function isPotentialDuplicate(draft, existingFeatures = [], options = {}) {
	return findDuplicateCandidate(draft, existingFeatures, options) !== null
}

/**
 * Filters community assets by multi-faceted criteria.
 *
 * @param {Array<object>} features
 * @param {object} criteria
 * @param {Map<number, object>} subcategories
 * @return {Array<object>}
 */
export function filterAssets(features = [], criteria = {}, subcategories = new Map()) {
	const {
		sectorId,
		unidadVecinalId,
		locationQuality,
		subcategoryId,
		categoryId,
		query,
		showRetired = false,
	} = criteria

	const needle = query ? fold(query).trim() : ''

	return features.filter((feature) => {
		// Retired status gate
		const isRetired = Boolean(feature.retired)
		if (showRetired !== isRetired) {
			return false
		}

		// Sector filter
		if (sectorId !== undefined) {
			if (sectorId === null) {
				if (feature.sectorId != null) {
					return false
				}
			} else if (feature.sectorId !== sectorId) {
				return false
			}
		}

		// Unidad Vecinal filter
		if (unidadVecinalId !== undefined) {
			if (unidadVecinalId === null) {
				if (feature.unidadVecinalId != null) {
					return false
				}
			} else if (feature.unidadVecinalId !== unidadVecinalId) {
				return false
			}
		}

		// Location quality filter
		if (locationQuality && feature.locationQuality !== locationQuality) {
			return false
		}

		// Subcategory filter
		if (subcategoryId != null && feature.subcategoryId !== subcategoryId) {
			return false
		}

		// Category filter
		if (categoryId != null) {
			const sub = subcategories.get(feature.subcategoryId)
			if (!sub || sub.categoryId !== categoryId) {
				return false
			}
		}

		// Query text search
		if (needle !== '') {
			const sub = subcategories.get(feature.subcategoryId)
			const matchesText = textMatches(needle, [
				feature.name,
				feature.address,
				feature.contactPerson,
				feature.notes,
				sub?.label,
				sub?.categoryLabel,
			])
			if (!matchesText) {
				return false
			}
		}

		return true
	})
}

/**
 * Sorts community assets purely without mutating input array.
 *
 * @param {Array<object>} features
 * @param {object} sortOptions
 * @return {Array<object>}
 */
export function sortAssets(features = [], { sortBy = 'name', sortOrder = 'asc' } = {}) {
	const sorted = [...features]
	const orderMultiplier = sortOrder === 'desc' ? -1 : 1

	sorted.sort((a, b) => {
		if (sortBy === 'revision') {
			const revA = a.revision ?? 0
			const revB = b.revision ?? 0
			return (revA - revB) * orderMultiplier
		}

		if (sortBy === 'sector') {
			const secA = String(a.sectorName ?? a.sectorId ?? '')
			const secB = String(b.sectorName ?? b.sectorId ?? '')
			return secA.localeCompare(secB, 'es', { sensitivity: 'base' }) * orderMultiplier
		}

		// Default: alphabetical by name
		const nameA = String(a.name ?? '')
		const nameB = String(b.name ?? '')
		return nameA.localeCompare(nameB, 'es', { sensitivity: 'base' }) * orderMultiplier
	})

	return sorted
}

/**
 * Where the map should go to show one record.
 *
 * A record carries GeoJSON `geometry`, never flat `lat`/`lon`: a Place is a
 * Point, a Sector or Unidad Vecinal a Polygon or MultiPolygon, and a record
 * without coordinates has `geometry: null` and is valid (`sin_coordenada`).
 *
 * @param {object|null|undefined} geometry The record's GeoJSON geometry.
 * @return {{kind: 'point', lat: number, lon: number}|{kind: 'bounds', bounds: number[][]}|null}
 *         A point to pan to, an area to fit, or null when there is nowhere to go.
 */
export function viewTargetOf(geometry) {
	const bounds = boundsOfGeometry(geometry)
	if (bounds === null) {
		return null
	}
	if (geometry.type === 'Point') {
		// Longitude first in GeoJSON; 0 is a real coordinate, so no truthiness test.
		const [lon, lat] = geometry.coordinates
		return { kind: 'point', lat, lon }
	}
	return { kind: 'bounds', bounds }
}

/**
 * Matches community assets and boundary streets for the Omnibox.
 *
 * @param {string|null} query
 * @param {object} data
 * @return {Array<object>} Unified results ranked by relevance.
 */
export function matchOmnibox(query, { assets = [], streets = [], subcategories = new Map(), limit = 8 } = {}) {
	if (!query) {
		return []
	}
	const needle = fold(query).trim()
	if (needle === '') {
		return []
	}

	const results = []

	// Match streets
	for (const street of streets) {
		const foldedName = fold(street.name).trim()
		if (foldedName.includes(needle)) {
			const score = foldedName.startsWith(needle) ? 100 : 70
			// The server's own row, `{ name, south, west, north, east, count }`
			// (BoundaryService::names()): goToStreet fits the map to the four edges.
			results.push({
				kind: 'street',
				name: street.name,
				south: street.south,
				west: street.west,
				north: street.north,
				east: street.east,
				score,
			})
		}
	}

	// Match assets
	for (const asset of assets) {
		const sub = subcategories.get(asset.subcategoryId)
		const foldedName = fold(asset.name).trim()
		const foldedAddr = fold(asset.address).trim()
		// The two halves, not `sub.label`: that is the composed "Category ·
		// Subcategory", which would show the category twice in the badge.
		const subLabel = sub?.subcategoryLabel ?? ''
		const catLabel = sub?.categoryLabel ?? ''

		let score = 0
		if (foldedName.startsWith(needle)) {
			score = 95
		} else if (foldedName.includes(needle)) {
			score = 80
		} else if (foldedAddr.includes(needle)) {
			score = 60
		} else if (fold(subLabel).includes(needle) || fold(catLabel).includes(needle)) {
			score = 50
		}

		if (score > 0) {
			results.push({
				kind: 'asset',
				id: asset.id,
				name: asset.name,
				address: asset.address,
				categoryLabel: catLabel,
				subcategoryLabel: subLabel,
				score,
			})
		}
	}

	// Sort by score descending
	results.sort((a, b) => b.score - a.score)

	return results.slice(0, limit)
}
