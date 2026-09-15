#!/usr/bin/env python3
"""Check the Category ramp that EnsureSeedData actually ships.

Reads the colours out of the seed rather than holding a copy of them, because a
copy is a second source of truth and would agree with the spec while the app
disagreed with both.

The rules it enforces, and where they come from — docs/design/map-legibility.md:

  - every colour clears 3:1 against a white glyph, against #ffffff, and against
    the dark theme background. A glyph inside a filled shape is non-text UI:
    WCAG 1.4.11, 3:1. Holding it to 4.5:1 crushes the usable lightness window
    from 0.169 to 0.054 and buys nothing.
  - no two Categories collapse under normal vision (dE-OK >= 0.05).
  - no two collapse CATASTROPHICALLY under any of the three dichromacies.

    That last one is a floor, deliberately, and not a target. Eleven categories
    cannot be made safe on hue for a dichromat: lowering chroma to let the glyph
    and the Sector labels dominate costs separation everywhere, and colour
    vision deficiency compounds it. Measured, the quiet ramp is better under
    deuteranopia and worse under protanopia and tritanopia than the Tailwind
    grab-bag it replaces. Gating on "no worse under all three" would therefore
    either fail forever or need a baseline generous enough to be meaningless.

    So the gate catches catastrophe — a pair that becomes literally the same
    colour — and the per-dichromacy counts are REPORTED rather than enforced.
    Identity is carried by the Maki glyph, which no dichromacy touches. See
    docs/design/map-legibility.md section 1.2.

A territorial division is deliberately out of scope. It is not drawn as a dot
anywhere any more — a hairline or a wash on the map, a ring in the list (#21,
#29) — so the seeded `#334155` reaches no surface and there is nothing here to
measure it against. It stays in the seed as inert data.

Usage:  python3 tools/ramp-check.py        (exit 0 = pass, 1 = fail)
"""
import math
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
SEED = REPO / 'lib' / 'Migration' / 'EnsureSeedData.php'
TAXONOMY_JS = REPO / 'src' / 'taxonomy.js'

WHITE = '#ffffff'
DARK_BG = '#171717'          # Nextcloud 34 dark theme main background
MIN_CONTRAST = 3.0           # WCAG 1.4.11, non-text
MIN_SEPARATION = 0.05        # dE-OK; below this two 18px dots read as one colour

# Two colours this close under a dichromacy are the same colour, not a tight
# pair. The palette shipping before this ramp bottomed out at dE 0.006 under
# TRITANOPIA — three pairs of it below this floor — which is what the floor
# exists to catch. It is not a target; see the module docstring.
CVD_CATASTROPHE_FLOOR = 0.015
DICHROMACIES = ('protanopia', 'deuteranopia', 'tritanopia')

# Where a spare is allowed to live. ADR-0013 fixes chroma at 0.090 and says not to
# raise it — raising it is the recurring wrong fix — and holds the ramp at the six
# hue families that C buys. So the only free variable in the search is lightness.
SPARE_CHROMA = 0.090
SPARE_LIGHTNESS_STEP = 0.005
NEUTRAL_CHROMA = 0.02        # below this a colour has no hue to speak of: `sin_clasificar`
                             # sits at 0.011 and must not be read as a seventh family
NEUTRALS = ('sin_clasificar', 'DEFAULT_SECTOR_COLOUR', 'UNKNOWN_COLOUR')
                             # neutral BY CONTRACT, not by measurement: ADR-0013's three
                             # greys. `NEUTRAL_CHROMA` is only the threshold that spots
                             # them, so the contract is gated rather than assumed.
RAMP_HUE_FAMILIES = 6        # ADR-0013: what chroma 0.090 buys for eleven Categories, and
                             # #40 puts opening a seventh out of scope
SPARE_CHROMA_TOLERANCE = 0.002   # 8-bit rounding: the seeded eleven land between 0.089 and 0.091
SPARE_HUE_TOLERANCE = 1.0        # degrees, same reason


def _to_linear(channel):
    return channel / 12.92 if channel <= 0.04045 else ((channel + 0.055) / 1.055) ** 2.4


def linear_rgb(hex_colour):
    hex_colour = hex_colour.lstrip('#')
    return [_to_linear(int(hex_colour[i:i + 2], 16) / 255) for i in (0, 2, 4)]


