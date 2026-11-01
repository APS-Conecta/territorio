# Custom areas do not change clinical Sector assignments

- **Status:** accepted (2026-11-01)

The center needs overlapping areas for examining its registered community assets, while a Sector
continues to name a clinical team’s territorial division. A Custom area is a polygon Feature filed
under the structural `custom_area` Subcategory, alongside Sector and Unidad Vecinal (ADR-0010).
It reuses Feature history, retirement, restore and geometry validation, but never participates in
the stored clinical assignments defined by ADR-0008.

Analytics derives every geographic intersection from the current geometry, including Zones that
cross a boundary. An asset can occur in several geographic breakdowns; the overall inventory
counts its identity once. Custom areas are geographic context, excluded from asset totals and
classification choices. A separate membership table would duplicate geometry-derived state without
serving a current server-side query, so no such table is introduced.

Custom areas draw as solid unfilled outlines with named labels prefixed “Área”, distinct from the
Sector wash and the Unidad Vecinal’s dashed hairline. Existing Sector and Unidad Vecinal records
and assignments are preserved.
