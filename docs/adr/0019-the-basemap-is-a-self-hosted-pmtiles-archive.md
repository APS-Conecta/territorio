# The basemap is a self-hosted PMTiles archive, not a public tile service

- **Status:** superseded by [ADR-0021](0021-the-basemap-is-openstreetmaps-tile-service-used-as-its-policy-asks.md)
  (2026-10-18); accepted 2026-09-18. The PMTiles archive and the raster setting are replaced by
  one constant OpenStreetMap tile URL. The record below is left as written.
- **Extends:** [ADR-0004](0004-openstreetmap-is-tiles-and-offline-imports-only.md), which made the
  basemap URL an administrator setting and defaulted it to OpenStreetMap's public tile service.
  That default is what changed. Everything ADR-0004 says about *not* talking to OSM at runtime, and
  about OSM-derived Datasets arriving as offline imports, stands unaltered.

## What happened

The public tile service answered **403**. Not intermittently and not for everyone: reachable from
the server, refused from a clinic's network.

A 403 there means *blocked*, and measurement said why this app was a candidate. Nextcloud serves
every page with `Referrer-Policy: no-referrer`, so tile requests left the browser carrying **no
`Referer` at all**; a browser supplies its own `User-Agent`, which names Chrome and not this app.
OpenStreetMap's Tile Usage Policy asks an application to identify itself by one or the other. Both
were absent, so the traffic was indistinguishable from anonymous scraping — the profile that gets
rate-limited and then blocked. Their wiki files `tile.openstreetmap.org` as a **P2 best-effort**
service, and their own answer to a blocked application is to run your own tile server or use a
third party.

Two separate faults, then: the app did not identify itself, and it depended on a best-effort
service it had been asked not to depend on. The first is fixed (`referrerPolicy: 'origin'`, which
sends the host and never a path). This ADR is about the second.

## Decision

**The basemap is a PMTiles archive this stack serves itself.** One file — all of Chile, from
Protomaps' OpenStreetMap-derived basemap build — read directly by the browser over HTTP Range
requests.

A basemap URL is therefore now one of two forms, and `Basemap::kind()` says which:

| form | what it is | fetched by | CSP directive |
|---|---|---|---|
| `…/{z}/{x}/{y}.png` | a directory of raster images | `<img>` | `img-src` |
| `…/chile.pmtiles` | one archive, read by byte range | `fetch` | `connect-src` |

Naming a PMTiles host in `img-src` blocks it while the policy reads as though it allowed it, which
is why the directive follows from the kind and not from a constant.

Rendered with **`protomaps-leaflet`**, not MapLibre. That library's own documentation puts it in
maintenance mode and points new projects at MapLibre, "except for legacy projects that heavily
depend on Leaflet" — which is exactly this map: geoman draws on it, markercluster clusters on it,
and `PICKABLE_PANE` orders it. The same docs call the library suited to non-interactive layers, and
a basemap under our own overlays is precisely that: nothing in it is ever clicked. MapLibre would
mean rewriting every layer above it.

### Why all of Chile

Measured, from the `20260915` planet build:

| extract | size |
|---|---|
| one comuna (La Florida) | 5.7 MB |
| Región Metropolitana | 82 MB |
| **Chile, incl. Isla de Pascua and Juan Fernández** | **1.04 GB** |

Chile-wide, because this app is for multiple comunas and multiple CESFAMs — a regional extract
saves ~985 MB of a 90 GB disk and buys an install that only works in Santiago.

The bbox is `-110.0,-56.0,-66.4,-17.5`. A bbox stopping at the mainland (`-75.8`) silently excludes
**Isla de Pascua** (lon −109.4) and **Juan Fernández** (−78.8), both comunas with health
facilities. Including them adds 368,000 tile entries and **4.7 MB**, because empty Pacific
deduplicates away.

`--maxzoom=15` is the whole archive; Protomaps document z15 as sufficient for street-level mapping,
and each further zoom level roughly doubles the file — measured here at 2.04×, 1.93×, 1.97×.

### The size of the file is not what anyone downloads

PMTiles is read by byte range, so a client fetches only the ranges holding the tiles it is drawing.
Measured over La Florida: **552 KB** for a screenful at z12, **863 KB** at z14, **952 KB** at z15,
and **2.3 MB** to work the whole comuna across all three — a ratio of about **1:460** against the
file. Vector tiles also re-render at any zoom, so z15 data serves z16–19 with no further fetches;
raster refetched on every level.

## Consequences

- **ADR-0004's "client browsers need internet for the basemap" no longer holds.** The archive is on
  the same tailnet as the app, so the basemap now works exactly where the app does. This is a
  better outcome than ADR-0004 hoped for, not a compromise: it was written expecting a clinic to
  lose internet and repoint the setting by hand.
- **The raster form stays supported**, because ADR-0004's promise was that an administrator can
  repoint this at their own server. A clinic still serving `{z}/{x}/{y}` keeps working, keeps the
  bounded tile retry, and keeps the contrast numbers `map-legibility.md § 2` documents for it.
- **Every measured legibility ratio was re-measured**, because they were all taken over raster OSM
  land. See `map-legibility.md § 2.2`. One of them needed a change: bare Protomaps `light` earth is
  darker than raster OSM land, which alone put the worst dot-on-tint pair at 2.65:1, under WCAG
  1.4.11's floor and under the 3.04:1 it replaced. A `brightness(1.09)` on the vector tile pane
  puts it at 3.14:1 and leaves every other ratio better than it was.
- **The archive has a refresh, and it cannot be a pinned URL.** Protomaps' daily builds expire —
  `20260908` was already 404 nine days later. So the file is extracted once and served locally, and
  refreshing it is a scheduled re-extract. A stale basemap is a missing street, not an outage.
- **Attribution is unchanged and still required.** The Protomaps basemap is an ODbL Produced Work
  and its own archive metadata declares `© OpenStreetMap`. Self-hosting moves where the bytes come
  from, not whose data it is — so a blank attribution box credits OSM for a PMTiles basemap too.
- **`{s}` and the tile-retry machinery are now raster-only.** `protomaps-leaflet` calls Leaflet's
  done callback as `done(undefined, tile)` unconditionally, so there is no `tileerror` to hook: a
  tile that never rendered is indistinguishable from one that did. The archive's reachability is
  therefore checked directly, once, by reading its first seven bytes — which also catches a server
  that ignores Range and an error page served with a good status code.

## Addendum (2026-09-26): the default stays public OpenStreetMap raster

`Basemap::DEFAULT_URL` — a fresh install's default, and the fallback when a configured URL is
refused — remains `tile.openstreetmap.org`, exactly what ADR-0004 set. Asked straight out against
this ADR's thesis ("not somebody else's public service"), the answer is that no archive ships
with the app: a default has to draw a map on a machine this repo has never seen, and public OSM
raster needs no account, key or configuration to do it. A default archive URL would point at a
host that does not exist, and a refused setting must still leave a working map on screen — a
settings page that says «not accepted» over a blank canvas has traded a warning for an outage.

The 403 that started this ADR is a rate-limit profile, not a hard block, and it is managed where
it is met: the bounded tile retry (`src/tiles.js`) and `referrerPolicy: 'origin'`, both of which
the raster form keeps. OSM remains one tile option among others, and nothing here reopens its
data APIs — ADR-0004's "tiles and offline imports only" still stands.