def _to_hex(linear):
    def encode(channel):
        channel = max(0.0, min(1.0, channel))
        srgb = 12.92 * channel if channel <= 0.0031308 else 1.055 * channel ** (1 / 2.4) - 0.055
        return round(max(0.0, min(1.0, srgb)) * 255)
    return '#%02x%02x%02x' % tuple(encode(c) for c in linear)


def relative_luminance(hex_colour):
    red, green, blue = linear_rgb(hex_colour)
    return 0.2126 * red + 0.7152 * green + 0.0722 * blue


def contrast(one, other):
    a, b = relative_luminance(one), relative_luminance(other)
    return (max(a, b) + 0.05) / (min(a, b) + 0.05)


def oklab(hex_colour):
    red, green, blue = linear_rgb(hex_colour)
    l = (0.4122214708 * red + 0.5363325363 * green + 0.0514459929 * blue) ** (1 / 3)
    m = (0.2119034982 * red + 0.6806995451 * green + 0.1073969566 * blue) ** (1 / 3)
    s = (0.0883024619 * red + 0.2817188376 * green + 0.6299787005 * blue) ** (1 / 3)
    return (0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s,
            1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s,
            0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s)


def separation(one, other):
    """dE-OK: Euclidean distance in OKLab."""
    return math.dist(oklab(one), oklab(other))


def oklch(hex_colour):
    """OKLab in polar form — (lightness, chroma, hue in degrees).

    ADR-0013's rules are written in these terms ("chroma 0.090", "six hue
    families"), so this is the form that can check them.
    """
    lightness, a, b = oklab(hex_colour)
    return lightness, math.hypot(a, b), math.degrees(math.atan2(b, a)) % 360


def from_oklch(lightness, chroma, hue):
    """The inverse of `oklab()`: an OKLCh coordinate back to a hex colour.

    Returns None outside sRGB rather than clamping, because a clamped channel
    quietly moves the colour off the chroma ADR-0013 pins it to, and the whole
    point of the search is that chroma is not negotiable.
    """
    a = chroma * math.cos(math.radians(hue))
    b = chroma * math.sin(math.radians(hue))
    l = (lightness + 0.3963377774 * a + 0.2158037573 * b) ** 3
    m = (lightness - 0.1055613458 * a - 0.0638541728 * b) ** 3
    s = (lightness - 0.0894841775 * a - 1.2914855480 * b) ** 3
    linear = [4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
              -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
              -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s]
    if min(linear) < 0 or max(linear) > 1:
        return None
    return _to_hex(linear)


def hue_families(colours):
    """The hue families the ramp already uses, read off the ramp itself.

    Derived rather than listed so that repainting a Category moves its family with
    it. That is all deriving buys: the ramp is held at ADR-0013's six by the count
    check in `spare_failures()`, because the palette this reads is in another file
    and a hue added there would otherwise open a seventh family in silence.

    Neutrals are excluded by chroma: `sin_clasificar` has no hue to be a family of.
    Which colours are allowed to be neutral is a contract, not a measurement, so
    `spare_failures()` gates that too — a tinted grey lands here as a family.
    """
    hues = sorted(hue for lightness, chroma, hue in map(oklch, colours) if chroma > NEUTRAL_CHROMA)
    # ponytail: clusters along a line rather than around the circle — no family
    # straddles 0 degrees today. Cluster modulo 360 if one ever does.
    families = []
    for hue in hues:
        if families and hue - families[-1][-1] < 15:
            families[-1].append(hue)
        else:
            families.append([hue])
    return [round(sum(family) / len(family)) for family in families]


