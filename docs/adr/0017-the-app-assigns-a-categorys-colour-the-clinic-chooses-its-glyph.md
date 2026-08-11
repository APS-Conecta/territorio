# The app assigns a Category's colour; the clinic chooses its glyph

- **Status:** accepted (2026-08-10)

When a clinic adds a Category, it types a label and picks a pictogram. It does not pick a colour —
the app assigns one from a list of spares vetted in advance.

The split follows from who can actually answer each question. A colour has to clear 3:1 against white
and against the dark theme, stay ΔE-OK 0.05 from every existing Category and both neutrals, and stay
0.015 from every one of them under protanopia, deuteranopia and tritanopia. Nobody satisfies that by
eye, and a colour input that silently accepts a failing value is worse than no input. A pictogram is
the opposite: no software can guess which mark means «comité de vivienda», and a person picks one in
seconds.

**The spares are derived where the arithmetic already lives.** `tools/ramp-check.py` implements OKLab
separation, WCAG contrast and the three dichromacy transforms, and runs on every `make lint`. It
gains a mode that derives every colour clearing all of those gates — holding chroma where ADR-0013
fixes it and staying inside the six existing hue families rather than opening a seventh — and those
ship as seeded data beside the eleven.

The alternative was to compute a colour on the write path, which means a second implementation of the
same arithmetic in PHP. Two implementations of one rule drift apart silently, and preventing exactly
that drift is why the ramp check exists. Deriving at lint time also means a change to the seeded
palette that invalidates a spare turns the build red at the moment it is made, rather than the first
time a clinic adds a Category on some other install.

**Run against the palette as it ships, the search yields eight spares.** That is a real ceiling, not
a theoretical one, and the twelfth clinic-added Category will reach it. When the spares are exhausted
the Category is still created and still works: it takes the neutral, and the panel says it will not
be told apart by colour. That is not an error and must not read as one — ADR-0013 already holds that
hue only groups Categories into families and the glyph is what identifies them, so a Category without
a distinct hue is doing what that ADR says every Category does anyway.

## Consequences

- The spare list is data, not a number in a comment. The app reads it; the check gates it.
- `make lint` fails if a change to the seeded eleven collides with a spare. Editing a Category's
  colour is now also a statement about colours no Category holds yet.
- Chroma is never raised to make room, and no seventh hue family is opened. Both are forbidden by
  ADR-0013 and both are the recurring wrong fix.
- A clinic-added Category with no chosen pictogram still draws the neutral fallback rather than a
  blank. A blank reads as a rendering failure; the neutral reads as unclassified.
- The pictogram is chosen from the set already vendored, and previewed at the size the map pin
  actually draws — 12px of mark is the floor at which a Maki glyph holds its detail, and a choice
  judged at list size can fail at pin size.
- No colour input is offered for a Category, on any screen. A person who wants a specific colour has
  no path, and that is the trade: they get one that works instead of one they chose.

## Sources

- ADR-0013 — hue groups Categories, the glyph identifies them
- `docs/design/map-legibility.md` §1.2 — the ramp arithmetic and why the band is narrow
- Issue #36 and its spec, #40; slices #43 and #46
