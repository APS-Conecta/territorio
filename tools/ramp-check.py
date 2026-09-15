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


def main():
    ramp = seeded_categories()
    failures = []

    # Not "expected 11": a Category is editable data (CONTEXT.md), so a clinic
    # adding a twelfth must not fail lint. Two is the point at which "a ramp"
    # means anything.
    if len(ramp) < 2:
        failures.append(f'parsed {len(ramp)} Categories out of the seed — the regex has drifted')

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

    if failures:
        print('\nFAIL', file=sys.stderr)
        for failure in failures:
            print(f'  - {failure}', file=sys.stderr)
        return 1

    print(f'\nOK — {len(ramp)} Categories, {len(pairs)} pairs checked')
    return 0


if __name__ == '__main__':
    sys.exit(main())
