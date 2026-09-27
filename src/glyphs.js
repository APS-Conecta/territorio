/**
 * One pictogram per Category, from Maki (CC0-1.0).
 *
 * Hue groups Categories; the glyph identifies them (ADR-0013). That is not a
 * preference: `2·C·sin(Δh/2)` says eleven hues separated by ΔE 0.10 need a
 * chroma louder than the palette this replaced, so the ramp carries six hue
 * families and the glyph carries the identity. A dichromacy touches the hue and
 * never the shape, which is the whole point.
 *
 * The seeded eleven are keyed by Category **slug**, which is the stable
 * identifier — a clinic may rename a label, and ids differ per install (see
 * taxonomy.js). A Category the clinic added has no seeded mark and chooses one
 * from `CATALOGUE` instead (#46); that choice is stored and OVERRIDES this map,
 * so an install where nobody has chosen anything still draws exactly what #37
 * re-picked.
 *
 * All eleven were re-picked at pin size for #37, against the vocabulary a
 * person already has from a phone map, and judged as rendered pixels at 19px
 * rather than as vector art at a desk — which is how the first pass got
 * `school` (a pencil crossed with an apple) past a review it could not survive
 * on a busy basemap. `college` is the cap every map draws education with,
 * `park` is the tree every map draws green space with, and `town` says
 * *neighbourhood* where `residential-community` said *one apartment block*.
 *
 * Three of these are chosen rather than obvious. `place-of-worship` over
 * `religious-christian`, because the app must not assume a denomination.
 * `circle-stroked` for "Sin clasificar", because it has to read as *nobody has
 * decided yet* — which is a real seeded Category — and not as an error.
 * `pitch` over `soccer` for deporte, because the list and Capas draw the same
 * mark at 12px and 11px, where `soccer`'s ball detaches into a stray speck and
 * `pitch` stays one figure.
 *
 * `police` is the weakest of the eleven and stayed anyway: Maki has no shield
 * or badge, and every alternative in the set means something narrower (a flame
 * is bomberos, a warning triangle is danger) or nothing at all. Flagged for
 * the owner in #37 rather than quietly swapped for a worse-but-crisper mark.
 *
 * Maki icons are a single `<path>` with no `fill` attribute on a
 * `viewBox="0 0 15 15"`, so they take their colour from CSS wherever they land.
 */

/**
 * The eleven seeded marks, bundled (L1-34): the picker's catalogue is the
 * 11%-of-bundle weight nobody who never opens it should pay, so the whole
 * Maki set loads on demand (loadCatalogue) and only what every pin can need
 * at first paint ships eagerly.
 *
 * Keyed by MAKI NAME, not Category slug: `glyphFor()`'s first lookup is
 * `MARKS.get(chosen)` — a chosen name — and the slug-keyed GLYPHS table
 * below supplies the second lookup's values. Copied from `@mapbox/maki`'s own
 * browser bundle — same bytes — and pinned by the equality test in
 * glyphs.test.js: the catalogue test asserts each inline markup equals what the
 * package ships under that name, so the subset cannot drift from its source any
 * more than the whole-set import could.
 */
