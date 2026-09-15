# A Sector's boundary provenance is read from history, not stored

- **Status:** accepted (2026-09-15)

A Sector arrives three ways — polygonized from picked boundary features (ADR-0007), drawn freehand,
or imported from a Dataset — and issue #50 asked it to say which. The issue names the first two;
the third is real and was found in review of this slice: `ImportService::classify()` files a row
under any Subcategory, Sector included, and CHANGELOG 0.33.0 is about undoing an import that
created one. The obvious answer is a column: `boundary_source`, written
where the shape is made. It was not taken, because the revision history already answers the
question and because the column would be worse than what it replaced.

Every write records which fields it changed, and `Feature::comparable()` carries both `geometry`
and `limit`. So a Sector generated from picked lines wrote the two in one revision, and a Sector
drawn freehand has a Created entry with a `geometry` key and no `limit` key at all — `asChanges()`
drops nulls, and `FeatureService::limit()` stores an empty Limit as null rather than `[]`.
`src/limit.js` reads those shapes and says which happened, with the date the line last moved.

What the shapes do **not** carry is the act. `geometry` written alone in a later revision is what a
dragged vertex leaves behind, and equally what «Generar» leaves behind on a reopened Sector whose
Limit did not change — the flow `keepPickedGeometry()` and issue #49 exist to make possible. So
the sentence for that shape states the order and the date, never a hand. What history does
establish is the other way round: generating needs picked lines, so a Limit, and saves the two
together, so a line last written while no Limit had ever been recorded was **not generated**.

It does not follow that it was drawn by hand, and that is the third origin's whole consequence: an
import writes a `geometry` and no Limit, which is a hand-drawn Sector's Created entry byte for
byte. History cannot separate them and no reading of it ever will. The record can — `datasetId` is
set by `FeatureService::applyProvenance()` on import and nowhere else, and `Feature::toClient()`
already ships it — so `provenance()` takes that one fact as an argument and says «Línea importada
del catastro» where history would have said a hand. Only the import's own creation is credited to
the catastro: a later `geometry` write is a re-import publishing a new official shape or somebody
dragging a vertex, indistinguishable as ever, so it falls to the dateline that names no act.

This is still not a column. `datasetId` was already there, already read by the client, and is the
answer to a different question — which Dataset owns this record — that provenance merely consults.

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
- **The sentence names an act only where history establishes one.** «dibujada a mano» and «fijada
  junto con el límite» are derived; a later geometry-only write is said as «guardada … », with the
  order and the date and no verb for the act, because a drag and a regenerate write the same shape.
  Wording it «ajustada» announced a hand adjustment for polygons the app itself had generated,
  which is the likeliest of these three cases to be hit.
- **The origin outlives the act, so it is read over the whole history and not off the newest
  write.** A revision that wrote `geometry` and `limit` together is where the boundary came from,
  and saving the line again does not undo that — so «fijada junto con el límite el … y guardada de
  nuevo el …» keeps both the origin and the date. Reading only the newest write of each field left
  the generated-then-dragged Sector naming no origin at all. Where no revision ever wrote the two
  together there is nothing to name, and the sentence stays the bare dateline.
- **A record that came from a Dataset is never told as hand-drawn.** The unit tests assert that as
  a rule over every fixture, not only as three wordings, and `tools/browser-check.py` imports a
  Sector and reads the sentence — the only check that exercises `datasetId` travelling from
  `toClient()` through `draftFrom()` into the sidebar, which no unit test can see.
- **A Limit removed is not a Limit recorded.** The revision that cleared one is skipped, because a
  Sector whose Limit was deleted was not built against it.
- The sidebar reloads a record's history after every save, since the sentence is derived from it.
- If an act ever does need recording — "generated", as a thing that happened rather than as fields
  that changed — the place for it is a new `Action` case witnessed by the server, not a column on
  the Feature.
