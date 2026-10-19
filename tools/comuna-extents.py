#!/usr/bin/env python3
"""Generate lib/Domain/ComunaExtentTable.php: where the map opens, per comuna.

  python3 -I tools/comuna-extents.py --zip DPA2023.zip --register establecimientos-deis.csv
  python3 -I tools/comuna-extents.py --zip ... --register ... --check
  python3 -I tools/comuna-extents.py --self-test

A maintainer runs this, never the app (ADR-0021). The app reads only the
committed table. Standard library only, because the machines this runs on have
no ogr2ogr, pyshp or shapely.

The ZIP is the Geoportal's DPA 2023 comuna boundaries, pinned by sha256 below
and in gestion's provisioning/data/packages.json (id geoportal-dpa-comunas-2023).
It is checked before a byte of it is read, and read in memory: nothing is
extracted, nothing from it is run.

Which part of a comuna? A comuna is one record with many rings (Natales has
15,772). Taking the largest part opens the map on the wrong land for Juan
Fernandez, Cabo de Hornos and Natales, where the town is not on it. So the box
is that of the part holding the most DEIS establishments, and of the largest
part when parts tie or none holds any (ADR-0021, "Where the map opens").

ponytail: a part is its outer ring. Holes are ignored, so a register point on
an island inside a lake counts for the part around the lake as well. No comuna
is shaped like that; revisit if one is.
"""
import argparse
import csv
import hashlib
import math
import os
import struct
import sys
import tempfile
import zipfile
from decimal import ROUND_CEILING, ROUND_FLOOR, Decimal
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
OUT = REPO / 'lib' / 'Domain' / 'ComunaExtentTable.php'
# comunas-deis.csv (gestion) minus 12202 Antartica and the 99999 sentinel, which
# the boundary file does not have. Committed here so no cross-repo path exists.
CUTS = REPO / 'tests' / 'fixtures' / 'comuna-cuts.txt'

ZIP_ID = 'geoportal-dpa-comunas-2023'
ZIP_ORIGIN = 'https://geoportal.cl/geoportal/catalog/download/912598ad-ac92-35f6-8045-098f214bd9c2'
ZIP_SHA256 = '8ac1ce872230b645c3cf04db2d980c1968e78f602144347f4513119dc9b945db'
SHP_MEMBER = 'COMUNAS/COMUNAS_v1.shp'
DBF_MEMBER = 'COMUNAS/COMUNAS_v1.dbf'
POLYGON = 5  # shapefile shape type

# Places people live that the register lacks, counted as one more establishment.
# The register is the primary-care trim of DEIS and holds nothing in Cabo de
# Hornos, so the rule would fall back to its largest part, Hoste island, while
# Puerto Williams is on Navarino. Add a place only when the rule picks uninhabited
# land and a register row cannot fix it. A place must be inside its part: the
# boundary is drawn at the waterline and the town's own coordinate falls just
# outside it, so this one is taken 700 m inland.
EXTRA_PLACES = {'12201': [(-67.6167, -54.9400)]}  # Puerto Williams


class Refused(Exception):
    """Input that is not what was pinned. Never repaired, never guessed."""


# ---- reading the inputs ---------------------------------------------------

def verify_sha256(path, expected):
    digest = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(1 << 20), b''):
            digest.update(chunk)
    if digest.hexdigest() != expected:
        raise Refused('%s is not the pinned file: sha256 %s, expected %s' % (path, digest.hexdigest(), expected))


def read_boundary_zip(path, expected=ZIP_SHA256):
    """The shapefile and its attribute table, after the hash and before anything else."""
    verify_sha256(path, expected)
    with zipfile.ZipFile(path) as z:
        return z.read(SHP_MEMBER), z.read(DBF_MEMBER)


def read_dbf(data):
    n, header_len, record_len = struct.unpack('<IHH', data[4:12])
    fields, at = [], 32
    while data[at] != 0x0D:
        fields.append((data[at:at + 11].rstrip(b'\0').decode(), data[at + 16]))
        at += 32
    if header_len + n * record_len > len(data):
        raise Refused('the attribute table is truncated')
    rows = []
    for k in range(n):
        record = data[header_len + k * record_len:header_len + (k + 1) * record_len]
        if record[:1] == b'*':
            raise Refused('the attribute table has a deleted record')
        row, at = {}, 1
        for name, length in fields:
            row[name] = record[at:at + length].decode('utf-8').strip()
            at += length
        rows.append(row)
    return rows


