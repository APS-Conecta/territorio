#!/usr/bin/env python3
"""Generate lib/Domain/ComunaExtentTable.php (red stage: the self-test only)."""
import hashlib
import os
import struct
import sys
import tempfile

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


if __name__ == '__main__':
    if sys.argv[1:] == ['--self-test']:
        self_test()
