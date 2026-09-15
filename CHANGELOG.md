# Changelog

All notable changes to Territorio are recorded here. Decisions behind them live in
[`docs/adr/`](docs/adr/).

## [0.44.0] — unreleased

Exports say whose data they carry.

### Added

- **Exports of OpenStreetMap-derived records name the ODbL licence** (#28). `GeoJsonWriter`,
  `CsvWriter` and `KmlWriter` carried no licence statement of any kind. Nothing was wrong while
  the files stayed inside a clinic — ODbL's share-alike triggers on public use — but handing a
  Sector file to the municipality is a normal thing for a CESFAM to do, and the app offers three
  export formats to make it easy. Attribution has to name the licence, not only the source, so the
  line is `© OpenStreetMap contributors, licencia ODbL` with the copyright URL.

  An OSM-derived Dataset arrives stamped `source_es: OpenStreetMap` with a `source_url` (ADR-0004),
  and `GeoJsonReader` joins those into `sourceRef`. Read from there **and** from `official`, the
  row as the authority published it. `sourceRef` alone would not have held: it is the Fuente box in
  the form, so clearing or rewriting it on an imported street would drop the licence off every
  export of exactly the record the notice is about, while the geometry in the file is still
  OpenStreetMap's — and it would drop off silently. `official` is written only by an import
  (`FeatureService::applyProvenance()` runs for a Provenance; a person's edit passes none,
  ADR-0003), so no edit can reach it. It rides in the export row and is written to no file: it is
  the whole published record, contact fields included, and each writer takes only the keys it maps
  — held to that by a test per writer. Found by review; the first version of this slice keyed on
  `sourceRef` alone.

  Matched loosely — a source somebody typed that merely mentions OSM makes a file carry a line it
  did not strictly owe, while a stricter match that misses one makes the file a licence breach, and
  those two costs are not comparable.

  **Where it goes is decided by the fourth criterion, that it must not break whoever opens the
  file.** GeoJSON gets a top-level `license` foreign member, which RFC 7946 §6.1 allows and
  `GeoJsonReader` ignores, so the export that goes back in still goes back in — pinned by the
  round trip in `ExportTest`, now run on an OSM-stamped fixture. KML appends it to the
  `<Document><description>` that already says what the format cannot carry; an `<atom:author>`
  would have cost a namespace declaration for a line shown in the same panel. CSV gets **a
  column**, because RFC 4180 has no comment syntax: a line above the header breaks header
  detection and a trailing line of a different width breaks a strict parser, so a cell is the only
  place a spreadsheet can hold it. The column appears only when the set needs it, and is filled
  only on the rows it is about — a notice repeated over a mixed set would say the municipality's
  own catastro belongs to OpenStreetMap.

  An export holding none of OSM's data carries nothing, in all three formats. That half is not
  tidiness: an attribution printed on every file is read as boilerplate, which is how the real one
  stops being seen.

## [0.43.0] — unreleased

The app icon, white where the surface behind it is coloured.

### Fixed

- **The app icon was the black one on every surface that draws it on a dark background** (#26).
  `img/app.svg` and `img/app-dark.svg` were the same 311 bytes, neither carrying a `fill`, so both
  drew black — and the light variant is the one nearly everything asks for. The plan had the pair
  backwards, and the server settles it: `AppManager::getAppIcon()` takes `app.svg` **without**
  `$dark` (`lib/private/App/AppManager.php:118`), which is what the header's current-app button and
  the app menu get (`lib/private/NavigationManager.php:353`) and what the favicon is rasterised from
  (`Theming\Util::getAppIcon()`). All three paint it on the primary colour. The only caller of
  `getAppIcon()` asking with `dark: true` is the apps list in Settings
  (`lib/private/legacy/OC_App.php:440`), a white page — and this app asks for `app-dark.svg` by name
  itself, in `AdminSection::getIcon()` (`lib/Settings/AdminSection.php:32`), for its own Settings
  section, which is white too. So `app.svg` gained `fill="#fff"` and `app-dark.svg` keeps none, and
  each file now carries a comment saying which surface it serves and, for the black one, which two
  consumers break if it is deleted.

  The convention is cited from the server's source rather than from the manual because the manual
  does not contain it: the `info.xml` page says the navigation icon "defaults to app.svg" and the
  Icons page says nothing about `app-dark.svg` or about colour at all (both checked against the 34
  manual on 2026-09-15). Core states it in a CSS comment instead — "App icons are bright by default"
  in `core/src/components/AppItem.vue`, which flips them with `primary-invert-if-bright` when the
  circle behind them is bright.

  Two things about the comments themselves are load-bearing and are now guarded by tests, because
  both fail silently. They sit **inside** the root element: `IconBuilder` decides a file is an SVG
  by looking for a leading `<svg` or `<?xml`, so a comment above the root costs the app its favicon.
  And they cannot spell a CSS custom property out in full — a double hyphen inside an XML comment is
  illegal, librsvg refuses the whole file, and the first draft of this fix did exactly that and was
  caught by rasterising it through Imagick rather than by reading it.

## [0.42.0] — unreleased

Four copies of one fixture, now one.

### Changed

- **One `sector()` fixture for the whole integration suite** (#60). Four tests carried the same
  twenty lines apiece — `SectorTest`, `AssignmentTest`, `LimitTest` and `BoundaryDatasetTest` —
  each hard-coding the fields it did not care about and taking as an argument the one it did: a
  colour, a set of rings, a Limit, nothing at all. `IntegrationTestCase` already owned `place()`
  for exactly this job, so `sector()` now sits beside it and opens the same `$extra` door: a test
  passes only the field it is about, and the fixture still goes through `FeatureService`, which is
  what makes the Places inside the new boundary get assigned as they would be in the app
  (ADR-0008).

  The issue was opened for a leak — Sectores surviving a run and claiming Places in the next one —
  and was re-titled on 2026-09-14 after the owner measured that all four copies did register their
  fixture for cleanup. What was left was the duplication, which is why this is a `Changed` and not
  a `Fixed`.

  Two fixtures changed shape in passing, neither asserted on: the Sectores in `LimitTest` now sit
  on the shared default boundary instead of a square of their own, and `BoundaryDatasetTest`'s
  hand-drawn Sector is that square rather than a triangle. And the copy cannot quietly come back as
  it was: the shared helper is `protected`, so a `private sector()` in a subclass is now a fatal
  error rather than a shadow.

### Documentation

- **`THIRD-PARTY-NOTICES.md` now names every package that ships, and a command says so** (#87).
  Measured 2026-09-15: **67 packages are compiled into `js/` and 67 are named**. The previous count of
  51 was short by sixteen for a reason worth writing down — `@geoman-io/leaflet-geoman-free`
  publishes an esbuild bundle with **23** of its dependencies concatenated in, not the three
  (`splaytree-ts`, `polyclip-ts`, `bignumber.js`) that #86 found. `lodash`, `rbush`,
  `sweepline-intersections` and ten more `@turf/*` packages were in every page load, attributed
  nowhere, resolved by nothing we install.

  The method is the fix. `make notices` builds, then reads three things, because each is blind
  where the next can see: the source maps' `.sources[]` (50 packages), the esbuild `// node_modules/
  .pnpm/<pkg>@<version>` breadcrumbs left inside a published `dist`'s own source text (16 more),
  and a literal-byte probe for a package that ships as bytes rather than as a module —
  `@mapbox/maki`'s icons are inlined as assets, and an asset carries no source-map entry. It
  exits non-zero listing every shipped package with no row in the file, and CI now runs it: the
  way this file went stale was that keeping it current was a procedure nobody was made to run.

  All three sources have a floor, because the failure worth fearing here is a census that goes
  blind quietly rather than one that goes wrong loudly. Too few packages in the maps is not a
  bundle that shrank; a probe whose bytes are absent is not a pass; and geoman in the bundle with
  no breadcrumbs found inside it is the scan having lost its footing, not a `dist` that stopped
  inlining — without that last one a minified republish upstream would drop the count back to 51
  and print, confidently and wrongly, that the file named all of them.

  Two things the file used to say were wrong, and both were wrong in the same direction — a real
  string mistaken for a path anyone could look up. The `node_modules/.pnpm/splaytree-ts@1.0.2/…`
  path it cited is not a path in this repository (we install with npm; there is no
  `node_modules/.pnpm`), and `jq -r '.sources[]'` does not name it: it is a string inside geoman's
  published `dist`, so it lives in `.sourcesContent[]`. Chasing that is what turned three inlined
  packages into sixteen. And deriving a package name from a source path needs the **last**
  `/node_modules/`, not any — a checkout whose `node_modules` is a symlink out of the tree yields
  paths with the repo prefix repeated inside them, and a plain `grep -o` over those invents a
  package called `opt`.

  Licences are read from each package's licence file, never from the `license` field: two
  packages (`@nextcloud/vue`, `@nextcloud/event-bus`) publish no licence file at all and are
  marked as the exception rather than blending into the table, and `sweepline-intersections` has
  no copy in a development checkout at all — its MIT text was read from the published tarball.
  The full texts of MIT, ISC and Apache-2.0 (`dompurify`'s election) are now in the file beside
  Leaflet's BSD-2 and `splaytree-ts`'s BSD-3, because those licences require the text to travel
  with a binary redistribution and `js/` is one. GPL-3.0's text ships as
  `LICENSES/GPL-3.0-or-later.txt` instead: the docs gate reads every fenced block in a governed
  `.md` as commands, and the GPL says "make sure" eight times over.

## [0.41.0] — unreleased

An update that omitted the geometry destroyed it.

### Documentation

- **`splaytree-ts` declares a licence that does not exist, and ships** (#27). Its `package.json`
  says `"BDS-3-Clause"`; its `LICENSE` file is a correct BSD 3-Clause. Recorded in
  `THIRD-PARTY-NOTICES.md` — as a paragraph a person reads, not an allowlist a scanner reads;
  nothing machine-readable exists to wire one to yet. The issue named `@turf/polygonize` as the
  parent; measured, it arrives inlined inside `@geoman-io/leaflet-geoman-free`'s published `dist`,
  together with `polyclip-ts` and `bignumber.js`.

  **Corrected within the day.** The first version of that note said whether the code reached `js/`
  was *"not established"*, because the `SplayTree*` symbols appear in neither bundle. Minification
  erases symbol names, so that evidence could not have shown presence even if present — the same
  mistake as grepping a file for a value that lives in a process. String literals survive it:
  `Concurrent modification during iteration` appears once in `js/territorio-main.js`, and the
  source map names the package outright. It ships, BSD-3-Clause requires its notice be reproduced
  in binary redistribution, and the text is now in the file. Found by `/code-review`.

  Counting the shipped set to check that claim surfaced a larger gap: **51 packages are compiled
  into `js/` and 18 are named**, and the file's documented update method — read the two webpack
  banner files — structurally cannot see the rest. Filed as #87.

### Fixed

- **Reclassifying a record into or out of a boundary Subcategory reassigned nothing** (#84).
  `reassignAround()` was given the geometry the record had before the write, and decided whether to
  sweep by comparing it. A Subcategory change moves no shape, so the comparison said "nothing
  happened" in both directions: a Zone promoted to `sector` claimed nobody, and a Sector demoted out
  of one kept every Place pointing at an id no read would resolve — «sin asignar» in the sidebar for
  records that were, in the database, assigned.

  It is ADR-0008's invariant — what falls inside a boundary is derived at the one door every write
  comes through — broken by an ordinary edit that never touches a shape, and reachable from the UI
  since long before #63; that change only added a second route to it.

  The seam now also receives what the record **was**: `$wasDivision`, read at the same moment and
  for the same reason as the old geometry, because after the write there is no way to ask. Becoming
  a boundary or ceasing to be one sweeps; everything else behaves exactly as before, and a rename
  still reassigns nobody.

  Also the third direction: Sector to Unidad Vecinal with the shape unchanged — both are boundaries,
  so a bool could not see it; the sweep now compares the division slug and runs from `write()`, the
  one door.

- **An update that omitted the geometry destroyed it** (#63). `applyGeometry` read the key with
  `?? null`, so an **absent** geometry and an explicitly **empty** one were the same thing — and
  both wiped four columns at once: the shape, the bounding box, the Kind and the Location quality.
  Every other optional field already drew that distinction with `array_key_exists`, and the comment
  three lines above states the rule plainly. Geometry was the field it was not true of.

  Found on 2026-08-12 by doing it: a Unidad Vecinal updated with only a name and a subcategory lost
  its polygon, its bounding box and its Kind. **The save reported success**, the record simply
  vanished from the map, and only the revision history made it recoverable. A Unidad Vecinal's
  boundary comes from a Cadastral Dataset — the clinic reads it and never draws it — so there is no
  redrawing it by hand.

  Absent now returns early and changes nothing. Sending `geometry` explicitly empty still clears all
  four, so clearing stays possible; it just has to be asked for.

  **The distinction is a parameter, not a caller's habit.** `apply()` serves both doors, and a new
  Feature carries `kind = ''` — which is not a Kind — so a create with no geometry key at all must
  still take the empty branch to get its Place and its Absent location quality. `apply()` and
  `applyGeometry()` now take `$whole`, so the rule lives in the function that applies it rather
  than in a line the next caller has to remember; the class docblock plans a second door, and that
  door would have inserted records with no Kind.

- **`tools/browser-check.py` could not pass on a fresh instance** — unrelated to the above, found
  while running the gate for it. Nextcloud's first-run wizard opens a modal whose overlay
  intercepts every click, so Playwright retried the first one 55 times and the run died in a wall
  of pointer-event noise naming the wizard rather than the app. The gate had only ever been green
  on an instance somebody had already clicked through by hand. It now clears the wizard itself,
  beside `log_in` — in the gate rather than in `make instance-up`, because `test-browser` has no
  prerequisite and is the one command run against an instance that is already up.

### Changed

- **`limit`, `picked` and `pickedGeometry` are built by one function.** Two places made a Sector
  draft — starting a new one and reopening a saved one — and each held its own list of the three
  fields only a Sector carries. They have drifted apart twice: `limit` absent meant `addLimitNote`
  had nothing to key on, so a tramo typed before the first save was dropped (#48); `pickedGeometry`
  absent meant `save()` carried undefined over a fresh object (#49). Both were one caller holding a
  field the other did not, and the next field would have been the third.

  `sectorFields()` lives beside the rest of the Limit shaping, so it is a thing that can be tested
  rather than a shape that has to be remembered twice.

## [0.40.0] — unreleased

A scan that does not ask twice.

### Changed

- **The duplicate scan stopped proposing pairs the table already holds.** `scan()` offered every
  candidate to the database on every run and let the unique index refuse the ones already recorded.
  The outcome was right — that is what the index is for, and ADR-0011 leans on it — but a refused
  insert in PostgreSQL still writes a row version for autovacuum to collect, and still burns a
  sequence value. `oc_territorio_duplicate` on the dev instance has taken **1,778,856 inserts**
  against **8,852 live rows** — a total that counts the successes and every other writer too, but
  the gap is the shape of it — and a single re-scan asked for **704** the table already held.

  The known pairs are read once per scan and skipped. The index remains the authority: this only
  filters what was already there when the scan began, so two scans at once still cannot both record
  the same pair.

### Notes

Pinned by a test that could not have been written against the outcome, because the outcome was
always correct — `testScanningTwiceDoesNotOfferTheSamePairTwice` has passed all along. `nextval` is
not transactional in PostgreSQL, so a refused insert moves the sequence exactly as a successful one
does, which makes the sequence the one thing that can tell "proposed nothing" from "proposed
everything and was refused". It failed at 704 before the change.

## [0.37.0] — unreleased

Asking the taxonomy once.

### Changed

- **Assignment no longer re-reads the Category tree.** Two paths asked it, and both asked for the
  whole thing — two full table scans for an answer that cannot change while the service lives.
  `isBoundary()` asks on every create, update, retire and restore; `boundaries()` asks again each
  time a boundary write empties its cache, so a request that moved *N* boundaries read the taxonomy
  *N+1* times.

  Measured on the dev instance: **55,576 sequential scans of `oc_territorio_category` against 431
  writes of it**. One run of the integration suite added 543 of them for 933 record writes; the same
  run adds **193** now.

  One map, `subcategory id => division slug`, answers both: `boundaries()` needs to know *which*
  division a Subcategory is, `isBoundary()` only that it is one — and an `isset` answers the second
  without walking the first. The loop that built it existed twice.

### Notes

**Memoising `TaxonomyService::tree()` itself was tried first, and is wrong.** It is the obvious place
and it broke `RampRepaint`: the colour repair writes a Category and reads the tree back in the same
request, so the memo served it the colours from before its own write. Two of that suite's assertions
failed on the clinic's own recoloured Category — not a hypothetical about #36 making the tree
editable, but a job that does it today.

What is cached instead is narrower: which Subcategories are divisions. #40 specifies that neither
those nor their Category can be removed and that a rename never changes a slug — but that is
specified, not shipped, and what guarantees this today is simpler: nothing in the app writes the
taxonomy outside the install-time repair.
## [0.33.0] — unreleased

Generar says which failure it is.

### Fixed

- **«Generar» on a reopened Sector failed, and blamed the streets.** `faceAround()` polygonizes the
  cached *geometries* of the picked lines, not their ids. `draftFrom()` rebuilds `picked` from the
  stored Limit — which is exactly what #15 put the names there for — and has nothing to fill
  `pickedGeometry` with, so a reopened Sector reached `faceAround` with an empty list, got null back,
  and was told «Las calles elegidas no cierran alrededor de ese punto». About streets that do close,
  so the person went and re-picked streets that were already right.

  The shapes come back on their own now. Every picked line that comes into view refills its own, so
  reopening a Sector and panning over its límite makes «Generar» work again **without re-picking
  anything**. That is safe only because the guard demands *all* of the picked lines: a partial set
  still closes, into a larger face than the one meant, with nothing on screen to say so — which is
  the silent failure `keepPickedGeometry` was written against. A half-filled cache reads as not
  ready, never as a smaller Sector.

  Until it is full the button is greyed out with the reason beside it, rather than offered and
  failing. ADR-0007 keeps hand-drawing first-class throughout, and the hint says so.
- **A save dropped the shapes of the lines just picked.** `save()` carried `picked` across the
  rebuild and not `pickedGeometry`, so pressing Guardar and then «Generar» failed the same way, on
  lines chosen a moment earlier. Both halves now cross the save.
- **A geometry that is not line work counted as a shape.** A Boundary row may carry one — an empty or corrupt
  geometry decodes to null — and `keepPickedGeometry` stored what it found, so a Limit whose lines
  were all like that passed a check for `undefined` and then produced no face: the same message,
  reached by another road. Worse once the cache refills on every map settle, where a null would have
  *overwritten* a shape already held. And nullness was only half of it: a boundary Dataset is
  filtered by Dataset and not by geometry, so a Point, a Polygon, or the one-repeated-coordinate
  LineString a KML import produces is pickable too, and each counted as ready. The guard asks
  `stretchesOf` now — held and usable are different questions, and only the second one matters.
- **Unpicking a street from the Limit left its shape in the cache.** `removeLimitPart` dropped the
  ids without going through `keepPickedGeometry`, so a shape outlived the pick it belonged to.
  Clicking the line on the map was never affected — that path always went through it.

### Notes

The first version of this refused outright, on the reasoning that the shapes could not be recovered.
An audit showed that was wrong: `loadBoundaries()` already receives the shape of every picked line
that comes into view, so one line rehydrates the cache as the person pans. The refusal became the
fallback rather than the answer.

The browser gate covers both halves — the button must survive a save, and a reopened Sector must
generate again with no re-picking and never say «no cierran» — and both were proven by reintroducing
each bug and watching the matching check fail.

## [0.32.0] — unreleased

One list of the streets a Limit runs along, not two.

### Changed

- **The Limit picker showed the same streets twice.** «Tramos del límite» is built from the Limit;
  «Elegido», thirty lines below it, was built from the picks. They always held the same streets —
  `recordLimit()` runs on every pick, so a picked id is already a Limit row by the time either list
  draws.

  «Elegido» was the strictly worse copy. It resolved names from the boundary rows *currently on
  screen*, and those are replaced wholesale every time the map settles and capped at three thousand
  against a comuna's eleven thousand — so a street a person had chosen degraded to «Fuera de la
  vista» as soon as they panned. The Limit row keeps the name permanently, which is exactly why #15
  put it there. Its «Quitar» was a second path to one outcome, and `removeLimitPart` unpicks the ids
  as well, so the remaining one loses nothing.

  Gone with it: `pickedGroups()`, its test block, and the `picked` prop — about sixty lines for no
  information a person could have read.

## [0.31.0] — unreleased

A pin you can click while looking at what was retired.

### Fixed

- **Clicking the map did nothing while «Ver retirados» was on.** The map draws the live Registry
  whatever that toggle says, and `listFor()` answers with the retired list *alone* once it is on —
  deliberately, so a search still reaches a record somebody is trying to restore. With the toggle on
  the two sets are disjoint, and `select()` was resolving the clicked id against the list's answer.
  It found nothing: the shape took the selected styling, the sidebar never opened, no history was
  fetched. The click looked accepted and was dead.

  Selecting is not a list operation. It resolves against the two sets the app holds, not against one
  view of them — the Capas filter narrows what is *listed*, never what exists.
- **«Abrir ficha» in the duplicate queue was dead the same way**, and more often: the queue is
  unfiltered on purpose, so its ids came from the whole live Registry while `select()` answered from
  the filtered list. Any Capas toggle or any search was enough. The same fix covers it.
- **Saving a record opened that way reported a failure over a save that had worked.** `applyIncoming`
  closed the draft whenever a record's retired flag differed from the toggle — which is every live
  record once one is reachable with «Ver retirados» on — and `save()` then read `picked` off the null
  that left behind. The user saw «No se pudo guardar. Revise la conexión» and an empty sidebar for a
  record the server had already accepted. The guard now closes the draft only when the record has
  actually left the screen, and `save()` reads what it needs before the merge rather than after.
- **A record retired by somebody else showed no restore banner.** `selectedIsRetired` asked whether
  the retired list was on show as well as whether the record was in it. The list is never cleared
  once loaded, so a record a colleague retired could be opened from the duplicate queue with the
  toggle off, and it came up with no banner and a live «Guardar». Membership is the whole question.

### Notes

Only a browser could see it. Both sets are correct on their own and neither suite pairs them, which
is the same shape as `Guardar` doing nothing for eight slices. The browser gate now clicks the
Sector it drew itself with the toggle on and requires a sidebar; reintroducing the old lookup and
rebuilding reddens it.

## [0.30.0] — unreleased

Saying who changed the límite.

### Fixed

- **A Sector's Limit never reached its history.** `comparable()` is keyed as the client knows a
  Feature and emits `limit`; `getUpdatedFields()` is keyed by property, and the property is
  `boundaryLimit` — the column is `boundary_limit` because `limit` is reserved. Of sixteen keys it is
  the only one that diverges, and `diff()` gated one on the other, so `isset($writing['limit'])`
  could never be true. Every Limit edit recorded «Editado» with nothing under it, against a
  promise written into `Feature.php` itself: *"quién cambió el límite is exactly the kind of question
  history exists to answer"*.

  An alias on the entity now carries it — not a rename, because `RevertService` and `ImportService`
  both key off the client names. A reflection test asserts every `comparable()` key resolves to a
  declared property through that alias, so the next reserved word cannot open the same hole quietly.

### Notes

**The test was the worse half.** `testChangingTheLimitIsInHistory` flattened `array_keys` across
every history entry, and `insert()` builds the Created entry from `comparable()` directly, bypassing
the updated-fields gate — so it always carried `limit` and the assertion passed on creation while
every update dropped it. It now asserts on the update entry alone, and fails when the alias is
removed. A test that cannot fail is worse than the bug it was meant to hold.

Confirmed in the running app: after changing a Limit the sidebar now reads
`Límite: (vacío) → prueba de historial`.

## [0.29.0] — unreleased

Names are text, not markup.

### Fixed

- **A stored name could redirect everyone who opened the map.** Leaflet assigns string tooltip
  content with `innerHTML`, so `bindTooltip(feature.name)` parsed a name as markup. Nextcloud's CSP
  stops script and every exfiltration channel — `script-src` is nonce-based, `connect-src`, `img-src`
  and `font-src` are all `'self'` — but **nothing covers navigation**, and an injected
  `<meta http-equiv="refresh">` was confirmed navigating a real browser. That made it a stored
  redirect to a page of the attacker's choosing, arriving from a trusted internal app.

  The realistic source is not a colleague — ADR-0005 already lets any user edit any field, so they
  gain nothing — but **an imported cadastre**: the second call site renders names straight out of a
  file the clinic did not write. Both now pass a text node, which cannot be parsed at all, rather
  than an escape, which is a thing you can get subtly wrong.

### Notes

Guarded in `tests/js/imports.test.js`, beside the two other mistakes that compiled and shipped. The
browser gate was tried first and abandoned after five runs: a tooltip only exists while Leaflet is
hovering a shape it has chosen to render, and it opens them on a timer, so the runs were measuring
the render lifecycle rather than the bug.

Proven both ways against the running instance, with a Unidad Vecinal renamed to carry the payload:
fixed, the tooltip holds the literal string with zero child elements and the page does not move;
reverted and rebuilt, the probe dies with *"Execution context was destroyed, most likely because of
a navigation"*.

## [0.28.0] — unreleased

Keeping what somebody typed before they saved.

### Changed

- **A re-import no longer keeps two hand-maintained lists of the same twelve fields.** `differs()`
  listed them beside `input()`, which lists them already. The two had to agree by hand, and where
  they disagree the loss is silent — not on an ordinary re-import, where the authority's own blob
  answers first and it is the whole row, but on the one path that reaches the fields at all: a
  record a **revert** put back, which writes old values without marking an override (ADR-0009), so
  the record disagrees with an official blob that is still current. `differs()` now derives from
  `input()`, with the two fields normalised that are stored in a shape they do not arrive in.
- **Both taxonomy joins come from `TaxonomyService::tree()`.** `ImportService` and `ExportService`
  each rebuilt the Category↔Subcategory join from the two mappers; four constructor dependencies and
  four imports went with them.
- **`DatasetMapper::findOrCreate` no longer takes an `Origin`.** Both callers passed
  `Origin::Cadastral` and the column is written once and never read. The enum stays — CONTEXT.md
  needs the word — and the parameter comes back when a Curated Dataset can be created.
- **`ContactFields::KEYS` is gone.** It was read by no production code: a canonical third copy of a
  list the two writers already hold, which is worse than two, because it looked like the authority
  while the writers were the authority. They must keep their own maps — GeoJSON says
  `contact_person`, a CSV column says `Persona de contacto` — so what has to hold is that the two
  agree, and a test now makes that true rather than claimed.

### Fixed

- **The gate left a live Sector behind on every run.** The check added for #48 draws and saves a
  hand-drawn Sector and never retired it, so five had accumulated, one per run. A Place left behind
  is harmless; a **boundary** is not — every Place inside it is reassigned to it (ADR-0008), so
  `ExportTest` began failing on a `sectorId` that had drifted to a Sector a gate run had left on the
  map. `retire_the_runs_record` already existed for exactly this, and its docstring already said it
  had broken two integration tests that way once before.
- **The browser gate drew into a full map, and mostly missed.** It hid the cadastre once at
  start-up — but a View is per-session and nothing persists it, so every one of the file's ten
  reloads brought the Unidades Vecinales straight back. Only the first draw was ever protected.
  Measured: three misses in five runs, and then every run once the map had filled up, at which point
  no branch could get a green gate at all. The boundaries now come off the map for the duration of
  each draw and go back afterwards, through the app's own Capas toggles — ten draws in ten, each
  after a reload. `draw_a_sector`'s `offset` went with it: it worked around a second draw landing
  inside the first one's polygon, which cannot happen once that polygon is hidden.
- **A tramo typed on a Sector that had never been saved was discarded**, with nothing said. ADR-0007
  keeps hand-drawing first-class and a hand-drawn Sector's whole Limit may be typed stretches — but
  `startSector` built the draft with no `limit` key at all, `addLimitNote` keys on it being defined,
  and the picker cleared its input either way. So the text vanished. It worked after a save and
  reopen, because that path sets an empty Limit, which is why the browser gate never saw it: every
  check there opens an already-saved Sector.

### Notes

The gate now reaches a Sector **before** its first save, which it never did before. Two smaller
things had to be true first, both of the same shape as issue #53 — a check that operates on
whatever the sidebar happens to hold. `draw_a_sector` now returns whether it actually drew, so a
caller cannot carry on regardless; and it takes an offset, because its position is fixed within a
run and a second draw landed inside the first one's polygon and selected it. NUDGE existed for
exactly that hazard, one scope too wide.

## [0.27.0] — unreleased

A pin that says what kind of place it is.

### Added

- **Every Category is drawn with its own pictogram**, from Maki (CC0-1.0): a cross for Salud, a
  school, a pitch, a police badge, a recycling mark, a community block, a town hall, a bus, a place
  of worship, a shop, and an open ring for "Sin clasificar". They appear in the map pin, the list
  swatch and the Capas legend, all from one function — so the legend cannot disagree with the map.
- `place-of-worship` rather than `religious-christian`, because the app must not assume a
  denomination. `circle-stroked` for "Sin clasificar", because that is a real seeded Category
  meaning *nobody has decided yet* and must not read as an error.
- A Category a clinic adds later, and a Feature whose Subcategory no longer exists, both fall back
  to the neutral glyph rather than rendering a blank dot — a blank reads as a rendering failure.

### Changed

- **Pins are 22px, not 18px; list swatches 18px; Capas swatches 16px.** Maki draws on a 15-unit grid
  and needs roughly 12px of actual mark before its detail survives. At 11px inside an 18px dot,
  `hospital` and `bus` still read but `school`, `police` and `place-of-worship` turned to mush —
  checked by rendering all eleven at four sizes and looking. The pin is the only place a glyph has
  no label beside it, so it is the one sized for legibility on its own.
- `webpack.config.js` loads `@mapbox/maki` as `asset/source`. The Nextcloud default for SVG is
  `asset/inline`, a data URI, which cannot be recoloured and cannot be inlined into the Leaflet
  `divIcon` — and both are needed.

### Fixed

- **The browser gate no longer edits the cadastre when a draw misses.** `draw_a_sector` waited for a
  colour input to confirm it had a Sector draft — but a Unidad Vecinal is a territorial division and
  carries one too, so a canvas click that selected a boundary instead satisfied the guard, and the
  gate typed a name and a colour into the cadastre and saved. Three Unidades Vecinales were renamed
  that way. A history section is the tell: it renders only for a record that already exists, and a
  Sector drawn a moment ago has none (issue #53).

### Notes

This is the half of ADR-0013 that does the identifying. Hue only groups the eleven Categories into
six families, because `2·C·sin(Δh/2)` says eleven separated hues need a chroma louder than the
palette that was replaced. **A dichromacy touches the hue and never the shape**, which is why the
glyph carries identity and the ramp is allowed to stay quiet.

The browser gate creates a Salud record, asserts its pin carries the `hospital` glyph filled with
the seed's own `salud` colour, and retires it again. Removing the glyph makes it report `None`;
mapping Salud to the wrong icon makes it report `'shop'`.

### Documentation — 2026-08-08

The repository's first documentation audit. No behaviour changed; several documents that described
behaviour were wrong.

- **A real clinic was named in code.** `src/filters.js`, three test fixtures and their assertions
  carried the establishment this app was first built for. Which clinic an install serves is instance
  configuration, so the fixtures now use a synthetic name and a `cesfam_de_prueba` source slug.
  ADR-0004 keeps the slug it recorded, with a dated correction saying why.
- **Every ADR has a `Status:` field**, dated from the commit that introduced it. ADR-0001 justified
  the app partly on "a REST surface other apps read" while `tests/unit/RoutesTest.php` fails if one
  is added; the new **ADR-0014** records that the surface is `RegistryService`, consumed by
  injection, and ADR-0001's Status links forward to it.
- **THIRD-PARTY-NOTICES.md was derived from the manifests only**, so DOMPurify (dual-licensed
  MPL-2.0 or Apache-2.0), `focus-trap`, `tabbable` and `escape-html` shipped in every page load
  unattributed. It is now reconciled against `js/*.LICENSE.txt`, which is what the bundle says about
  itself, and it names both sources.
- **`package.json` no longer declares a version.** It said `0.1.0` while `appinfo/info.xml` said
  `0.27.0`; info.xml is the one Nextcloud reads and the one every slice bumps, so the second
  declaration is gone rather than duplicated.
- **Nothing blocks a merge here**, and `CLAUDE.md` and the `slice` skill said otherwise. A private
  repo on this plan cannot have a protected branch and nobody can approve their own pull request, so
  the browser gate is a step you do not skip and the approval is a self-review checkpoint. The skill
  also documented `git push origin main`; it now lands through a pull request.
- `.github/` and `.githooks/` did not exist: CODEOWNERS, the issue forms, the PR template, the
  pre-commit hook and the `docs` workflow are now in place from the org's canon. The vendored
  checker cannot run yet — see `CLAUDE.md`.
- New `docs/index.md` names the owner of each fact.

## [0.25.0] — unreleased

Geometry that draws correctly and means nothing.

### Fixed

Three ways a boundary could look right on the map and be wrong underneath. All silent — the shape
drew, the import succeeded, and nothing anywhere said the geometry was broken.

- **An unclosed ring contained nobody.** GeoJSON requires a ring to repeat its first position last
  (RFC 7946 §3.1.6) and real files do not always do it. `PointInPolygon` needs four positions before
  a ring has an inside, so an unclosed **triangle** assigned every Place inside it to nothing —
  while Leaflet, which closes rings when it draws, showed a perfectly ordinary Sector. An unclosed
  **quad** already has four positions and worked, so closure was silently required at three vertices
  and silently optional at four. Rings are now closed once, on the way in, in `Geometry` — which is
  also what stops the app **re-exporting invalid GeoJSON** to whoever asked for it. A ring that
  cannot bound an area at all is refused with a message naming the ring.
- **KML holes were dropped.** `KmlReader` read `<outerBoundaryIs>` and ignored `<innerBoundaryIs>`,
  so a park drawn around a lagoon claimed every record standing in the lagoon.
- **A `<MultiGeometry>` placemark imported shapeless.** One shape inside is now unwrapped — MyMaps
  writes that often enough that refusing it would throw away ordinary records. Several shapes make a
  Multi\* geometry this app does not store, and it now arrives as a type the import **refuses**, so
  the batch counts and reports it. Arriving with no geometry made it indistinguishable from a record
  somebody never located.
- **One degenerate line poisoned a whole street.** A KML `<LineString>` with a repeated coordinate
  imports as `[[x,y],[x,y]]` — two positions and no length — which passed the picker's length check
  and made `polygonize` refuse the entire collection. The editor reported that as *"las calles
  elegidas no cierran alrededor de ese punto"*, which was untrue; the streets closed perfectly. And
  because picking by name picks every fragment of a street, one bad row broke the whole pick.

### Notes

Each fix fails its own test when reintroduced. The unclosed ring is pinned twice: in `GeometryTest`
on what gets stored, and end to end in `AssignmentTest`, where removing the fix leaves a Place
inside a triangle assigned to nobody.

## [0.24.0] — unreleased

Making undo finish the job, and stopping a Sector handing its shape to the next record.

### Fixed

- **Closing an edited Sector wrote its polygon onto whatever record you opened next.** Drag a vertex,
  click another record in the list, save it — and it saved with the Sector's shape, because a drawn
  shape wins over typed coordinates. Geoman binds `remove` to `disable()`, and `disable()` fires
  `pm:update` when a vertex has been dragged, so tearing down the draft layer ran a handler that was
  still bound and emitted the *old* record's geometry into the *new* record's draft. One line:
  unbind before removing. The browser gate now drags a vertex, switches records, saves, and reads
  the geometry back out of the change feed — removing the fix makes it say
  *"was saved with a Polygon after an edited Sector was closed — it took the Sector's shape"*.

A revert is the only recovery path this app has — ADR-0009 chose that over a backup feature — and
it had three holes. All of them silent, and all of them reported as success.

- **Undoing an import that created a Sector or a Unidad Vecinal left every Place still pointing at
  it.** `RevertService` retired the boundary through the mapper, which writes the column; only
  `FeatureService` also lets go of what the boundary covered (ADR-0008). So the Registry held an
  assignment to a record no read will resolve, and the sidebar said "sin asignar" about a record the
  database said was assigned. Registry-wide, and the revert counted those records as updated.
- **Undoing a redraw put the old shape back and left the assignments where they were.** Same duty,
  the other path: `FeatureService::revertTo()` wrote the geometry without reassigning, so a boundary
  that shrank off a Place left the Place still claiming to be inside it.
- **A batch that touched one record twice was only half undone.** A revert stamps its own Revisions
  as it goes, so after undoing the newer entry the older one saw the revert's own write and decided
  a colleague had been there. The record stayed on the map, counted as **"omitido"** — which reads
  as deliberately protected rather than missed. It happens whenever one file holds a Place and a
  boundary covering it, because importing the boundary reassigns the Place inside the same batch.
- `Action::Merged` had no arm in the revert's `match`, so a merge recorded under an import id would
  have been a 500 mid-revert. Unreachable today; a revert is the wrong moment to fall over.

### Notes

`RevisionLog::forImport()` carried a comment asserting no import touches one record twice. That was
true of duplicate rows, which are collapsed before anything is written, and missed reassignment
entirely. Corrected.

`tests/integration/RevertAssignmentTest.php` crosses revert and assignment, which nothing did
before — each of the three fixes fails exactly one of its tests when reintroduced.

## [0.23.0] — unreleased

Making the map's colours mean one thing each.

### Changed

- The eleven **Category colours** are now a designed low-chroma ramp instead of a Tailwind-600
  grab-bag. Hue carries a *family* of six, lightness distinguishes the two members inside a family,
  and identity will be carried by a pictogram (#20). The reason it has to work that way is
  arithmetic, not taste: `2·C·sin(Δh/2)` says eleven separated hues need a chroma louder than the
  palette being replaced. See [ADR-0013](docs/adr/0013-hue-groups-categories-the-glyph-identifies-them.md).
- The ramp is **quiet on purpose**, so that a Sector's boundary and its name can be the loudest
  thing on the map rather than competing with eleven category dots.

### Fixed

- A Sector nobody recoloured was drawn in **exactly the colour of every school** —
  `DEFAULT_SECTOR_COLOUR` and Educación were both `#2563eb`, byte for byte.
- `comercio_economia` sat at **2.94:1** against a white page, and `seguridad` at **2.27:1** against
  the dark theme. Both were below the 3:1 that non-text UI needs and nothing had ever measured them.
- `medioambiente` and `movilidad_transporte` were ΔE 0.049 apart — one colour at 18px. Under
  tritanopia **five** pairs fell below that threshold and **three** of them were literally the same
  colour (ΔE 0.006, 0.009 and 0.015).
- `UNKNOWN_COLOUR`, which marks a Feature whose Subcategory no longer exists, was byte-identical to
  `sin_clasificar`. The list already refuses to *label* those two alike; the colour said they were
  the same thing anyway.

### Added

- `EnsureSeedData::ensureRamp()` — the repair step that carries the ramp to an install that already
  exists. `ensureTaxonomy()` returns the moment the Category table has rows, so without this a
  change to the seed reaches only a brand-new install. It repaints **row by row, guarded on the old
  colour**, so a Category a clinic recoloured for itself survives untouched; that guard is pinned by
  `tests/integration/RampRepaintTest.php` against a real database.
- `tools/ramp-check.py`, run by `make lint`. It reads the colours the app actually ships — out of
  the PHP seed and out of `src/taxonomy.js` — rather than holding a copy that could agree with
  itself while the app disagreed with both. It gates on contrast, on normal-vision separation, and
  on a catastrophe floor under each of the three dichromacies.
- `docs/design/map-legibility.md`, the working-out behind issues #20–#26, and
  [ADR-0013](docs/adr/0013-hue-groups-categories-the-glyph-identifies-them.md) for the decision
  inside it that binds.

### Vocabulary

- **Sector** now says what it is *for*: it divides the population as much as the ground, and each
  one has a fixed team so the same families are seen by the same people. That purpose is what makes
  a Sector the figure and a Unidad Vecinal the ground, and it was nowhere in the repo.
- **Unidad Vecinal** now says it is a unit of *community* organisation — the basis on which a Junta
  de Vecinos is formed — that the clinic uses as **reference**, not as a working division.

### Known gap

`ensureRamp()` repaints `territorio_category` only. Every Sector **already drawn** keeps the colour
stored on its own row, including the old `#2563eb` default — so those are now saturated blue on a
deliberately quiet map. Issue #21 owns it.

### Notes

This is **not** a colour-blindness fix and is not claimed as one. The new ramp collapses more pairs
under protanopia and tritanopia than the one it replaces, and fewer under deuteranopia; the numbers
are in the ADR. No palette makes eleven categories safe on hue — the glyph does, and that is #20.

## [0.22.7] — unreleased

Writing down what a Sector runs along.

### Added

- A Sector keeps its **Limit**: the Boundary features it was built from, by name, plus any
  stretches somebody types in because no dataset holds them. Shown as one sentence — "Va por
  Vicuña Mackenna, el canal y por detrás del colegio" — with each part removable.
- The Limit comes back when the Sector is reopened, and survives adjusting the shape by hand.
- It appears in the GeoJSON and CSV exports as that sentence. Not in KML, which carries only a name
  and a location and says so in the file.
- The GeoJSON export writes it but the import does not read it back — the only field that does not
  round-trip, and deliberately: its parts name Boundary features by id, which mean nothing in
  another install, so reading the sentence back would replace a Limit built from a dozen picked
  streets with one line of prose.

### Notes

- **The Limit records intent; the geometry records the agreed line.** They match when a Sector is
  generated and stop matching the moment a vertex moves, which is expected rather than drift to be
  corrected. Nothing compares them or tries to keep them in step.
- Names are stored beside the ids, not looked up later. The lines are loaded only while the editor
  is open and only for what is on screen, so a Limit holding bare ids would read as blanks the
  moment somebody scrolled away — or after the Boundary Dataset was re-imported.
- A repeated name is said once, and listed once. A comuna holds one street as dozens of ways, so
  picking "Vicuña Mackenna" records dozens of parts — one row per name, with the count beside it,
  and removing the row removes all of them.
- A Sector drawn entirely by hand has no Limit at all, and that is valid (ADR-0007).
- Omitting the Limit from a save leaves it alone, like every other optional field. That is what
  makes it survive hand-adjustment: a client dragging a vertex must not have to remember to send
  the Limit back unchanged in order to keep it.
- The column is `boundary_limit`, not `limit`: the latter is reserved in every database this could
  run on, and a column needing quotes everywhere is one somebody eventually forgets to quote.

## [0.21.8] — unreleased

Building a Sector from the streets around it.

### Added

- **Generar el polígono**, in the Limit picker once something is picked: click inside the block and
  the picked lines become a Sector polygon, straight into the ordinary editor with every vertex
  draggable.
- Ordering, ring closure and repeated names need no intervention. The picked lines are cut into
  every enclosed face they make and the one containing the click is kept, which disposes of all
  three at once rather than solving them one at a time.
- When the lines do not close around the point, it says so and nothing changes. Hand-drawing stays
  available throughout (ADR-0007).

### Notes

- The generated polygon is a starting point, never the final answer. Real limits run along a canal,
  the edge of a park, or a line two teams agreed on years ago — none of which is in any dataset.
- The seed click is the person's, and cannot be guessed. Four streets crossing make several blocks,
  and the centre of the picked lines is outside the Sector whenever its limit runs along an L of two
  avenues.
- Nested faces resolve to the smallest one containing the click, so a Sector inside a ring of picked
  streets is the Sector rather than everything around it.
- The shape of each line is kept the moment it is picked. The list of lines on screen is replaced
  every time the map settles and capped at three thousand rows, so building a Sector from four
  avenues — which means panning along them — otherwise lost picks silently.
- `@turf/polygonize` and `@turf/boolean-point-in-polygon` are new dependencies. Polygonizing by hand
  is a graph traversal with an edge case for every way a street can join another one.

### Fixed

- Two integration tests asserted a record's Sector was `null` after a boundary moved away from it.
  That is a claim about every Sector on the instance, not about the one that moved, and the browser
  gate broke it the moment it started building four-kilometre Sectors. They now assert the record
  let go of *that* boundary. The gate retires its own Sector at the end of a run.

## [0.20.7] — unreleased

Taking the filtered set out of the app.

### Added

- **Exportar**, beside the search, exporting exactly what the list is showing — layers and search
  applied. GeoJSON (complete, and it goes back in through import), CSV (for a spreadsheet) and KML
  (name and location only).
- An export carrying contact details asks for the user's own password, using Nextcloud's
  `PasswordConfirmationRequired(strict: true)` rather than any credential invented here, and is
  recorded with who, when, which format and how many records (ADR-0006). An export carrying none
  asks for nothing, because a prompt that appears every time teaches people to type their password
  without reading why.
- An administrator reads the export log in **Administración → Territorio**: who, when, which
  format, how many records and what the export was of. It is the only route in the app that is not
  open to every user, because it is the list of who took personal data out of the building.

### Notes

- The export log is its own table, not a Revision (ADR-0012). `territorio_revision` IS the change
  cursor every open browser polls, and an export changes nothing — logging one there would make
  every open map in a clinic refetch for a change that does not exist.
- The confirmed and unconfirmed exports are two routes, not one route with a flag.
  `PasswordConfirmationRequired` is an attribute on a method, so a single endpoint deciding for
  itself would put the whole protection behind an `if` that a later edit can invert.
- KML carries no contact details whatever is asked for, and says so inside the file rather than
  only in the dialog — the file is what gets emailed to somebody who never saw the dialog.
- The CSV starts with a byte-order mark. Excel does not ask about encoding, it guesses Latin-1, and
  "Ñuñoa" opens as mojibake on the machines this file is actually opened on.
- Re-importing a GeoJSON export updates rather than duplicates, and every field a person can see
  survives. It does count as one update: an import rewrites `official`, the record of what the
  authority published, and an export of ours is not the authority.
- A CSV gives latitude and longitude only for a single point. A Sector is a polygon and its centre
  is not where the Sector is.
- A GeoJSON exported WITHOUT contact details cannot be imported back. Its contact fields are absent
  rather than empty, and an import reads absence as deletion — re-importing one would clear a
  comuna's telephone numbers, one silent "Actualizado" per record. The file says it is partial and
  the reader refuses it.
- A CSV value beginning with `=`, `+`, `-` or `@` is written as text. Excel executes such a cell
  when the file is opened, every user can edit every record (ADR-0005), and the formula would run
  on the machine of whoever opened the export rather than whoever typed it.
- A KML cannot be exported "with contact details" at all. It carries none, so the request can only
  be a mistake, and honouring it would mean a password prompt for a file with nothing personal in
  it — the unearned prompt ADR-0006 exists to avoid.

## [0.19.4] — unreleased

Two records that might be one place, decided by a person.

### Added

- A **Posibles duplicados** pane listing candidate pairs, with the outstanding count on the button that
  opens it. Each pair says why it was offered, shows both records side by side, and offers three
  answers: keep this one, keep that one, or they are not duplicates.
- Import flags candidate pairs and merges nothing (ADR-0011). Two shapes are detected, both of
  which appear in the real data: records at the same coordinate under different names, and similar
  names within a short distance of each other.
- Merging retires the loser and records a pointer to the survivor, which is the mapping
  `fonasa_eliminados.csv` was keeping by hand. Restoring the record undoes the merge and puts the
  pair back in the queue.
- `Fusionado` is its own entry in history, separate from `Retirado`: a merged record was not
  withdrawn, it was decided to be the same place as another one.

### Notes

- No auto-merge, at any threshold (ADR-0011). A pair left in a queue is visible and harmless; a
  wrong merge fails silently and is discovered by somebody phoning the wrong place.
- A dismissed pair never comes back. Re-offering it after the next import would teach people to
  close the queue and leave it closed.
- Detection compares Places only. A Sector has an extent rather than a coordinate, so "the same
  place" would mean overlapping areas — a different question, and one that would offer every
  Sector against the Unidad Vecinal it covers.
- Similar names count only within about 150 m. "Sede Junta de Vecinos" is thirty different
  buildings in La Florida, and pairing them all is 435 questions nobody will ever read.

## [0.18.8] — unreleased

Saying where a Sector's limit runs.

### Added

- While a Sector is open, the comuna's streets, canals and railways load for the view on screen and
  can be picked — by clicking a line, or by typing a name. Both land in the same selection, which
  is listed and individually removable. They unload when the editor closes.
- `GET /boundaries` returns the Boundary features overlapping a box, and only that: they are absent
  from every other read because a comuna is around eleven thousand lines (issue #12).

### Notes

- The app never resolves a street NAME (ADR-0007). Typing offers every name that matches and picks
  none of them: "Los Toros" and "Pasaje Los Toros Norte" are two rows, and choosing between them is
  the person's job, in one click, rather than a geocoder's guess.
- Names are grouped, because a comuna holds one street as dozens of separate ways. Picking the name
  picks all of it, and the count says how much that is — thirty identical rows is not a choice
  anybody can make.
- A line picked and then panned away from stays picked. It just cannot be named until it is loaded
  again, and dropping it would silently unpick it.
### Fixed

- The map no longer stops drawing after zooming. Its Leaflet handles lived in `data()`, so Vue 3
  wrapped every one of them in a reactive Proxy — and Leaflet's event bus identifies a listener by
  its context object, so a layer registered raw and unregistered through a Proxy left a handler
  pointing at a layer whose map was already gone. The next zoom threw and the map went stale. This
  had been reported and mis-diagnosed three times; nothing there was ever rendered, so none of it
  needed to be reactive.
- `tools/browser-check.py` now zooms and pans with the Limit picker open. The fault above only
  appeared about one run in three, because a redraw has to land inside a view change to create the
  broken handler — but once created it survives, so churning first and zooming after makes it show
  every time. Verified by reintroducing it: three runs, three failures.

### Notes

- Zoom animation stays off. It was a guess at the fault above rather than its cause, and it is kept
  because this map still rebuilds layer groups from three separate causes and an animated zoom is
  the widest window for one to land in.

## [0.17.1] — unreleased

Where a record falls, worked out rather than typed.

### Added

- A Place's Sector and Unidad Vecinal are resolved from its coordinate and stored (ADR-0008).
  Ray-casting point-in-polygon in about forty lines of PHP with a bounding-box pre-check — no
  PostGIS, which is not in the database image, no GEOS, no geometry library.
- Redrawing a boundary reassigns everything it gained and everything it lost, each as an ordinary
  write with its own Revision and history entry. ADR-0008 asks for exactly that: the reassignment
  is the point, and it has to be visible.
- The sidebar shows a record's Sector and Unidad Vecinal and offers nowhere to type them.
- `RegistryService` counts by Sector and by Unidad Vecinal, which is the half issue #18 had to
  leave until something stored an assignment.

### Notes

- A boundary that arrives AFTER the records takes them on. That is the likeliest
  order in practice — a comuna's Unidades Vecinales land after its catastro does — and without it
  every one of those records would sit assigned to nothing.
- Retiring a boundary lets go of everything it held, and restoring it takes them back. A record
  keeping an id that points at a retired Sector would be assigned to something the app will not
  show, and the sidebar would call it unassigned.
- A point on a boundary counts as inside. Ray casting alone answers that differently depending on
  which way the ray leaves, and a record on the line between two sectores belongs to one of them
  rather than to neither — "neither" is the answer that looks like a bug to whoever finds it.
- Renaming or recolouring a boundary reassigns nobody and spends no Revisions. Only a moved shape
  can change an answer.
- Null is a real answer, twice over: a record with no coordinate, and one outside every boundary
  the install holds. The catastro this app inherits has plenty of both.

## [0.16.0] — unreleased

The comuna, and the Unidades Vecinales that describe it.

### Added

- Setup asks which Comuna the install serves — exactly one, by CUT code, refused unless it is five
  digits. A Territorio is always a portion of one Comuna and never reaches into a second.
- `tools/comuna-cut.php` cuts the national `UnidadesVecinales_2024v4` file down to one comuna,
  keyed on `t_id_uv_ca`. The national file is 128 MB and 6,887 polygons across 346 comunas; one
  comuna is under 100 KB, and **the app must never need the national one**.
- A comuna's Unidades Vecinales import as a Cadastral Dataset and toggle beside the Category tree,
  like Sectores. They are read from the cadastre, never drawn.
- A file cut for another comuna is refused by name, before anything is written.
- The map opens on the comuna the install serves, measured from the Unidades Vecinales it holds,
  instead of a fixed guess at central Santiago.

### Fixed

- **Clicking the map to place a record no longer selects a boundary instead.** Once a comuna's
  Unidades Vecinales are imported they tile the whole view, so every click landed on one and handed
  the next save to the cadastre — one was renamed this way while the browser check ran. A click
  belongs to the open draft whenever one is waiting for a coordinate.
- **The map no longer empties itself.** Rebuilding the marker layer emptied a cluster group the map
  was still using, so a redraw landing inside a zoom or a pending reposition left Leaflet holding
  markers whose map was gone — it threw, and the pins did not come back. The group is now swapped
  whole. Zoom animation is off for the same reason: a smooth zoom is not worth a map that blanks.

### Notes

- The cutter converts a `.shp` with `ogr2ogr`, which is GDAL's job and not a dependency of this
  app — it runs once, on whoever cuts the file. Hand it a `.geojson` and it skips that step. The
  conversion is the one part no test here covers; `cutComuna()`, where every decision lives, is pure
  and tested.
- Verified end to end on a dev instance with an invented national file of two comunas: 37 Unidades
  Vecinales cut and imported, a file for another comuna refused by name, and a re-import spending
  no Revisions at all.

## [0.13.0] — unreleased

KML.

### Added

- KML imports through the same pipeline as GeoJSON: same Dataset, same batch, same derived ids,
  same revert. Points, lines and areas all arrive.
- A Folder name becomes the Category — and where the folder names a Subcategory instead, that is
  found too. Both matter for the real file: without the first, every record lands unclassified
  while its Folder names the right Category; without the second, "Ciclovías" and "Plazas y parques"
  alone leave 309 of 865 records, 36% of the export, in Sin clasificar while `ciclovia` and `plaza`
  sit in the seed.
- The import dialog says what KML cannot carry **before** the import runs, while a person can
  still choose a different file.

### Notes

- KML does not become rows of its own. It becomes a GeoJSON FeatureCollection and is then read by
  the reader that already existed, so the two formats cannot drift into importing differently.
- Checked against the real `La_Florida_Google_MyMaps.kml`: 865 placemarks, not one `ExtendedData`
  element between them. So no phone, email, contact person, opening hours or source — the warning
  is a fact about the format, not a caution.
- Parsed with SimpleXML, which PHP already has. A library to read five tag names would be a
  dependency for nothing.
- Placemarks are matched by local name, because KML files do not agree on a namespace: Google Earth
  still writes `earth.google.com/kml/2.1`, and naming the OGC one alone found nothing in those and
  told the person their file was empty.
- A named Placemark whose geometry cannot be read still becomes a record, with no coordinate. That
  is a valid state here, and dropping the row silently is what the batch's counts exist to prevent.

## [0.12.0] — unreleased

An answer for the app next door.

### Added

- `OCA\Territorio\Service\RegistryService` — counts and record lookups for other apps on this
  instance, by injection. No OCS route and no external API, and two tests keep it that way: one
  refuses an `ocs` section in the route file, another refuses a `Db\` class in any controller.

### Changed

- The import dialog's batch list and the page's opening cursor moved out of their controllers and
  into the services that own them. A controller that reads the database itself is a controller an
  OCS version would have to copy rather than replace.

### Notes

- The service reads through `FeatureMapper::findAll()` rather than querying for itself, so what it
  answers and what the app shows cannot drift: retired records are out, and so are the
  Boundary-feature Datasets.
- Counting records **by** the Sector or Unidad Vecinal they fall in is not there yet — ADR-0008
  derives that from geometry and stores it, and nothing stores one until issue #11. Counting the
  sectores themselves already works: they are Features under `division_territorial`.

## [0.11.0] — unreleased

Search.

### Added

- A search box narrows the Datos list, the map and the Análisis counts together, because it is a
  predicate on the one filtered set rather than a mechanism beside it. Case- and accent-insensitive:
  "castanos" finds "Los Castaños". It matches name, address, contact person and the
  "Categoría · Subcategoría" label, and it composes with the Capas toggles in either order.

### Notes

- Search reaches the retired list, where the Capas toggles deliberately do not. A toggle is a
  background setting someone forgot they left on; a query is someone saying what they are looking
  for, and ignoring it in the list they are staring at would overrule them.

## [0.10.0] — unreleased

Guardar works. It never had.

### Fixed

- The save button submits the form. `FeatureDetail.vue` passed `native-type="submit"` to `NcButton`
  — the @nextcloud/vue **8** prop name. Under 9 it is `type`, so the prop fell through as an inert
  HTML attribute, the button stayed a `type="button"`, and a `type="button"` inside a form does not
  submit it. `@submit.prevent` never fired and `App.vue::save()` was unreachable from the UI. It had
  been that way since the first Places slice: **not one record in this app's history had ever been
  written from a browser** — every revision carried a null actor, meaning `occ` or a test.
- A refusal that happens before a draft exists is now visible. `startSector()` wrote its error into
  the sidebar, which only renders while a draft is open, so a failed draw removed the polygon and
  said nothing at all.

### Added

- `tools/browser-check.py` — drives the running app with Playwright: creates a Place through the
  form, draws and saves a Sector, reloads, and asserts both survived. It checks the Registry rather
  than the screen, because a green screen over an empty table is the failure it exists to catch.
- A static guard for props @nextcloud/vue 9 no longer has. Vue passes an unknown prop straight
  through as an attribute, so a stale name is silent — no warning, no build error.

### Notes

- Three suites, a webpack build and a two-axis review on every slice passed for eight slices while
  the app's primary action did nothing. None of them loads a component or opens the app. That is the
  gap the browser check closes.

## [0.9.1] — unreleased

The comuna's streets, canals and railways — in the Registry, off the map.

### Added

- `tools/boundary-features.php` produces a comuna's Boundary features as GeoJSON from a bounding
  box. A repo-side tool run by hand, never a call the app makes (ADR-0004).
- `occ territorio:import … --boundary` marks the Dataset as boundary features, which takes the
  whole collection out of every ordinary read at once: the map, the Datos list, the Análisis
  counts and the change feed.
- Import accepts every geometry the Registry can store, not Points alone. What is left over —
  a MultiPolygon, say — is still counted rather than refused, so one odd row cannot make a
  cadastre of thousands an all-or-nothing import.

### Notes

- Named lines only, and the name filter earns its place twice over: for La Florida it is the
  difference between 11,178 ways and 19,034, the rest being service roads and driveways nobody
  bounds a sector with — and the import refuses a nameless row outright, so an unnamed way would
  abort the whole file rather than be skipped.
- Ids are OSM's own (`way/12345`) rather than the digest of name and position the reader derives
  otherwise. A street renamed or redrawn upstream therefore updates the record it already has,
  which is what makes a refresh months later safe — and no Sector already drawn is touched by one.
- Boundary features are filed under "Sin clasificar · Otro" deliberately. They are never drawn and
  never toggled, so what they are is said by the Dataset they belong to; seeding a taxonomy row
  for eleven thousand invisible records would put a branch in Capas that means nothing to anyone.

## [0.8.0] — unreleased

Sectores, drawn by hand.

### Added

- A Sector is drawn on the map with the polygon tool, named however the clinic names things, given
  a colour of its own, and saved. It renders as a light translucent fill under a clear outline,
  beneath the pins standing on it.
- Vertices snap to boundaries already on the map while drawing, so adjacent sectores share an
  exact edge instead of leaving slivers between them. Pins are excluded: a limit runs along a
  street, a river or another Sector, never through the middle of a school.
- An existing Sector reopens editable: select it and drag its vertices. The edit is an ordinary
  change, so it appears in history like any other.
- Sectores have their own toggle in Capas, beside the Category tree rather than inside it.

### Notes

- A Sector is a Feature, not a table of its own ([ADR-0010](docs/adr/0010-a-sector-is-a-feature-not-a-table-of-its-own.md)):
  it gets history, retirement, the change cursor and the revert without any of them being written
  a second time.
- This is the fallback path of ADR-0007 and it ships first. When the street picker arrives it
  produces a starting polygon and hands it to exactly this editor — hand-drawing stays fully
  supported, never second-class.
- Zones and Routes are no longer refused on the way in. The restriction was only ever "nothing
  draws one yet"; the geometry code has measured polygons correctly all along.
- Snapping works against whatever is on the map, so it will cover Unidad Vecinal boundaries as
  soon as they are imported — they are Features drawn by the same code.

## [0.7.3] — unreleased

### Fixed

- The record list came back. `listFor` was called in `App.vue` without being imported, so the
  computed behind the Datos column threw a `ReferenceError` and took the list down with it —
  present since the Capas panel landed. Nothing catches this class of mistake yet: the three
  suites test `filters.js` directly and never load `App.vue`, and there is no JS linter.

## [0.7.2] — unreleased

Undo an import.

### Added

- Recent runs are listed in the import dialog with who ran them and what they did, each with a
  **Deshacer**. Undo lives beside the thing it undoes.
- Reverting retires what a run created and puts back what it overwrote — and leaves alone anything
  touched since, reporting how many it left.

### Notes

- A revert is expressed as the inverse of each action the batch recorded, applied newest first:
  created becomes retired, an update is restored to its previous values, a retirement is undone.
  Expressing it that way rather than as an "undo import" routine is what makes a revert revertible
  — it records its own batch, its Revisions are stamped with it, and undoing THAT is the same code
  walking a different batch.
- A revert never marks a record as a local override. Going through the ordinary edit path would,
  and every future import would then skip those records for good (ADR-0003) — a strange and
  permanent consequence of pressing undo.
- Nothing is undone blindly. A record with a newer Revision than the batch's has been touched
  since, so it is left exactly as it is: a revert exists to prevent silent loss, not to cause it.

## [0.6.0] — unreleased

GeoJSON import.

### Added

- `occ territorio:import <archivo> --dataset=<slug>` and a browser upload, both through one
  service so they cannot drift.
- Datasets (Cadastral or Curated), and Import batches recording actor, moment, Dataset and the
  Revision range the run produced — the range ADR-0009's revert depends on and that nothing can
  reconstruct afterwards.
- Provenance on Features: which Dataset a record came from, the external id a re-import matches
  on, and the official values kept beside a local edit.

### Notes

- Developed against the real 865-feature La Florida catastro to learn what real files look like:
  538 would be created, 18 rows are exact duplicates, 309 are kinds nothing draws yet, and
  re-importing moves the Revision cursor by zero. That data is NOT loaded — the dev instance holds
  invented records only.
- Matching is on `(Dataset, external id)`. The real file carries no `id` and no `uid` — checked,
  not assumed — so ids are derived from source, name and position, which is what makes a second
  import update instead of creating 865 more records.
- The file repeats 18 rows verbatim, inherited from FONASA. Collapsing them is right; doing it
  silently is not, so the count is reported.
- Nothing is written until the whole file has been read and checked. One bad coordinate refuses
  the entire import — a half-imported catastro is far harder to reason about than a refused one.
- A record someone edited, or retired, is never overwritten (ADR-0003). The authority's latest
  values are still refreshed alongside, so "official vs local" stays current and the override can
  be undone.

## [0.5.0] — unreleased

The Capas panel, and one filtered set behind everything on screen.

### Added

- The Category tree as toggles in the app navigation. Unchecking a Category takes its whole branch
  with it; unchecking one Subcategory leaves its siblings alone. The parent checkbox shows an
  indeterminate state when only part of the branch is hidden, and clicking it then reveals the
  rest rather than hiding what was left.
- An Análisis view: counts by Category and Subcategory over the filtered set, drawn as bars.
- A JS test runner (vitest) and `make test-js`. The filter logic is a pure module with 17 tests —
  the first frontend logic in the repo that is tested rather than eyeballed.

### Notes

- The filter records what is **hidden**, never what is visible. That is what makes a Subcategory
  added to the taxonomy appear on its own with no code change: it is absent from the hidden set,
  so it shows. Storing visible ids would leave every new row invisible until some code went
  looking for it.
- The map, the Datos list and Análisis all derive from one filtered set rather than filtering
  separately. Three filters is how a count ends up disagreeing with what is on screen.
- Análisis counts what it is handed and filters nothing itself, for the same reason.
- The filter is a View (CONTEXT.md): it lives in the browser, is never sent anywhere, and changing
  it changes nothing for any other user.

## [0.4.0] — unreleased

History and retirement.

### Added

- Every change appends a history entry naming who did it, when, and what changed — including what
  the field used to say. Those previous values are the point, not decoration: ADR-0009 says an
  import revert "restores what it overwrote", and this is where that comes from.
- Deleting a record retires it (ADR-0005). It leaves the map and the list, keeps its history, and
  can be restored intact. (There are no counts to leave yet — Análisis is issue #5.)
- A record's history is shown in its sidebar; retired records are browsable and restorable from
  the list.

### Notes

- The history columns went onto `territorio_revision`, the log that already carried the Revision
  and the moment. Adding the actor, the action and the diff to those rows is what ADR-0002 and
  ADR-0005 meant by one mechanism rather than two.
- `findChangedSince()` is the one read that does NOT hide retired records, deliberately. A client
  already holding a record has to be told it was retired so it can stop drawing it; filtering it
  out of the change feed would leave it on every open map until someone reloaded.
- Retiring is confirmed inline, in two steps, never with a browser `confirm()` — that blocks the
  page, and there is nothing irreversible to warn about.
- `Retired` and `Restored` are distinct actions rather than an ordinary diff of a boolean, because
  "who retired this?" is a question history exists to answer and should not need reading every
  entry to find.

## [0.3.0] — unreleased

Collaboration: one shared map that keeps itself current.

### Added

- A monotonic Revision stamped on every change, issued from a single locked row. Deliberately not
  an autoincrement: that guarantees two writers get *different* numbers, which is not what a cursor
  needs — it needs numbers to become visible in the order they were issued. With an autoincrement,
  writer A can take 5, writer B take 6 and commit first, and a client polling in between banks 6
  and never asks for 5 again.
- `GET /changes?since=N` — the cursor every open map polls. When nothing has changed it answers
  from a single indexed read and never touches the Feature table, which is what makes a
  five-second poll from every open map affordable on an instance with no push backend (ADR-0002).
- Client polling roughly every five seconds, stopping entirely while the tab is hidden and
  catching up in one request when it returns.
- The Revision log is already the change log: issue #4 adds the actor and the action to these same
  rows rather than introducing a second counter.

### Notes

- Stamping happens in `FeatureMapper::insert()/update()`, inside one transaction, and neither half
  is optional. The cursor is correct only as long as no write escapes without advancing it, and
  only as long as the Revision and the row become visible together — a Revision landing first lets
  a poll bank a cursor past a change it was never given. `RevisionLog::allocate()` refuses outside
  a transaction so that window cannot be constructed.

- Seeding runs from a repair step, not a migration's `postSchemaChange`. That hook is skipped when
  a schema is applied on a first install, so seeds written there run on every upgrade and on no
  fresh install — the one case that matters most, and the one a developer upgrading their own dev
  instance never sees.
- The page reads the Revision *before* the Features, deliberately. The other order lets a change
  landing between the two reads be absent from the list yet already counted in the cursor, so the
  client would never ask for it and would stay stale forever. This way it is simply delivered
  again, and merging a record twice is harmless.
- Not SSE and not WebSockets: this instance runs `nextcloud:34-apache`, where a held-open
  connection pins a PHP worker.

## [0.2.0] — unreleased

Places: the Registry's first records.

### Added

- The Category/Subcategory taxonomy, seeded with the eleven categories and the subcategories the
  La Florida catastro actually used. It is editable data, not code — adding a row makes it appear
  in the app.
- Features stored as GeoJSON text with a bounding box in four plain columns. No PostGIS is
  available, and a Nextcloud app must run on MySQL, SQLite and Postgres alike.
- Datos: a list of records beside the map, with a sidebar to add and edit one. Selecting a record
  highlights its marker; markers cluster and are coloured by Category.
- A record with no coordinate is valid — it appears in Datos and not on the map. The inherited
  catastro holds real organisations whose address never resolved.
- Kind and the bounding box are derived from the geometry and never accepted from the client.
  Location quality is forced to "absent" when there is no geometry to describe.
- An integration suite that round-trips the mappers against a real Postgres.

### Notes

- `FeatureMapper::delete()` refuses outright. ADR-0005 forbids hard-deleting a Feature anywhere in
  the app, and QBMapper hands every subclass a public `delete()`, so the mapper is where that rule
  has to be enforced rather than merely written down. Retirement arrives with history in issue #4;
  until then a Feature cannot be removed at all, which is the safe direction to be wrong in.
- Only Point geometry is accepted. Zones and Routes are measured correctly but nothing draws them
  yet, so one would appear as a single pin on an arbitrary vertex. Lifted in issue #10.
- An update only writes the fields it was sent. Omitting one leaves it alone; clearing it means
  sending it empty.

## [0.1.0] — unreleased

First slice: the app shell and the map. No territorial data yet.

### Added

- Nextcloud 34 app that appears in the navigation and opens to a full-height Leaflet map.
- Administrator setting for the tile URL and attribution, so a clinic without internet access can
  repoint the basemap at a self-hosted tile server with no code change (ADR-0004).
- The Content-Security-Policy image host is derived from the configured tile URL, so the policy
  cannot drift from the tiles it exists to allow. Leaflet's `{s}` subdomain placeholder becomes a
  CSP wildcard; ports are preserved.
- A tile URL that is not a usable template is stored as typed — so the administrator can see and
  correct it — but never served, and never reaches the response header.
