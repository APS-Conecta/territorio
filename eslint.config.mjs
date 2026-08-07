import vue from 'eslint-plugin-vue'

/**
 * Narrow on purpose: this is here for one class of mistake, a name used but
 * never imported.
 *
 * `listFor` was called in App.vue with no import, threw a ReferenceError inside
 * a computed, and took the whole Datos column down — past a green build and
 * three green suites, because none of them loads a component.
 *
 * So `no-undef` is the point and style rules are off. Arguing with a linter
 * about formatting is a way to spend an afternoon without finding a bug.
 *
 * It does NOT catch a stale prop on a third-party component — `native-type`,
 * which cost every save this app never made. Vue passes an unknown prop through
 * as a plain attribute and no linter sees it. `tools/browser-check.py` is what
 * actually covers that.
 *
 * `.mjs` rather than `.js` so package.json needs no `"type": "module"`, which
 * webpack.config.js would then have to be rewritten for.
 */

/** The browser surface this app actually touches. */
const BROWSER = {
	console: 'readonly',
	document: 'readonly',
	window: 'readonly',
	setInterval: 'readonly',
	clearInterval: 'readonly',
	setTimeout: 'readonly',
	clearTimeout: 'readonly',
	ResizeObserver: 'readonly',
	FormData: 'readonly',
	URL: 'readonly',
	OC: 'readonly',
	Date: 'readonly',
}

export default [
	{ ignores: ['js/', 'node_modules/', 'vendor/', 'tools/'] },

	...vue.configs['flat/essential'],

	{
		files: ['src/**/*.js', 'src/**/*.vue'],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'module',
			globals: BROWSER,
		},
		rules: {
			'no-undef': 'error',
			'no-unused-vars': ['error', { args: 'none' }],

			// The repo overrides the linter. FeatureDetail edits the draft that
			// App.vue owns, in place and on purpose — its own comment says why:
			// "the sidebar edits one record at a time, and copying it back and
			// forth would only add a second place for the two to disagree." That
			// is a decision, not an oversight, and restructuring the sidebar to
			// satisfy a default rule would be the linter designing the app.
			'vue/no-mutating-props': 'off',
		},
	},

	{
		files: ['tests/js/**/*.js'],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'module',
			globals: { process: 'readonly', URL: 'readonly' },
		},
		rules: {
			'no-undef': 'error',
			'no-unused-vars': ['error', { args: 'none' }],
		},
	},
]
