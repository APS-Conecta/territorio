# The export log is its own table, not a Revision

- **Status:** accepted (2026-08-07)

ADR-0006 says every contact-bearing export "appends a history entry naming the user, the moment
and what was exported", and the obvious reading is that it becomes a row in `territorio_revision`
beside every other thing that has happened. It must not, for two reasons that both matter.

`territorio_revision` is not a history table that happens to drive polling — it **is** the change
cursor (ADR-0002). Every row is issued by incrementing `territorio_cursor` under a row lock, and
every open browser asks "what changed since revision N" every five seconds. Recording an export
there would bump that cursor, and twenty open maps in a clinic would each refetch, re-render and
find nothing different. An export changes nothing about any record; making it look like a change
is a lie told to the one mechanism that has to be believed.

The second reason is smaller and would not, alone, be enough: `territorio_revision.feature_id` is
`notnull`, and an export is not a change to one Feature. It is an act on a *set* — often a
filtered set of hundreds. Relaxing that column to accommodate one row type that is not about a
Feature would weaken a constraint that is correct for every other row.

So exports go in `territorio_export`, with the actor, the moment, the format, the size of the set
and whether it carried contact fields. "Part of history" in ADR-0006 means the record is kept and
never deleted, which this satisfies; it does not mean sharing a table with the change cursor.

## Consequences

- Exporting takes no Revision and does not wake the pollers. This is the point.
- Two logs exist, and a future "everything that has happened" report has to read both. That is a
  report's problem, and the alternative was making every poll in the building do the work instead.
- An export names a set, not a record, so the log records what the set *was* — how many records,
  and whether contacts were in it — rather than which ones. Storing hundreds of ids per export
  would grow without bound and answer a question nobody has asked; if "exactly which records left
  the building" ever needs answering, that is a new column and a new decision.
- Only contact-bearing exports are logged. ADR-0006 ties the record to the confirmation, and a
  log of every GeoJSON download would bury the entries that are actually about people.
