# Anyone may edit; history and retirement are the safety net, not permissions

- **Status:** accepted (2026-08-06)

The obvious way to protect a shared Registry is to restrict who can write to it, so it is worth
recording that we deliberately did the opposite. Territorial knowledge lives with the people who
walk the territory — the TENS who knows the taller moved, the matrona who knows the address is
wrong — and putting them behind an editor group is the reliable way to get a map that is out of
date. Every user who can open Territorio can add, edit and retire records. The risks that
normally justify permissions are answered directly instead: every mutation appends a history
entry recording who changed what and when; deletion is retirement, not removal, so a retired
record disappears from the map and can be restored; and the whole Registry can be exported as a
snapshot and restored from one.

History reuses the monotonic revision counter that ADR-0002 already requires for the live-sync
cursor — the same sequence answers "what changed since revision N" for both a polling client and
a person asking who moved a boundary. There is one mechanism, not two.

## Consequences

- No hard `DELETE` of a Feature anywhere in the app. Every read path must exclude retired records
  by default, or retired data reappears on the map.
- History grows without bound; it is append-only and never edited to correct a mistake.
- Writing still requires an authenticated Nextcloud session — "anyone" means any user of this
  instance, never anonymous.

## Amendment — the revision history is the only change record (2026-10-27)

The revision history is the app's record of who changed what. The app keeps no second record of
Registry changes beside it: it writes none to `nextcloud.log` and dispatches none to `admin_audit`.
A second record would depend on instance configuration this app does not control and would drift
from the history it repeats.
