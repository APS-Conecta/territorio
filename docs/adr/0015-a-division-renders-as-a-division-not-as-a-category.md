# A territorial division renders as a division, not as a Category

- **Status:** accepted (2026-08-10)

A Sector and a Unidad Vecinal are Features filed under a seeded Category (ADR-0010), and they stay
that way. What changes is that being filed under a Category no longer means *looking* like one.

On the map a Unidad Vecinal draws with no fill and a dashed hairline; a Sector draws a subordinate
wash and carries a permanent label at its centroid. In the Datos list neither draws the coloured dot
every other record draws — a Unidad Vecinal draws a dashed ring, a Sector a solid one, echoing the
map. And the colour input is offered for a Sector only.

The alternative was to promote divisions out of the taxonomy into a structural kind of their own.
That is the cleaner model on paper and it was rejected: it changes every read of Category — the Capas
panel, Análisis, the filters, import, export, the seed and its migration — to produce no behaviour a
person can see, and it would reopen ADR-0010, which settled that a Sector is a Feature rather than a
table of its own.

The rule is worth stating as a rule because three separate defects were the same defect. A Unidad
Vecinal was offered a colour picker on a record the clinic only reads; the division colour `#334155`
measured 1.73:1 against the dark theme in a list where 3:1 is the bar; and a UV fill and a Sector
fill were drawn identically, so the cadastre competed with the clinic's own working division for the
same visual weight. Each has a narrow fix. Together they have one.

**The colour problem dissolves rather than gets solved.** ADR-0013 measures the neutral band as full
— the tightest gap is ΔE 0.055 against a 0.05 floor — so there was no legible grey for a division to
move to, and every candidate outside the band reads wrong for a territorial boundary. Once a division
is not drawn as a coloured dot, it needs no colour that separates from eleven Categories, and the
constraint that had no solution stops applying.

## Consequences

- `#334155` stays in the seed as inert data. It renders nowhere, and a comment says why. No migration
  and nothing destroyed, which is the same treatment a Unidad Vecinal that already carries a colour
  of its own receives.
- `tools/ramp-check.py` stops reporting the division colour. A value nothing paints is not a value a
  contrast gate has anything to say about, and a permanent red is a red people learn to ignore.
- The dash is now an idiom with one meaning across the app: **the app is not asserting this
  precisely.** A cadastre boundary the clinic did not draw, and a coordinate known only
  approximately, both carry it. Anything else reaching for a dash has to fit that sentence.
- A Sector is identified by its label, not by its colour. CONTEXT.md allows a clinic to name sectores
  with words, numbers or colours, so a colour-first identity was right for one naming scheme out of
  three and a label is right for all three. A Sector's stored colour survives as a subordinate wash.
- Adopting `#949494` for the wash means adopting `fillOpacity: 0.10` with it. At 0.15 all 55 Category
  pairs collapse against the basemap; the two numbers are one decision and cannot be taken apart.
- Divisions keep their Subcategories and their place in the taxonomy table, so a Sector still cannot
  be saved without one and the seed still restores «División territorial» on every upgrade.

## Sources

- `docs/design/map-legibility.md` §1.2, §2 — the measurements this rests on
- ADR-0010 — a Sector is a Feature, not a table of its own
- ADR-0013 — hue groups Categories, the glyph identifies them
- Issues #21, #24, #29 — the three defects, closed by one slice
