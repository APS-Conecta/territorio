# A Sector's boundary provenance is read from history, not stored

- **Status:** accepted (2026-09-15)

A Sector arrives two ways — polygonized from picked boundary features (ADR-0007) or drawn freehand
— and issue #50 asked it to say which. The obvious answer is a column: `boundary_source`, written
where the shape is made. It was not taken, because the revision history already answers the
question and because the column would be worse than what it replaced.

Every write records which fields it changed, and `Feature::comparable()` carries both `geometry`
and `limit`. So a Sector generated from picked lines wrote the two in one revision, a dragged
vertex wrote `geometry` alone, and a Sector drawn freehand has a Created entry with a `geometry`
key and no `limit` key at all — `asChanges()` drops nulls, and `FeatureService::limit()` stores an
empty Limit as null rather than `[]`. `src/limit.js` reads those three shapes and says which
happened, with the date the line last moved.

A column would also not have been the truth. `faceAround()` runs in the browser, so the server
never witnesses "generated" — `Action` has created, updated, retired, restored and merged and
nothing else — and a stored flag would be the client's word for exactly what history already
holds, with a second way for the two to disagree.

The sentence states provenance and never a defect. The Limit records intent and the geometry
records the agreed line: they stop matching the moment somebody drags a vertex, which is expected
and is never drift to be corrected (`CONTEXT.md`).

## Consequences

- **The history entries are now a read interface, not only a screen.** `comparable()`,
  `asChanges()` and `FeatureService::limit()`'s null are load-bearing: record nulls in a creation
  and every hand-drawn Sector announces a Limit it never had. `FeatureDiffTest` pins that.
- **One revision is one save, so acts inside a save are invisible.** A Sector generated and then
  dragged before its first save, and one drawn by hand with streets picked in the same save, both
  read as "fixed together with the Limit". Telling those apart means recording the act rather than
  the fields it changed — a product question, and still not a column.
- **A Limit removed is not a Limit recorded.** The revision that cleared one is skipped, because a
  Sector whose Limit was deleted was not built against it.
- The sidebar reloads a record's history after every save, since the sentence is derived from it.
- If an act ever does need recording — "generated", as a thing that happened rather than as fields
  that changed — the place for it is a new `Action` case witnessed by the server, not a column on
  the Feature.