def read_shp(data):
    """One list of rings per record, each ring a list of (lon, lat)."""
    if len(data) < 100 or struct.unpack('>i', data[:4])[0] != 9994:
        raise Refused('not a shapefile')
    if struct.unpack('>i', data[24:28])[0] * 2 != len(data):
        raise Refused('the shapefile is truncated')
    pos, records = 100, []
    while pos < len(data):
        words = struct.unpack('>i', data[pos + 4:pos + 8])[0]
        body = data[pos + 8:pos + 8 + words * 2]
        pos += 8 + words * 2
        if struct.unpack('<i', body[:4])[0] != POLYGON:
            raise Refused('a record is not a polygon')
        n_parts, n_points = struct.unpack('<2i', body[36:44])
        starts = struct.unpack('<%di' % n_parts, body[44:44 + 4 * n_parts])
        at = 44 + 4 * n_parts
        flat = struct.unpack('<%dd' % (2 * n_points), body[at:at + 16 * n_points])
        ends = starts[1:] + (n_points,)
        records.append([list(zip(flat[2 * a:2 * b:2], flat[2 * a + 1:2 * b:2])) for a, b in zip(starts, ends)])
    return records


def read_register(path):
    """CUT -> [(lon, lat)] of the DEIS establishments, and how many had coordinates."""
    by_cut, count = {}, 0
    with open(path, encoding='utf-8', newline='') as f:
        for row in csv.DictReader(f):
            try:
                lon, lat = float(row['longitud']), float(row['latitud'])
            except ValueError:
                continue  # no coordinates: nothing to place
            if math.isfinite(lon) and math.isfinite(lat):
                by_cut.setdefault(row['comuna_codigo'].strip(), []).append((lon, lat))
                count += 1
    return by_cut, count


# ---- the part rule --------------------------------------------------------

def signed_area(ring):
    return sum(x1 * y2 - x2 * y1 for (x1, y1), (x2, y2) in zip(ring, ring[1:] + ring[:1])) / 2


def outer_rings(rings):
    """Clockwise rings are the parts; counter-clockwise ones are holes."""
    return [r for r in rings if signed_area(r) < 0]


def contains(ring, lon, lat):
    inside = False
    for (x1, y1), (x2, y2) in zip(ring, ring[1:] + ring[:1]):
        if (y1 > lat) != (y2 > lat) and lon < (x2 - x1) * (lat - y1) / (y2 - y1) + x1:
            inside = not inside
    return inside


def choose_part(parts, points):
    """The part with the most points; the larger one of any that tie, so also with none."""
    def key(ring):
        xs, ys = [p[0] for p in ring], [p[1] for p in ring]
        near = [p for p in points if min(xs) <= p[0] <= max(xs) and min(ys) <= p[1] <= max(ys)]
        return sum(contains(ring, *p) for p in near), abs(signed_area(ring))
    return max(parts, key=key)


def outward_box(ring):
    """(west, south, east, north), 3 decimals, rounded away from the part so it never clips it."""
    xs, ys = [p[0] for p in ring], [p[1] for p in ring]

    def to(value, rounding):
        return str(Decimal(repr(value)).quantize(Decimal('0.001'), rounding=rounding))
    return to(min(xs), ROUND_FLOOR), to(min(ys), ROUND_FLOOR), to(max(xs), ROUND_CEILING), to(max(ys), ROUND_CEILING)


def build_table(shp, dbf, cuts, points_by_cut, extra=EXTRA_PLACES):
    """CUT -> name, box of the chosen part, box of the plain largest part."""
    names, records = read_dbf(dbf), read_shp(shp)
    if len(names) != len(records):
        raise Refused('%d attribute rows for %d shapes' % (len(names), len(records)))
    table = {}
    for row, rings in zip(names, records):
        cut = row['CUT_COM']
        if cut in table:
            raise Refused('comuna %s appears twice' % cut)
        parts = outer_rings(rings)
        if not parts:
            raise Refused('comuna %s has no outer ring' % cut)
        table[cut] = {
            'name': row['COMUNA'],
            'box': outward_box(choose_part(parts, points_by_cut.get(cut, []) + extra.get(cut, []))),
            'largest_box': outward_box(max(parts, key=lambda r: abs(signed_area(r)))),
        }
    if sorted(table) != sorted(cuts):
        raise Refused('the boundary file and the CUT list differ: only in the file %s, only in the list %s'
                      % (sorted(set(table) - set(cuts)), sorted(set(cuts) - set(table))))
    return table


# ---- writing the table ----------------------------------------------------