const SEEDED_MARKS = {
	hospital: "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg version=\"1.1\" id=\"hospital\" xmlns=\"http://www.w3.org/2000/svg\" width=\"15\" height=\"15\" viewBox=\"0 0 15 15\">\n  <path id=\"rect4194\" d=\"M7,1C6.4,1,6,1.4,6,2v4H2C1.4,6,1,6.4,1,7v1&#xA;&#x9;c0,0.6,0.4,1,1,1h4v4c0,0.6,0.4,1,1,1h1c0.6,0,1-0.4,1-1V9h4c0.6,0,1-0.4,1-1V7c0-0.6-0.4-1-1-1H9V2c0-0.6-0.4-1-1-1H7z\"/>\n</svg>",  // salud
	college: "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg id=\"college\" xmlns=\"http://www.w3.org/2000/svg\" width=\"15\" height=\"15\" viewBox=\"0 0 15 15\">\n  <path d=\"M7.5,1L0,4.5l2,0.9v1.7C1.4,7.3,1,7.9,1,8.5s0.4,1.2,1,1.4V10l-0.9,2.1&#xA; C0.8,13,1,14,2.5,14s1.7-1,1.4-1.9L3,10c0.6-0.3,1-0.8,1-1.5S3.6,7.3,3,7.1V5.9L7.5,8L15,4.5L7.5,1z M11.9,7.5l-4.5,2L5,8.4v0.1&#xA; c0,0.7-0.3,1.3-0.8,1.8l0.6,1.4v0.1C4.9,12.2,5,12.6,4.9,13c0.7,0.3,1.5,0.5,2.5,0.5c3.3,0,4.5-2,4.5-3L11.9,7.5L11.9,7.5z\"/>\n</svg>",  // educacion
	pitch: "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg version=\"1.1\" id=\"pitch\" xmlns=\"http://www.w3.org/2000/svg\" width=\"15\" height=\"15\" viewBox=\"0 0 15 15\">\n  <path d=\"M5,3C4.4477,3,4,2.5523,4,2s0.4477-1,1-1s1,0.4477,1,1S5.5523,3,5,3z M12.5,10H10L9,7L8,5.25L9,5l2.3,1l0,0&#xA;&#x9;c0.2761,0.1105,0.5895-0.0239,0.7-0.3S11.9761,5.1105,11.7,5l0,0L9,4H7L5,5L4,6H2.5C2.2239,6,2,6.2239,2,6.5S2.2239,7,2.5,7H5l1-1&#xA;&#x9;l1,2l-2,2v3.5C5,13.7761,5.2239,14,5.5,14S6,13.7761,6,13.5v-3.11L8,9l1,2h3.5c0.2761,0,0.5-0.2239,0.5-0.5S12.7761,10,12.5,10z\"/>\n</svg>",  // deporte_recreacion
	police: "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg version=\"1.1\" id=\"police\" xmlns=\"http://www.w3.org/2000/svg\" width=\"15\" height=\"15\" viewBox=\"0 0 15 15\">\n  <path id=\"rect4718\" d=\"M5.5,1L6,2h5l0.5-1H5.5z M6,2.5v1.25c0,0,0,2.75,2.5,2.75S11,3.75,11,3.75V2.5H6z M1.9844,3.9863&#xA;&#x9;C1.4329,3.9949,0.9924,4.4485,1,5v4c-0.0001,0.6398,0.5922,1.1152,1.2168,0.9766L5,9.3574V14l5.8789-6.9297&#xA;&#x9;C10.7391,7.0294,10.5947,7,10.4414,7H6.5L3,7.7539V5C3.0077,4.4362,2.5481,3.9775,1.9844,3.9863z M11.748,7.7109L6.4121,14H12&#xA;&#x9;V8.5586C12,8.2451,11.9061,7.9548,11.748,7.7109z\"/>\n</svg>",  // seguridad
	park: "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg version=\"1.1\" id=\"park\" xmlns=\"http://www.w3.org/2000/svg\" width=\"15\" height=\"15\" viewBox=\"0 0 15 15\">\n  <path d=\"M14,5.75c0.0113-0.6863-0.3798-1.3159-1-1.61C12.9475,3.4906,12.4014,2.9926,11.75,3&#xA;&#x9;c-0.0988,0.0079-0.1962,0.0281-0.29,0.06c-0.0607-0.66-0.6449-1.1458-1.3048-1.0851C9.8965,1.9987,9.6526,2.1058,9.46,2.28l0,0&#xA;&#x9;c0-0.6904-0.5596-1.25-1.25-1.25S6.96,1.5896,6.96,2.28C6.96,2.28,7,2.3,7,2.33C6.4886,1.8913,5.7184,1.9503,5.2797,2.4618&#xA;&#x9;C5.1316,2.6345,5.0347,2.8451,5,3.07C4.8417,3.0195,4.6761,2.9959,4.51,3C3.6816,2.9931,3.0044,3.659,2.9975,4.4874&#xA;&#x9;C2.9958,4.6872,3.0341,4.8852,3.11,5.07C2.3175,5.2915,1.8546,6.1136,2.0761,6.9061C2.2163,7.4078,2.6083,7.7998,3.11,7.94&#xA;&#x9;c0.2533,0.7829,1.0934,1.2123,1.8763,0.959C5.5216,8.7258,5.9137,8.2659,6,7.71C6.183,7.8691,6.4093,7.9701,6.65,8v5L5,14h5l-1.6-1&#xA;&#x9;v-2c0.7381-0.8915,1.6915-1.5799,2.77-2c0.8012,0.1879,1.603-0.3092,1.7909-1.1103C12.9893,7.7686,13.0025,7.6444,13,7.52&#xA;&#x9;c0.0029-0.0533,0.0029-0.1067,0-0.16C13.6202,7.0659,14.0113,6.4363,14,5.75z M8.4,10.26V6.82C8.6703,7.3007,9.1785,7.5987,9.73,7.6&#xA;&#x9;h0.28c0.0156,0.4391,0.2242,0.849,0.57,1.12C9.7643,9.094,9.0251,9.6162,8.4,10.26z\"/>\n</svg>",  // medioambiente
	town: "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg height=\"15\" viewBox=\"0 0 15 15\" width=\"15\" xmlns=\"http://www.w3.org/2000/svg\" id=\"town\">\n  <path d=\"m10.651 6.121c-.0445-.0357-.0999-.05516-.157-.05516s-.1125.01946-.157.05516l-2.245 1.808c-.02881.02323-.05204.05263-.06796.08603-.01593.03341-.02414.06997-.02404.10697v4.625c0 .0671.02666.1315.0741.1789.04745.0474.1118.0741.1789.0741h1.494c.0671 0 .13145-.0267.1789-.0741s.0741-.1118.0741-.1789v-1.747h1v1.747c0 .0671.0267.1315.0741.1789.0475.0474.1118.0741.1789.0741h1.494c.0671 0 .1315-.0267.1789-.0741s.0741-.1118.0741-.1789v-4.627c.0007-.03831-.0074-.07627-.0237-.11095s-.0404-.06514-.0703-.08905zm-.651 3.879h-1v-1h1zm2 0h-1v-1h1zm-6.29-9.184997c-.02299-.034654-.05419-.063081-.09083-.082746s-.07758-.029956-.11917-.029956c-.04158 0-.08252.010291-.11916.029956-.03665.019665-.06785.048092-.09084.082746l-3.248 4.120997c-.02752.0415-.04214.09021-.042.14v7.671c-.00013.0331.00626.0659.0188.0965s.031.0585.0543.082c.02331.0235.05102.0422.08154.0549.03053.0128.06327.0195.09636.0196h2.5c.06632-.0008.12964-.0277.17626-.0749.04661-.0471.07275-.1108.07274-.1771v-1.748h1v1.748c0 .0668.02655.1309.07381.1782s.11136.0738.17819.0738h.748v-6c-.00004-.07511.01684-.14926.04938-.21695.03255-.06769.07993-.12718.13862-.17405l1.812-1.609c0-.05-3.29-4.184997-3.29-4.184997zm-1.71 8.184997h-1v-1h1zm0-3h-1v-1h1zm2 3h-1v-1h1zm0-3h-1v-1h1z\"/>\n</svg>",  // organizacion_social
	'town-hall': "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg version=\"1.1\" id=\"town-hall\" xmlns=\"http://www.w3.org/2000/svg\" width=\"15\" height=\"15\" viewBox=\"0 0 15 15\">\n  <path d=\"M13,4H9l0-3L7.5,0L6,1v3H2L1,5v1h13V5L13,4z M7.5,1.5c0.4,0,0.7,0.3,0.7,0.8S7.9,3,7.5,3S6.7,2.7,6.7,2.2&#xA;&#x9;C6.7,1.8,7.1,1.5,7.5,1.5z M13,7H2v4l-1,1.5V14h13v-1.5L13,11V7z M5,12.5H4V8h1V12.5z M8,12.5H7V8h1V12.5z M11,12.5h-1V8h1V12.5z\"/>\n</svg>",  // servicio_publico
	bus: "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg width=\"15\" height=\"15\" viewBox=\"0 0 15 15\" xmlns=\"http://www.w3.org/2000/svg\" id=\"bus\">\n  <path d=\"M2 3C2 1.9 2.9 1 4 1H11C12.1 1 13 1.9 13 3V11C13 12 12 12 12 12V13C12 13.55 11.55 14 11 14C10.45 14 10 13.55 10 13V12H5V13C5 13.55 4.55 14 4 14C3.45 14 3 13.55 3 13V12C2 12 2 11 2 11V3ZM3.5 4C3.22 4 3 4.22 3 4.5V7.5C3 7.78 3.22 8 3.5 8H11.5C11.78 8 12 7.78 12 7.5V4.5C12 4.22 11.78 4 11.5 4H3.5ZM4 9C3.45 9 3 9.45 3 10C3 10.55 3.45 11 4 11C4.55 11 5 10.55 5 10C5 9.45 4.55 9 4 9ZM11 9C10.45 9 10 9.45 10 10C10 10.55 10.45 11 11 11C11.55 11 12 10.55 12 10C12 9.45 11.55 9 11 9ZM4 2.5C4 2.78 4.22 3 4.5 3H10.5C10.78 3 11 2.78 11 2.5C11 2.22 10.78 2 10.5 2H4.5C4.22 2 4 2.22 4 2.5Z\"/>\n</svg>",  // movilidad_transporte
	'place-of-worship': "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg version=\"1.1\" id=\"place-of-worship\" xmlns=\"http://www.w3.org/2000/svg\" width=\"15\" height=\"15\" viewBox=\"0 0 15 15\">\n  <path id=\"path9064\" d=\"M7.5,0l-2,2v2h4V2&#xA;&#x9;L7.5,0z M5.5,4.5L4,6h7L9.5,4.5H5.5z M2,6.5c-0.5523,0-1,0.4477-1,1V13h2V7.5C3,6.9477,2.5523,6.5,2,6.5z M4,6.5V13h7V6.5H4z&#xA;&#x9; M13,6.5c-0.5523,0-1,0.4477-1,1V13h2V7.5C14,6.9477,13.5523,6.5,13,6.5z\"/>\n</svg>",  // religion
	shop: "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg height=\"15\" viewBox=\"0 0 15 15\" width=\"15\" xmlns=\"http://www.w3.org/2000/svg\" id=\"shop\">\n  <path d=\"m13.33 5h-1.83l-.39-2.33c-.1601-.7182-.7017-1.2905-1.41-1.49-.3493-.1124-.7131-.173-1.08-.18h-2.24c-.3669.007-.7307.0676-1.08.18-.7083.1995-1.2499.7718-1.41 1.49l-.39 2.33h-1.83c-.2761-.0017-.5013.2208-.503.497-.0003.0519.0074.1035.023.153l1.88 6.3c.1964.6246.7753 1.0496 1.43 1.05h6c.651-.0047 1.2247-.4289 1.42-1.05l1.88-6.3c.0829-.2634-.0635-.5441-.3269-.627-.0463-.0146-.0945-.0223-.1431-.023zm-8.81 0 .36-2.17c.0807-.3625.3736-.6395.74-.7.2463-.0776.5019-.1213.76-.13h2.24c.2614.0078.5205.0515.77.13.3664.0605.6593.3375.74.7l.35 2.17h-6z\"/>\n</svg>",  // comercio_economia
	'circle-stroked': "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg width=\"15\" height=\"15\" viewBox=\"0 0 15 15\" xmlns=\"http://www.w3.org/2000/svg\" id=\"circle-stroked\">\n  <path d=\"M7.5 12.3C4.84898 12.3 2.7 10.151 2.7 7.5C2.7 4.84898 4.84898 2.7 7.5 2.7C10.151 2.7 12.3 4.84898 12.3 7.5C12.3 10.151 10.151 12.3 7.5 12.3ZM7.5 14C11.0899 14 14 11.0899 14 7.5C14 3.9101 11.0899 1 7.5 1C3.9101 1 1 3.9101 1 7.5C1 11.0899 3.9101 14 7.5 14Z\"/>\n</svg>",  // sin_clasificar
}

