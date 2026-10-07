## 2026-10-07 - Index row formatting boundary lookup (O(N*M) -> O(N))

In `src/indice.js`, `rowsFor` formats every feature row for the Índice table, looking up Sector and Unidad Vecinal boundary names via `nameOf(boundaries, id)`. With `N` features and `M` boundary features, this executed $2 \times N$ linear searches ($O(N \times M)$) over `boundaries` on every render/sort of the table.

By constructing an `id -> boundary` Map once before iterating over features in `rowsFor`, and updating `nameOf` in `src/boundaries.js` to accept a Map as well as an Array, boundary name lookups during row generation are now $O(1)$ per assignment field ($O(N)$ total).
