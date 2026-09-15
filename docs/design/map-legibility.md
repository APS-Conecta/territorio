# Map legibility — the mark system

How a Feature is drawn, and why. Written 2026-08-07 after an audit of the whole frontend.
Vocabulary is [`CONTEXT.md`](../../CONTEXT.md); decisions that bind beyond this document are in
[`docs/adr/`](../adr/).

## The thesis

**Hue is one channel doing four jobs.** It carries Category, Sector identity, cluster size, and
picker state — over a colourful raster basemap, with no figure/ground separation between a cadastre
boundary the clinic may never touch and a Sector it drew this morning.

The fix is not a legend and not a repaint. It is to **give each domain distinction its own channel**:

| Domain distinction | Channel | Today |
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

Maki icons are a single `<path>` with **no `fill` attribute** on a `viewBox="0 0 15 15"`, so they
take their colour from CSS. Two render paths, because there are two rendering contexts:

- **Inside Vue** (Capas panel, list swatch): `NcIconSvgWrapper` with the **`svg`** prop, which
  preserves Maki's 15-unit viewBox and gives `role="img"` plus DOMPurify sanitising for free.
  Do **not** use its `path` prop — that renders into a hardcoded `viewBox="0 0 24 24"` and would
  scale every Maki glyph wrongly.
- **Inside the Leaflet `divIcon`** (`TerritorioMap.vue:559`) — built as an HTML string outside
  Vue's render tree, so `NcIconSvgWrapper` cannot be used. Inline the markup into the string and
  set `fill` in CSS.

Both paths need the raw markup, so `webpack.config.js` overrides the inherited `.svg` rule
(`asset/inline`, a data URI) with `asset/source` for `node_modules/@mapbox/maki` only.

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

Three neutrals have to coexist inside the narrow lightness band where a colour clears 3:1 against
both a white page and the dark theme: `sin_clasificar` `#71777c`, the Sector default `#949494`, and
`UNKNOWN_COLOUR` `#62676c` for a Feature whose Subcategory no longer exists. The tightest gap is
ΔE 0.055 against a 0.05 floor. **There is no room for a fourth neutral** — anything else needing a
"quiet grey" has to reuse one of these or earn a hue.

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
**Curated Dataset** ("born inside the clinic"). Enormous operational weight, currently zero visual
weight. Unidad Vecinal becomes ground; Sector becomes figure.

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
  dot-on-tint contrast at **3.04:1** (`deporte_recreacion`). Above 3:1, but only just — and the
  code currently ships `fillOpacity: 0.15` (`TerritorioMap.vue:550`), where the same pair measures
  **2.90:1**. Adopting `#949494` as the Sector wash therefore requires adopting 0.10 with it; #21
  owns both. What keeps this off the defect list today is the pin's 2px `--color-main-background`
  border, so a dot never relies on fill-vs-tint contrast alone.
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

### 2.1 The 0.15 category fill carries no information

Composited over OSM land at `fillOpacity: 0.15`, **all 55 category pairs collapse** (ΔE 0.004–0.012)
and every fill sits at contrast 1.12–1.14 against the bare basemap. The translucent category wash is
decorative noise. Keep a fill to say *there is a zone here*; never to say *which category*. The
outline and the label identify.

## 3. Defects

Each is independently verifiable and independently shippable.

| | Defect | Evidence |
|---|---|---|
| **D1** | Cluster hue means *count* while dot hue means *Category* — one channel, two meanings, switching on zoom | markercluster buckets on `getChildCount()` into small/medium/large. Fix: `iconCreateFunction`, already unused in the options object at `TerritorioMap.vue:316`, walking `getAllChildMarkers()` through `colourOf`. ~40 lines |
| **D2** | Every un-recoloured Sector is drawn in exactly the colour of every school | `taxonomy.js:28` `#2563eb` is byte-identical to `EnsureSeedData.php:48` |
| **D3** | A Unidad Vecinal is offered a colour picker, and `colourOf` honours it over the division colour | `FeatureDetail.vue:24` gated on `isDivision`, which is true for *any* division. No figure/ground rule can be guaranteed while this exists |
| **D4** | The map asserts a precision the Registry never claimed | `locationQuality` is stored, validated, imported, exported and historicised (`LocationQuality.php`, `CsvWriter.php:31`, `FeatureHistory.vue:92`) and never drawn. `aproximada` renders identically to `exacta` |
| **D5** | `img/app.svg` and `img/app-dark.svg` are byte-identical, so one of them does nothing | |
| **D6** | A Sector can be clicked but never tabbed to | `L.Marker` defaults `keyboard: true`; `Path.js` and `Layer.js` have no keyboard handling at all. No Leaflet plugin anywhere fixes this |

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

## 5. Two things the glossary claims that the code does not do

Neither is a defect in this work, and neither belongs in `CONTEXT.md` — it is a glossary, not a
status report. Recorded here so the next person does not rediscover them.

**The taxonomy is not editable.** CONTEXT.md calls a Category "editable data, not code: a clinic
adds its own and it appears in the panel and in reports at once". There is no taxonomy route in
`appinfo/routes.php` and no UI — the eleven Categories are seeded and, in practice, fixed. That is
why this document could specify a palette at all and expect it to survive. If taxonomy editing is
ever built, the ramp needs a rule for what colour a new Category gets, and ADR-0013 says the honest
answer is that it groups with an existing family and its glyph tells it apart.

**A Sector has no team.** CONTEXT.md says "each Sector has one fixed team, so the same families
are seen by the same people over time, which is the whole point of drawing one" — but no Feature
carries one. A Sector today is a name, a
boundary and a colour. Not needed for anything here; worth knowing before someone designs a
"who covers this address" feature and finds the answer is not stored.

## 6. Verification

Playwright, asserting on DOM and computed style — never screenshot diffing, because remote OSM
tiles under every screenshot would flake it into being ignored. Each assertion must fail when the
defect is reintroduced.

- a Salud record's pin carries the `hospital` glyph and the `salud` fill
- a Sector carries a permanent tooltip holding its name, at `direction: center`
- a Unidad Vecinal renders with no fill and a dashed hairline
- a cluster's icon reflects the categories of `getAllChildMarkers()`, not its count
- an `aproximada` record renders its distinct treatment, an `exacta` one does not
- a Sector path is reachable by keyboard
- `tools/ramp-check.py` passes: 0/55 collapsed pairs, no contrast failure
