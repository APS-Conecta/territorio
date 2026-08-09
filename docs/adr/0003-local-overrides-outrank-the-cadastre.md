# A local override outranks the cadastre on re-import

- **Status:** accepted (2026-08-06)

Cadastral Datasets — the national Unidades Vecinales file, FONASA establishment lists — are
re-imported whenever their publisher issues a new release, so the obvious rule is that the
official source wins. We chose the opposite: when a clinic has edited a cadastral record, that
edit is treated as the more current truth, because it was made by someone standing in the
territory correcting a boundary or an address the authority got wrong, and destroying it would
destroy irreplaceable fieldwork. Import therefore matches on the provenance-prefixed external id
(`<source>:<hash>`, the convention the prior art already used), refreshes untouched records, and
leaves overridden ones alone.

## Consequences

- The Registry drifts from the published cadastre over time, deliberately. This is not a bug.
- Every record must be able to answer "official value vs local value", so the incoming cadastral
  value is retained alongside the override rather than discarded — otherwise nobody can tell
  which boundaries are still official, and an override can never be reverted.
- Import must be idempotent on the external id, or a second run duplicates the Registry.