def render(table, register_sha, register_count):
    rows = ''.join('\t\t%r => [%s], // %s\n' % (cut, ', '.join(table[cut]['box']), table[cut]['name'])
                   for cut in sorted(table))
    return '''<?php

declare(strict_types=1);

namespace OCA\\Territorio\\Domain;

/**
 * GENERATED by tools/comuna-extents.py. Do not edit; regenerate.
 *
 * Where the map opens for each comuna (ADR-0021): one box per CUT, which
 * ComunaExtent::forCut() reads. Data only; the judgement lives in ComunaExtent.
 *
 * Source:   %(id)s
 *           %(origin)s
 *           sha256 %(sha)s
 *           %(n)d records, one per comuna (12202 Antartica is not in the file)
 * CRS:      GCS_SIRGAS-Chile degrees, used as lon/lat. Its offset from WGS84 is
 *           under a metre, far below the 3-decimal rounding (about 100 m).
 * Part:     a comuna of several islands or pieces of land has several parts. The
 *           box is that of the part holding the most DEIS establishments, and of
 *           the largest part by planar area when parts tie or none holds any.
 *           Rounded outward to 3 decimals: west and south down, east and north up.
 * Register: DEIS establishments, sha256 %(rsha)s,
 *           %(rn)d with coordinates.
 * Columns:  [west, south, east, north]. BoundingBox takes (minLat, minLon,
 *           maxLat, maxLon), so ComunaExtent::of() is the one place that reorders.
 *
 * Regenerate: python3 -I tools/comuna-extents.py --zip PATH --register PATH
 * Verify:     the same command with --check exits 1 if this file would change.
 */
final class ComunaExtentTable {
\t/** @var array<string, array{float, float, float, float}> */
\tpublic const BY_CUT = [
%(rows)s\t];
}
''' % {'id': ZIP_ID, 'origin': ZIP_ORIGIN, 'sha': ZIP_SHA256, 'n': len(table),
       'rsha': register_sha, 'rn': register_count, 'rows': rows}


# ---- the self-test --------------------------------------------------------
# Synthetic shapefiles built with struct, so the parser, the part rule and the
# rounding are proved without the 108 MB file. The real file is proved by
# running the generator and --check.

def _square(x0, y0, x1, y1):
    """A clockwise ring: in ESRI shapefiles that is an outer ring."""
    return [(x0, y0), (x0, y1), (x1, y1), (x1, y0), (x0, y0)]


