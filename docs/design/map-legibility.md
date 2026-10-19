# Map legibility — the mark system

How a Feature is drawn, and why. Written 2026-08-07 after an audit of the whole frontend.
Vocabulary is [`CONTEXT.md`](../../CONTEXT.md); decisions that bind beyond this document are in
[`docs/adr/`](../adr/).

## The thesis

**Hue is one channel doing four jobs.** It carries Category, Sector identity, cluster size, and
picker state — over a colourful raster basemap, with no figure/ground separation between a cadastre
boundary the clinic may never touch and a Sector it drew this morning.

The fix is not a legend and not a repaint. It is to **give each domain distinction its own channel**:

| Domain distinction | Channel | At the 2026-08-07 audit |
|---|---|---|
| Category (what it is about) | **glyph**, hue as grouping | hue alone |
| Kind (Place / Zone / Route) | the geometry itself | already correct |
| Sector identity | **permanent centroid label** | hue, in a free colour picker |
| Cadastral vs Curated | **figure and ground** | nothing |
| Location quality | **a visible mark** | nothing at all |
| Cluster size | the number already written in the bubble | hue — which collides with Category |

## 1. The mark

### 1.1 Glyph

Every Category carries a pictogram from **Maki 8.2.0** (CC0-1.0, `@mapbox/maki`). All eleven have a
match; no Temaki fallback is needed.

| Category | Maki icon | Category | Maki icon |
|---|---|---|---|
| salud | `hospital` | servicio_publico | `town-hall` |
| educacion | `college` | movilidad_transporte | `bus` |
| deporte_recreacion | `pitch` | religion | `place-of-worship` |
| seguridad | `police` | comercio_economia | `shop` |
| medioambiente | `park` | sin_clasificar | `circle-stroked` |
| organizacion_social | `town` | | |

All eleven were re-picked at pin size in #37, judged as rendered pixels at 19px rather than as
vector art: `college`, `park` and `town` replaced `school`, `recycling` and `residential-community`,
which read as blobs on a busy basemap. `police` is the weakest survivor — Maki ships no shield or
badge, and every alternative means something narrower — and is flagged in #37 rather than swapped
for a crisper mark that means the wrong thing.

`place-of-worship` over `religious-christian` deliberately: the app must not assume a
denomination. `circle-stroked` reads as *nobody has decided yet*, which is what "Sin clasificar"
means — not as an error.

The eleven above are the **seeded** map, keyed by slug in `src/glyphs.js`. Since #36 a Category a
clinic adds chooses its own mark, from the same Maki catalogue — all 215 layouts, previewed at the
19px the pin actually draws rather than at legend size. The stored name overrides the seeded map and
never replaces it, so an install where nobody chose anything still draws what #37 re-picked;
`glyphFor()` answers with the stored name, then the slug's mark, then `circle-stroked`. Never a
blank — a blank reads as a rendering failure, the neutral mark reads as unclassified (ADR-0017).

Maki icons are a single `<path>` with **no `fill` attribute** on a `viewBox="0 0 15 15"`, so they
take their colour from CSS. Two render paths, because there are two rendering contexts:

