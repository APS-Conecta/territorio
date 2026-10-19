# The basemap is OpenStreetMap's tile service, used as its policy asks

- **Status:** accepted (2026-10-18)
- **Supersedes:** [ADR-0019](0019-the-basemap-is-a-self-hosted-pmtiles-archive.md), which made the
  basemap a self-hosted PMTiles archive and kept a raster URL as an administrator setting.
- **Amends:** [ADR-0004](0004-openstreetmap-is-tiles-and-offline-imports-only.md), only its
  consequence that the tile URL is a runtime setting. "Tiles and offline imports only" stands.

## Context

Two things were true at once. The development map was grey: `make instance-up` forced the tile
setting to a PMTiles archive on a host that dev machines cannot always reach, and
`protomaps-leaflet` paints blank tiles on failure and never raises `tileerror`, so nothing said so.
And the stack carried a second basemap path (archive, extract timer, `/tiles/` route, an
administrator setting, a CSP branch per form) to avoid depending on a service
[ADR-0019](0019-the-basemap-is-a-self-hosted-pmtiles-archive.md) had called best-effort.

The 403 that started ADR-0019 had a cause this app could fix: tile requests left the browser with
no `Referer`. `referrerPolicy: 'origin'` fixed it, and the raster form has run that way since.
What remains is whether the app should keep an alternative to the public service at all.

## Decision

