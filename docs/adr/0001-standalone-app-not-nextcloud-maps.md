# Territorio is a standalone app, not built on Nextcloud Maps

The instance shipped with the official `maps` app (1.7.1) enabled, so a reader will reasonably
ask why we wrote our own map app instead of extending it. Maps models *personal* geography —
each user's own favorites, GPX tracks, geolocated photos and devices — while Territorio is an
*institutional registry*: shared, typed territorial zones, places and routes with provenance,
an editorial lifecycle, and a REST surface other apps read. The only overlap is "pins on a
Leaflet map", which is a library concern, not a domain one. We chose a separate app and are
removing `maps` from the instance rather than forking it, accepting that we rebuild the map
screen ourselves and lose GPX/photo geolocation, in exchange for owning our data model and not
merging a third-party app on every Nextcloud upgrade.

## Consequences

- Removing `maps` deletes users' favorites, tracks and geolocated-photo indexes. The removal is
  a `gestion/` deployment change, not a Territorio change.
- Territorio owns its own storage; it must not depend on `maps` tables or its OCS API.
