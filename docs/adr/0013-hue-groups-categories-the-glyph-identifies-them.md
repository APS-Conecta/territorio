# Hue groups Categories; the glyph identifies them

- **Status:** accepted (2026-08-07)

A Feature is drawn as one coloured dot, and hue is the only channel carrying what it is. Eleven
Categories, over a raster basemap that has colours of its own, for an audience that includes the
~8% of men with a colour vision deficiency. This decides that hue stops trying to do that job.

The arithmetic is what forces it. At constant lightness and chroma, N hues sit on a circle of
radius C in OKLab, and the distance between neighbours is exactly `2·C·sin(Δh/2)`. Eleven hues
separated by ΔE 0.10 therefore need **C ≥ 0.177**, which is not a quiet palette by any reading —
it is louder than the Tailwind-600 grab-bag it would replace (mean C 0.160). At C = 0.090 the same
formula yields about six. So the ramp carries **six hue families**, lightness distinguishes the two
members inside a family, and a Maki pictogram carries the identity.

The honest part: this is not a colour-blindness fix, and claiming it as one would be false. Measured
against the palette it replaces, the new ramp collapses **more** pairs under protanopia (4/55 vs
0/55) and tritanopia (6/55 vs 5/55), and fewer under deuteranopia (1/55 vs 2/55) — though the worst
case improves threefold, from ΔE 0.006 to 0.018. Lowering chroma costs separation everywhere and a
dichromacy compounds it. **No palette makes eleven categories safe on hue.** The glyph is what makes
them safe, because no dichromacy touches a shape, and the ramp is then free to be quiet — which is
what lets a Sector's boundary and its label read as the loudest thing on the map, where they belong.

## Consequences

- The ramp is only ever a **grouping**. Anything that needs a Feature identified — a legend, a
  screen reader, a report — must carry the glyph or the label, never the colour alone.
- **Do not raise the chroma.** It is the recurring wrong fix: it trades a measured grouping for an
  identification the arithmetic above says is unreachable, and it puts the Category dots back into
  competition with the Sector boundaries.
- Colours stay **data**, seeded in `EnsureSeedData.php` and repainted by `ensureRamp()`, because a
  Category is editable (`CONTEXT.md`). Changing one is a data change, not a code change — and a
  clinic that has chosen its own colour keeps it.
- `tools/ramp-check.py`, run by `make lint`, gates on contrast and on normal-vision separation, and
  on a ΔE 0.015 catastrophe floor per dichromacy. The per-dichromacy counts are reported and never
  enforced: a gate on the unreachable either fails forever or is set generously enough to mean
  nothing. Tritanopia currently sits 0.003 above that floor.
- Three neutrals now share the narrow lightness band that clears 3:1 against both a white page and
  the dark theme — `sin_clasificar`, the Sector default, and the broken-reference colour — with
  ΔE 0.055 at the tightest. There is no room for a fourth.
- Adding a twelfth Category is still a data change, but it lands in a ramp with no spare hue family.
  It will group with an existing one, and its glyph is what will tell it apart.
