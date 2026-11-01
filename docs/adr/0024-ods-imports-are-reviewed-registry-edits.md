# ADR-0024: ODS imports are reviewed Registry edits

## Status

Accepted — issue #252, confirmed inventory design in #249.

## Context

The existing CSV is a readable one-way export. Staff need a downloadable spreadsheet
that can update the same community assets without silently dropping optional details,
recreating manually entered records, or merging places that happen to look alike.

## Decision

ODS exchanges point assets only. Each exported record carries its installation ID,
Feature ID and Revision. New records leave identity cells empty. Classification uses
the current taxonomy; unknown values and incomplete coordinate pairs are visible row
errors. Optional blank cells preserve stored values; the review offers explicit field
clearing. No address lookup occurs.

Uploading produces a session-bound preview with no Registry writes. Staff select rows,
review proposed changes and acknowledge possible duplicates; similarity never merges
records (ADR-0011). Repeated IDs are errors, not last-row-wins. Retired and stale records remain protected. Updates are deliberate local edits: they
preserve the source snapshot and set a local override, so later cadastral imports cannot
erase staff corrections (ADR-0003). A fresh, explicitly reviewed ODS export may update
an existing local override. Classification errors can be corrected in preview without
changing the taxonomy or writing any Registry records.

Confirmation revalidates the file under the existing Registry cursor lock and refuses
if the Registry or taxonomy changed since preview. The selected rows form one transaction and one
reversible import batch (ADR-0009); absent spreadsheet rows never delete records.
Contact-bearing ODS exports use the existing password-confirmed, logged route (ADR-0006).

The reader accepts a bounded text/numeric subset of ODS, never evaluates formulas,
and refuses XML declarations and excessive archive/table expansion. Template dropdowns
are advisory; server-side validation is authoritative across spreadsheet applications.

## Consequences

Staff must re-export stale rows and repeat preview after any concurrent Registry edit.
This conservative conflict policy is deliberately simple for a clinic-sized inventory.
One preview per session expires after thirty minutes. Large inventories are exported
in selections of at most 5000 records; areas stay in map editing and GeoJSON exchange.
