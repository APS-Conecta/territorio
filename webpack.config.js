const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// @nextcloud/webpack-vue-config prepends the app name to the output filename,
// so entry 'main' → js/territorio-main.js (matches Util::addScript).
webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
	admin: path.join(__dirname, 'src', 'admin.js'),
}

// A name a component imports must exist, at build time — webpack 5 reports a
// missing export as a WARNING and ships the binding undefined, which is how
// `escapeHtml` crashed every production load on the basemap branch the suites
// never run (review L1-01/L1-02). strictExportPresence fails the build instead.
webpackConfig.module.strictExportPresence = true

module.exports = webpackConfig