def gate_failures(name, colour, others):
    """Everything a colour must clear to be a Category's colour, in one place.

    The same three rules the module docstring states: 3:1 against both backgrounds,
    dE-OK 0.05 under normal vision, and no catastrophic collapse under any of the
    three dichromacies. `others` is (name, colour) pairs.
    """
    failures = []
    for what, against in (('a white glyph and a white page', WHITE), ('the dark theme', DARK_BG)):
        ratio = contrast(colour, against)
        if ratio < MIN_CONTRAST:
            failures.append(f'{name} {colour}: {ratio:.2f}:1 vs {what} — needs {MIN_CONTRAST}:1')
    for other, other_colour in others:
        distance = separation(colour, other_colour)
        if distance < MIN_SEPARATION:
            failures.append(f'{name} {colour} is indistinguishable from {other} {other_colour}: '
                            f'dE {distance:.3f} < {MIN_SEPARATION}')
        for kind in DICHROMACIES:
            distance = separation(dichromat(colour, kind), dichromat(other_colour, kind))
            if distance < CVD_CATASTROPHE_FLOOR:
                failures.append(f'{kind}: {name} {colour} and {other} {other_colour} are the same '
                                f'colour — dE {distance:.3f} < {CVD_CATASTROPHE_FLOOR}')
    return failures


# ponytail: nothing exercises `derive_spares()` / `spare_failures()` on a schedule —
# their correctness was proven once by hand, by seeding each violation and watching
# `make lint` go red (see PR #43). The repo has no Python harness, and one is not worth
# standing up for two functions. The ceiling: if a later edit makes `spare_failures()`
# return nothing — the exact shape already caught once in this slice, where
# `'const SPARES' in source` was satisfied by `const SPARES_RENAMED` — lint stays green
# and nobody learns. Re-seed a violation by hand after touching either function.
def derive_spares(taken):
    """Every colour a Category added by a clinic could take (ADR-0017).

    Chroma where ADR-0013 fixes it, hue inside a family the ramp already uses, and
    clearing every gate a seeded Category clears — against the eleven, against both
    neutrals, and against the other spares, which is why this is greedy rather than
    a filter: each spare has to survive the ones already accepted.

    Walking lightness upwards takes the darkest value of each hue that still clears
    everything, and the step is what fixes the count: 0.005 yields the eight #40 and
    ADR-0017 record. A finer grid finds more only by packing them onto the dE 0.05
    floor exactly, which is the floor at which two dots start to read as one colour
    — not a place to sit a palette on purpose.
    """
    spares = []
    for hue in hue_families([colour for _, colour in taken]):
        steps = round(1 / SPARE_LIGHTNESS_STEP)
        for step in range(steps + 1):
            candidate = from_oklch(step * SPARE_LIGHTNESS_STEP, SPARE_CHROMA, hue)
            if candidate is None:
                continue
            if not gate_failures('candidate', candidate, taken + [(s, s) for s in spares]):
                spares.append(candidate)
    return spares


def dichromat(hex_colour, kind):
    """Vienot, Brettel & Mollon 1999, applied in linear RGB."""
    red, green, blue = linear_rgb(hex_colour)
    if kind == 'protanopia':
        red = 2.02344 * green - 2.52581 * blue
    elif kind == 'deuteranopia':
        green = 0.494207 * red + 1.24827 * blue
    else:
        blue = -0.395913 * red + 0.801109 * green
    return _to_hex([red, green, blue])


def seeded_categories():
    """slug -> hex for the eleven Categories, straight out of the PHP seed.

    The slice stops before `DIVISIONS_SLUG` so `RAMP_WAS`, which holds the OLD
    colours, is never mistaken for current ones. DIVISIONS itself falls inside
    that slice but does not match the regex, which wants a `'slug' => ['label'
    => …]` row — so it is excluded by shape. That exclusion is now deliberate:
    a territorial division is not a dot (#21), so its colour is not this check's
    business — see the module docstring.
    """
    source = SEED.read_text(encoding='utf-8')
    taxonomy = source.split('private const TAXONOMY')[1].split('public const DIVISIONS_SLUG')[0]
    found = re.findall(r"'(\w+)'\s*=>\s*\['label'\s*=>\s*'[^']*',\s*'color'\s*=>\s*'(#[0-9a-fA-F]{6})'",
                       taxonomy)
    return dict(found)


def javascript_colours():
    """The two colours the frontend owns, which no Category ever supplies.

    They belong in this check and not in a PHP test because what matters about
    them is perceptual distance, and this is the only place that can measure it.
    A PHP test asserting byte-inequality passes at dE 0.000001 — which is exactly
    how `#7d7d7d` shipped sitting dE 0.026 from `sin_clasificar`.
    """
    source = TAXONOMY_JS.read_text(encoding='utf-8')
    found = {}
    for name in ('DEFAULT_SECTOR_COLOUR', 'UNKNOWN_COLOUR'):
        match = re.search(name + r"\s*=\s*'(#[0-9a-fA-F]{6})'", source)
        if match is None:
            raise SystemExit(f'{name} not found in {TAXONOMY_JS}')
        found[name] = match.group(1)
    return found


