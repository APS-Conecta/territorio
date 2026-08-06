# There is no backup feature; imports are revertible instead

Territorio holds a clinic's territorial knowledge and offers no "back up the Registry" button, so
it is worth recording that this is deliberate rather than missing. The decisions in ADR-0005
already remove most of what a backup would be for: nothing is ever hard-deleted, so an accidental
delete is undone by restoring the retired record, and every change is in history, so any single
edit can be traced and reversed. That leaves one realistic failure mode a snapshot would address —
a bad bulk import mangling hundreds of records at once — and a snapshot is a poor answer to it,
because restoring one discards every unrelated edit made since. Instead, each import is recorded
as a batch spanning a range of revisions, and reverting the batch retires what it created and
restores what it overwrote, touching nothing else.

Catastrophic loss — disk failure, dropped tables — is handled by the Postgres backup in the
`gestion` deployment, which is the correct layer for it and which Territorio must not duplicate.
The GeoJSON export already gives anyone who wants an off-system copy a lossless one.

## Consequences

- Every import must record its batch and revision range at the time it runs; this cannot be
  reconstructed afterwards.
- A revert is itself a change and appears in history. Reverting a revert is therefore possible.
- If someone asks for a "restore from backup" button, the answer is a revertible import or a
  retired record, not a new mechanism.
