import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { fold } from '../../src/filters.js'
import { DIVISIONS_SLUG } from '../../src/taxonomy.js'
import { readable } from '../../src/limit.js'

/**
 * The cross-stack twins: rules that exist on both sides of the HTTP boundary
 * because each side needs them where it stands (L5-02).
 *
 *  - `fold`: the browser folds what somebody typed (the Registry search, the
 *    street picker) and the server folds record names (the duplicate queue,
 *    `DuplicateFinder::fold`) — two sources disagreeing about an accent are
 *    not describing two places, and both sides must keep agreeing about it.
 *  - `readable`: the Limit as a sentence — `readable()` draws it in the
 *    picker, `ExportService::readableLimit()` writes it in the export column
 *    — one sentence for one Limit, on both sides.
 *
 * Until now the two pairs were held together by comments. Comments drift
 * silently; these tests fail loudly. Like `pins.test.js` and
 * `glyphs.test.js`, they read the PHP source and build assertions out of what
 * they find there — a rewrite on either side breaks the tripwire and names
 * the twin. Brittle-on-reformat is the price of loud failure (the
 * `glyphs.test.js` doctrine), and the failure messages say which side moved.
 */

// fileURLToPath, not .pathname: the repo lives under a directory with a space
// in its name, and .pathname hands back the %20 still escaped. (pins.test.js)
const ROOT = fileURLToPath(new URL('../../', import.meta.url))

describe('fold (filters.js) against Text::fold (the duplicate queue)', () => {
	it('folds the names both stacks must agree about', () => {
		// The shapes the two folds exist for: the real duplicate pair the
		// finder's own docblock names, and the spellings its rules exist to
		// survive — an accent, a case, a tilde nobody typed.
		expect(fold('Jardín Infantil Rayún')).toBe('jardin infantil rayun')
		expect(fold('JARDIN INFANTIL RAYUN')).toBe('jardin infantil rayun')
		expect(fold('CESFAM Bellavista')).toBe('cesfam bellavista')
		expect(fold('Peñaflor')).toBe('penaflor')
	})

	it('folds a field that is not there into the empty string', () => {
		// The `?? ''` half of the rule: a field the record does not carry
		// folds to the empty string, not to "undefined" or "null".
		expect(fold(null)).toBe('')
		expect(fold(undefined)).toBe('')
	})

	it('keeps the one documented asymmetry the comments promise', () => {
		// The server's fold collapses runs of whitespace — "the one thing
		// this adds", because "Cesfam  Bellavista" out of a CSV column is a
		// spelling difference too. The browser folds and trims but never
		// collapses, so the search's needle keeps its internal spacing.
		// Pinned as-is: "fixing" one side without the other is a decision,
		// not a drive-by.
		expect(fold('Cesfam  Bellavista')).toBe('cesfam  bellavista')
	})

	it('still folds the way the server folds', () => {
		// The server fold moved to aps-common (DuplicateFinder::fold delegates),
		// so the tripwire reads the committed copy of the package the app ships.
		const php = readFileSync(`${ROOT}vendor/aps/common/src/Text.php`, 'utf8')

		// Tripwires, not proofs: the PHP fold is decompose (FORM_D) → strip
		// combining marks (\p{Mn}) → lower-case — the same three steps as the
		// JS NFD → \p{Diacritic} → toLowerCase. A rewrite over there that
		// changes any step breaks this test and names the twin before the
		// search and the duplicate queue silently disagree. The null-guard
		// doctrine from glyphs.test.js applies: a rename or reformat that
		// leaves these unmatched stops this file from being about the server
		// and says so.
		expect(php, 'the server stopped decomposing before stripping marks')
			.toMatch(/Normalizer::FORM_D/)
		expect(php, 'the server stopped stripping combining marks')
			.toMatch(/preg_replace\('\/\\p\{Mn\}\/u'/)
		expect(php, 'the server stopped lower-casing')
			.toMatch(/mb_strtolower/)
	})
})

describe('readable (limit.js) against readableLimit (the export column)', () => {
	/**
	 * The sentence rule read out of the PHP that builds the same sentence.
	 *
	 * Not copied here, and that is the whole point: a copy of the separators
	 * in this test would drift exactly the way the comment did, staying green
	 * against its own stale copy while the export column and the picker came
	 * to say different things. (The glyphs.test.js serverShape() doctrine.)
	 *
	 * @return {[string, string]} the list separator and the final-join word
	 */
	function serverSeparators() {
		const php = readFileSync(`${ROOT}lib/Service/ExportService.php`, 'utf8')
		const join = php.match(/implode\('([^']+)', \$names\) \. '([^']+)' \. \$last/)

		// Before comparing: a rename or a reformat over there would leave this
		// null, and a sentence built from null matches nothing or everything —
		// either way this file would stop being about the server and nothing
		// would say so.
		expect(join, 'the sentence rule could not be found in ExportService').not.toBeNull()

		return [join[1], join[2]]
	}

	it('builds the sentence the export column builds', () => {
		const [list, final] = serverSeparators()
		const parts = [
			{ name: 'Vicuña Mackenna' },
			{ name: 'el canal' },
			{ name: 'el límite del parque' },
		]

		expect(readable(parts)).toBe(`Vicuña Mackenna${list}el canal${final}el límite del parque`)
		expect(readable([{ name: 'Avenida Sur' }, { name: 'Avenida Norte' }]))
			.toBe(`Avenida Sur${final}Avenida Norte`)
		expect(readable([{ name: 'Avenida Sur' }])).toBe('Avenida Sur')
		expect(readable([])).toBe('')
	})

	it('says each name once, however many ways the street is cut into', () => {
		const [, final] = serverSeparators()
		// A comuna holds one street as dozens of separate ways, so a Limit
		// records thirty parts named "Vicuña Mackenna" and the sentence must
		// say it once — the same dedup the export column does.
		const thirtyWays = Array.from({ length: 30 }, () => ({ name: 'Vicuña Mackenna' }))

		expect(readable([...thirtyWays, { name: 'el canal' }]))
			.toBe(`Vicuña Mackenna${final}el canal`)
	})
})

describe('taxonomy.js slug constants against EnsureSeedData.php', () => {
	const seed = readFileSync(`${ROOT}lib/Migration/EnsureSeedData.php`, 'utf8')
	const php = (name) => seed.match(new RegExp(`const ${name} = '([^']+)'`))?.[1]
	const phpFallback = seed.match(/FALLBACK = \['category' => '([^']+)', 'subcategory' => '([^']+)'\]/)

	it('pins DIVISIONS_SLUG and the division subcategory slugs', () => {
		expect(php('DIVISIONS_SLUG')).toBe(DIVISIONS_SLUG)
		expect(seed).toContain(`'sector' =>`)
		expect(seed).toContain(`'unidad_vecinal' =>`)
	})

	it('pins FALLBACK: a PHP rename of the category slug breaks the js source that assumes it', () => {
		const taxonomySource = readFileSync(`${ROOT}src/taxonomy.js`, 'utf8')
		expect(taxonomySource).toContain(`'${phpFallback[1]}'`)
		// The subcategory half ('otro') has no JS twin — defaultSubcategoryId
		// resolves the first subcategory dynamically — so the pin is the PHP
		// pair's presence, asserted above by the regex that captured it.
		expect(phpFallback).not.toBeNull()
	})
})
