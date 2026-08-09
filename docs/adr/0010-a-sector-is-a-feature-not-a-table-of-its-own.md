# A Sector is a Feature, not a table of its own

- **Status:** accepted (2026-08-06)

`CONTEXT.md` already says it — "a school, a plaza, a cycle path, a Sector" are all Features — and
storing it that way is what makes the rest of the app apply to it for free. A Sector drawn today
gets history, retirement and restore, the polled change cursor, the Datos list and the revert,
none of which had to be written twice. A `territorio_sector` table would have needed every one of
them again, and the second copy is where they drift.

The cost is two accommodations, both small and both visible. A Feature carries exactly one
Subcategory, so sectores need somewhere to be filed: a "División territorial" Category, seeded
and repaired on every upgrade because the app cannot save a Sector without it. And every Sector
shares that one Subcategory, so a per-Category colour would draw them all alike — the colour has
to live on the record, which is the `colour` column, null everywhere else and meaning "take the
Category's".

## Consequences

- The Capas panel is the Category tree **plus one toggle per territorial division**, exactly as
  `CONTEXT.md` describes it. The division Category is deliberately not drawn as a branch, and it
  is not offered in the Subcategoría picker either: a colegio filed as a Sector would hide behind
  the wrong toggle and be coloured like a boundary.
- Unidad Vecinal (issue #9) is the same shape of thing and belongs in the same Category — as a
  Cadastral Dataset rather than a drawn one, which changes where the geometry comes from and
  nothing about how it is stored.
- Derived assignment (ADR-0008, issue #11) reads sectores out of the Feature table like anything
  else, so it needs no join to a second store.
- Anything that lists Features now lists sectores too, including exports and search. Where that is
  wrong it has to be filtered on the division Subcategory — one place, by slug.