/**
 * The marks loaded so far: the seeded eleven always, the other 204 after
 * `loadCatalogue()` resolves.
 *
 * A Map and not an object literal, because the key comes from a database column.
 * `constructor`, `toString` and `valueOf` are shaped exactly like Maki names —
 * lower case letters and nothing else — so the server's shape check stores them
 * and an object would answer all three with a function off `Object.prototype`.
 * Truthy, so the fallbacks below would be skipped, and the pin's markup is an
 * HTML string interpolated unescaped: it would read «function Object() { [native
 * code] }» where the pictogram belongs.
 */
const MARKS = new Map(Object.entries(SEEDED_MARKS))

/**
 * Every mark's name, in Maki's own order — what the picker offers (#46).
 * Empty until `loadCatalogue()` resolves; CapasPanel awaits it before opening
 * the grid, so the grid never offers half a catalogue.
 */
export const CATALOGUE = []

let loading = null

/**
 * Load the whole Maki catalogue, once, on first picker open (L1-34) —
 * `import()` is the code-split point: the 180 KB of markup leaves the main
 * bundle for a chunk the grid's first open pays for.
 *
 * The dynamic specifier is the browser bundle for the same reason the static
 * one always was: `@mapbox/maki`'s `main` is a Node script that globs the
 * filesystem and throws on import outside a browser target. Not
 * `require.context()` over `icons/` either, nor a hand-written list of imports:
 * the context helper is webpack's alone, so the catalogue would be empty under
 * vitest and every assertion about a chosen mark would pass while proving
 * nothing — and a hand-written list is a second copy of an inventory Maki owns.
 *
 * @return {Promise<string[]>} resolves with CATALOGUE
 */
