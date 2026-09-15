# Collaboration is a polled change cursor, not push

- **Status:** accepted (2026-08-06)

Territorio's map is collaborative — one shared Dataset, edits by one person must reach everyone
else without a reload — so a reader will assume WebSockets and wonder why the client polls. The
instance has no `notify_push` / High Performance Backend, and it runs `nextcloud:34-apache`,
where every held-open connection pins a PHP worker; SSE would therefore convert a handful of
watching users into a worker exhaustion outage, which is the exact failure the clinic cannot
afford. Territorial editing tolerates seconds of latency, so each client keeps a revision number
and polls a change cursor roughly every five seconds — answered by one indexed `MAX(revision)`
query, returning only rows newer than the client's cursor, and paused whenever the tab is hidden.

## Consequences

- Freshness is bounded by the poll interval (~5s), by design. Do not "fix" this with SSE.
- If `notify_push` is ever installed, the cursor endpoint stays; only the trigger to call it
  changes from a timer to a push event. The upgrade is contained to the client transport.
- Every mutating endpoint must advance the revision counter, or clients silently go stale.

  **Correction (2026-09-15):** renaming a Category or a Subcategory (#41) does not advance it, and
  neither will the rest of the taxonomy's write path (#40). The counter hangs on a Feature id —
  `territorio_revision.feature_id` — and a Category is not a Feature, so honouring this literally
  would mean a schema change to hang a revision on nothing. The browser that made the change swaps
  in the tree the server answers with; another browser reads the new label on its next load. A
  Category is renamed a handful of times in an install's life, and that is not worth a second
  collection on every five-second poll forever. The two settings endpoints already did not advance it,
  and the duplicate queue set the precedent of a mutation refetched on demand with its reason
  written down. Left as written above rather than edited: rewriting a dated record is the worse
  defect (`docs/index.md`).
