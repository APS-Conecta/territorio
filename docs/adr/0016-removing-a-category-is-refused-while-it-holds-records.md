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

## Amendment — «Sin clasificar · Otro» is permanent too (2026-09-15, #47)

Building the removal path found a second pair that can never go, and the consequence above named
only the territorial division. Three things resolve «Sin clasificar · Otro» by slug and none of them
creates it: `ImportService::prepare()` refuses a whole file without it, `defaultSubcategoryId()` in
`src/taxonomy.js` starts every new record there rather than at whatever Category sorts first, and
`tools/boundary-features.php` stamps it on every Limit. `EnsureSeedData::FALLBACK` owns the slugs,
because the seeder is what creates them.

The in-use count is no protection here: being **empty** is this Category's ordinary state on a clinic
whose imports resolve cleanly, so the guard above would have waved it through on exactly the installs
that depend on it — and the seed would put it back on the next upgrade, with nothing said anywhere.

**Unlike the division, this one is discovered at save time, and that is a deliberate difference.** The
division has no row in the Capas panel at all — `treeOf` leaves it out, because ADR-0015 puts one
toggle per division beside the tree — so the panel had somewhere to put a sentence and nowhere to put
a control. «Sin clasificar» is an ordinary Category branch with an ordinary bin, and hiding that bin
would put the rule in a second place and let the two drift. The cost is real and is accepted here: a
person can click that bin and be refused, which is the experience the consequence above exists to
avoid. Worth revisiting if anybody actually tries it — the panel already knows the slug.

## Sources

- ADR-0005 — open editing, guarded by history rather than permissions
- ADR-0011 — similar records are queued for a person, never merged
- Issue #36 and its spec, #40