**The basemap is OpenStreetMap's standard raster tile service,
`https://tile.openstreetmap.org/{z}/{x}/{y}.png`, loaded directly by each user's browser, following the
[OSMF Tile Usage Policy](https://operations.osmfoundation.org/policies/tiles/).** It is one constant
in one place. There is no setting.

### Why not the OpenStreetMap API v0.6

"Load OpenStreetMap" could mean reading map data from the API and drawing it. It does not work:

- The OSMF API policy says the editing API is not for read-only purposes or projects, and sends
  map access to the Tile Usage Policy. The API is the wrong door by the operator's own rule.
- Measured: one 2 x 2 km `/map` cell is about 6 MB of JSON. A 0.3 degree box in Santiago is refused
  outright by the API's 50,000-node cap.

### Rejected alternatives

- **A server-side proxy.** The Tile Usage Policy says OSMF generally does not recommend proxies:
  traffic from every user of an instance would reach OSM from one address, and the instance would
  take on a server-side fetch of a URL it chose, which is a surface the app does not have today.
- **Vector rendering from OSM data.** It needs a tile source, which is the problem being solved,
  and it rewrites every layer above the basemap (see ADR-0019 on why that is not on offer).
- **Keeping PMTiles.** It works, and a clinic that has it keeps working until the release that
  removes it (below). It costs a 1 GB archive that cannot be re-fetched from a pinned URL
  (Protomaps builds expire), a timer, a route, a second code path and a second CSP branch, all to
  serve a map the policy allows us to load directly.
- **Keeping the administrator setting.** A setting that accepts any URL is a second basemap path
  and a server-side request surface. Nothing in the policy needs it.

## The policy compliance contract

Each row is something the code does or a test asserts; none is left to configuration.

| Rule | Implementation |
|---|---|
| Exact URL | The HTTPS constant above, no query string, no cache-busting parameter |
| Attribution | OpenStreetMap contributors, linked to its copyright page, in Leaflet's attribution control, visible at every viewport width the app supports |
| Referer | `referrerPolicy: 'origin'`, asserted on every tile request in the browser gate |
| Cache | Honoured. A retry sets the same `src` again with no cache-busting |
| No prefetch, no wide scans | `updateWhenIdle: true`, `updateWhenZooming: false`, `keepBuffer` left at its default; requests are bounded by the viewport |
| Do not hammer a refusing server | The 1 s, 4 s, 12 s retry ladder stays, under a **global budget of 16 retries per page**, checked when the timer fires and refilled by one per successful `tileload` |
| Zoom | `minZoom: 8`, `maxZoom: 18`, the same on the map and the layer |
| No offline use | Not supported (below) |
| Tests never request OSM | Browser tests route `openstreetmap.org` to a mock and abort anything unmatched; the unit-test setup throws on any OSM URL |

## Where the map opens

The map opens on the **Comuna extent**, then the Unidad Vecinal bounds, then a Chile-wide bounds
constant, whichever comes first. With the minimum zoom of 8, that last constant opens on the middle
of the country at the widest zoom the map allows: a country fact, not a clinic fact.

The Comuna extent is derived from the install's comuna CUT, which the installer already writes. A
table inside the app maps each CUT to a bounding box. Nothing is stored, so a comuna changed by an
administrator moves the opening view at once, and an install that was provisioned before this
decision needs no re-provisioning. A CUT missing from the table falls through to the next source.

A comuna of several islands or pieces of land has several polygon parts, and the largest is not
always where people live: for Juan Fernández it is an uninhabited island 1.9 degrees from the
inhabited one, and for Natales and Cabo de Hornos the town is outside the largest part. The box
is therefore that of the part holding the most DEIS establishments; a tie goes to the larger of the
tied parts, and a comuna with no establishment in the register falls back to its largest part.
Two towns are added by hand because the register cannot place them: Puerto Williams, in a comuna
(Cabo de Hornos) with no register row at all, and Achao, whose part the register undercounts
(Quinchao). Both are listed with their reason in the generator and in the table's header.
The table is generated by `tools/comuna-extents.py` from the pinned boundary file and the DEIS
register, and committed; the app reads only the table. The register is not pinned, so the
table is reproducible only against the snapshot named in its header.

**There is no pan lock.** The extent decides only where the map opens. A person may need to look
outside the comuna: a referral hospital, a neighbouring comuna's boundary, a route.

## Consequences

- **No offline mode.** A clinic without internet has no basemap. Records, drawing and every layer
  keep working over a plain background, as they do for any tile failure.
- **PMTiles goes.** The archive, the Protomaps extract timer, the `/tiles/` route,
  `protomaps-leaflet` and the `kind` fork in the map and the Content-Security-Policy are removed.
  ADR-0004's "client browsers need internet for the basemap" holds again.
- **The `tile_url` and `tile_attribution` settings go.** A block by OSM is therefore fixed by a
  code release that changes one constant. This is accepted. Runbook note: OSM answers a blocked
  client with a valid PNG image, which the browser loads without error, so the app raises no
  banner and no `tileerror`. A basemap showing a "blocked" image, with no warning on screen, is
  how a block looks.
- **A stored `tile_url` is deleted on upgrade**, in the release that removes the setting, by one
  idempotent repair step that logs the old values first. Nextcloud cannot downgrade an app, so
  the log is the only record of what was there.
- **Privacy.** Every tile request carries the tile coordinates, which place a viewport to roughly
  300 m at zoom 17, and the instance's origin as `Referer`. Both reach OpenStreetMap's servers. No
  record, name or contact detail does. The threat model records this when the removal ships.
- **Overpass is a different policy.** `tools/boundary-features.php` queries Overpass offline, from a
  maintainer's machine, to produce Datasets. That is governed by Overpass's own usage rules and is
  untouched by this decision; the app still makes no Overpass calls at runtime (ADR-0004).
- **Legibility ratios are re-measured** against the standard OSM surfaces when the vector path
  goes; `map-legibility.md` § 2.2 is retired then.

## Rollout

This ADR records the decision and ships first, on its own. The code follows in two releases so
that each step can be reverted by shipping a forward version:

1. **Release N** adds the Comuna extent and the policy-hardened OSM layer. The PMTiles path and
   the setting are untouched, so an install with a stored archive URL keeps working. Between the
   two releases a person checks the public service by hand, once, from a real clinic network.
2. **Release N+1** removes PMTiles, the setting and the `/tiles/` route, and the installer retires
   the tile stack.

Until N+1 ships, the README, the threat model and `map-legibility.md` still describe the PMTiles
basemap, and they stay correct for the code they describe. They are edited by the release that
makes them false, not by this one.