def seeded_spares():
    """The spare colours the seed ships, straight out of the PHP, for the same
    reason the Categories are read rather than copied.

    Its own regex, and deliberately outside the slice `seeded_categories()` reads:
    `SPARES` sits after `DIVISIONS_SLUG` precisely so a bare `'#rrggbb'` in it is
    never mistaken for a Category row.

    Returns [] if the constant is not there at all — main() turns that into a
    failure rather than a vacuous pass, because an empty list satisfies every
    assertion below and would report OK while gating nothing.
    """
    block = re.search(r'const SPARES\s*=\s*\[(.*?)\]', SEED.read_text(encoding='utf-8'), re.S)
    if block is None:
        return []
    return re.findall(r"'(#[0-9a-fA-F]{6})'", block.group(1))


def spare_failures(spares, taken):
    """Every shipped spare still clears what it was derived under (ADR-0017).

    This is the half that turns a palette edit red: `taken` is the eleven and both
    neutrals, so moving a Category onto a spare's colour fails here, at the moment
    it is made, rather than the first time a clinic adds a Category.

    Pairs among the spares are checked once, not twice — separation is symmetric.
    """
    failures = []
    if not spares:
        failures.append('no spares found in the seed — SPARES or the regex reading it has drifted')

    # Everything below derives from "the ramp has six hue families and three greys that
    # are not families at all". Both halves are gated here rather than trusted, because
    # the colours come out of two other files: an edit there that opens a seventh family
    # would have the derivation quietly recommending colours ADR-0013 never decided on.
    families = hue_families([colour for _, colour in taken])
    if len(families) > RAMP_HUE_FAMILIES:
        failures.append(f'the palette now has {len(families)} hue families {families} — '
                        f'ADR-0013 holds the ramp at {RAMP_HUE_FAMILIES} and opens no seventh')
    for name, colour in taken:
        if name in NEUTRALS:
            chroma = oklch(colour)[1]
            if chroma >= NEUTRAL_CHROMA:
                failures.append(f'{name} {colour}: chroma {chroma:.3f} is not neutral '
                                f'(< {NEUTRAL_CHROMA}) — ADR-0013 keeps these three grey, and a '
                                'tinted one reads as a hue family the spares get derived inside')
    for index, spare in enumerate(spares):
        name = f'spare {index + 1}'
        others = list(taken) + [(f'spare {other + 1}', colour)
                                for other, colour in enumerate(spares) if other > index]
        failures += gate_failures(name, spare, others)

        lightness, chroma, hue = oklch(spare)
        if chroma > SPARE_CHROMA + SPARE_CHROMA_TOLERANCE:
            failures.append(f'{name} {spare}: chroma {chroma:.3f} is above {SPARE_CHROMA} — '
                            'ADR-0013 forbids raising it to make room')
        # What this asserts is narrower than the count check above: the spare sits AT a
        # family hue, within the rounding allowance. A repaint that moves a family by a
        # degree fails here without any seventh family existing, and the remedy is then
        # to re-derive SPARES — so the message says that rather than naming ADR-0013,
        # which only the count check is entitled to name.
        if min(abs(hue - family) for family in families) > SPARE_HUE_TOLERANCE:
            failures.append(f'{name} {spare}: hue {hue:.0f} is not at a family hue '
                            f'(families {families}) — re-derive SPARES from the current ramp')
    return failures