export function loadCatalogue() {
	loading ??= import('@mapbox/maki/browser.esm.js').then(({ layouts, svgArray }) => {
		layouts.forEach((name, index) => {
			MARKS.set(name, svgArray[index])
			CATALOGUE.push(name)
		})
		return CATALOGUE
	}).catch((exception) => {
		// A chunk lost to one network blip must not be lost for the session:
		// a cached rejection would answer every later open with the same
		// failure (L2-04). Forget it, so the next open asks again.
		loading = null
		throw exception
	})

	return loading
}

/** The mark each seeded Category is drawn with, until somebody chooses another. */
const GLYPHS = {
	salud: 'hospital',
	educacion: 'college',
	deporte_recreacion: 'pitch',
	seguridad: 'police',
	medioambiente: 'park',
	organizacion_social: 'town',
	servicio_publico: 'town-hall',
	movilidad_transporte: 'bus',
	religion: 'place-of-worship',
	comercio_economia: 'shop',
	sin_clasificar: 'circle-stroked',
}

/**
 * The glyph a Category is drawn with: the one it was given, then the one its
 * slug ships with, then the neutral.
 *
 * Falls back rather than returning nothing, for three cases that are all real: a
 * Category a clinic added and has not chosen a mark for; a Feature pointing at a
 * Subcategory the taxonomy no longer has, which `colourOf` already handles the
 * same way; and a stored name this app cannot draw — the column is a VARCHAR and
 * a restored dump or a retired Maki icon can put one there. A blank dot is worse
 * than a neutral one — it reads as a rendering failure rather than as
 * "unclassified", and the row beside it already says which it is (ADR-0017).
 *
 * A chosen name this page has not loaded yet — a stored 'bakery' before any
 * picker has opened — kicks the load rather than waiting for one: the neutral
 * stands in for exactly this paint, and the real mark is there from the next.
 *
 * @param {?string} categorySlug the Category's slug, from indexSubcategories()
 * @param {?string} chosen the Maki name stored against the Category, if any
 * @return {string} SVG markup, never empty
 */
export function glyphFor(categorySlug, chosen = null) {
	if (chosen !== null && !MARKS.has(chosen)) {
		loadCatalogue()
	}

	return MARKS.get(chosen) ?? MARKS.get(GLYPHS[categorySlug]) ?? MARKS.get(GLYPHS.sin_clasificar)
}

/** Every Category slug that has a glyph of its own. Read by the tests. */
export const GLYPH_SLUGS = Object.keys(GLYPHS)
