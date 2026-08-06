const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// @nextcloud/webpack-vue-config prepends the app name to the output filename,
// so entry 'main' → js/territorio-main.js (matches Util::addScript).
webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
	admin: path.join(__dirname, 'src', 'admin.js'),
}

module.exports = webpackConfig
