# OpenStreetMap is a basemap and an offline import source, nothing more

- **Status:** accepted (2026-08-06)
- **Correction (2026-08-08):** the Dataset slug named below identifies the establishment this app
  was first built for. Which clinic an install serves is instance configuration, never a repository
  fact, so the fixtures and comments that carried that slug now use a synthetic one. The slug in
  this ADR is left as written — it records provenance that really had that name, and rewriting a
  dated record would be the worse defect.

The brief cited OSM's API v0.6 and taginfo alongside the tile service, which invites the
assumption that Territorio talks to OSM at runtime — so it is worth stating that it deliberately
does not. OSM provides the background tiles, and OSM-derived features arrive as ordinary GeoJSON
Datasets produced by a repo-side Overpass query, which is how the existing 309
OSM-derived features of the first comuna were obtained. The app makes no Overpass calls of its own and never
writes to OSM: live Overpass is a free public service with rate limits and no SLA that would
become a runtime failure mode inside a clinic, and write-back via API v0.6 is a different product
altogether — OAuth against osm.org, changesets, conflict resolution, and permanent public edits
under a named account.

The tile URL is an administrator setting defaulting to the public OSM tile service, with
attribution and a Content-Security-Policy allowance for the tile host. A clinic that later loses
internet access repoints that one setting at a self-hosted tile server; no code changes.

**Superseded in part (2026-09-18) by
[ADR-0019](0019-the-basemap-is-a-self-hosted-pmtiles-archive.md).** The public OSM tile service
answered 403 — it is a best-effort service whose own policy asks heavy users to host their own — so
the default is now a self-hosted PMTiles archive, and a basemap URL may be either form. The
mechanism this paragraph describes is exactly what made that a configuration change instead of a
rewrite; it was right. Only the default moved.

## Consequences

- Refreshing OSM-derived data is a re-import, governed by ADR-0003.
- taginfo is a research aid used while choosing which OSM tags to query. It is not a dependency.
- ~~Out of the box, client browsers need internet for the basemap.~~ **No longer true as of
  ADR-0019**: the archive is served beside the app, so the basemap reaches exactly as far as the
  app does. The second half stands and is load-bearing — the map must stay usable, with features
  and geometry intact, when the basemap fails to load.

## Addendum (2026-10-18): the runtime-tiles consequence is amended by ADR-0021

[ADR-0021](0021-the-basemap-is-openstreetmaps-tile-service-used-as-its-policy-asks.md) supersedes
ADR-0019 and, with it, the first paragraph's mechanism: the tile URL stops being an administrator
setting, and the PMTiles route is gone. The basemap is OpenStreetMap's tile service, loaded by the
browser as that service's policy asks. What this ADR decided about OSM and the app still stands:
tiles and offline imports only, no Overpass calls of the app's own, no write-back to OSM.
