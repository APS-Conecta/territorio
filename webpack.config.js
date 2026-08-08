const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// @nextcloud/webpack-vue-config prepends the app name to the output filename,
// so entry 'main' → js/territorio-main.js (matches Util::addScript).
webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
	admin: path.join(__dirname, 'src', 'admin.js'),
}

/**
 * Maki's icons are loaded as SOURCE, not as the Nextcloud default.
 *
 * The inherited `.svg` rule is `asset/inline`, which yields a data URI. A data
 * URI cannot be recoloured — the glyph has to be white on the Category's fill —
 * and it cannot be inlined into the Leaflet divIcon, which is an HTML string
 * built outside Vue's render tree. Both are needed (see src/glyphs.js), so this
 * package gets `asset/source` and everything else keeps the default.
 *
 * Scoped by `include`, and the inherited rule is told to `exclude` the same
 * path: two rules matching one file with different `type` values is not a
 * configuration webpack resolves in the order you would guess.
 */
const maki = path.join(__dirname, 'node_modules', '@mapbox', 'maki')

webpackConfig.module.rules.forEach((rule) => {
	if (rule.test !== undefined && String(rule.test).includes('svg')) {
		rule.exclude = rule.exclude === undefined ? [maki] : [rule.exclude, maki].flat()
	}
})

webpackConfig.module.rules.unshift({
	test: /\.svg$/,
	include: maki,
	type: 'asset/source',
})

module.exports = webpackConfig
