import booleanPointInPolygon from '@turf/boolean-point-in-polygon'

/** Geographic selection is many-to-many; stored clinical assignments stay unchanged. */
export function intersectsArea(geometry, area) {
	if (!geometry || !area || !['Polygon', 'MultiPolygon'].includes(area.type)) {
		return false
	}
	if (geometry.type === 'Point') {
		return booleanPointInPolygon(geometry, area)
	}
	if (!['Polygon', 'MultiPolygon', 'LineString', 'MultiLineString'].includes(geometry.type)) {
		return false
	}

	const paths = pathsOf(geometry)
	const boundaries = pathsOf(area)
	if (paths.some(path => path.some(coordinate => booleanPointInPolygon(coordinate, area)))) {
		return true
	}
	if (['Polygon', 'MultiPolygon'].includes(geometry.type)
		&& boundaries.some(path => path.some(coordinate => booleanPointInPolygon(coordinate, geometry)))) {
		return true
	}
	return paths.some(path => boundaries.some(boundary => pathsIntersect(path, boundary)))
}

function pathsOf(geometry) {
	switch (geometry.type) {
		case 'LineString': return [geometry.coordinates]
		case 'Polygon':
		case 'MultiLineString': return geometry.coordinates
		case 'MultiPolygon': return geometry.coordinates.flat()
		default: return []
	}
}

function pathsIntersect(left, right) {
	for (let a = 1; a < left.length; a++) {
		for (let b = 1; b < right.length; b++) {
			if (segmentsIntersect(left[a - 1], left[a], right[b - 1], right[b])) return true
		}
	}
	return false
}

function segmentsIntersect(a, b, c, d) {
	const cross = (p, q, r) => (q[0] - p[0]) * (r[1] - p[1]) - (q[1] - p[1]) * (r[0] - p[0])
	const within = (p, q, r) => r[0] >= Math.min(p[0], q[0]) && r[0] <= Math.max(p[0], q[0])
		&& r[1] >= Math.min(p[1], q[1]) && r[1] <= Math.max(p[1], q[1])
	const [abc, abd, cda, cdb] = [cross(a, b, c), cross(a, b, d), cross(c, d, a), cross(c, d, b)]
	if (((abc > 0 && abd < 0) || (abc < 0 && abd > 0))
		&& ((cda > 0 && cdb < 0) || (cda < 0 && cdb > 0))) return true
	return (abc === 0 && within(a, b, c)) || (abd === 0 && within(a, b, d))
		|| (cda === 0 && within(c, d, a)) || (cdb === 0 && within(c, d, b))
}
