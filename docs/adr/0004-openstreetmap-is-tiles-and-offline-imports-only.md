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
`osm_los_castanos` features were obtained. The app makes no Overpass calls of its own and never
writes to OSM: live Overpass is a free public service with rate limits and no SLA that would
become a runtime failure mode inside a clinic, and write-back via API v0.6 is a different product
altogether — OAuth against osm.org, changesets, conflict resolution, and permanent public edits
under a named account.

The tile URL is an administrator setting defaulting to the public OSM tile service, with
attribution and a Content-Security-Policy allowance for the tile host. A clinic that later loses
internet access repoints that one setting at a self-hosted tile server; no code changes.

## Consequences

- Refreshing OSM-derived data is a re-import, governed by ADR-0003.
- taginfo is a research aid used while choosing which OSM tags to query. It is not a dependency.
- Out of the box, client browsers need internet for the basemap. The map must stay usable, with
  features and geometry intact, when tiles fail to load.