def _shp(records):
    body = b''
    for number, rings in enumerate(records, 1):
        points = [p for ring in rings for p in ring]
        starts, at = [], 0
        for ring in rings:
            starts.append(at)
            at += len(ring)
        xs, ys = [p[0] for p in points], [p[1] for p in points]
        content = (struct.pack('<i4d2i', 5, min(xs), min(ys), max(xs), max(ys), len(rings), len(points))
                   + struct.pack('<%di' % len(rings), *starts)
                   + b''.join(struct.pack('<2d', *p) for p in points))
        body += struct.pack('>2i', number, len(content) // 2) + content
    head = struct.pack('>7i', 9994, 0, 0, 0, 0, 0, (100 + len(body)) // 2) + struct.pack('<2i', 1000, 5)
    return head + bytes(64) + body


def _dbf(rows):
    fields = [(b'CUT_COM', 5), (b'COMUNA', 30)]
    head = struct.pack('<4xIHH20x', len(rows), 32 + 32 * len(fields) + 1, 1 + sum(n for _, n in fields))
    for name, length in fields:
        head += struct.pack('<11sc4xBB14x', name, b'C', length, 0)
    body = b''.join(b' ' + b''.join(v.ljust(n).encode() for v, (_, n) in zip(row, fields)) for row in rows)
    return head + b'\r' + body


def _expect_refusal(what, fn, *args):
    try:
        fn(*args)
    except Refused:
        return
    raise AssertionError(what + ': was not refused')


def self_test():
    big, small = _square(10, 10, 20, 20), _square(0, 0, 1, 1)
    hole = list(reversed(_square(12, 12, 14, 14)))      # counter-clockwise: not a part
    assert outer_rings([big, hole, small]) == [big, small], 'a hole is not a part'
    assert contains(big, 15, 15) and not contains(big, 25, 15), 'point in ring'

    # Two parts: the smaller one holds more register points and wins.
    assert choose_part([big, small], [(0.5, 0.5), (0.2, 0.2), (15, 15)]) is small, 'most points wins'
    # A tie falls back to the larger of the tied parts; so does no points at all.
    assert choose_part([small, big], [(0.5, 0.5), (15, 15)]) is big, 'tie goes to the larger'
    assert choose_part([small, big], []) is big, 'no points goes to the larger'
    assert choose_part([small, big], [(50, 50)]) is big, 'points in no part go to the larger'

    # Rounded OUTWARD to 3 decimals; a value already at 3 decimals does not move.
    assert outward_box(_square(-70.6924, -33.4786, -70.6246, -33.4261)) == \
        ('-70.693', '-33.479', '-70.624', '-33.426'), 'outward rounding'
    assert outward_box(_square(-70.692, -33.479, -70.625, -33.426)) == \
        ('-70.692', '-33.479', '-70.625', '-33.426'), 'exact values stay'

    # The parser, end to end: a three-comuna file with the register choosing the part.
    shp = _shp([[big, hole, small], [big, small], [small]])
    dbf = _dbf([('99001', 'Uno'), ('99002', 'Dos'), ('99003', 'Tres')])
    cuts = ['99001', '99002', '99003']
    table = build_table(shp, dbf, cuts, {'99001': [(0.5, 0.5), (0.2, 0.2)], '99002': [], '99003': [(0.5, 0.5)]})
    assert table['99001']['box'] == ('0.000', '0.000', '1.000', '1.000'), 'the part with the points'
    assert table['99001']['largest_box'] == ('10.000', '10.000', '20.000', '20.000'), 'plain largest kept for the report'
    assert table['99002']['box'] == ('10.000', '10.000', '20.000', '20.000'), 'no points: largest'
    assert table['99003']['box'] == ('0.000', '0.000', '1.000', '1.000'), 'single part'
    assert table['99001']['name'] == 'Uno'

    # A place the register lacks counts like a register row.
    extra = build_table(shp, dbf, cuts, {}, {'99002': [(0.5, 0.5)]})
    assert extra['99002']['box'] == ('0.000', '0.000', '1.000', '1.000'), 'extra place'

    # The CUT list is the authority: a missing or an extra comuna is refused.
    _expect_refusal('missing comuna', build_table, shp, dbf, cuts + ['99004'], {})
    _expect_refusal('extra comuna', build_table, shp, dbf, cuts[:2], {})
    _expect_refusal('duplicate comuna', build_table, _shp([[small], [small]]), _dbf([('99001', 'A'), ('99001', 'B')]), ['99001'], {})
    _expect_refusal('truncated shapefile', build_table, shp[:-9], dbf, cuts, {})

    # Deterministic, sorted, quoted keys, header facts present.
    text = render(table, 'f' * 64, 7)
    assert text == render(dict(reversed(list(table.items()))), 'f' * 64, 7), 'order of input does not matter'
    assert text.index("'99001'") < text.index("'99002'") < text.index("'99003'")
    assert "'99001' => [0.000, 0.000, 1.000, 1.000], // Uno" in text, 'row format'
    for fact in (ZIP_ID, ZIP_ORIGIN, ZIP_SHA256, '3 records', 'most DEIS establishments', 'python3 -I tools/comuna-extents.py'):
        assert fact in text, 'header lacks ' + fact

    # The sha is checked before anything is read: a file that is not even a ZIP
    # is refused for its hash, not for being unreadable.
    with tempfile.TemporaryDirectory() as tmp:
        path = os.path.join(tmp, 'x.zip')
        with open(path, 'wb') as f:
            f.write(b'not a zip')
        _expect_refusal('wrong sha', read_boundary_zip, path, '0' * 64)
        assert verify_sha256(path, hashlib.sha256(b'not a zip').hexdigest()) is None, 'right sha passes'
    print('self-test ok')


# ---- command line ---------------------------------------------------------

def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('--zip', help='the DPA 2023 ZIP, checked against the pinned sha256')
    ap.add_argument('--register', help='the DEIS establishments CSV (comuna_codigo, latitud, longitud)')
    ap.add_argument('--cuts', default=str(CUTS), help='the CUT list the boundary file must match')
    ap.add_argument('--out', default=str(OUT))
    ap.add_argument('--check', action='store_true', help='exit 1 if --out differs from what would be generated')
    ap.add_argument('--self-test', action='store_true')
    args = ap.parse_args()
    if args.self_test:
        return self_test()
    if not (args.zip and args.register):
        ap.error('--zip and --register are required')

    shp, dbf = read_boundary_zip(args.zip)
    by_cut, count = read_register(args.register)
    cuts = Path(args.cuts).read_text(encoding='utf-8').split()
    table = build_table(shp, dbf, cuts, by_cut)
    text = render(table, hashlib.sha256(Path(args.register).read_bytes()).hexdigest(), count)

    if args.check:
        if Path(args.out).read_text(encoding='utf-8') != text:
            print('%s is out of date: regenerate it' % args.out, file=sys.stderr)
            return 1
        print('%s is up to date (%d comunas)' % (args.out, len(table)))
        return 0
    Path(args.out).write_text(text, encoding='utf-8', newline='\n')
    print('wrote %s: %d comunas' % (args.out, len(table)))
    for cut in sorted(table):
        row = table[cut]
        if row['box'] != row['largest_box']:
            print('not the largest part: %s %s %s -> %s' % (cut, row['name'], row['largest_box'], row['box']))
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Refused as e:
        print('refused: %s' % e, file=sys.stderr)
        sys.exit(1)
