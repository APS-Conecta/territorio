# Sector boundaries are built by picking real boundary features, then adjusted by hand

A CESFAM describes its sectores the way it always has — "from Vicuña Mackenna to the rail line,
down to Departamental" — so Territorio lets a person build the boundary that way instead of
tracing it freehand. This is the largest single piece of work in the app and was chosen knowing
that; the cheaper alternative was to trace the streets by eye off the basemap.

It is made tractable by refusing to resolve street *names*. The comuna's boundary features —
named highways, waterways and railways, about 11,400 of them for La Florida — are imported as a
Cadastral Dataset and loaded only while a Sector is being edited, never on the ordinary map. The
user picks real objects from that set, by clicking them or by typing with autocomplete over
streets that actually exist in the comuna, so ambiguity is settled by the person in one click
rather than guessed by a geocoder. The enclosed area is then found by polygonizing the selected
lines and keeping the face containing a point the user clicks inside, which disposes of segment
ordering, ring closure and duplicate names at once.

The result is always a starting point, never the final answer: every vertex stays draggable,
because real limits run along things that are not in any dataset — a canal, a park edge, an
invisible line agreed between two teams. The picked features are retained as the Sector's Limit,
so the boundary stays explainable after it is drawn.

## Consequences

- Sector editing needs the boundary-feature Dataset present; without it, hand-drawing must still
  work, so drawing is the fallback and never a second-class path.
- Once a boundary is hand-adjusted it no longer matches its Limit exactly. That is expected; the
  Limit records intent, the geometry records the agreed line.
- The boundary network goes stale as OSM changes. Refreshing it is a re-import under ADR-0003 and
  must never move a Sector that has already been drawn.
