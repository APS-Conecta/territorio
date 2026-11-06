# A Layer is fetched at view time and never stored

- **Status:** accepted (2026-10-24)
- **Tags:** layers, wfs, arcgis, geoportal

## Context

The Registry holds what the clinic typed or imported — nothing else can appear on the map. But
every CESFAM works inside national public geodata: the health network around it, schools,
kindergartens, ferias libres, sports infrastructure, police quadrants, emergency shelters. Today
the only path onto the map for any of that is the Cadastral Dataset one — download a file, import
it — which is heavy (the ten services hold 370 to 20,701 features nationally), stale on arrival,
and fills the Registry with records nobody at the clinic curates. Issue #256 asked for the ten
named services on the map "without downloading them".

The services were probed live during design. Both families are public and keyless — no API key
exists to manage — and both answer cross-origin (`access-control-allow-origin: *`), so a user's
browser can talk to them directly. The nine geoportal.cl services are OGC WFS workspaces that
return GeoJSON, filtered by bounding box and paged; the tenth, Albergues código azul (MDSYF), is
an ArcGIS FeatureServer answering `f=json` with a geometry envelope. Two of the ten are not
points at all: Ferias libres is MultiLineString — a feria is modelled as the street segment it
occupies — and Plan Cuadrante is MultiPolygon.

## Decision

**A Layer is fetched live from its public API at view time, scoped to the current viewport, and
never stored.** No import batch, no Revision, no Registry rows; a Layer's points exist only on the
page that asked for them.

What follows from this — vector rendering through Leaflet, exactly two adapters (WFS and ArcGIS)
behind one declarative registry in code, browser-direct requests — is settled by the parent spec
(#256) and lands in its own slices with its own tests. This ADR records the founding rule and
what it costs.

### Rejected alternatives

- **Import as a Cadastral Dataset.** The machinery exists, but a national file is 370–20,701
  features nobody at the clinic curates, stale on the day it lands. Worse, the health service
  re-publishes its workspace in place — June's establishments replace February's under the same
  URL — so a stored copy would age while its Local overrides kept pointing at rows that no longer
  correspond to anything.
- **WMS raster tiles.** Every geoportal workspace also serves WMS, and a tile overlay is the least
  code that exists. But a WMS overlay is a picture: no popups, no clustering, no legend coherence
  with the Category hues — and the ferias' street segments and the quadrants want geometry-aware
  styling and clicking regardless.
- **A server-side proxy.** There is no key to shelter and CORS is already open (verified), and a
  proxy is a server-side fetch of a URL the app chose — a surface this app does not have, the same
  posture ADR-0021 took on the basemap.
- **Storing a snapshot for offline.** Contradicts the founding ask, and offline basemap is already
  accepted as out (ADR-0021). A Layer needs the user's internet, like the tiles under it.

## Consequences

- **A Layer is as fresh as its service and as available as the user's internet.** A dead service
  is a notice and a badge on its toggle; the map, the Registry and every other Layer keep working.
  Turning a Layer off leaves the map exactly as it was — nothing persists, nothing to clean up.
- **The user's browser talks to government services directly.** Viewport coordinates reach
  geoportal.cl and services3.arcgis.com; no record, name or contact detail does — the same
  privacy posture as the OSM tiles (ADR-0021).
- **Attribution is mandatory** in every Layer popup: the source service is named, and its licence
  is recorded as unknown in the org's API catalogue, so the popup says where the data came from
  and stops there.
- **The registry is code, not configuration.** Ten entries, static; an eleventh Layer is a
  release, not a setting. Revisit only when someone other than a developer must manage sources.
- **The glossary gains Layer** (CONTEXT.md), and two sentences change job: the Dataset entry's
  avoid-list keeps "layer" so the word cannot stand in for Dataset, and the taxonomy note that
  there is no separate Layer object now scopes itself to Features — a Layer is external, never
  stored, and toggled in its own section.
