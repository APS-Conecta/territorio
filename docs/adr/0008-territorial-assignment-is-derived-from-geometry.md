# A Place's Sector and Unidad Vecinal are derived, not typed

- **Status:** accepted (2026-08-06)

Both fields existed as free text in the source data and a reader will expect them to stay
editable, so it matters that they are now read-only. In the curated La Florida dataset, 393 of
556 places have an empty `sector` — 71% blank after a full curation effort — which is the
evidence that hand-assignment does not survive contact with real work. Now that Unidades
Vecinales and Sectores are polygons in the Registry, the question "which sector is this school
in?" is answered by the geometry that already exists. Territorio resolves each Place against the
boundaries and stores the resulting ids, recomputing whenever the Place moves or a boundary is
redrawn, so filters and reports stay ordinary indexed queries rather than per-request
point-in-polygon work.

## Consequences

- Redrawing a Sector reassigns every Place inside it. That is the point, and it must be visible
  in history like any other change.
- A Place with no coordinate — the source data has several — resolves to no Sector and no Unidad
  Vecinal. This is a valid state and must not be treated as an error.
- Sector and Unidad Vecinal are not editable fields on a Place. Correcting an assignment means
  correcting the geometry, which is the honest fix.