def main():
    ramp = seeded_categories()
    failures = []

    # Not "expected 11": a Category is editable data (CONTEXT.md), so a clinic
    # adding a twelfth must not fail lint. Two is the point at which "a ramp"
    # means anything.
    if len(ramp) < 2:
        failures.append(f'parsed {len(ramp)} Categories out of the seed — the regex has drifted')

    # The two loops below are a deliberate duplicate of `gate_failures()`: they keep
    # per-case wording that reads better for the eleven and the two neutrals. The cost
    # is that they are a second shape for "check a colour" — a rule added to one MUST be
    # added to the other, or spares and Categories quietly stop clearing the same gates,
    # which is the drift this whole check exists to prevent.
    #
    # White-glyph-on-fill and fill-on-white-page are the SAME inequality,
    # 1.05/(Y+0.05), so one lightness decision settles both and checking them
    # separately would report one broken colour as two findings.
    for slug, colour in sorted(ramp.items()):
        for what, against in (('a white glyph and a white page', WHITE), ('the dark theme', DARK_BG)):
            ratio = contrast(colour, against)
            if ratio < MIN_CONTRAST:
                failures.append(f'{slug} {colour}: {ratio:.2f}:1 vs {what} — needs {MIN_CONTRAST}:1')

    # The two frontend colours are held to the same separation bar as a Category,
    # because on screen they are dots exactly like one.
    everything = dict(ramp)
    for name, colour in javascript_colours().items():
        for what, against in (('a white glyph and a white page', WHITE), ('the dark theme', DARK_BG)):
            ratio = contrast(colour, against)
            if ratio < MIN_CONTRAST:
                failures.append(f'{name} {colour}: {ratio:.2f}:1 vs {what} — needs {MIN_CONTRAST}:1')
        for other, other_colour in sorted(everything.items()):
            distance = separation(colour, other_colour)
            if distance < MIN_SEPARATION:
                failures.append(f'{name} {colour} is indistinguishable from {other} '
                                f'{other_colour}: dE {distance:.3f} < {MIN_SEPARATION}')
        everything[name] = colour

    pairs = [(a, b) for i, a in enumerate(sorted(ramp)) for b in sorted(ramp)[i + 1:]]

    collapsed = [(a, b, separation(ramp[a], ramp[b])) for a, b in pairs
                 if separation(ramp[a], ramp[b]) < MIN_SEPARATION]
    for a, b, distance in collapsed:
        failures.append(f'{a} and {b} collapse: dE {distance:.3f} < {MIN_SEPARATION}')

    for kind in DICHROMACIES:
        distances = sorted((separation(dichromat(ramp[a], kind), dichromat(ramp[b], kind)), a, b)
                           for a, b in pairs)
        collapses = sum(1 for distance, _, _ in distances if distance < MIN_SEPARATION)
        for distance, a, b in distances:
            if distance < CVD_CATASTROPHE_FLOOR:
                failures.append(f'{kind}: {a} and {b} are the same colour — dE {distance:.3f} '
                                f'< {CVD_CATASTROPHE_FLOOR}')
        # Reported, not enforced: the glyph carries identity here, not the hue.
        print(f'  {kind:14} min dE {distances[0][0]:.3f}, {collapses}/{len(pairs)} pairs under '
              f'{MIN_SEPARATION} (reported, not gated)')

    distances = sorted(separation(ramp[a], ramp[b]) for a, b in pairs)
    print(f'  normal vision  {len(collapsed)}/{len(pairs)} collapsed, '
          f'min dE {distances[0]:.3f}, median {distances[len(distances) // 2]:.3f}')

    # The spares a Category added by a clinic can take (ADR-0017). Derived here and
    # shipped as data in the seed, because the alternative is a second implementation
    # of this arithmetic on the write path, in PHP, drifting silently from this one.
    taken = sorted(ramp.items()) + sorted(javascript_colours().items())
    spares = seeded_spares()
    failures += spare_failures(spares, taken)
    derived = derive_spares(taken)
    print(f'  spares         {len(spares)} shipped, {len(derived)} derivable: {" ".join(derived)}')
    # Derived and shipped differing is REPORTED, not gated. Fewer shipped than
    # derivable only means a clinic has fewer colours to be given; what must not
    # happen is a shipped spare that no longer clears the gates, and that is what
    # `spare_failures` above fails on.
    if sorted(derived) != sorted(spares):
        print('                 (shipped and derivable differ — see ADR-0017 before changing either)')

    if failures:
        print('\nFAIL', file=sys.stderr)
        for failure in failures:
            print(f'  - {failure}', file=sys.stderr)
        return 1

    print(f'\nOK — {len(ramp)} Categories, {len(pairs)} pairs, {len(spares)} spares checked')
    return 0


if __name__ == '__main__':
    sys.exit(main())
