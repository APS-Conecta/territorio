# Removing a Category is refused while it holds records

- **Status:** accepted (2026-08-10)

A clinic can remove a Category or a Subcategory it does not use. While records are filed under one,
the control is refused: it says how many records there are and where to see them, and the person
reclassifies them first. Nothing is reassigned, retired or destroyed on their behalf.

Three options were on the table. Move the records to «Sin clasificar»; retire them alongside the
Category as one revertible unit; or refuse.

Moving them is the one that looks kindest and is worst. It is a silent bulk reclassification of
possibly hundreds of records, each one a Revision, triggered by a click a person meant as tidying —
and it turns «Sin clasificar» into the place things go to die, which is the state issue #36 was
reported to fix. Retiring them is more honest but hides the same size of act behind the same small
control: «remove this layer» and «retire 340 places» are not the same sentence and should not be the
same button.

Refusing is the only one where the size of the consequence is visible before it happens. It also
costs the least to build: no bulk migration, no undo path, no batch semantics.

**It keeps ADR-0005 without ever testing it.** That ADR says nothing in the Registry is destroyed. A
removal that cannot orphan anything never has to be reconciled with it. And it takes the same stance
as ADR-0011: where the app would have to guess what somebody meant, it asks instead.

**The count has to be honest, and it is not the count Análisis shows.** Análisis counts what a person
is currently looking at — filters applied, retired records excluded, boundary Datasets excluded. The
refusal counts everything that *would be orphaned*, retired records included, because a retired
record can be restored and the schema carries no foreign key that would catch the orphan afterwards.
Two numbers answering two questions; conflating them is exactly how the orphan gets through.

## Consequences

- A new query is needed. Nothing in the app counts Features by Subcategory today, and the existing
  count that feeds Análisis cannot be reused for this.
- «División territorial» can never be removed, and neither can its two Subcategories. A Sector and a
  Unidad Vecinal cannot be saved without one, and the seed restores them on every upgrade. The panel
  says why, where the control would otherwise be, rather than failing at save time.
- The refusal must be a next step rather than a dead end. Saying "84 records" without saying where
  they are leaves a person stuck with a number.
- Reclassifying in bulk is deliberately not offered. If somebody hits that wall in real use it can be
  reconsidered as its own decision, with its own undo.
- «Sin clasificar» stays a legitimate seeded Category. Nothing may start treating it as the bucket
  removed things fall into.
- Removing still asks first, in the same two-step inline shape retiring a record already uses — never
  a browser dialog, which blocks the page.

## Sources

- ADR-0005 — open editing, guarded by history rather than permissions
- ADR-0011 — similar records are queued for a person, never merged
- Issue #36 and its spec, #40