- **Inside Vue** (Capas panel, list swatch): `NcIconSvgWrapper` with the **`svg`** prop, which
  preserves Maki's 15-unit viewBox and gives `role="img"` plus DOMPurify sanitising for free.
  Do **not** use its `path` prop — that renders into a hardcoded `viewBox="0 0 24 24"` and would
  scale every Maki glyph wrongly. **Colour it by setting `color` on the container, never `fill` on
  the svg**: the wrapper ships `.icon-vue[data-v-…] svg { fill: currentColor }` at specificity
  (0,2,1), a scoped `:deep(svg) { fill: … }` compiles to (0,2,1) as well, and a tie is decided by
  stylesheet load order — which the library won, so both swatches rendered in the theme's dark text
  colour for months (#37). A third swatch surface must feed `currentColor` too.
- **Inside the Leaflet `divIcon`** (`TerritorioMap.vue`, `iconFor`) — built as an HTML string
  outside Vue's render tree, so `NcIconSvgWrapper` cannot be used, and nothing competes for the
  fill. Inline the markup into the string and set `fill` in CSS.

Both paths need the raw markup, and since #46 it arrives as JavaScript: `src/glyphs.js` imports
`layouts` and `svgArray` from `@mapbox/maki/browser.esm.js`, Maki's own browser bundle, which ships
every name and its markup. So `webpack.config.js` no longer knows about the package at all — it
carried an `asset/source` override of the inherited `.svg` rule (`asset/inline`, a data URI) while
eleven icons were imported one file at a time, and a picker offering all 215 cannot be built that
way. Deliberately not `require.context()`, which is webpack's alone: under vitest the catalogue would
be empty and every assertion about a chosen mark would pass while proving nothing.

### 1.2 The ramp

Hue carries the **family**. Lightness carries the **member**. The glyph carries **identity**.

This is forced, not chosen. At constant lightness and chroma, N hues sit on a circle of radius C in
OKLab, and the distance between neighbours is exactly `2·C·sin(Δh/2)`. Eleven hues at ΔE 0.10
therefore require **C ≥ 0.177**, which is not low chroma by any definition. At C = 0.090 — 56% of
the chroma shipping today — you get about six families. So six is what we take.

| Category | hex | OKLCH | Family |
|---|---|---|---|
| salud | `#99564e` | `0.530 0.090 28` | Cuidado |
| organizacion_social | `#b06b62` | `0.600 0.090 28` | Cuidado |
| medioambiente | `#407b51` | `0.530 0.090 152` | Verde |
| deporte_recreacion | `#549065` | `0.600 0.090 152` | Verde |
| servicio_publico | `#0b798a` | `0.530 0.090 212` | Redes |
| movilidad_transporte | `#2f8e9f` | `0.600 0.090 212` | Redes |
| seguridad | `#5a68a0` | `0.530 0.090 272` | Instituciones |
| educacion | `#6d7db6` | `0.600 0.090 272` | Instituciones |
| comercio_economia | `#8a7431` | `0.565 0.090 91` | Recursos |
| religion | `#916393` | `0.565 0.090 325` | Creencia |
| sin_clasificar | `#71777c` | `0.565 0.010 248` | Neutral |

Validated numerically (`tools/ramp-check.py`, which is the check for this section):

- **Normal vision: 0 of 55 pairs collapse.** min ΔE 0.069, median 0.114.
- **Every colour clears 3:1** against a white glyph, against `#ffffff`, and against the dark theme.
  A glyph inside a filled shape is non-text UI — WCAG 1.4.11, 3:1. Holding it to 4.5:1 does not
  narrow the window, it **empties** it: 4.5:1 against a white page needs Y ≤ 0.183 and 4.5:1
  against the dark theme needs Y ≥ 0.214. No colour satisfies both. The 3:1 window is 0.163 wide
  on the grey axis, 0.147 at C 0.09.
- **Within-family lightness separation ΔE 0.069–0.072**, above the 0.05 adjacent-pair threshold.
- **Colour-vision deficiency — a real trade-off, not a win.** Measured by the same tool:

  | | Protanopia | Deuteranopia | Tritanopia |
  |---|---|---|---|
  | The palette this replaced | 0/55 (min ΔE 0.056) | 2/55 (min 0.031) | 5/55 (min **0.006**) |
  | This ramp | **4/55** (min 0.022) | 1/55 (min 0.038) | **6/55** (min 0.018) |

  More pairs collapse under protanopia and tritanopia; the *worst* case improves threefold under
  tritanopia. Lowering chroma so the glyph and the Sector labels can dominate costs separation
  everywhere, and a dichromacy compounds it. This is the price of the design, stated rather than
  hidden.

**No palette makes eleven categories safe for a colourblind user.** That is why the glyph is
primary and the ramp only groups. Do not "fix" the ramp by raising chroma; that trades a proven
grouping for an unprovable identification.

`ramp-check.py` therefore **gates** on contrast and on normal-vision separation, and only on a
catastrophe floor of ΔE 0.015 under each dichromacy — the thing that catches two colours becoming
literally the same, which the old palette did at 0.006. The per-dichromacy counts above are
reported, never enforced: gating on eleven-hue CVD safety is gating on the unreachable. Tritanopia's
current minimum is 0.018, which is 0.003 above that floor — thin, and worth knowing before anyone
nudges a hue.

The colours stay **data**, seeded in `EnsureSeedData.php` — a Category is editable per
CONTEXT.md. This document specifies the seed, not a constant.

**The eleven are no longer all there is.** #36 made the taxonomy editable, so the table above is the
palette a clinic *starts* from rather than the palette it keeps. Eight vetted spares ship beside the
eleven as `EnsureSeedData::SPARES`, and a Category somebody adds takes the next unused one — the app
assigns it, nobody picks it, because nothing above can be satisfied by eye (ADR-0017). The spares
were derived by `ramp-check.py`'s own functions, holding chroma at 0.090 and staying inside the six
families above rather than opening a seventh, and the check re-gates all eight on every `make lint`:
against the eleven, against both neutrals, and against each other. That is the point of deriving them
where the arithmetic already lives. Editing a Category's colour is now also a statement about colours
no Category holds yet, and the build goes red at the moment the statement is made rather than the
first time a clinic adds a Category on some other install.

Eight is a real ceiling, and the twelfth added Category reaches it. It is still created and still
works: it takes the neutral, and the panel says it will not be told apart by colour. That is not an
error — a Category without a distinct hue is doing what this section says every Category does anyway.

Three neutrals have to coexist inside the narrow lightness band where a colour clears 3:1 against
both a white page and the dark theme: `sin_clasificar` `#71777c`, the Sector default `#949494`, and
`UNKNOWN_COLOUR` `#62676c` for a Feature whose Subcategory no longer exists. The tightest gap is
ΔE 0.055 against a 0.05 floor. **There is no room for a fourth neutral** — anything else needing a
"quiet grey" has to reuse one of these or earn a hue. A Category added after the spares run out is
the case that tests this and does not break it: it reuses the Sector default `#949494`, which is
already what a whole Category nobody could give a hue to is painted. Deliberately not
`UNKNOWN_COLOUR`, which means a *broken* reference.

> This document is a design reference, not a decision record. `.claude/skills/slice/SKILL.md` says
> the issue and the CHANGELOG are the record and that binding decisions belong in an ADR.
>
> One decision here meets all three ADR tests — hard to reverse, surprising without context, and the
> result of a real trade-off — and has its own:
> [ADR-0013](../adr/0013-hue-groups-categories-the-glyph-identifies-them.md).
>
> § 4's rejections are **not** ADRs, deliberately. Adding a Leaflet plugin or a canvas legend later
> costs nothing; they are cheap to reverse and so fail the first test. What is expensive is
> *re-deriving* them — a 620-plugin survey, a licence audit — so they are recorded here as evidence
> rather than as decisions. Anyone is free to overturn them; nobody should have to repeat the work
> to find out why they were made.

## 2. Figure and ground

CONTEXT.md distinguishes a **Cadastral Dataset** ("the clinic reads it, never draws it") from a
**Curated Dataset** ("born inside the clinic"). Enormous operational weight and, before #21, zero visual
weight. Unidad Vecinal is ground; Sector is figure.

| | Unidad Vecinal — ground | Sector — figure |
|---|---|---|
| Fill | none | neutral **`#949494`** @ 0.10, or the clinic's tint |
| Outline | `#7d7d7d` @ 0.45, weight 1, `dashArray "2 3"` | solid, weight 2 |
| Label | 11px muted, above a zoom threshold only | **permanent, at the centroid** |

The two greys are **not** the same decision, and the difference is why one of them moved.
`#949494` is `DEFAULT_SECTOR_COLOUR` — it is painted as an 18px **dot** in the list and the sidebar,
so it must clear ΔE 0.05 from every Category or it reads as one of them. It was `#7d7d7d` until that
sat ΔE 0.026 from `sin_clasificar`. The UV outline stays `#7d7d7d` because it is a one-pixel
**line** over a basemap, never a dot beside a dot; it is judged on contrast against the tile, not on
separation from a palette. `tools/ramp-check.py` gates the first and has nothing to say about the
second.

Measured (recompute with `tools/ramp-check.py`'s own functions, not by eye):

- UV hairline at 0.45 reads at **1.35–1.49:1** over OSM land, water and green, and **1.87:1** over
  the dark background — visible, never loud.
- The Sector tint at **alpha 0.10** over OSM land composites to `#e9e6e0`, leaving the worst
  dot-on-tint contrast at **3.04:1** (`deporte_recreacion`). Above 3:1, but only just — and at the
  0.15 the wash shipped before #21, the same pair measured **2.90:1**. So `#949494` and 0.10 are one
  decision, and the code carries both (`FIGURE_FILL_OPACITY` in `src/marks.js`); changing either
  alone puts a measured contrast below its floor. What keeps this from being a defect is the pin's
  2px `#171717` ring — one fixed ink, measured over every surface in § 2.2 — so a dot never relies
  on fill-vs-tint contrast alone.
- These are composited in **sRGB**, which is what a browser does with CSS alpha. An earlier draft
  of this section computed them in linear light and read ~0.05 high throughout.
- The tint sits ΔE 0.021 from the bare basemap against ΔE ~0.45 for a dot, so a Sector a clinic
  *does* tint stays subordinate by roughly twenty to one.

**A Sector is identified by its label, not its colour.** CONTEXT.md says a Sector is "named however
that clinic names things — words, numbers or colours", so a colour-first identity is correct for one
naming scheme out of three. The label is correct for all three. Colour is demoted to a wash that
helps tell neighbours apart, and is neutral when unset.

Permanent centroid labels cost nothing: Leaflet 1.9's
`bindTooltip(name, { permanent: true, direction: 'center' })` falls through to `Polygon.getCenter()`
→ `PolyUtil.polygonCenter()`, a true area-weighted centroid.

**Labels on tiles are dark-on-light in both themes.** The basemap is raster OSM regardless of the
Nextcloud theme, so a dark-theme label of `#ebebeb` on OSM land `#f2efe9` renders at **1.04:1** —
invisible. Label colour follows the *tile*, never the theme.

**Do not add a label-collision library.** At four to eight Sectores collisions do not arise. Across
~50–100 Unidades Vecinales they do — and the answer there is a zoom threshold, which is the correct
cartography and costs no dependency.

### 2.2 Re-measured against the Protomaps basemap (ADR-0019)

Every ratio in § 2 was taken over **raster OSM** land, water and green. ADR-0019 replaced that
basemap with a self-hosted Protomaps PMTiles archive, so all of them became claims about a surface
that is no longer there. Recomputed with `tools/ramp-check.py`'s own functions, over the eleven
seeded Categories — the same set that produced § 2's figures, verified by reproducing its
`3.04:1 (deporte_recreacion)` exactly before changing anything.

The vector basemap is drawn `light`, and its tile pane carries `saturate(0.55) brightness(1.09)`
where the raster pane carries `saturate(0.55)` alone. § 2 warns that a brightness filter "would
move all of them at once" — it does, which is why all of them are below.

**Worst dot-on-tint, Sector wash `#949494` at alpha 0.10** (WCAG 1.4.11 floor 3:1):

| surface | raster OSM (before) | Protomaps `light`, bare | Protomaps `light` + `brightness(1.09)` |
|---|---|---|---|
| land / earth | **3.04** ✅ | 2.65 ❌ | **3.14** ✅ |
| green / park | 2.60 ❌ | 2.51 ❌ | 2.98 ❌ |
| water | 2.20 ❌ | 2.23 ❌ | 2.63 ❌ |

Three things this table says that § 2 did not:

1. **Water and green never passed.** § 2 quotes 3.04:1 "over OSM land" and that is the only surface
   it measured. Over raster water the same pair read **2.20:1** and over green **2.60:1**, both
   under the floor, for as long as this app has existed. ADR-0019 did not introduce that; it is the
   first time anyone looked. It stays under the floor, by less than before, and what keeps it off
   the defect list is unchanged and stated in § 2 itself: the pin's 2px `#171717` ring, so a dot
   never relies on fill-vs-tint contrast alone.
2. **Bare `light` would have been a real regression** on the one surface that did pass — 3.04 → 2.65
   — and no alpha rescues it. Even a wash of zero reads 2.84:1, because what changed is the
   basemap's own lightness, not the wash over it.
3. **With the brightness, the new basemap beats the old one on every surface measured**, including
   the two that were already failing.

`white` was measured too: 3.44 / 3.38 / 2.58, the only option that clears the floor on land *and*
park. It was rejected for a reason no ratio captures — it renders a basemap so pale that the street
grid is hard to read, and reading the street grid is how a Sector gets drawn (ADR-0007).

**The rest of § 2, re-measured:**

| claim in § 2 | over raster | over Protomaps + brightness | verdict |
|---|---|---|---|
| UV hairline `#7d7d7d` @0.45, over the basemap | 1.35–1.49 | 1.57–1.68 | holds — the band widens upward, still "visible, never loud" |
| UV hairline over the dark page background | 1.87 | **1.87** | unchanged, and necessarily: the filter is on the tile pane, and the page is not in it |
| dark-theme label `#ebebeb` on the basemap | 1.04 | 1.08 | still invisible ⇒ **"labels follow the tile, never the theme" stands** |
| Sector tint stays subordinate to a dot | ΔE 0.021 vs ~0.45 | unchanged | the tint is composited from the same two colours |

**The «¿Dónde estoy?» dot** is new and measured on the same terms. `#1a73e8` sits ΔE **0.113** from
its nearest Category (`educacion`) against the 0.05 floor at which two 18px dots read as one
colour, and **4.07:1** against the basemap. It is deliberately outside the Category ramp: hue in
this app groups Categories (ADR-0013), so a position dot in a ramp hue would read as a record
somebody filed. It is also a dot with a white ring rather than a pin, because a pin reads as a
filed Place.

**The pin ring follows the tile, never the theme (2026-09-26).** § 2's label rule — the tiles are
light whatever the Nextcloud theme is doing — binds the ring too, and the ring broke it: it was
`--color-main-background`, white in the light theme, measuring **1.15–1.69:1** over the very
surfaces the tiles paint. Under the 3:1 floor in both themes, since the first release. The ring is
now one fixed dark ink — `#171717`, byte-identical to what the dark theme always drew — measured
over every substrate a pin can sit on:

| surface | raster OSM | Protomaps `light`, bare | Protomaps `light` + `brightness(1.09)` |
|---|---|---|---|
| land / earth | 15.62 | 13.49 | 16.20 |
| green / park_a | 13.75 | 12.77 | 15.35 |
| green / park_b | — | **10.58** — the floor | 12.66 |
| water | 11.18 | 11.57 | 13.88 |

The floor clears the requirement more than three times over, and the ink also clears 3:1 against
every legal fill — eleven Categories, the shipped spares, both greys; the worst is a spare at
**3.04:1**. Both loops are gated, not asserted: `tools/ramp-check.py` reads the ring out of
`TerritorioMap.vue`, measures it against the OSM raster surfaces it carries as constants, and fails
on drift of the ring.
Recomputing this section's earlier rows with the gate's arithmetic reproduces land exactly and
sits **≤ 0.08** off the hand-written water/green rows above — they were rounded by hand when
written; the rows in this table are the gate's own.

### 2.1 The 0.15 category fill carries no information

Composited over OSM land at `fillOpacity: 0.15`, **all 55 category pairs collapse** (ΔE 0.004–0.012)
and every fill sits at contrast 1.12–1.14 against the bare basemap. The translucent category wash is
decorative noise. Keep a fill to say *there is a zone here*; never to say *which category*. The
outline and the label identify.

## 3. Defects

The audit of 2026-08-07 found six. All six are fixed; none is open. The table is kept as a record
of what was wrong and where it was fixed, because § 4 and the verification list in § 6 refer to
them by number.

| | Defect, as found | Fixed in |
|---|---|---|
| **D1** | Cluster hue meant *count* while dot hue meant *Category* | 0.57.0, #22 — a cluster is coloured by the Categories under it |
| **D2** | Every un-recoloured Sector was drawn in exactly the colour of every school (`#2563eb`) | 0.56.0, #21 — the Sector default is the neutral `#949494` |
| **D3** | A Unidad Vecinal was offered a colour picker | 0.56.0, #24 — the picker is offered to a Sector only |
| **D4** | `aproximada` rendered identically to `exacta` | 0.57.0, #23 — an approximate coordinate draws a dashed ring |
| **D5** | `img/app.svg` and `img/app-dark.svg` were byte-identical, so one of them did nothing | 0.48.0, #26 — `app.svg` is the white variant, `app-dark.svg` the black one |
| **D6** | A Sector could be clicked but never tabbed to | 0.49.0, #25 — every Zone and Route is tab-focusable (`src/a11y.js`) |

## 4. What was evaluated and rejected

**620 Leaflet plugins.** Verdict: add none. The four layer-lifecycle workarounds in
`TerritorioMap.vue` are the correct handling of a Leaflet invariant, not debt awaiting a library —
`leaflet-realtime` mutates live groups in exactly the pattern that produced
`latLngToLayerPoint of null`, and `@vue-leaflet/vue-leaflet` independently arrived at the same
`markRaw` fix this repo reached alone, at the price of 102 open issues and a 605-line rewrite.
`leaflet-mapwithlabels` was the sole candidate and is rejected above. Three items assumed to need
plugins are core or near-core: permanent centroid labels (free), the cluster fix (~40 lines through
an existing hook), and a greyed basemap for figure/ground (one CSS line on `.leaflet-tile-pane`).

**APS Conecta brand tokens in `src/`.** Rejected. `appinfo/info.xml` targets any Nextcloud 34 and
CONTEXT.md is written for any CESFAM, so Territorio stays a pure Nextcloud-variable citizen and
inherits the brand through the server theme, as Files and Talk do. The brandbook contributes its
*method* — OKLCH as the authoritative space, light/dark parity — not its hues. The dev instance is
left unthemed on purpose: stock Nextcloud is the constraint under test.

**A legend control on the map canvas.** Rejected. Canvas legends belong to published maps that have
no panel; this app has one. Of the eight encodings on the map, five appear at the instant the user
causes them — a legend for state you created one second ago explains what you already know. Of the
remaining three, two want the encoding fixed rather than explained (D1, D4). Capas carries the
residue, gaining a glyph and a live count.

## 5. One thing the glossary claims that the code does not do

It is not a defect in this work, and it does not belong in `CONTEXT.md` — that is a glossary, not a
status report. Recorded here so the next person does not rediscover it.

**A Sector has no team.** CONTEXT.md says "each Sector has one fixed team, so the same families
are seen by the same people over time, which is the whole point of drawing one" — but no Feature
carries one. A Sector today is a name, a
boundary and a colour. Not needed for anything here; worth knowing before someone designs a
"who covers this address" feature and finds the answer is not stored.

> **This section held a second item until 2026-09-15: "the taxonomy is not editable".** It recorded
> that there was no taxonomy route in `appinfo/routes.php` and no UI, that the eleven Categories were
> seeded and in practice fixed, and that this was why a palette could be specified at all and
> expected to survive. #36 built the thing it described as missing: six taxonomy routes, and a Capas
> panel that adds, renames and removes both a Category and a Subcategory. Its closing sentence —
> that taxonomy editing would need a rule for what colour a new Category gets, and that ADR-0013's
> honest answer is an existing family plus a glyph that tells it apart — is now #43 and ADR-0017.
> The note is gone because the code caught up with the glossary, not because it was ever wrong, and
> what survives of it is in §1.2. This is a design reference rather than a dated record, so a claim
> that stopped being true is removed rather than annotated in place; the removal is dated here
> because the reasoning it carried is still load-bearing.

## 6. Verification

Playwright, asserting on DOM and computed style — never screenshot diffing, because remote OSM
tiles under every screenshot would flake it into being ignored. Each assertion must fail when the
defect is reintroduced.

- a Salud record's pin carries the `hospital` glyph and the `salud` fill
- a pin draws 26px with a 19px glyph, anchored on the centre of the dot just measured
- the Datos list (18/12) and the Capas legend (16/11) paint their glyph white
- a Sector carries a permanent tooltip holding its name, at `direction: center`
- a Unidad Vecinal renders with no fill and a dashed hairline
- a cluster's icon reflects the categories of `getAllChildMarkers()`, not its count
- an `aproximada` record renders its distinct treatment, an `exacta` one does not
- a Sector path is reachable by keyboard
- `tools/ramp-check.py` passes: 0/55 collapsed pairs, no contrast failure, and all eight spares
  still clear every gate the eleven clear
