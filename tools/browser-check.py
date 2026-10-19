#!/usr/bin/env python3
"""Drive the running app the way a person would, and say what actually happened.

    make instance-up && make build && python3 tools/browser-check.py

This exists because nothing else in the repo touches the app. Three suites, a
webpack build and a two-axis review on every slice all passed for eight slices
while `Guardar` did nothing at all: `native-type` is the @nextcloud/vue 8 prop
name, so under 9 the button stayed a `type="button"` and never submitted its
form. No unit test loads a component, and webpack emits whatever it is given.

So this checks the two things only a browser can: that a Place typed into the
form reaches the database, and that a Sector drawn on the map does. It asserts
on the Registry, not on the DOM — a green screen with an empty table is exactly
the failure this is here to catch.

Runs headless against the throwaway dev instance (compose.dev.yaml, loopback
only, admin/dev). Screenshots land in tools/.browser-check/ for a human to look
at afterwards.

ponytail: one script, no framework, no page objects. It grows a function per
slice that has a screen; when that stops fitting, it wants a real suite.

A note on clicking. Positioned clicks on a map canvas are the least reliable
thing here: once a comuna's cadastre is loaded the whole view is polygons, and
what sits under a given pixel changes with the data. So anything that needs a
record to EXIST types its coordinate, and the behaviour of a click is checked on
its own, where it is the subject rather than a step on the way to something
else. That took this from passing two runs in three to nine of nine.
"""

import io
import json
import math
import re
import subprocess
import sys
import unicodedata
import struct
import uuid
import zipfile
import zlib
from contextlib import contextmanager
from pathlib import Path

from playwright.sync_api import TimeoutError as PlaywrightTimeout, sync_playwright

BASE = "http://localhost:8083"
USER, PASSWORD = "admin", "dev"
SHOTS = Path(__file__).parent / ".browser-check"

# Every run leaves its records behind — ADR-0005 means nothing here can be
# deleted, only retired — so each run tags its own. That is what lets the
# assertions be exact counts instead of "more than before".
#
# ponytail: two undeletable records per run, forever. Fine on a throwaway
# instance you can `make instance-clean`; if this ever runs somewhere that must
# persist, it needs to retire what it made on the way out.
RUN = uuid.uuid4().hex[:6]
# The redesign check's own token, so what it seeds is not found by the later checks
# that search the list for RUN and count exactly this run's records.
REDESIGN = uuid.uuid4().hex[:6]

# `--only redesign` runs the topbar check alone, which is the quick one, and
# `--only extent` the opening-view check alone and `--only tiles` the basemap checks
# (the topbar check seeds the records they search for). The rest
# drives the docked panel: `to_list()` puts every check back on the list, and the
# helpers above it (`open_first_row`, `edit`, `open_capas`, `toggle_division`)
# are the new layout's way to what the old sidebar and Capas column showed at once.
ONLY = None
if "--only" in sys.argv:
    ONLY = sys.argv[sys.argv.index("--only") + 1:][:1] and sys.argv[sys.argv.index("--only") + 1]
    if ONLY not in ("redesign", "extent", "tiles"):
        sys.exit("usage: browser-check.py [--only redesign|extent|tiles]")

# What a GeoJSON written WITHOUT contact fields is marked with, so the check can
# tell a partial export from a complete one. Kept in step with
# lib/Service/ContactFields.php by the test that reads it there.
ContactFieldsPartial = "territorio_sin_contactos"

# Checks that could not run, for the summary. A skip is not a pass: this gate
# exists because three green suites hid a save button that did nothing, and a
# green line hiding "the polygonize checks never ran" would be the same mistake
# in a smaller way.
SKIPPED = []


def skip(reason):
    SKIPPED.append(reason)
    print(f"SKIP  {reason}")

# Where this run clicks, nudged so it never lands on what the last run left.
# Clicking a marker selects that record and clicking inside a polygon selects
# that Sector — both replace the open draft, so a fixed spot means every run
# after the first silently edits the previous run's record instead of creating
# its own. That is the app behaving correctly and the check being naive.
NUDGE = int(RUN, 16) % 120

# Our own list, not Nextcloud's: the header carries a "Buscar contactos" field
# and a menu full of list items, and unscoped selectors find those first.
ROWS = ".app-content-list .list-item"
MARKERS = ".leaflet-marker-icon"
SEARCH = ".territorio-list__search input"
# The docked panel covers the map's left 408px and the topbar its top ~110px, so
# a click placed there lands on them. Canvas positions below start clear of both.
MAP_CLEAR = {"x": 480, "y": 160}

# The Índice (#105): the fourth pane, and its own table. Addressed by
# `data-key` rather than by column position, because the whole point of the pane
# is that columns can be taken away.
INDICE = '[data-testid="indice-table"]'
INDICE_COLUMNS = ".territorio-indice__columns"

# The Capas panel's own rename affordance (#41): the pencil per row, and the
# field it turns the label into.
CAPAS_RENAME = ".territorio-capas__rename"
CAPAS_REMOVE = ".territorio-capas__remove"
CAPAS_INPUT = ".territorio-capas__input"


def dismiss_first_run_wizard():
    """Nextcloud's welcome modal intercepts every click, so nothing below works.

    This gate could not pass on a FRESH instance at all: the wizard opens a modal
    whose overlay swallows pointer events, so Playwright retried the very first
    click 55 times and the run died in a wall of pointer-event noise naming the
    wizard rather than the app. It was only ever green on an instance somebody had
    already clicked through by hand, which is the worst shape a gate can have.

    Here rather than in `make instance-up`, so the gate establishes its own
    precondition: `make test-browser` has no prerequisite and is the one command a
    developer runs against an instance that is already up.

    Disabling the app is not enough on its own — the per-user flag is what the
    modal reads — and neither call is fatal: a Nextcloud without the app, or a
    container that is not running, both fail later and more honestly than here.
    """
    container = ["docker", "compose", "-f", "compose.dev.yaml", "exec", "-T",
                 "-u", "www-data", "nextcloud", "php", "occ"]
    for args in (["app:disable", "firstrunwizard"],
                 ["user:setting", USER, "firstrunwizard", "show", "0"]):
        try:
            subprocess.run([*container, *args], capture_output=True, timeout=120)
        except (subprocess.SubprocessError, OSError):
            return


def log_in(page):
    dismiss_first_run_wizard()
    page.goto(f"{BASE}/apps/territorio/", wait_until="domcontentloaded")
    if "/login" not in page.url:
        return
    # Nextcloud's login form is Vue-rendered, so wait for the field rather than
    # the load event.
    page.wait_for_selector("#user", timeout=30_000)
    page.fill("#user", USER)
    page.fill("#password", PASSWORD)
    page.click('button[type="submit"]')
    page.wait_for_url(f"{BASE}/apps/territorio/**", timeout=30_000)


def console_errors(page):
    """Anything the app logged that a person would never see.

    Stacks, not just messages: "Cannot read properties of null" names a symptom
    and could come from anywhere, and a gate that reports only that sends
    somebody guessing.
    """
    found = []
    page.on("console", lambda m: found.append(m.text) if m.type == "error" else None)
    page.on("pageerror", lambda e: found.append((e.stack or str(e))[:2000]))
    return found


def served_basemap(page):
    """The basemap this page was served, decoded the way `loadState` decodes it,
    or None when the page carries none.

    Off the page already open, not a fresh fetch like served_taxonomy(): the
    basemap is fixed for a page's life, and the question every caller asks is
    what THIS page was told — what the map should have painted, and which one
    host besides this server the page may ask for anything.
    """
    return page.evaluate(
        """() => {
            const element = document.getElementById('initial-state-territorio-basemap')
            return element === null ? null : JSON.parse(atob(element.value))
        }""",
    )


# OpenStreetMap's tile service. Its Tile Usage Policy forbids scraping, and a gate that
# loads real tiles is an automated client of that service on every run, so the gate never
# reaches it: every request to the host is answered here, and anything else addressed to
# openstreetmap.org is refused.
OSM_HOST = re.compile(r"openstreetmap\.org")
OSM_TILE = re.compile(r"^https://tile\.openstreetmap\.org/\d+/\d+/\d+\.png$")

# OSM's land colour and a one-pixel grid in a slightly darker one, so a tile that painted
# has more than one colour and the seams between tiles are visible in a screenshot.
OSM_LAND = (0xF2, 0xEF, 0xE9)
OSM_GRID = (0xD9, 0xD4, 0xCC)


def osm_tile_png():
    """A 256 x 256 PNG tile in OpenStreetMap's land colour, encoded with the standard library."""
    def chunk(kind, data):
        body = kind + data
        return struct.pack(">I", len(data)) + body + struct.pack(">I", zlib.crc32(body))

    rows = b"".join(
        b"\x00" + b"".join(bytes(OSM_GRID if x == 0 or y == 0 else OSM_LAND) for x in range(256))
        for y in range(256)
    )
    header = struct.pack(">IIBBBBB", 256, 256, 8, 2, 0, 0, 0)

    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", header)
            + chunk(b"IDAT", zlib.compress(rows)) + chunk(b"IEND", b""))


class OsmTiles(list):
    """The tile requests the mock has answered, oldest first, and how it answers the next.

    `mode` is "ok" (a tile), "down" (503, the way an overloaded or firewalled host fails) or
    "blocked" (403 carrying a tile-shaped image, which is how OpenStreetMap answers a blocked
    client: a browser loads it without an error, so the app cannot tell).
    """

    mode = "ok"
    # URLs addressed to openstreetmap.org that were NOT a bare tile request (a query string,
    # plain http, another path). The app must never send one, and a check that only looked at
    # the answered requests could not see it: the refusal drops them before they are counted.
    refused = None

    def __init__(self):
        super().__init__()
        self.refused = []


def mock_osm(context):
    """Answer OpenStreetMap's tile requests with a generated tile; return the requests seen.

    Playwright runs the LAST registered route that matches, so the refusal goes first and
    the tile answer second. Refusing everything else on the host means a link to the
    copyright page, or a tile URL that stopped matching, fails loudly instead of leaving
    the machine.
    """
    tiles = OsmTiles()
    png = osm_tile_png()

    def answer(route):
        tiles.append(route.request)
        if tiles.mode == "down":
            route.fulfill(status=503, body="")
        else:
            route.fulfill(status=403 if tiles.mode == "blocked" else 200,
                          content_type="image/png", body=png,
                          headers={"cache-control": "max-age=3600"})

    def refuse(route):
        tiles.refused.append(route.request.url)
        route.abort()

    context.route(OSM_HOST, refuse)
    context.route(OSM_TILE, answer)

    return tiles


# True once one tile OpenStreetMap's service answered has loaded: a raster tile is an <img>,
# and Leaflet creates the element before the request has come back, so paint is a tile with
# width and not a tile element.
TILE_PAINTED = """() => [...document.querySelectorAll('img.leaflet-tile')].some((tile) =>
    tile.src.startsWith('https://tile.openstreetmap.org/') && tile.complete && tile.naturalWidth > 0)"""


def the_basemap_is_painted(page, failures):
    """OpenStreetMap's tiles draw, credited as served.

    The served state is compared with what the map painted, the way
    `every_category_reaches_the_browser_with_its_colour` does. A raster tile is an <img>
    that has loaded, so paint is a tile with width, not a tile element: Leaflet creates
    the element before the request has come back.

    Failed, not skipped, on an instance serving anything else: this gate answers tile
    requests from a mock of OpenStreetMap's service, so another URL would reach a host
    the mock refuses. `make instance-up` clears what an earlier run pinned.
    """
    served = served_basemap(page)
    if served is None:
        failures.append(
            "the page carries no served basemap at #initial-state-territorio-basemap, so "
            "nothing could be compared with what the map painted",
        )
        return

    if not served["usesOpenStreetMap"]:
        failures.append(
            f"the instance serves a {served['kind']} basemap ({served['url']}), not "
            f"OpenStreetMap's tiles — `make instance-up` clears a tile setting an earlier run pinned",
        )
        return

    try:
        page.wait_for_function(TILE_PAINTED, timeout=10_000, polling=500)
    except PlaywrightTimeout:
        failures.append(
            f"no tile painted within 10 s from {served['url']} — the page asked for tiles the "
            f"gate's mock did not answer, or its CSP refused them",
        )

    attribution = page.locator(".leaflet-control-attribution")
    painted = attribution.first.inner_text() if attribution.count() > 0 else ""
    if served["attribution"] not in painted:
        failures.append(
            f"the map credits {painted!r} but the app served {served['attribution']!r}",
        )

    if page.get_by_text("No se pudo cargar el fondo de mapa").count() > 0:
        failures.append(
            "the map says «No se pudo cargar el fondo de mapa» over a basemap that painted",
        )

    page.screenshot(path=str(SHOTS / "basemap.png"), full_page=False)


OSM_TILE_PREFIX = "https://tile.openstreetmap.org/"
OSM_BANNER = "No se pudo cargar el fondo de mapa"


def _settled_tile_requests(page, tiles):
    """Reload, wait for a tile to paint and the view to settle, and return the requests since."""
    first = len(tiles)
    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_function(TILE_PAINTED, timeout=10_000, polling=500)
    page.wait_for_timeout(1500)

    return list(tiles[first:])


def the_tiles_follow_the_policy(page, tiles, failures):
    """OpenStreetMap's Tile Usage Policy, asserted on what the browser actually sent (ADR-0021).

    https://operations.osmfoundation.org/policies/tiles/ — the clauses a browser can break:
    the exact HTTPS URL with nothing appended (a cache-busting parameter turns every request
    into an origin hit), a valid Referer (Nextcloud sends `Referrer-Policy: no-referrer`, and
    without an override the tile requests carry none, which is the profile OpenStreetMap
    blocks), and no scan: one screen is at most 54 tiles, so a settled load asking for far
    more is fetching what nobody is looking at. The page may also only be allowed to load
    images from the tile host, and not from `*`.

    The Cache-Control a request carries is NOT asserted: Playwright's routing makes Chromium
    add `no-cache` to every request it intercepts, so that check would fail by construction.
    """
    refused_before = len(tiles.refused)
    try:
        requests = _settled_tile_requests(page, tiles)
    except PlaywrightTimeout:
        failures.append("no tile painted after a reload, so the tile policy could not be checked")
        return

    refused = tiles.refused[refused_before:]
    if refused:
        failures.append(
            f"{len(refused)} request(s) to openstreetmap.org were not a bare https tile request, "
            f"e.g. {refused[0]!r}",
        )

    if not requests:
        failures.append("a settled load asked for no tile, so the tile policy could not be checked")
        return

    odd = [r.url for r in requests if "?" in r.url or not r.url.startswith(OSM_TILE_PREFIX)]
    if odd:
        failures.append(f"{len(odd)} tile request(s) were not the bare https tile URL, e.g. {odd[0]!r}")

    origin = BASE + "/"
    no_referer = [r.url for r in requests if r.headers.get("referer") != origin]
    if no_referer:
        failures.append(
            f"{len(no_referer)} of {len(requests)} tile requests did not carry Referer {origin!r}, "
            f"e.g. {no_referer[0]!r}",
        )

    if len(requests) > 80:
        failures.append(
            f"a settled load asked for {len(requests)} tiles: one screen needs at most 54, and the "
            f"limit here is 80 to allow for the view settling",
        )

    policy = page.request.get(f"{BASE}/apps/territorio/").headers.get("content-security-policy", "")
    images = re.search(r"img-src ([^;]*)", policy)
    sources = images.group(1).split() if images else []
    if "https://tile.openstreetmap.org" not in sources:
        failures.append(f"the page's img-src {sources} does not name the tile host")
    if {"*", "http:", "https:"} & set(sources):
        failures.append(f"the page's img-src {sources} lets images load from anywhere")


# Where the attribution has to stay readable: the widest desktop, the breakpoint, the width at
# which the docked panel and the credit meet, and the two phones. Each with the panel open and
# collapsed, in both themes. The surfaces are OpenStreetMap's land, water and park colours,
# which are what the credit sits on.
ATTRIBUTION_VIEWPORTS = [(1400, 900), (810, 900), (640, 900), (360, 740), (320, 568)]
OSM_SURFACES = {"land": (0xF2, 0xEF, 0xE9), "water": (0xAA, 0xD3, 0xDF), "park": (0xCD, 0xEB, 0xB0)}
ATTRIBUTION_LINK = '.leaflet-control-attribution a[href="https://www.openstreetmap.org/copyright"]'
ATTRIBUTION_READING = """() => {
    const link = document.querySelector('""" + ATTRIBUTION_LINK + """')
    if (link === null) return null
    const control = link.closest('.leaflet-control-attribution')
    const r = link.getBoundingClientRect()
    const points = [[r.left + r.width / 2, r.top + r.height / 2], [r.left + 1, r.top + 1],
                    [r.right - 1, r.top + 1], [r.left + 1, r.bottom - 1], [r.right - 1, r.bottom - 1]]
    const covered = points.filter(([x, y]) => {
        const hit = document.elementFromPoint(x, y)
        return !(hit && control.contains(hit))
    }).length
    const filtered = []
    for (let e = link; e && e !== document.documentElement; e = e.parentElement) {
        const c = getComputedStyle(e)
        if (c.filter !== 'none' || Number(c.opacity) < 1 || c.mixBlendMode !== 'normal') {
            filtered.push(e.className || e.tagName)
        }
    }
    const text = getComputedStyle(link)
    return {
        left: r.left, top: r.top, right: r.right, bottom: r.bottom, height: r.height,
        width: innerWidth, viewportHeight: innerHeight, covered, filtered,
        fontSize: parseFloat(text.fontSize), colour: text.color,
        background: getComputedStyle(control).backgroundColor,
        hidden: text.visibility !== 'visible' || text.display === 'none',
    }
}"""


def _composite(background, surface):
    """`rgba(r, g, b, a)` laid over an (r, g, b) surface, as an `rgb(r, g, b)` string."""
    numbers = [float(n) for n in re.findall(r"[\d.]+", background)]
    alpha = numbers[3] if len(numbers) > 3 else 1.0
    mixed = [round(alpha * numbers[i] + (1 - alpha) * surface[i]) for i in range(3)]

    return f"rgb({mixed[0]}, {mixed[1]}, {mixed[2]})"


def the_attribution_is_visible(page, failures):
    """The OpenStreetMap credit is on screen, uncovered and readable (Tile Usage Policy).

    The policy: attribution shown clearly, "not hidden beneath UI, behind toggles, or
    off-screen". The docked panel is a left-hand overlay and the credit sits bottom-right,
    so on a narrow screen the panel covered most of it. Measured on what is rendered: the
    link is inside the viewport, nothing sits over any of five points of it, no ancestor
    dims or filters it, its text is at least 11 px, and it keeps a 4.5:1 contrast over each
    of OpenStreetMap's land, water and park colours. Light and dark theme, five viewports,
    panel open and collapsed.
    """
    toggle = page.locator(".territorio-docked-panel__toggle")
    problems = []
    for scheme in ("light", "dark"):
        page.emulate_media(color_scheme=scheme)
        for width, height in ATTRIBUTION_VIEWPORTS:
            page.set_viewport_size({"width": width, "height": height})
            for panel in ("open", "collapsed"):
                if (toggle.get_attribute("aria-expanded") == "true") != (panel == "open"):
                    toggle.click()
                    page.wait_for_timeout(450)

                where = f"{width}x{height}, panel {panel}, {scheme} theme"
                reading = page.evaluate(ATTRIBUTION_READING)
                if reading is None:
                    problems.append(f"{where}: no link to openstreetmap.org/copyright")
                    continue
                if reading["hidden"] or reading["left"] < 0 or reading["top"] < 0 \
                        or reading["right"] > reading["width"] or reading["bottom"] > reading["viewportHeight"]:
                    problems.append(f"{where}: the credit is hidden or off screen")
                if reading["covered"]:
                    problems.append(f"{where}: something sits over {reading['covered']} of 5 points of the credit")
                if reading["filtered"]:
                    problems.append(f"{where}: an ancestor filters or dims it: {reading['filtered']}")
                if reading["fontSize"] < 11:
                    problems.append(f"{where}: the credit is {reading['fontSize']}px, under 11px")
                for surface, rgb in OSM_SURFACES.items():
                    ratio = _contrast(reading["colour"], _composite(reading["background"], rgb))
                    if ratio < 4.5:
                        problems.append(f"{where}: contrast {ratio:.2f}:1 over {surface}, under 4.5:1")
                if width == 360 and panel == "open" and scheme == "light":
                    page.screenshot(path=str(SHOTS / "attribution-360.png"), full_page=False)

    page.emulate_media(color_scheme="no-override")
    page.set_viewport_size({"width": 1400, "height": 900})
    if (toggle.get_attribute("aria-expanded") == "true") is False:
        toggle.click()
        page.wait_for_timeout(450)
    for line in problems[:6]:
        failures.append(line)
    if len(problems) > 6:
        failures.append(f"...and {len(problems) - 6} more attribution problems")


def the_search_flies_without_a_tile_storm(page, tiles, failures):
    """Picking a search result flies the map there without asking for tiles on the way.

    `flyTo` ignores `zoomAnimation: false`, and with Leaflet's defaults it asked for tiles at
    every zoom it passed through: measured 255 requests for a jump from zoom 12 to 17, the
    shape of a scan to anyone watching the tile service. With `updateWhenZooming: false` it
    asks for the destination only (65).
    """
    place = f"Cesfam Rediseño {REDESIGN}"
    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector(OMNIBOX_INPUT, timeout=15_000)
    page.wait_for_function(TILE_PAINTED, timeout=10_000, polling=500)
    page.wait_for_timeout(1000)
    first = len(tiles)
    titles, problem = _omnibox_pick(page, f"Rediseño {REDESIGN}", place)
    if problem:
        failures.append(f"flying to a search result: {problem} (rows: {titles})")
        return

    page.wait_for_timeout(3500)
    asked = [corners for corners in (_tile_corners(r.url) for r in tiles[first:]) if corners is not None]
    if not asked:
        failures.append("flying to a search result asked for no tile, so no flight was measured")
        return

    zooms = sorted({corners[0] for corners in asked})
    if len(asked) > 90:
        failures.append(
            f"flying to a search result asked for {len(asked)} tiles: it needs about one screen "
            f"(at most 54), and the limit here is 90 to allow for the view settling",
        )
    if len(zooms) > 2:
        failures.append(f"flying to a search result asked for tiles at zooms {zooms}, not only where it landed")


@contextmanager
def expected_console_errors(errors, status):
    """Forgive the console errors a check causes ON PURPOSE by making the tile service answer `status`.

    Chromium logs «Failed to load resource: the server responded with a status of 503» for every
    tile it was refused, and this gate fails a run on any console error. The check below
    breaks the tile service deliberately; only that status, only while it does, is dropped.
    """
    mark = len(errors)
    try:
        yield
    finally:
        errors[mark:] = [line for line in errors[mark:] if f"status of {status}" not in line]


def the_map_survives_its_tiles_failing_and_recovers(page, tiles, errors, failures):
    with expected_console_errors(errors, 503):
        _the_map_survives_its_tiles_failing_and_recovers(page, tiles, failures)


def _the_map_survives_its_tiles_failing_and_recovers(page, tiles, failures):
    """With the tile service down the app still works, asks politely, and recovers on its own.

    ADR-0004: a basemap that fails is a note, never a broken map. Down means 503 to every
    tile. The banner appears, the search still moves the map, and the retries stay inside the
    page's budget of 16 (measured before the budget: 170 requests in 24 seconds from a
    clinic's page against a host that was already refusing it). When the host comes back a
    pan asks for new tiles and the banner clears without a reload.
    """
    banner = page.get_by_text(OSM_BANNER)
    tiles.mode = "down"
    try:
        first = len(tiles)
        page.reload(wait_until="domcontentloaded")
        page.wait_for_selector(OMNIBOX_INPUT, timeout=15_000)
        banner.first.wait_for(timeout=20_000)
    except PlaywrightTimeout:
        failures.append("with the tile service down, the page showed no note that the basemap could not load")
        tiles.mode = "ok"
        return

    # The ladder is 1 s, 4 s and 12 s: wait it out, then count what it cost.
    page.wait_for_timeout(18_000)
    sent = [r.url for r in tiles[first:]]
    retries = len(sent) - len(set(sent))
    if retries > 16:
        failures.append(f"with the tile service down the page repeated {retries} tile requests; its budget is 16")

    place = f"Cesfam Rediseño {REDESIGN}"
    titles, problem = _omnibox_pick(page, f"Rediseño {REDESIGN}", place)
    if problem:
        failures.append(f"with the tile service down the search stopped working: {problem} (rows: {titles})")

    tiles.mode = "ok"
    page.mouse.move(700, 450)
    page.mouse.down()
    page.mouse.move(300, 450, steps=12)
    page.mouse.up()
    try:
        page.wait_for_function(TILE_PAINTED, timeout=10_000, polling=500)
        banner.first.wait_for(state="hidden", timeout=10_000)
    except PlaywrightTimeout:
        failures.append("when the tile service came back, a pan did not bring the basemap back")

    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector(OMNIBOX_INPUT, timeout=15_000)


def the_blocked_basemap_is_invisible_to_the_app(page, tiles, errors, failures):
    with expected_console_errors(errors, 403):
        _the_blocked_basemap_is_invisible_to_the_app(page, tiles, failures)


def _the_blocked_basemap_is_invisible_to_the_app(page, tiles, failures):
    """A block by OpenStreetMap looks like a working map to the app, and this pins that.

    OpenStreetMap answers a blocked client with a 403 that carries an image. A browser loads
    an <img> whatever the status says, so Leaflet fires `tileload`, never `tileerror`: no
    banner, no retry, and a basemap that shows an 'access blocked' picture. The runbook says
    so (ADR-0021); this fails the day that stops being true, which would be the day the app
    could be taught to notice.
    """
    tiles.mode = "blocked"
    try:
        _settled_tile_requests(page, tiles)
    except PlaywrightTimeout:
        failures.append("a 403 that carries an image did not load as a tile")
        tiles.mode = "ok"
        return

    if page.get_by_text(OSM_BANNER).count() > 0:
        failures.append(
            "the app noticed a 403 that carries an image, which the runbook says it cannot: "
            "update the ADR-0021 runbook note before this assertion",
        )

    tiles.mode = "ok"
    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector(OMNIBOX_INPUT, timeout=15_000)


def the_instance_starts_without_a_comuna(page, failures):
    """Whether the instance is the unprovisioned one the gate assumes; says so when it is not.

    A chosen Comuna opens the map on its extent (ADR-0021) and every check below was written
    for the map opening on the records it seeds. Nothing in the app can unchoose a Comuna, and
    the extent check leaves one chosen, so a second run on the same instance would fail
    somewhere far from the cause. Better to stop here and name it.
    """
    chosen = page.evaluate(
        """() => {
            const element = document.getElementById('initial-state-territorio-comuna')
            return element === null ? null : JSON.parse(atob(element.value)).chosen
        }""",
    )
    if chosen is None:
        failures.append("the page carries no comuna at #initial-state-territorio-comuna")
    elif chosen:
        failures.append(
            "the instance already has a Comuna chosen, which opens the map somewhere these checks "
            "do not expect: start from a clean one with `make instance-clean && make instance-up`",
        )

    return chosen is False


# Concepción (CUT 08101) and the Comuna extent the app's table holds for it, as
# [[south, west], [north, east]]. Chosen because it is far from Santiago: a map that still
# opened on the old hard-coded view, or on the imported Unidades Vecinales, would be
# looking at the wrong city, and so the check could not pass by luck. The table is
# outward-rounded to 3 decimals, so the check asks only that the view CONTAINS this box's
# centre, never that it equals it.
CONCEPCION = ("08101", "Concepción", (-36.928, -73.092), (-36.764, -72.844))


def _tile_corners(url):
    """The (zoom, west, south, east, north) of an OSM tile URL, or None for any other URL."""
    found = re.search(r"/(\d+)/(\d+)/(\d+)\.png$", url)
    if found is None:
        return None
    z, x, y = (int(group) for group in found.groups())
    n = 2 ** z

    def lat(row):
        return math.degrees(math.atan(math.sinh(math.pi * (1 - 2 * row / n))))

    return z, x / n * 360 - 180, lat(y + 1), (x + 1) / n * 360 - 180, lat(y)


def the_map_opens_on_the_comuna_extent(page, tiles, failures):
    """With a Comuna chosen, the map opens on its extent (ADR-0021).

    Asserted on what the browser asked the tile service for, because that is the one
    thing a map that opened in the right place and one that merely looks right cannot
    fake: the tiles at the opening zoom must cover the extent's centre, and that zoom
    must be one that frames a comuna (a view of the whole country, or of one block,
    would both be wrong).

    The comuna is chosen the way an administrator chooses it, through the settings
    endpoint, because that write lands in the web process itself. `occ config:app:set`
    would not: APCu is per-process (README, "Two traps"), so the page would keep
    serving the old value. Nothing can unchoose a Comuna, so this is last in the full run
    and the next run must start from a clean instance, which
    `the_instance_starts_without_a_comuna` insists on.
    """
    cut, name, (south, west), (north, east) = CONCEPCION
    token = page.evaluate("() => document.head.dataset.requesttoken")
    saved = page.request.put(
        f"{BASE}/apps/territorio/settings/comuna",
        data=json.dumps({"cut": cut, "name": name}),
        headers={"requesttoken": token, "content-type": "application/json"},
    )
    if not saved.ok:
        failures.append(f"choosing the comuna {cut} through the settings endpoint answered {saved.status}")
        return

    first = len(tiles)
    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    try:
        page.wait_for_function(TILE_PAINTED, timeout=10_000, polling=500)
    except PlaywrightTimeout:
        failures.append("no tile painted after the page opened with a comuna chosen")
        return
    page.wait_for_timeout(1500)

    opened = [corners for corners in (_tile_corners(request.url) for request in tiles[first:])
              if corners is not None]
    if not opened:
        failures.append("the page opened with a comuna chosen and asked for no tile")
        return

    zoom = max(corners[0] for corners in opened)
    at_zoom = [corners for corners in opened if corners[0] == zoom]
    covered = (min(c[1] for c in at_zoom), min(c[2] for c in at_zoom),
               max(c[3] for c in at_zoom), max(c[4] for c in at_zoom))
    centre = ((south + north) / 2, (west + east) / 2)

    if not 11 <= zoom <= 15:
        failures.append(
            f"the map opened at zoom {zoom} on {name}, whose extent is "
            f"{north - south:.2f} x {east - west:.2f} degrees: a comuna frames at zoom 11 to 15",
        )
    if not (covered[0] <= centre[1] <= covered[2] and covered[1] <= centre[0] <= covered[3]):
        failures.append(
            f"the tiles the map opened on cover lon {covered[0]:.3f}..{covered[2]:.3f}, "
            f"lat {covered[1]:.3f}..{covered[3]:.3f}, which does not contain {name}'s "
            f"centre {centre[1]:.3f}, {centre[0]:.3f}",
        )


# What the gate wraps to count the GPS. `navigator.geolocation` is what Leaflet's
# `locate({ watch: true })` subscribes to, and a watch nobody clears is a
# subscription the browser keeps. Installed on the page once, before the app is
# re-entered, so every document from then on carries the counters.
GEOLOCATION_COUNTER = """
(() => {
    const geolocation = navigator.geolocation
    const watch = geolocation.watchPosition.bind(geolocation)
    const clear = geolocation.clearWatch.bind(geolocation)
    window.__territorioGeolocation = { watch: 0, clear: 0, codes: [] }
    geolocation.watchPosition = (onFix, onError, options) => {
        window.__territorioGeolocation.watch += 1
        return watch(onFix, (error) => {
            window.__territorioGeolocation.codes.push(error.code)
            if (onError) onError(error)
        }, options)
    }
    geolocation.clearWatch = (id) => {
        window.__territorioGeolocation.clear += 1
        return clear(id)
    }
})()
"""

# La Florida, inside the comuna the install serves, 30 m: a fix the accuracy
# gate trusts (src/locate.js ACCURACY_LIMIT_M), so a notice after it is a fault.
LOCATION_FIX = {"latitude": -33.5226, "longitude": -70.5985, "accuracy": 30}
LOCATE_BUTTON = ".territorio-locate a"
LOCATE_NOTICE = '[data-testid="locate-notice"]'
OVERLAY_PATHS = ".leaflet-overlay-pane path"

# What the gate installs to read where the map really went. Leaflet fires
# `moveend` once a move has landed, so the bounds and the zoom at that moment
# are the map's state — not what the control meant to ask for, which is all a
# DOM or a source text can say. On the prototype, because the map is
# TerritorioMap's non-reactive handle and nothing outside the component holds
# it. Evaluated once the page has loaded rather than installed before it like
# GEOLOCATION_COUNTER, because `window.L` only exists once Leaflet's bundle has
# run — and it is the app's own Leaflet: markercluster attaches itself to
# `window.L`, and TerritorioMap reads `L.markerClusterGroup` off its imported
# `L`, which only works because the two are one object. Installing it empties
# the list, so what it holds is every move since. It answers whether it could:
# without `window.L` there is nothing to wrap, and a ReferenceError out of
# page.evaluate would end the whole run rather than fail one check.
VIEW_RECORDER = """
(() => {
    if (typeof window.L === 'undefined') {
        return false
    }
    const fire = L.Map.prototype.fire
    window.__territorioViews = []
    L.Map.prototype.fire = function (type, data, propagate) {
        if (type === 'moveend') {
            const bounds = this.getBounds()
            window.__territorioViews.push({
                south: bounds.getSouth(),
                west: bounds.getWest(),
                north: bounds.getNorth(),
                east: bounds.getEast(),
                zoom: this.getZoom(),
            })
        }
        return fire.call(this, type, data, propagate)
    }
    return true
})()
"""


def the_locate_control_can_ask_where_the_person_is(page, errors, failures):
    """«¿Dónde estoy?» is allowed to ask, and lets go when it is refused (FR-2).

    Dead on every Nextcloud 34 page until the fix this measures: core attaches a
    bare FeaturePolicy to every TemplateResponse, and a bare one says
    `geolocation 'none'`, so every click failed with code 1 — permission granted
    or not — before the browser ever asked. Three green suites and this gate
    never saw it: nothing in them rendered a page and read its headers. So the
    header is read HERE, off the document response, after the middleware has had
    its say. The PHPUnit test proves the factory, not the header, and a policy
    value can read correct and block silently (78a94e3) — which is why the
    string is not the end of it.

    Then the effect. Refused: the Spanish notice, and the watch Leaflet started
    is cleared — measured before the fix, two refused clicks left
    `watchPosition` ×2 and `clearWatch` ×0, one live GPS subscription per retry.
    Granted, with a 30 m fix emulated: the button is pressed, the circle and the
    dot are painted (paint, not the button: c8837c7), no notice; and a second
    click stops it cleanly. Console errors are read around the clicks, so a
    permissions-policy violation is attributed to this check and not only to
    the blanket line at the end of the run.

    Failed, not skipped, whatever is missing (#141): a `'none'` header, no
    control, no notice — each one is the finding. Runs first, before anything
    pans or draws: the first fix recentres to zoom 16, and this ends on a
    reload that puts the view back on the comuna, the way main() does before
    the cluster checks.
    """
    page.add_init_script(GEOLOCATION_COUNTER)
    response = page.goto(f"{BASE}/apps/territorio/", wait_until="domcontentloaded")
    policy = response.all_headers().get("feature-policy", "") if response is not None else ""
    if "geolocation 'self'" not in policy:
        failures.append(
            f"the app page's Feature-Policy is {policy!r}, not geolocation 'self' — every "
            f"«¿Dónde estoy?» click fails with code 1 before the browser is asked "
            f"(maps-compare research, FR-2)",
        )
        return

    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    button = page.locator(LOCATE_BUTTON)
    if not settles(page, lambda: button.count() > 0, timeout=10_000):
        failures.append("the map has no «¿Dónde estoy?» control under the zoom control")
        return

    def gps():
        return page.evaluate("() => window.__territorioGeolocation")

    notice = page.locator(LOCATE_NOTICE)
    paths = page.locator(OVERLAY_PATHS)
    drawn_before = paths.count()
    logged_before = len(errors)

    # Refused first. Nothing has been granted to this context, and Playwright
    # answers an ungranted request the way a person pressing «Bloquear» does:
    # code 1, PERMISSION_DENIED.
    page.context.clear_permissions()
    button.click()
    if not settles(page, lambda: notice.count() > 0, timeout=6_000):
        failures.append("refusing the permission showed no notice at all")
    else:
        text = notice.first.inner_text().strip()
        if "No tenemos permiso para usar su ubicación" not in text:
            failures.append(
                f"refusing the permission showed {text!r}, not the notice that says where "
                f"the switch is (src/locate.js locateErrorMessage, code 1)",
            )
    if button.get_attribute("aria-pressed") != "false":
        failures.append("the button stays pressed after the permission was refused")

    refused = gps()
    if (refused["watch"], refused["clear"]) != (1, 1):
        failures.append(
            f"after a refused click the GPS is watch {refused['watch']} / clear "
            f"{refused['clear']} (codes {refused['codes']}), not 1 / 1 — the error handler "
            f"left Leaflet's watch alive, and the next click starts a second one on top of it "
            f"(maps-compare research, FR-2)",
        )

    # Granted, with a fix the browser hands over at once.
    page.context.grant_permissions(["geolocation"])
    page.context.set_geolocation(LOCATION_FIX)
    button.click()
    if not settles(page, lambda: paths.count() >= drawn_before + 2, timeout=6_000):
        failures.append(
            f"with the permission granted and a 30 m fix, the overlay pane went from "
            f"{drawn_before} to {paths.count()} paths, not +2 — no accuracy circle and no dot "
            f"were painted",
        )
    if button.get_attribute("aria-pressed") != "true":
        failures.append("the button is not pressed while the map is following a granted fix")
    if notice.count() > 0:
        failures.append(
            f"a 30 m fix, which the accuracy gate trusts, still showed "
            f"{notice.first.inner_text().strip()!r}",
        )
    page.screenshot(path=str(SHOTS / "locate.png"), full_page=False)

    # And off again: the second click is the stop, so the GPS goes quiet.
    button.click()
    if not settles(page, lambda: paths.count() == drawn_before, timeout=6_000):
        failures.append(
            f"stopping left {paths.count() - drawn_before} location path(s) on the map",
        )
    if button.get_attribute("aria-pressed") != "false":
        failures.append("the button stays pressed after the second click, which is the stop")
    stopped = gps()
    if (stopped["watch"], stopped["clear"]) != (2, 2):
        failures.append(
            f"after refuse, follow and stop the GPS is watch {stopped['watch']} / clear "
            f"{stopped['clear']}, not 2 / 2 — a watch survived the stop",
        )

    violations = [line for line in errors[logged_before:] if "ermissions policy" in line]
    if violations:
        failures.append(
            f"the header grants geolocation 'self' but the browser still refused it: "
            f"{violations[:1]} — the policy value reads correct and blocks (78a94e3)",
        )

    # Back to the neutral context and the comuna view: the first fix recentred
    # the map to zoom 16, and every check below clicks canvas pixels where the
    # install's own view puts things.
    page.context.clear_permissions()
    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1000)


def _holds(view, line):
    """Whether a view VIEW_RECORDER saw has the whole of a line in it, given as
    (latitude, west, east) — the fixture's lines run west to east."""
    latitude, west, east = line
    return (view["south"] <= latitude <= view["north"]
            and view["west"] <= west and east <= view["east"])


def save(page, failures, what):
    """Press Guardar and report what the app said if it refused.

    Without this the gate could only say a record was missing afterwards, which
    is the symptom rather than the reason — and the app already puts the reason
    on screen.
    """
    page.get_by_role("button", name="Guardar").click()
    page.wait_for_timeout(800)

    # In the panel only: the map's own notice about an unreachable basemap is a
    # warning too, and it is not the app refusing the record.
    # The duplicate shield is a warning about a neighbour, shown while typing; saving goes ahead.
    refusal = page.locator(".territorio-docked-panel").locator(
        ".notecard--error, .notecard--warning:not(.territorio-detail__duplicate-alert)",
    )
    if refusal.count() > 0:
        failures.append(f"saving {what} was refused: {refusal.first.inner_text().strip()}")


def create_a_place(page, name, failures):
    """The #2 criterion nobody ever exercised: create a record end to end.

    The coordinate is typed, not clicked. The form offers both — "Haga clic en el
    mapa para situar el registro, o escriba las coordenadas" — and typing is the
    half that does not depend on what happens to be drawn under the cursor. What
    a click does is checked on its own in clicking_places_the_coordinate(), where
    it is the subject rather than a step on the way to something else.
    """
    to_list(page)
    page.get_by_role("button", name="Agregar", exact=True).click()
    page.get_by_label("Nombre").fill(name)
    page.get_by_label("Latitud").fill(f"-33.5{300 + NUDGE}")
    page.get_by_label("Longitud").fill(f"-70.5{300 + NUDGE}")
    save(page, failures, "the Place")
    # Dismiss the sidebar so it does not overlay the map for the next click.
    #
    # Escape rather than its close button, deliberately: closing it properly
    # widens the map canvas, and every position-based click after that lands
    # somewhere else than the one before it. Escape leaves the geometry alone.
    dismiss(page)
    page.wait_for_timeout(400)


LAYERS_BUTTON = ".territorio-layers-button"
LAYERS_PANEL = ".territorio-layers-panel"


def division_row(page, label):
    """The layers menu's row for one territorial division; the menu has to be open."""
    return page.locator(f"{LAYERS_PANEL} .territorio-layers-item").filter(has_text=label).last


def dismiss(page):
    """Leave whatever is open for the list: Escape, then «← Volver a la lista».

    The old sidebar closed on Escape. The docked panel shows the list OR a record
    and only the back button leaves a record, so both are pressed: Escape for a
    dropdown or a mode, the button for the record.
    """
    page.keyboard.press("Escape")
    back = page.locator(".territorio-detail__back")
    if back.count() > 0 and back.first.is_visible():
        back.first.click()
        page.wait_for_timeout(300)


def to_list(page):
    """The state a check starts from: the map pane, the list in the docked panel.

    The old layout showed the list and a record side by side, so whatever one
    check left open the next still found the list. The docked panel shows one or
    the other, and the checks below all begin by looking for the list.
    """
    # A dialog left open (an import that was only read, say) swallows every click.
    dialog = page.get_by_role("dialog")
    if dialog.count() > 0:
        dialog.first.get_by_role("button", name=re.compile("Cerrar|Close")).first.click()
        page.wait_for_timeout(300)
    if page.locator(".territorio-fullscreen-view").count() > 0:
        _pane(page, "Mapa")
    dismiss(page)
    if page.locator(".territorio-docked-panel--collapsed").count() > 0:
        page.locator(".territorio-docked-panel__toggle").click()


def run(check, page, *args, **kwargs):
    """One check, from the list."""
    to_list(page)
    return check(page, *args, **kwargs)


def edit(page):
    """Open the open record's form: «Editar» on the inspection card, if that is what is shown."""
    button = page.get_by_role("button", name="Editar", exact=True)
    if button.count() > 0 and button.first.is_visible():
        button.first.click()
        page.wait_for_timeout(300)


def largest(shapes):
    """The biggest of several matching shapes: a stroke colour can also be a 5px vertex mark."""
    areas = shapes.evaluate_all(
        "els => els.map((el) => { const b = el.getBoundingClientRect(); return b.width * b.height })",
    )

    return shapes.nth(areas.index(max(areas)))


def open_first_row(page):
    """Open the list's first record as a form, the way the old sidebar showed it."""
    page.locator(ROWS).first.click()
    page.wait_for_timeout(300)
    edit(page)


def open_capas(page):
    """The Capas panel, which now lives in the taxonomy drawer: open the drawer, then the panel."""
    if page.locator(".territorio-taxonomy-drawer").count() == 0:
        if page.locator(LAYERS_BUTTON).count() == 0:
            _pane(page, "Mapa")
        page.locator(LAYERS_BUTTON).click()
        page.get_by_role("button", name="Configurar taxonomía y capas").click()
        page.wait_for_selector(".territorio-capas", timeout=5_000)
    if not page.locator(".territorio-capas").evaluate("node => node.open"):
        page.locator(".territorio-capas__summary").click()
        page.wait_for_timeout(300)
    # Renaming, adding and removing are behind «Editar capas»; the checks below do all three.
    edit_layers = page.locator(".territorio-capas__edit")
    if edit_layers.get_attribute("aria-pressed") != "true":
        edit_layers.click()
        page.wait_for_timeout(300)


def capas_shot(page, path):
    """A picture of the Capas panel, which is only on the page while its drawer is open."""
    open_capas(page)
    page.locator(".territorio-capas").screenshot(path=path)


def run_in_capas(check, page, *args):
    """One check of the Capas panel, from the list, with the panel open."""
    to_list(page)
    open_capas(page)
    return check(page, *args)


@contextmanager
def map_pane(page):
    """The map pane for the duration: the layers menu exists nowhere else. Puts the pane back."""
    active = page.locator(".territorio-omnibox__tab--active").inner_text().strip()
    if active != "Mapa":
        _pane(page, "Mapa")
    try:
        yield
    finally:
        if active != "Mapa":
            _pane(page, active)


def toggle_division(page, label):
    """Tick or untick one of the layers menu's division toggles, then close the menu."""
    with map_pane(page):
        page.locator(LAYERS_BUTTON).click()
        division_row(page, label).click()
        page.locator(LAYERS_BUTTON).click()
        page.wait_for_timeout(400)


def division_is_shown(page, label):
    """Whether the menu has a toggle for the division and it is ticked."""
    with map_pane(page):
        page.locator(LAYERS_BUTTON).click()
        row = division_row(page, label).locator("input[type=checkbox]")
        shown = row.count() > 0 and row.is_checked()
        page.locator(LAYERS_BUTTON).click()

    return shown


def hide_divisions(page):
    """Take the drawn boundaries off the map, and say which ones were on.

    Geoman snaps new vertices to what is already drawn, and a click that misses
    selects whatever is under it — so with a comuna's Unidades Vecinales and a
    run's Sectores on screen, drawing is a coin toss. Hiding them is what a
    person drawing a boundary does anyway, through the app's own Capas toggles.

    This has to happen per draw, not once at start-up. A View is per-session and
    nothing persists it, so every one of this file's ten reloads brings the
    cadastre straight back — which is why hiding once protected only the first
    draw and every later one was drawing into a full map (issue #56).
    """
    hidden = []
    for label in ("Sector", "Unidad Vecinal"):
        if division_is_shown(page, label):
            toggle_division(page, label)
            hidden.append(label)

    return hidden


def show_divisions(page, labels):
    """Put back exactly what hide_divisions took away."""
    for label in labels:
        if not division_is_shown(page, label):
            toggle_division(page, label)


def draw_a_sector(page, name, failures, colour="#16a34a", save_it=True):
    """The #10 criterion: draw a polygon, name it, colour it, save it.

    `save_it=False` leaves the draft open. That is the only way to reach a Sector
    before its first save, and every other check here opens an already-saved one —
    which is exactly why #48 survived eight slices.

    Returns whether a Sector was actually drawn. A caller that goes on regardless
    operates on whatever the sidebar happens to hold, which is the mistake #53
    was: say so in the return value rather than only in `failures`.
    """
    # Drawn on empty ground: see hide_divisions() for why, and why once at
    # start-up was not enough. Restored in the `finally` below, because every
    # check after this one reads a list that Capas filters.
    hidden = hide_divisions(page)
    try:
        return _draw(page, name, failures, colour, save_it)
    finally:
        show_divisions(page, hidden)


def _draw(page, name, failures, colour, save_it):
    """The draw itself, with the boundaries already off the map."""
    page.locator(".territorio-draw-sector__button").click()

    canvas = page.locator('[data-testid="map-canvas"]')
    left, top = MAP_CLEAR["x"] + NUDGE, MAP_CLEAR["y"] + (NUDGE % 60)
    # The first vertex again closes the ring: with no Geoman toolbar there is no
    # Finalizar. The same pixel hits the same marker, so it is no coin toss.
    for x, y in [(left, top), (left + 110, top), (left + 110, top + 90), (left, top + 90), (left, top)]:
        canvas.click(position={"x": x, "y": y})

    # The sidebar for a Sector carries a colour input and no coordinate fields.
    # Waiting for it here turns "the polygon never became a draft" into a sentence
    # instead of a timeout thirty seconds later on an unrelated locator.
    #
    # Measured 2026-08-11, before the boundaries were taken off the map: the
    # draw missed three runs in five, and then every run once the map filled up.
    # It is the clicks that are fragile, not the app.
    try:
        page.wait_for_selector('input[type="color"]', timeout=5_000)
    except PlaywrightTimeout:
        failures.append("drawing the polygon did not open a Sector draft")
        return False

    # The colour input does NOT prove a Sector was drawn. A Unidad Vecinal is a
    # territorial division and carries one too, so when the draw did not take, a
    # canvas click selected a cadastre boundary and this typed a name and colour
    # straight into it — three were renamed that way before anyone noticed
    # (issue #53). A history section is the tell: it renders only for a record
    # that already exists, and a Sector drawn a moment ago has none.
    if page.locator(".territorio-history").count() > 0:
        failures.append(
            "the draw did not take: the sidebar holds an existing record, not a new Sector — "
            f"refusing to rename {page.get_by_label('Nombre').input_value().strip()!r}",
        )
        dismiss(page)
        page.wait_for_timeout(400)
        return False

    page.get_by_label("Nombre").fill(name)
    # The colour input is native, so set it directly — a colour picker cannot be
    # driven by clicking.
    page.locator('input[type="color"]').fill(colour)
    if save_it:
        save(page, failures, "the Sector")
    return True


def a_tramo_typed_before_the_first_save_is_kept(page, failures):
    """#48: a tramo typed on a Sector that has never been saved was discarded.

    ADR-0007 keeps hand-drawing first-class, and a hand-drawn Sector's whole Limit
    may be typed stretches. `startSector` built the draft with no `limit` key at
    all, `addLimitNote` guarded on it being defined, and the picker cleared its
    input either way — so the text vanished and nothing said why.
    """
    name = f"Sector a mano {RUN}"
    tramo = "por detrás del colegio"

    if not draw_a_sector(page, name, failures, save_it=False):
        return

    picker = page.locator(".territorio-picker")
    if picker.count() == 0:
        failures.append("a Sector drawn by hand has no Limit picker before its first save")
        return

    page.get_by_label("Agregar un tramo a mano").fill(tramo)
    page.get_by_role("button", name="Agregar tramo").click()
    page.wait_for_timeout(400)

    rows = picker.locator(".territorio-picker__parts li")
    if rows.count() == 0 or tramo not in rows.first.inner_text():
        failures.append(f"a tramo typed before the first save was dropped: {tramo!r}")

    save(page, failures, "the hand-drawn Sector")
    dismiss(page)

    # Reopened, because the point is that it outlives the draft it was typed on.
    page.locator(SEARCH).fill(name)
    page.wait_for_timeout(800)
    if page.locator(ROWS).count() == 0:
        failures.append(f"{name!r} is not in the list after saving")
        page.locator(SEARCH).fill("")
        return

    open_first_row(page)
    page.wait_for_timeout(1500)
    kept = page.locator(".territorio-picker__parts li")
    if kept.count() == 0 or tramo not in kept.first.inner_text():
        failures.append(f"a tramo typed before the first save did not survive it: {tramo!r}")

    dismiss(page)
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


def clicking_places_the_coordinate(page, failures):
    """A click on the map belongs to the draft that is waiting for a coordinate.

    The regression this exists for: once a comuna's Unidades Vecinales are
    imported they tile the whole view, so a click landed on a boundary and
    selected it — the draft was replaced and the next save edited the cadastre.
    One Unidad Vecinal was renamed that way before this was fixed.
    """
    page.get_by_role("button", name="Agregar", exact=True).click()
    page.get_by_label("Nombre").fill(f"Prueba de clic {RUN}")

    page.locator('[data-testid="map-canvas"]').click(position={"x": MAP_CLEAR["x"] + 100, "y": 300})
    page.wait_for_timeout(400)

    if page.get_by_label("Latitud").input_value().strip() == "":
        failures.append("clicking the map did not fill the open draft's coordinate")
    if page.get_by_label("Nombre").input_value() != f"Prueba de clic {RUN}":
        failures.append("clicking the map replaced the open draft with another record")

    # Nothing is saved: this is about where the click went, and leaving a record
    # behind for it would only be more to tidy.
    dismiss(page)
    page.wait_for_timeout(400)


def the_assignment_is_shown_and_not_editable(page, failures):
    """#11: Sector and Unidad Vecinal are shown, never typed (ADR-0008)."""
    page.locator(SEARCH).fill(f"Plaza del Navegador {RUN}")
    page.wait_for_timeout(400)
    open_first_row(page)
    page.wait_for_timeout(600)

    sidebar = page.locator(".territorio-detail")
    if sidebar.get_by_text("Unidad Vecinal:").count() == 0:
        failures.append("the sidebar does not say which Unidad Vecinal the record is in")

    # The criterion is that there is nowhere to type one. Any input carrying the
    # word would be exactly the promise ADR-0008 says the app cannot keep.
    for label in ("Sector", "Unidad Vecinal"):
        if page.get_by_role("textbox", name=label, exact=True).count() > 0:
            failures.append(f"{label} is an editable field, which ADR-0008 forbids")

    dismiss(page)
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


def the_limit_can_be_picked(page, failures):
    """#13: pick the lines a Sector's Limit runs along, by name or by clicking.

    Needs boundary features on the instance; skipped with a word rather than a
    false pass when there are none.
    """
    page.locator(SEARCH).fill(f"Sector Navegador {RUN}")
    page.wait_for_timeout(400)
    open_first_row(page)
    page.wait_for_timeout(1200)

    picker = page.locator(".territorio-picker")
    if picker.count() == 0:
        failures.append("opening a Sector did not open the Limit picker")
        return

    # By label: the picker has a second field now, for the stretches of a limit
    # that follow nothing anybody could pick (issue #15).
    box = picker.get_by_label("Buscar una calle")
    # A letter, not a word: the loaded names are whatever streets this comuna
    # actually has, and the check should not need to know them.
    box.fill("a")
    page.wait_for_timeout(600)

    offered = picker.locator(".territorio-picker__suggestions li")
    if offered.count() == 0:
        skip("no boundary features in view to pick from")
        dismiss(page)
        page.locator(SEARCH).fill("")
        return

    # Picking by name puts it in the Limit…
    offered.first.locator("button").click()
    page.wait_for_timeout(500)
    chosen = picker.locator(".territorio-picker__parts li")
    if chosen.count() != 1:
        failures.append(f"picking a name chose {chosen.count()} things, expected 1")

    # Order matters here in a way it did not before: this list holds hand-typed
    # tramos as well as picked ones, so these counts only mean "one picked street"
    # while nothing has typed a note on this Sector yet. main() puts both of those
    # after this check.
    # …and removing it takes it out again.
    chosen.first.get_by_role("button", name="Quitar").click()
    page.wait_for_timeout(500)
    if picker.locator(".territorio-picker__parts li").count() != 0:
        failures.append("removing a picked line did not remove it")

    # Clicking a line on the map is the other half of the same criterion, and the
    # half nothing else exercises. It is also where a Sector polygon sitting on
    # top swallowed the click, selected itself, and took the whole selection with
    # it — so this asserts the picks survive as much as that one arrives.
    lines = page.locator(".leaflet-pane[class*=pickable] path")
    if lines.count() == 0:
        skip("no boundary line on screen to click")
    else:
        box.fill("")
        page.wait_for_timeout(300)
        # The same line twice, not `.first` twice: a picked line is redrawn, and
        # the second `.first` can be another street altogether.
        line = lines.first.element_handle()
        line.click(force=True)
        page.wait_for_timeout(500)

        after = picker.locator(".territorio-picker__parts li")
        if after.count() != 1:
            failures.append(f"clicking a line picked {after.count()} things, expected 1")

        line.click(force=True)
        page.wait_for_timeout(500)
        if picker.locator(".territorio-picker__parts li").count() != 0:
            failures.append("clicking the same line again did not unpick it")

    dismiss(page)
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


def a_pin_opens_a_record_while_the_retired_list_is_shown(page, failures):
    """#67: the map draws the live Registry whatever the retired toggle says.

    `select()` resolved the clicked id against the Datos list, and once that
    toggle is on the list holds only retired records — deliberately, so a search
    reaches them. The two sets are then disjoint, so every click on the map found
    nothing: the shape took the selected styling and the sidebar never opened.

    Only a browser sees it. Both sets are right on their own, and neither the unit
    suite nor the integration suite pairs them — which is the same shape as
    `Guardar` doing nothing for eight slices.

    Draws its own Sector rather than clicking whatever shape lies nearest: the
    second half of this SAVES the record it opens, and a check may only write to a
    record it made. The colour is how it is found again, so a Sector left behind
    by an earlier run cannot be picked up instead.
    """
    name = f"Sector Retirados {RUN}"
    colour = "#8b1a8b"
    if not draw_a_sector(page, name, failures, colour=colour):
        return

    dismiss(page)
    page.wait_for_timeout(500)

    shape = page.locator(f'.leaflet-overlay-pane path[stroke="{colour}"]')
    if not settles(page, lambda: shape.count() > 0, timeout=6_000):
        failures.append(f"{name!r} saved but was not drawn, so the retired-toggle check could not run")
        retire_the_runs_record(page, name)
        return

    page.get_by_role("button", name="Ver retirados", exact=True).click()
    # The retired list is fetched on toggle. Waiting for the button to change its
    # own label is what says the toggle landed; clicking before it does would be
    # testing the state this check exists to leave behind.
    #
    # And the answer is not discardable. If the toggle never lands, this check
    # passes without exercising #67 at all, and the cleanup below then raises on a
    # button that is not there — leaving this run's Sector live on the map, which
    # reassigns every Place inside it (issue #58).
    if not settles(page, lambda: page.get_by_role("button", name="Ver activos", exact=True).count() > 0):
        failures.append("«Ver retirados» did not switch the list, so the dead-click check did not run")
        retire_the_runs_record(page, name)
        return

    largest(shape).click(force=True)
    if not settles(page, lambda: page.locator(".territorio-detail").count() > 0, timeout=6_000):
        failures.append(
            "clicking the map with «Ver retirados» on opened nothing — the click is dead (#67)",
        )
        page.screenshot(path=str(SHOTS / "retired-toggle-dead-click.png"), full_page=False)
    else:
        # And the record it opens has to be savable. `applyIncoming` closed the
        # draft for any record whose retired flag differed from the toggle — which
        # was every live one, once this fix made a live one reachable. `save()`
        # then read `picked` off a null draft, so a save that had ALREADY succeeded
        # reported "revise la conexión" and took the sidebar with it.
        edit(page)
        page.get_by_role("button", name="Guardar").click()
        page.wait_for_timeout(1500)

        refusal = page.locator(".notecard--error")
        if refusal.count() > 0:
            failures.append(
                f"saving with «Ver retirados» on reported {refusal.first.inner_text().strip()!r} (#67)",
            )
        if page.locator(".territorio-detail").count() == 0:
            failures.append("saving with «Ver retirados» on closed the sidebar (#67)")

    dismiss(page)
    page.wait_for_timeout(300)
    page.get_by_role("button", name="Ver activos", exact=True).click()
    page.wait_for_timeout(600)
    retire_the_runs_record(page, name)


def settles(page, predicate, timeout=15_000):
    """Wait for something to become true, rather than sleeping and hoping.

    A fixed wait is a bet on how long a round trip takes, and this app answers a
    click by posting, re-polling the change feed and re-reading the queue. The
    bet is usually right, which is worse than usually wrong: the check passes
    most runs and fails a few, and a gate that gets re-run is a gate nobody
    believes.
    """
    waited = 0
    while waited < timeout:
        if predicate():
            return True
        page.wait_for_timeout(250)
        waited += 250

    return predicate()


def the_duplicate_queue_works(page, failures):
    """#16: an import FLAGS two records that look like one place, and merges none.

    Driven through a real import, because "Import flags candidate pairs" is the
    criterion — creating the pair by hand would check the queue's rendering while
    leaving the sentence in the ticket unexercised.

    The twins are put at a coordinate this run picks, far from the cadastre this
    instance already holds, so the pair the queue is asked about is this run's
    and not one of the real ones.
    """
    # Two different names sharing one token nothing else in this run carries —
    # different names is the point (that is the shape being detected), and the
    # shared token is how the check finds both of them again.
    token = f"Gemelo{RUN}"
    twin = f"Balneario {token}"
    partner = f"Piscina {token}"
    # Half a degree apart between runs, not a ten-thousandth. Every run leaves its
    # records behind, and at 11 m apart one run's twins are inside the 150 m rule
    # of the last run's — so the queue legitimately offered this run's record
    # paired with an older leftover, and merging one card left the other standing.
    # Detection was right; the check was reading the wrong card.
    lat, lon = -33.47 - NUDGE / 200, -70.47 - NUDGE / 200

    # Two records, one coordinate, two names — the first of the two shapes the
    # real data actually contains.
    geojson = json.dumps({
        "type": "FeatureCollection",
        "features": [
            {
                "type": "Feature",
                "properties": {"id": f"nav-{RUN}-a", "name": twin},
                "geometry": {"type": "Point", "coordinates": [lon, lat]},
            },
            {
                "type": "Feature",
                "properties": {"id": f"nav-{RUN}-b", "name": partner},
                "geometry": {"type": "Point", "coordinates": [lon, lat]},
            },
        ],
    }).encode()

    page.get_by_role("button", name="Importar / deshacer").click()
    page.wait_for_timeout(600)
    # A Dataset of this run's own, so re-running does not update the last run's
    # rows: matching is on (Dataset, external id), and an import into the same
    # Dataset would find the twins already there and change nothing.
    page.get_by_label("Conjunto de datos").fill(f"navegador-{RUN}")
    page.locator('input[type="file"]').set_input_files({
        "name": f"navegador-{RUN}.geojson",
        "mimeType": "application/geo+json",
        "buffer": geojson,
    })
    page.wait_for_timeout(500)
    page.get_by_role("button", name="Importar", exact=True).click()
    page.wait_for_timeout(2500)
    dismiss(page)
    page.wait_for_timeout(800)

    # Both are in the Registry. The criterion that matters most: nothing was
    # merged on similarity, so both are still there for somebody to look at.
    page.locator(SEARCH).fill(token)
    if not settles(page, lambda: page.locator(ROWS).count() == 2):
        failures.append(
            f"importing two records at one coordinate left {page.locator(ROWS).count()} in the list, expected 2",
        )
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(300)

    _pane(page, "Posibles duplicados")
    page.wait_for_timeout(500)

    # Scoped to BOTH of this run's names. A card naming one of them and a record
    # from some earlier run is a real candidate pair and none of this check's
    # business; asserting on "any card mentioning this name" made the check fail
    # for a queue that was working correctly.
    card = (page.locator(".territorio-duplicados__pair")
            .filter(has_text=twin)
            .filter(has_text=partner))
    if card.count() == 0:
        failures.append("the imported pair was not offered in the queue")
        _pane(page, "Mapa")
        return

    card = card.first
    if "coordenada" not in card.locator(".territorio-duplicados__why").inner_text():
        failures.append("the pair does not say why it was offered")

    # Keep one. The other leaves the Registry, and the pair leaves the queue.
    card.locator(".territorio-duplicados__side").first.get_by_role(
        "button", name="Conservar este",
    ).click()

    if not settles(
        page,
        lambda: (page.locator(".territorio-duplicados__pair")
                 .filter(has_text=twin)
                 .filter(has_text=partner).count() == 0),
    ):
        failures.append("the pair is still in the queue after being merged")

    _pane(page, "Mapa")
    page.locator(SEARCH).fill(token)
    if not settles(page, lambda: page.locator(ROWS).count() == 1):
        failures.append(
            f"merging left {page.locator(ROWS).count()} visible records, expected 1",
        )
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(300)


def exports_ask_for_a_password_only_when_earned(page, failures):
    """#17: three formats, and the prompt appears only when contacts are in.

    The criterion this checks is a NEGATIVE one as much as a positive one —
    "exports without contact fields need no confirmation" (ADR-0006) — because a
    prompt that appears every time teaches people to type their password without
    reading why, which is the failure the rule exists to avoid.
    """
    def open_dialog():
        page.get_by_role("button", name="Exportar", exact=True).click()
        page.wait_for_timeout(600)

    # A record with a telephone number in it, because the criterion is about
    # contact fields and the Places the other checks make have none — asserting
    # "the file carries the phone" against a record without one would pass or
    # fail for reasons that have nothing to do with exporting.
    contact = f"Contacto Navegador {RUN}"
    page.get_by_role("button", name="Agregar", exact=True).click()
    page.get_by_label("Nombre").fill(contact)
    page.get_by_label("Latitud").fill(f"-33.5{400 + NUDGE}")
    page.get_by_label("Longitud").fill(f"-70.5{400 + NUDGE}")
    page.get_by_label("Teléfono").fill("+56 9 8765 4321")
    save(page, failures, "the record with contact details")
    dismiss(page)
    page.wait_for_timeout(400)

    # A filtered set, so what comes back is checkable rather than the whole
    # comuna: this is also the "current filtered set" half of the criterion.
    page.locator(SEARCH).fill(contact)
    page.wait_for_timeout(600)
    open_dialog()

    if "1 registro" not in page.locator(".territorio-export__count").inner_text():
        failures.append(
            "the export dialog does not say it will export the filtered set: "
            + page.locator(".territorio-export__count").inner_text(),
        )

    # GeoJSON without contacts: no prompt, and a file arrives.
    with page.expect_download(timeout=20_000) as download:
        page.get_by_role("button", name="Exportar", exact=True).last.click()
    geojson = download.value
    if not geojson.suggested_filename.endswith(".geojson"):
        failures.append(f"the GeoJSON export arrived as {geojson.suggested_filename!r}")
    else:
        written = json.loads(Path(geojson.path()).read_text())
        if len(written.get("features", [])) != 1:
            failures.append(
                f"the export carried {len(written.get('features', []))} records, expected the 1 on screen",
            )
        elif "phone" in written["features"][0]["properties"]:
            failures.append("an export without contacts carried a phone number")
        elif "license" in written:
            # #28: the record on screen was typed into this instance, so an ODbL
            # notice here would be a false claim about the clinic's own catastro
            # — and a notice on every file is read as boilerplate, which is how
            # the real one stops being seen.
            failures.append("an export of local records claimed OpenStreetMap's licence")

    # Turning contacts on says so before anything is downloaded.
    open_dialog()
    page.get_by_text("Incluir datos de contacto").click()
    page.wait_for_timeout(300)
    if page.get_by_text("Se le pedirá su contraseña").count() == 0:
        failures.append("turning on contact details did not say a password would be asked for")

    # And it does ask. Nextcloud's own dialog, not one of ours — so this looks
    # for a password field rather than for anything this app renders.
    page.get_by_role("button", name="Exportar", exact=True).last.click()
    page.wait_for_timeout(2500)
    password = page.locator('input[type="password"]')
    if password.count() == 0:
        failures.append("a contact-bearing export did not ask for the password (ADR-0006)")
        page.reload(wait_until="domcontentloaded")
        page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    else:
        # Answered rather than dismissed, so the rest of the criterion — the file
        # carries the contacts, and the export is recorded — can be checked at
        # all. Nothing downstream of the prompt is reachable without going
        # through it.
        password.first.fill(PASSWORD)
        with page.expect_download(timeout=30_000) as download:
            page.keyboard.press("Enter")
        confirmed = download.value
        written = json.loads(Path(confirmed.path()).read_text())
        if written["features"][0]["properties"].get("phone") != "+56 9 8765 4321":
            failures.append(
                "the confirmed export did not carry the contact fields: "
                + repr(written["features"][0]["properties"]),
            )
        if ContactFieldsPartial in Path(confirmed.path()).read_text():
            failures.append("a confirmed export was marked as written without contacts")

    # Reloaded before carrying on. Nextcloud's confirmation dialog belongs to the
    # server, not to this app, and whatever it leaves behind — an overlay, a
    # focus trap — is not this check's to unpick by guessing at its markup.
    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1000)
    page.locator(SEARCH).fill(contact)
    page.wait_for_timeout(600)

    # KML says what it cannot carry, before the export runs.
    open_dialog()
    page.get_by_text("KML —").click()
    page.wait_for_timeout(300)
    if page.get_by_text("No lleva teléfono").count() == 0:
        failures.append("choosing KML did not say what it cannot carry")

    with page.expect_download(timeout=20_000) as download:
        page.get_by_role("button", name="Exportar", exact=True).last.click()
    kml = download.value
    if not kml.suggested_filename.endswith(".kml"):
        failures.append(f"the KML export arrived as {kml.suggested_filename!r}")

    a_kmz_is_that_kml_in_an_archive(page, Path(kml.path()).read_bytes(), failures)

    dismiss(page)
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)

    the_server_refuses_an_unconfirmed_export(page, failures)
    the_administrator_can_read_the_export_log(page, failures)


def a_kmz_is_that_kml_in_an_archive(page, kml_bytes, failures):
    """#102: a KMZ is the KML in a ZIP, and the dialog treats it as the KML it is.

    Driven straight after the KML download, on the same filtered set, and given
    that download's bytes: the criterion is that the two are IDENTICAL, so
    anything less than comparing them against each other would be checking a
    weaker thing. This is also the one criterion no PHP test can reach — the
    file has to come down the wire, through the route the dialog chose.
    """
    page.get_by_role("button", name="Exportar", exact=True).click()
    page.wait_for_timeout(600)
    page.get_by_text("KMZ —").click()
    page.wait_for_timeout(300)

    # The same sentence a KML gets, naming the format the person chose: a KMZ
    # cannot carry a telephone number either.
    if page.get_by_text("No lleva teléfono").count() == 0:
        failures.append("choosing KMZ did not say what it cannot carry")

    # And no contacts switch, which is the half that matters: offering it would
    # ask for the person's password over a file with nothing personal in it
    # (ADR-0006) and the server would refuse the export afterwards anyway.
    if page.get_by_text("Incluir datos de contacto").count() != 0:
        failures.append("the KMZ format offered the contacts switch")

    # Kept, because two of #102's criteria are about what the dialog shows and a
    # count of zero is not something a person can read back later.
    page.screenshot(path=str(SHOTS / "kmz-dialog.png"), full_page=False)

    with page.expect_download(timeout=20_000) as download:
        page.get_by_role("button", name="Exportar", exact=True).last.click()
    kmz = download.value
    if not kmz.suggested_filename.endswith(".kmz"):
        failures.append(f"the KMZ export arrived as {kmz.suggested_filename!r}")
        return

    path = Path(kmz.path())
    if not zipfile.is_zipfile(path):
        failures.append("the KMZ did not arrive as an archive")
        return

    with zipfile.ZipFile(path) as archive:
        if "doc.kml" not in archive.namelist():
            failures.append(f"the KMZ holds {archive.namelist()} and no doc.kml")
            return
        inside = archive.read("doc.kml")

    if inside != kml_bytes:
        failures.append(
            "the doc.kml inside the KMZ is not what the KML export wrote "
            f"({len(inside)} bytes against {len(kml_bytes)})",
        )


def an_export_of_osm_data_names_the_licence(page, failures):
    """#28: a file holding OpenStreetMap's data names ODbL, through the real door.

    The unit tests pin what each writer emits from a row. This is the half only
    the running app can answer: that a record which arrived from an OSM-derived
    Dataset still looks OSM-derived by the time the browser has downloaded it —
    through the controller, the filter the browser sends, and the download.

    The Dataset is imported through `occ` and NOT as a Boundary one: boundary
    features are left out of every ordinary read, so an export can never contain
    them, and keying this check on one would check nothing. It is stamped the way
    `tools/boundary-features.php` stamps what it produces (ADR-0004), because
    that stamp is the whole signal.
    """
    name = f"Calle del Navegador {RUN}"
    geojson = json.dumps({
        "type": "FeatureCollection",
        "features": [{
            "type": "Feature",
            "properties": {
                "id": f"osm-{RUN}",
                "name": name,
                "source_es": "OpenStreetMap",
                "source_url": "https://www.openstreetmap.org/way/101",
            },
            "geometry": {"type": "Point", "coordinates": [-70.585, -33.525]},
        }],
    })

    if not import_dataset(geojson, f"osm-{RUN}", boundary=False):
        skip("could not import an OSM-derived Dataset through occ; the ODbL notice is unchecked")
        return

    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1500)

    # Assert the record reached the Registry before asking what an export of it
    # says: a notice missing from a file that holds nothing would look identical
    # to a notice the writer failed to emit.
    if registry_feature(page, name) is None:
        failures.append(f"{name!r} did not reach the Registry, so the ODbL notice was not checked")
        return

    page.locator(SEARCH).fill(name)
    page.wait_for_timeout(800)
    page.get_by_role("button", name="Exportar", exact=True).click()
    page.wait_for_timeout(600)

    with page.expect_download(timeout=20_000) as download:
        page.get_by_role("button", name="Exportar", exact=True).last.click()
    written = json.loads(Path(download.value.path()).read_text())

    if len(written.get("features", [])) != 1:
        failures.append(
            f"the export carried {len(written.get('features', []))} records, expected the 1 on screen",
        )
    licence = written.get("license", "")
    if "ODbL" not in licence or "OpenStreetMap" not in licence:
        failures.append(f"an export of OSM-derived records did not name the licence: {licence!r}")

    dismiss(page)
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


def the_export_offers_the_live_set_with_retirados_on(page, failures):
    """#130: with «Ver retirados» on, Exportar offers the live set, not nothing.

    The Datos list holds only retired records once that toggle is on — it is
    the recovery screen, and it is why a search reaches them at all — so an
    export derived from the list offered «0 registros» with its button disabled
    while the map kept drawing a full live Registry. `exportableIds` follows
    `liveFiltered` now, the same set the map and the Índice draw, so the toggle
    no longer changes what a download carries.

    Two records of this run's own, one retired: the search then matches exactly
    one live and one retired, so every wrong count says something distinct — 0
    is the bug as filed, 2 would be counting the retired record too.
    """
    live = f"Exportable {RUN} Vivo"
    gone = f"Exportable {RUN} Retirado"
    for name, offset in ((gone, 600), (live, 700)):
        page.get_by_role("button", name="Agregar", exact=True).click()
        page.get_by_label("Nombre").fill(name)
        page.get_by_label("Latitud").fill(f"-33.5{offset + NUDGE}")
        page.get_by_label("Longitud").fill(f"-70.5{offset + NUDGE}")
        save(page, failures, f"the exportable record {name!r}")
        dismiss(page)
        page.wait_for_timeout(400)

    retire_the_runs_record(page, gone)

    page.get_by_role("button", name="Ver retirados", exact=True).click()
    if not settles(page, lambda: page.get_by_role("button", name="Ver activos", exact=True).count() > 0):
        failures.append("«Ver retirados» did not switch the list, so the export check did not run")
        page.locator(SEARCH).fill("")
        return

    # Both names carry «Exportable» and the run stamp, so one search matches the
    # retired record in the list and the live record the export must still offer.
    page.locator(SEARCH).fill(f"Exportable {RUN}")
    page.wait_for_timeout(600)
    if not settles(page, lambda: page.locator(ROWS).count() > 0):
        failures.append(
            "the retired record was not listed under «Ver retirados», so the export check did not run",
        )
        page.locator(SEARCH).fill("")
        page.get_by_role("button", name="Ver activos", exact=True).click()
        page.wait_for_timeout(600)
        return

    page.get_by_role("button", name="Exportar", exact=True).click()
    page.wait_for_timeout(600)

    said = page.locator(".territorio-export__count").inner_text()
    if "1 registro" not in said:
        failures.append(f"with «Ver retirados» on, the export did not offer the live set: {said!r} (#130)")
    if "2 registro" in said:
        failures.append(f"the export offered the retired record too: {said!r} (#130)")
    if not page.get_by_role("button", name="Exportar", exact=True).last.is_enabled():
        failures.append("with «Ver retirados» on, the dialog's Exportar button was disabled (#130)")

    # The export dialog is an NcDialog: Escape does not dismiss it and its
    # modal mask keeps intercepting clicks, so close through Cancelar (r18).
    page.get_by_role("button", name="Cancelar", exact=True).first.click()
    page.wait_for_timeout(300)
    page.get_by_role("button", name="Ver activos", exact=True).click()
    page.wait_for_timeout(600)
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


def the_administrator_can_read_the_export_log(page, failures):
    """ADR-0006: "An administrator can read that list as a report."

    On the page an administrator actually opens, not as a route somebody would
    have to curl. The export above is the entry this expects to find, which is
    also why that one is carried through the password prompt rather than
    cancelled at it.
    """
    page.goto("http://localhost:8083/settings/admin/territorio", wait_until="domcontentloaded")
    page.wait_for_timeout(2500)

    table = page.locator(".territorio-admin__exports")
    if table.count() == 0:
        failures.append("the admin settings page shows no export log")
        return

    if table.get_by_text("admin").count() == 0:
        failures.append("the export log does not name who exported")
    if table.get_by_text("búsqueda", exact=False).count() == 0:
        failures.append("the export log does not say what the export was of (ADR-0006 asks for scope)")

    # Back to the app. This is the only check that leaves it, and everything
    # after it assumes the Registry is on screen.
    page.goto("http://localhost:8083/apps/territorio/", wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1000)


def the_server_refuses_an_unconfirmed_export(page, failures):
    """The protection itself, rather than the prompt in front of it.

    Everything above checks that the dialog ASKS. That is client-side: delete
    `#[PasswordConfirmationRequired]` from the controller and every check above
    still passes, because the browser would go on prompting and the server would
    go on accepting. Nothing about a rule that guards personal data should live
    only in the code that draws the button.

    So this posts the same request with the session's own cookies and no
    confirmation at all, the way anything but this app's dialog would, and asks
    the server what it does about it (ADR-0006).
    """
    token = page.evaluate("() => document.head.querySelector('meta[name=csp-nonce]') && window.OC?.requestToken")
    if not token:
        failures.append("could not read the request token; the unconfirmed-export check did not run")
        return

    for path, expected in (
        ("/apps/territorio/export/contacts", 403),
        # The unconfirmed route must keep working, or "the prompt appears only
        # when it is earned" would be satisfied by refusing everything.
        ("/apps/territorio/export", 200),
    ):
        response = page.request.post(
            f"http://localhost:8083{path}",
            headers={"requesttoken": token, "OCS-APIRequest": "true"},
            data={"ids": [], "format": "geojson"},
        )
        if response.status != expected:
            failures.append(
                f"POST {path} without a password confirmation answered "
                f"{response.status}, expected {expected}",
            )


def go_to_the_square(page, failures):
    """Put the map over this run's «Cuadro», the way a person gets to a street: the search box.

    The map opens wherever the install's data is, which is not where this square
    is, and the picker only offers the lines in view.
    """
    name = f"Cuadro {RUN}"
    _titles, problem = _omnibox_pick(page, name, name)
    if problem:
        failures.append(f"the search could not take the map to {name!r}: {problem}")


def click_inside_the_square(page):
    """Click the middle of the map, which go_to_the_square() left inside the square."""
    canvas = page.locator('[data-testid="map-canvas"]')
    size = canvas.bounding_box()
    canvas.click(position={"x": size["width"] / 2, "y": size["height"] / 2})
    page.wait_for_timeout(1200)


def a_sector_can_be_built_from_picked_lines(page, failures):
    """#14: picked lines plus a click inside become an editable polygon.

    The lines are imported through `occ`, which is the only way to make a
    Boundary Dataset — the UI deliberately has no control for it, because
    boundaries are a cadastre somebody loads once and not a thing a person
    creates (ADR-0007, issue #12). That is a real coupling to the dev instance,
    and it is why this skips with a word rather than failing if the command does
    not run: a check that cannot set up its own data has nothing to say.

    A square four kilometres across, centred where this instance's data is, so
    that a click at the middle of the canvas is inside it whatever the map
    happens to be fitted to. Its four sides share one name, so picking the name
    picks all four — which is also the "duplicate names need no intervention"
    half of the criterion.
    """
    name = f"Cuadro {RUN}"
    south, west, north, east = -33.54, -70.60, -33.50, -70.56
    corners = [(west, south), (east, south), (east, north), (west, north)]
    sides = [
        {
            "type": "Feature",
            "properties": {"id": f"cuadro-{RUN}-{index}", "name": name},
            "geometry": {
                "type": "LineString",
                "coordinates": [list(corners[index]), list(corners[(index + 1) % 4])],
            },
        }
        for index in range(4)
    ]
    geojson = json.dumps({"type": "FeatureCollection", "features": sides})

    if not import_dataset(geojson, f"cuadro-{RUN}", boundary=True):
        skip("could not import boundary lines through occ; polygonize unchecked")
        return

    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1500)

    # Open the Sector this run drew earlier, which is what opens the picker.
    if not open_the_runs_sector(page, failures):
        return
    go_to_the_square(page, failures)

    picker = page.locator('[data-testid="boundary-picker"]')
    # By label: the picker has a second field now, for the stretches of a limit
    # that follow nothing anybody could pick (issue #15).
    box = picker.get_by_label("Buscar una calle")
    box.fill(name)
    page.wait_for_timeout(800)
    offered = picker.locator(".territorio-picker__suggestions li")
    if offered.count() == 0:
        skip("the imported square is not in view; polygonize unchecked")
        dismiss(page)
        page.locator(SEARCH).fill("")
        return

    # One click picks all four sides, because they share a name.
    offered.first.locator("button").click()
    page.wait_for_timeout(600)

    # #15's first criterion, on the actual picking path: choosing a name records
    # it as what the Sector runs along. Everything else that tests the Limit
    # hands one to the service or types a stretch; this is the only place a real
    # Boundary feature is picked and the result read.
    said = picker.locator(".territorio-picker__limit")
    if said.count() == 0 or name not in said.inner_text():
        failures.append(
            "picking a boundary feature did not record it as the Sector's Limit: "
            + (said.inner_text() if said.count() > 0 else "nothing shown"),
        )

    # And it says the name ONCE, though it stands for four separate lines.
    parts = picker.locator(".territorio-picker__parts li")
    if parts.count() != 1:
        failures.append(
            f"a street picked as four lines is {parts.count()} rows in the Limit, expected 1",
        )

    generate = picker.get_by_role("button", name="Generar el polígono")
    if generate.count() == 0:
        failures.append("picking lines did not offer to generate the polygon")
        return

    if generate.is_disabled():
        failures.append("Generar is greyed out on lines picked a moment ago")
        return

    generate.click()
    page.wait_for_timeout(400)
    if picker.get_by_text("Haga clic dentro del sector").count() == 0:
        failures.append("pressing Generar did not ask where the sector is")

    click_inside_the_square(page)

    if page.get_by_text("no cierran", exact=False).count() != 0:
        failures.append("generating over a closed square said the lines do not close")

    # Saved and read back, because the Sector this opens ALREADY had a shape —
    # it was drawn by hand earlier in this run. "The draft now has a geometry"
    # was true before the button was pressed, so the only honest question is
    # whether the shape became THIS square, and the only way to ask it is to
    # look at what was stored.
    save(page, failures, "the generated sector")

    # #49's first half, asserted while the draft is still open: `save()` rebuilds
    # the draft from what the server returned, and the server knows the Limit's
    # NAMES and not the shapes behind them. Carrying only the ids across left the
    # picker unable to generate a second time, on lines picked a moment earlier.
    # Counted before it is questioned: `is_disabled()` waits thirty seconds and then
    # RAISES when nothing matches, and there is no try/except around a check — so an
    # unguarded ask aborts the run and skips the retire at the end of main(), leaving
    # this run's four-kilometre Sector live on the map (issue #58).
    kept = picker.get_by_role("button", name="Generar el polígono")
    if kept.count() == 0:
        failures.append("saving a Sector closed the Limit picker (#49)")
    elif kept.is_disabled():
        failures.append("saving a Sector lost the shapes of the lines just picked (#49)")

    # #50's middle case, and the only place anything generates one: this save
    # wrote the geometry and the Limit in the same revision. Read here, with the
    # draft still open, because the editable-polygon check below drags a vertex
    # and saves again — which makes it the third case instead.
    a_sector_says_how_its_boundary_came_to_be(page, "together", failures, open_it=False)

    dismiss(page)
    page.wait_for_timeout(600)

    corners = exported_corners(page, f"Sector Navegador {RUN}", failures)
    if corners is None:
        return

    wanted = sorted({(round(west, 2), round(south, 2)), (round(east, 2), round(south, 2)),
                     (round(east, 2), round(north, 2)), (round(west, 2), round(north, 2))})
    if corners != wanted:
        failures.append(
            f"the generated sector is not the square that was picked: {corners} against {wanted}",
        )
        return

    a_generated_polygon_is_editable(page, wanted, failures)
    a_reopened_limit_can_generate_again(page, failures)


def a_reopened_limit_can_generate_again(page, failures):
    """#49: a reopened Sector knows the names its Limit runs along, not the shapes.

    `draftFrom()` derives `picked` from the stored Limit — which is what #15 put
    the names there for — and has nothing to fill `pickedGeometry` with. Asked to
    generate anyway, `faceAround` got an empty list, returned null, and the app
    said «Las calles elegidas no cierran alrededor de ese punto». About streets
    that do close, so the person went and re-picked streets that were already
    right.

    The shapes come back on their own now: every picked line that comes into view
    refills its own, which is safe because `canPolygonize` demands all of them. So
    the assertion is the ticket's first criterion literally — generating from a
    stored Limit works, without re-picking anything — and the button un-greying is
    what says the cache refilled.

    Runs after the polygonize check, which is what leaves this run's Sector with a
    stored Limit and its lines on screen.
    """
    if not open_the_runs_sector(page, failures):
        return
    go_to_the_square(page, failures)

    picker = page.locator('[data-testid="boundary-picker"]')
    # The button, not the parts list: that list counts hand-typed tramos too, so it
    # says "this Sector has a Limit" where what is needed is "this Sector has picked
    # LINES". The button renders on `pickedIds`, which is exactly that.
    generate = picker.get_by_role("button", name="Generar el polígono")
    if generate.count() == 0:
        skip("the reopened Sector has no picked lines, so the reopen check did not run")
        dismiss(page)
        page.locator(SEARCH).fill("")
        return

    # Greyed out until the shapes are back, so waiting for it is the assertion.
    if not settles(page, lambda: not generate.is_disabled(), timeout=10_000):
        failures.append(
            "a reopened Sector never became generatable, though its Limit's lines are on screen (#49)",
        )
        dismiss(page)
        page.locator(SEARCH).fill("")
        return

    generate.click()
    page.wait_for_timeout(400)
    click_inside_the_square(page)

    # The whole point of the ticket: no message may blame the streets when the
    # cache was what was empty.
    if page.get_by_text("no cierran", exact=False).count() != 0:
        failures.append("generating from a reopened Limit still says the lines do not close (#49)")

    # Discarded, not saved: the shape it just made is the same square the
    # polygonize check already asserted, and this run has written enough.
    dismiss(page)
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


def a_generated_polygon_is_editable(page, square, failures):
    """#14: "The result is immediately editable — vertices drag."

    Worth its own check because it was the criterion nothing exercised: the
    geometry goes into the ordinary draft, so it LOOKS obviously true, and a
    generated shape replaces a layer that was already on the map — which is not
    the path a hand-drawn one takes.
    """
    if not open_the_runs_sector(page, failures):
        return
    go_to_the_square(page, failures)

    handles = page.locator(".leaflet-marker-pane .marker-icon")
    if handles.count() == 0:
        failures.append("the generated polygon has no draggable vertices")
        dismiss(page)
        page.locator(SEARCH).fill("")
        return

    handle = handles.first.bounding_box()
    page.mouse.move(handle["x"] + handle["width"] / 2, handle["y"] + handle["height"] / 2)
    page.mouse.down()
    # Far enough to change a corner at the zoom the square was fitted to, once rounded.
    page.mouse.move(handle["x"] + 150, handle["y"] + 150, steps=12)
    page.mouse.up()
    page.wait_for_timeout(600)

    save(page, failures, "the dragged sector")
    dismiss(page)
    page.wait_for_timeout(600)

    moved = exported_corners(page, f"Sector Navegador {RUN}", failures)
    if moved is not None and moved == square:
        failures.append("dragging a vertex of the generated polygon changed nothing")


def a_gone_line_says_so_and_keeps_its_name(page, failures):
    """#51: a Limit part whose line is gone from the cadastre says so.

    The two labels are the criterion, and only a browser can see them side by
    side: «a mano» marks a stretch nobody could have picked, «ya no está en el
    catastro» marks one that WAS picked and whose line the cadastre no longer
    has. Reading one for the other sends somebody looking for a street that was
    never in any dataset.

    Runs after the polygonize and Limit checks, which between them leave this
    run's Sector with both kinds of part: the four sides of «Cuadro RUN», picked,
    and «por detrás del colegio RUN», typed.

    Retired through the app's own DELETE — ADR-0005 retires rather than destroys,
    and that is what «gone» means here — because a Boundary feature is in no list
    to click. A re-import does NOT retire what a newer file stopped publishing,
    so there is no import path to drive this from. Restored again at the end,
    because a save re-sending a Limit that names a retired line is refused by
    design, and every later save of this run's Sector walks through that refusal.
    """
    sector = registry_feature(page, f"Sector Navegador {RUN}")
    cited = [part["featureId"] for part in (sector or {}).get("limit") or [] if "featureId" in part]
    if not cited:
        skip("this run's Sector cites no boundary feature; the gone-from-cadastre label is unchecked")
        return

    retired = page.evaluate(
        """async (id) => {
            const response = await fetch(
                `/index.php/apps/territorio/features/${id}`,
                { method: 'DELETE', headers: { requesttoken: window.OC.requestToken } },
            )
            return response.status
        }""",
        cited[0],
    )
    if retired != 200:
        failures.append(f"could not retire a cited boundary feature: HTTP {retired}")
        return

    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1200)
    if not open_the_runs_sector(page, failures):
        return

    # The Registry first: the part must still be there, with its name, because
    # the sentence a team says out loud does not change because a cadastre
    # release did. Nothing swept the Sector when the line was retired.
    after = registry_feature(page, f"Sector Navegador {RUN}")
    still = [part for part in (after or {}).get("limit") or [] if part.get("featureId") == cited[0]]
    if len(still) != 1 or not still[0].get("name"):
        failures.append(f"retiring a cited line changed the Sector's Limit: {(after or {}).get('limit')}")

    name = still[0]["name"] if still else None
    rows = page.locator('[data-testid="boundary-picker"] .territorio-picker__parts li')
    said = [rows.nth(index).inner_text() for index in range(rows.count())]

    # The criterion is what the two labels look like next to each other, which is
    # a judgement no assertion makes for you. Saved so whoever lands this can look
    # at it and say what they saw, per the slice contract.
    page.locator('[data-testid="boundary-picker"]').screenshot(
        path=str(SHOTS / "gone-from-cadastre.png"),
    )

    gone = [text for text in said if "ya no está en el catastro" in text]
    if len(gone) != 1:
        failures.append(f"a Limit part whose line is gone did not say so: {said}")
    elif name is not None and name not in gone[0]:
        failures.append(f"the gone part lost its name on screen: {gone[0]!r}, expected {name!r}")

    # And the two answers stay different things. A typed tramo cites nothing, so
    # nothing can make it gone; a picked line that went away is not «a mano».
    if [text for text in said if "a mano" in text and "ya no está en el catastro" in text]:
        failures.append(f"one row reads as both typed by hand and gone from the cadastre: {said}")
    if not [text for text in said if "a mano" in text]:
        failures.append(f"the hand-typed tramo stopped saying «a mano»: {said}")

    # And put the line back. The dead reference is this check's own making,
    # and the app refuses a save that re-sends a Limit naming a retired line by
    # design (L1-21: «quite esas partes») — so leaving it retired makes every
    # later save of this run's Sector fail, starting with the 30-tramos PUT the
    # retire-control check below grows the sidebar with. Restored through the
    # door the screen offers, the same fetch shape the retire two screens up
    # used, so the cleanup proves that door too.
    restored = page.evaluate(
        """async (id) => {
            const response = await fetch(
                `/index.php/apps/territorio/features/${id}/restore`,
                { method: 'POST', headers: { requesttoken: window.OC.requestToken } },
            )
            return response.status
        }""",
        cited[0],
    )
    if restored != 200:
        failures.append(f"could not restore the line this check retired: HTTP {restored}")

    dismiss(page)
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


def retire_the_runs_record(page, name):
    """Take one of the run's own records off the map again (ADR-0005: retire,
    not delete).

    Called for every Sector a run draws, at the end. After the polygonize check
    the run's main Sector is four kilometres across and covers most of what the
    dev instance holds, so leaving it behind reassigns half the Registry to it —
    which is what a Sector is FOR, and exactly why a run must not leave one lying
    about. It broke two integration tests that way before this existed, and broke
    the same two again when the hand-drawn Sector of #48 was left behind for five
    runs (issue #58). A Place left behind is harmless; a boundary is not.
    """
    to_list(page)
    page.locator(SEARCH).fill(name)
    page.wait_for_timeout(700)
    if page.locator(ROWS).count() == 0:
        return

    open_first_row(page)
    page.wait_for_timeout(1200)

    # Two clicks: retiring asks first, because nothing else in this app does.
    ask = page.get_by_role("button", name="Retirar del mapa")
    if ask.count() > 0:
        ask.first.click()
        page.wait_for_timeout(400)
        page.get_by_role("button", name="Retirar", exact=True).first.click()
        page.wait_for_timeout(1500)

    dismiss(page)
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


def reclaim_the_runs_category(page, category, *names):
    """Take one of the run's Categories off the instance again (#131).

    The other half of `retire_the_runs_record`: a retired record still counts
    under its Category (ADR-0016), so retiring the run's records alone leaves
    the Category behind — one per run, each spending a spare colour, until the
    instance walks into the neutral branch and `TaxonomyColourTest` can no
    longer see a walk to measure (#134). This walks the refusal sentence's own
    route: restore each record (a retired record cannot be saved — the Guardar
    group is `v-if="!retired"`), reclassify it to «Sin clasificar · Otro»
    through the Subcategoría picker, retire it again (ADR-0005: retire, not
    delete), then remove the now-empty Category through the Capas bin two-step.

    App doors only — the same doors a person has. A fetch or an occ call here
    would tidy the instance while proving nothing about the doors, and a
    teardown that bypassed them could hide a broken one for good.

    Best-effort and silent like `retire_the_runs_record`: a teardown stumble
    must not fail a passing run, and a Category stranded by a crashed run must
    not make every later run fail at the same removal. Anything that cannot be
    found is left for `make instance-clean`, the way RUN's own note says.
    """
    to_list(page)
    for name in names:
        # A search filters the retired list too (listFor()), so the list must be
        # in active mode before an empty result may be read as "retired".
        if page.get_by_role("button", name="Ver retirados", exact=True).count() == 0:
            back = page.get_by_role("button", name="Ver activos", exact=True)
            if back.count() > 0:
                back.first.click()
                settles(page, lambda: page.get_by_role(
                    "button", name="Ver retirados", exact=True).count() > 0, timeout=6_000)

        page.locator(SEARCH).fill(name)
        page.wait_for_timeout(700)
        if page.locator(ROWS).count() == 0:
            # Absent from the active list: retired, or gone with a crashed run.
            # Retired must be restored first — the Guardar group is hidden while
            # the record is retired, and restoring closes the draft, so the
            # sequence is forced: restore, back to «Ver activos», reopen below.
            page.get_by_role("button", name="Ver retirados", exact=True).first.click()
            settled = settles(page, lambda: page.get_by_role(
                "button", name="Ver activos", exact=True).count() > 0, timeout=6_000)
            page.locator(SEARCH).fill(name)
            page.wait_for_timeout(700)
            if not settled or page.locator(ROWS).count() == 0:
                page.locator(SEARCH).fill("")
                continue  # gone, not retired: nothing to reclassify

            open_first_row(page)
            page.wait_for_timeout(1200)
            restore = page.get_by_role("button", name="Restaurar", exact=True)
            if restore.count() > 0:
                restore.first.click()
                page.wait_for_timeout(1500)
            dismiss(page)
            page.wait_for_timeout(300)
            page.get_by_role("button", name="Ver activos", exact=True).first.click()
            settles(page, lambda: page.get_by_role(
                "button", name="Ver retirados", exact=True).count() > 0, timeout=6_000)
            page.wait_for_timeout(400)

            page.locator(SEARCH).fill(name)
            page.wait_for_timeout(700)
            if page.locator(ROWS).count() == 0:
                page.locator(SEARCH).fill("")
                continue  # the restore never landed; leave it for instance-clean

        # Reclassified through the door the refusal sentence itself names
        # («Reclasifíquelos y vuelva a intentarlo»). «Sin clasificar» is
        # structural (#47) — it can never itself be removed — so a record parked
        # there can never orphan a Category again.
        open_first_row(page)
        page.wait_for_timeout(1200)
        # Reclassified through the door the refusal sentence itself names
        # («Reclasifíquelos y vuelva a intentarlo»). «Sin clasificar» is
        # structural (#47) — it can never itself be removed — so a record parked
        # there can never orphan a Category again. A pick that never offered its
        # option is this helper's own kind of stumble — silent, and the record
        # goes back retired where it was found, its Category left for
        # instance-clean like any other it strands.
        if not _pick(page, "Subcategoría", "Sin clasificar · Otro", [], f"{name!r}"):
            dismiss(page)
            page.wait_for_timeout(400)
            retire_the_runs_record(page, name)
            continue
        save(page, [], f"{name!r}")  # a throwaway list: teardown stays silent
        dismiss(page)
        page.wait_for_timeout(400)

        retire_the_runs_record(page, name)

    # Empty now — every record the run filed under it is retired under «Sin
    # clasificar». The bin two-step is the door #47 asserts; the throwaway
    # failures list keeps a refused removal from failing a passing run.
    row = _capas_category(page, category)
    if row.count() > 0:
        _remove_in_capas(page, row, f"«{category}»", [])


def exported_corners(page, name, failures):
    """The saved geometry of one record, read back through the export.

    Through the export rather than by reading the map, because a shape on screen
    is a path in pixels and what this needs to know is what was STORED — the same
    reason every other check here reloads rather than trusting the browser.
    """
    page.locator(SEARCH).fill(name)
    page.wait_for_timeout(800)
    page.get_by_role("button", name="Exportar", exact=True).click()
    page.wait_for_timeout(600)

    with page.expect_download(timeout=20_000) as download:
        page.get_by_role("button", name="Exportar", exact=True).last.click()
    written = json.loads(Path(download.value.path()).read_text())

    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)

    shapes = [f for f in written["features"] if f["geometry"]["type"] == "Polygon"]
    if len(shapes) != 1:
        failures.append(f"expected one polygon named {name!r}, exported {len(shapes)}")
        return None

    return sorted({(round(x, 2), round(y, 2)) for x, y in shapes[0]["geometry"]["coordinates"][0]})


def the_editor_says_so_when_the_lines_do_not_close(page, failures):
    """#14: "When no closed face contains the seed point, the user is told."

    One line cannot enclose anything, so this is the failure every time rather
    than sometimes — and it is the criterion that matters most, because a real
    limit often runs along something no dataset holds and this is what happens
    then. Silence here would leave somebody clicking the map waiting.
    """
    if not open_the_runs_sector(page, failures):
        return

    picker = page.locator('[data-testid="boundary-picker"]')
    picker.get_by_label("Buscar una calle").fill("a")
    page.wait_for_timeout(800)
    offered = picker.locator(".territorio-picker__suggestions li")
    if offered.count() == 0:
        skip("no boundary features in view to pick from")
        dismiss(page)
        page.locator(SEARCH).fill("")
        return

    offered.first.locator("button").click()
    page.wait_for_timeout(600)
    generate = picker.get_by_role("button", name="Generar el polígono")
    if generate.count() == 0:
        failures.append("picking lines did not offer to generate the polygon")
        return
    if generate.is_disabled():
        failures.append("Generar is greyed out on lines picked a moment ago")
        return

    generate.click()
    page.wait_for_timeout(400)

    canvas = page.locator('[data-testid="map-canvas"]')
    size = canvas.bounding_box()
    canvas.click(position={"x": size["width"] * 0.8, "y": size["height"] * 0.8})
    page.wait_for_timeout(1000)

    if page.get_by_text("no cierran", exact=False).count() == 0:
        failures.append("lines that do not close said nothing when generating")

    # ADR-0007: hand-drawing is available throughout, including after this.
    if page.locator(".territorio-draw-sector__button").count() == 0:
        failures.append("hand-drawing is no longer available after a failed generate")

    dismiss(page)
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


def open_the_runs_sector(page, failures):
    """Open this run's own Sector, which is what opens the Limit picker."""
    page.locator(SEARCH).fill(f"Sector Navegador {RUN}")
    page.wait_for_timeout(800)
    if page.locator(ROWS).count() == 0:
        failures.append(f"'Sector Navegador {RUN}' is not in the list to edit")
        page.locator(SEARCH).fill("")
        return False

    open_first_row(page)
    page.wait_for_timeout(1500)
    if page.locator('[data-testid="boundary-picker"]').count() == 0:
        failures.append("opening a Sector did not open the Limit picker")
        dismiss(page)
        page.locator(SEARCH).fill("")
        return False

    return True


def import_dataset(geojson, dataset, boundary):
    """Load a GeoJSON as a Dataset through occ, the only way there is.

    `boundary` decides which kind. A Boundary Dataset is left out of every
    ordinary read — including an export — so a check about what an export
    carries has to ask for an ordinary one.
    """
    container = ["docker", "compose", "-f", "compose.dev.yaml", "exec", "-T",
                 "-u", "www-data", "nextcloud"]
    written = subprocess.run(
        [*container, "sh", "-c", f"cat > /tmp/{dataset}.geojson"],
        input=geojson.encode(), capture_output=True, timeout=120,
    )
    if written.returncode != 0:
        return False

    done = subprocess.run(
        [*container, "php", "occ", "territorio:import", f"/tmp/{dataset}.geojson",
         f"--dataset={dataset}", *(["--boundary"] if boundary else [])],
        capture_output=True, timeout=300,
    )

    return done.returncode == 0


def the_limit_is_kept_and_survives_adjustment(page, failures):
    """#15: what a Sector runs along is written down, and stays written down.

    The criterion that matters is the last one: adjusting the shape by hand must
    not lose it. That is the moment a person would lose what they wrote — the
    first time they nudge a corner — and it is invisible unless something
    reopens the record and looks.
    """
    if not open_the_runs_sector(page, failures):
        return

    picker = page.locator('[data-testid="boundary-picker"]')

    # A stretch nobody could pick, which is half of what a real limit follows.
    stretch = f"por detrás del colegio {RUN}"
    picker.get_by_label("Agregar un tramo a mano").fill(stretch)
    picker.get_by_role("button", name="Agregar tramo").click()
    page.wait_for_timeout(400)

    if picker.get_by_text("Va por", exact=False).count() == 0:
        failures.append("the Limit is not shown in readable form on the Sector")

    save(page, failures, "the sector with a limit")
    dismiss(page)
    page.wait_for_timeout(600)

    # Reopened, because a Limit that only exists in this browser's memory looks
    # exactly like one that was stored.
    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1200)
    if not open_the_runs_sector(page, failures):
        return

    picker = page.locator('[data-testid="boundary-picker"]')
    if picker.get_by_text(stretch, exact=False).count() == 0:
        failures.append("the Limit did not survive a reload")
        dismiss(page)
        page.locator(SEARCH).fill("")
        return

    # Now adjust the shape by hand and save again. The Limit is intent; the
    # geometry is the agreed line, and moving one must not erase the other.
    handles = page.locator(".leaflet-marker-pane .marker-icon")
    if handles.count() > 0:
        handle = handles.first.bounding_box()
        page.mouse.move(handle["x"] + handle["width"] / 2, handle["y"] + handle["height"] / 2)
        page.mouse.down()
        page.mouse.move(handle["x"] + 40, handle["y"] + 40, steps=6)
        page.mouse.up()
        page.wait_for_timeout(500)
    else:
        # A failure, not a skip. An open Sector always has draggable vertices —
        # a_generated_polygon_is_editable asserts exactly that — so "no handle"
        # here means something is broken, and skipping would let this check
        # report green having adjusted nothing.
        failures.append("the sector had no draggable vertex, so nothing was adjusted")

    save(page, failures, "the adjusted sector")
    dismiss(page)
    page.wait_for_timeout(600)
    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1200)
    if not open_the_runs_sector(page, failures):
        return

    if page.locator('[data-testid="boundary-picker"]').get_by_text(stretch, exact=False).count() == 0:
        failures.append("adjusting the geometry by hand lost the Limit")

    dismiss(page)
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


def _wrote(entry, field):
    """A revision that wrote a value into one field — not one that CLEARED it.

    An emptied Limit is stored as null (`FeatureService::limit`), and a Sector
    whose Limit was removed was not built against one.
    """
    change = (entry.get("changes") or {}).get(field)

    return change is not None and change.get("to") is not None


def _last_wrote(history, field):
    """The newest revision that wrote a value into one field, as the sidebar reads it."""
    for entry in history:
        if _wrote(entry, field):
            return entry

    return None


def a_sector_says_how_its_boundary_came_to_be(page, state, failures, open_it=True):
    """#50: a Sector says whether its line was generated or drawn, and when it moved.

    Asserted twice over, because either half alone can be green while the feature
    is broken. The sentence on screen is what the criterion is about — and the
    stored revisions are what it must be derived FROM, so this fetches the record's
    own history through the API and checks the shape the sentence claims. A
    sidebar reading «junto con el límite» over a history where nothing ever wrote a
    Limit is exactly the failure this gate exists to catch, and it looks identical
    on screen to the real thing.

    `state` is one of drawn / together / adjusted — the three the ticket's last
    criterion asks to be distinguishable.
    """
    wording = {
        "drawn": "dibujada a mano",
        "together": "junto con el límite",
        # A generated Sector saved again still names the Limit it came from, and
        # adds the second date — so what tells this state from "together" on screen
        # is the re-save clause, not the origin one (#50, criterion 1).
        "adjusted": "guardada de nuevo",
    }[state]

    if open_it and not open_the_runs_sector(page, failures):
        return

    said = page.locator('[data-testid="boundary-provenance"]')
    # The sentence needs the history, which is fetched after the sidebar opens.
    if not settles(page, lambda: said.count() > 0, timeout=10_000):
        failures.append(f"the Sector says nothing about how its boundary came to be (#50, {state})")
    else:
        text = said.inner_text().strip()
        if wording not in text:
            failures.append(f"the Sector says {text!r}, which does not read as {state!r} (#50)")
        # The keys stopped being mutually exclusive when the adjusted sentence
        # gained its origin clause: «Línea fijada junto con el límite … y guardada
        # de nuevo …» CONTAINS "together"'s whole key, so the key alone lets an
        # adjusted sentence pass as a together one and the two states are no
        # longer distinguishable — which is criterion 5 itself (#50). What
        # separates them is the re-save clause, so "together" must also NOT have
        # it.
        if state == "together" and "guardada de nuevo" in text:
            failures.append(
                f"the Sector says {text!r}, which is an adjusted sentence read as {state!r} (#50)"
            )
        # The framing constraint, on the real path: the Limit records intent and
        # the geometry records the agreed line, so a sentence calling them wrong
        # to differ is a defect however accurate its dates are.
        for wrong in ("desfase", "no coinciden", "desajust", "corregir"):
            if wrong in text.lower():
                failures.append(f"the provenance sentence reads as a defect to fix: {text!r} (#50)")

    name = f"Sector Navegador {RUN}"
    feature = registry_feature(page, name)
    if feature is None:
        failures.append(f"{name!r} is not in the Registry, so its provenance rests on nothing")
    else:
        history = page.evaluate(
            """async (id) => {
                const response = await fetch(
                    `/index.php/apps/territorio/features/${id}/history`,
                    { headers: { requesttoken: window.OC.requestToken } },
                )
                return await response.json()
            }""",
            feature["id"],
        )
        line = _last_wrote(history, "geometry")
        limit = _last_wrote(history, "limit")

        if line is None:
            failures.append("the stored history records no geometry, so the sentence is derived from nothing (#50)")
        elif state == "drawn" and any(
            _wrote(entry, "limit") and entry["revision"] <= line["revision"] for entry in history
        ):
            # A Limit written down AFTER the line still reads «dibujada a mano»,
            # and rightly: generating needs picked lines and saves the two
            # together, so a line written while no Limit stood was drawn. What
            # would make the sentence false is a Limit that already stood.
            failures.append("the sidebar said hand-drawn while a Limit already stood (#50)")
        elif state == "together" and (limit is None or limit["revision"] != line["revision"]):
            failures.append("the sidebar said generated while no stored revision wrote both (#50)")
        elif state == "adjusted" and (limit is None or line["revision"] <= limit["revision"]):
            failures.append("the sidebar dated an adjustment the stored history does not show (#50)")
        elif state == "adjusted" and not any(
            _wrote(entry, "geometry") and _wrote(entry, "limit") and entry["revision"] <= line["revision"]
            for entry in history
        ):
            # The origin half of the same sentence: it credits the Limit for this
            # boundary, so some revision must have written the two together.
            failures.append("the sidebar credited the Limit for a line no revision wrote with one (#50)")

    if open_it:
        dismiss(page)
        page.locator(SEARCH).fill("")
        page.wait_for_timeout(400)


def an_imported_sector_says_it_came_from_the_catastro(page, failures):
    """#50's third origin: a boundary the municipality published is not a hand.

    Driven through a real import, because the defect lives precisely between the
    server and the sidebar and nothing else reaches it. `ImportService::classify()`
    files a row under any Subcategory, Sector included, and an import writes no
    Limit — so an imported Sector's history is a hand-drawn one's byte for byte,
    and the only thing that tells them apart is `datasetId`, shipped by
    `Feature::toClient()` and carried onto the draft by `draftFrom()`. A unit test
    cannot see that wiring: `provenance()` is handed the flag. This is the check
    that would fail if the flag stopped arriving.

    The polygon is small and deliberately nowhere near this instance's data. An
    imported Sector claims every Place it covers (ADR-0008), and a check that
    reassigns half the Registry to itself to read one sentence has done more
    damage than the sentence is worth.
    """
    name = f"Sector Catastro {RUN}"
    south, west, north, east = -33.71, -70.41, -33.70, -70.40
    geojson = json.dumps({
        "type": "FeatureCollection",
        "features": [{
            "type": "Feature",
            "properties": {
                "id": f"catastro-{RUN}",
                "name": name,
                # The taxonomy an import files a Sector under — the same pair an
                # export writes for one, so this is a round trip of real data.
                "category": "division_territorial",
                "subcategory": "sector",
            },
            "geometry": {
                "type": "Polygon",
                "coordinates": [[[west, south], [east, south], [east, north],
                                 [west, north], [west, south]]],
            },
        }],
    })

    if not import_dataset(geojson, f"catastro-{RUN}", boundary=False):
        skip("could not import a Sector through occ; the imported-boundary wording is unchecked")
        return

    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1500)

    # The Registry first, and it is not decoration: a sentence that says the right
    # thing about a record which never came from a Dataset proves nothing at all.
    stored = registry_feature(page, name)
    if stored is None:
        failures.append(f"{name!r} is not in the Registry, so nothing was imported to read (#50)")
        return
    if stored.get("datasetId") is None:
        failures.append(
            f"{name!r} carries no datasetId, so the record is not imported and the wording "
            "below would be checked against the wrong thing (#50)",
        )
        return

    page.locator(SEARCH).fill(name)
    page.wait_for_timeout(800)
    if page.locator(ROWS).count() == 0:
        failures.append(f"{name!r} is in the Registry but not in the list to open (#50)")
        page.locator(SEARCH).fill("")
        return

    open_first_row(page)
    page.wait_for_timeout(1500)

    said = page.locator('[data-testid="boundary-provenance"]')
    if not settles(page, lambda: said.count() > 0, timeout=10_000):
        failures.append("the imported Sector says nothing about how its boundary came to be (#50)")
    else:
        text = said.inner_text().strip()
        if "a mano" in text:
            failures.append(f"an imported boundary is told as hand-drawn: {text!r} (#50)")
        elif "importada" not in text:
            failures.append(f"the imported Sector says {text!r}, which does not name the import (#50)")

    dismiss(page)
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


def the_map_survives_churn(page, errors, failures):
    """Zoom and pan with the Limit picker open, which is when groups get swapped.

    The null-map error this catches — "Cannot read properties of null (reading
    'latLngToLayerPoint')" — appears intermittently, because it needs a redraw
    to land inside a view change. But the broken handler it leaves behind is
    permanent: once one exists, EVERY later zoom throws and the map stops
    drawing. So churning first and zooming after turns a fault you see one run
    in three into one you see every run it is present.

    Run with a Sector open on purpose: the picker reloads its lines on every
    move, which is the redraw most likely to collide with one.
    """
    page.locator(SEARCH).fill(f"Sector Navegador {RUN}")
    page.wait_for_timeout(400)
    open_first_row(page)
    page.wait_for_timeout(1000)

    before = len(errors)
    canvas = page.locator('[data-testid="map-canvas"]')
    zoom_in = page.locator(".leaflet-control-zoom-in")
    zoom_out = page.locator(".leaflet-control-zoom-out")

    for _ in range(4):
        zoom_in.click()
        page.wait_for_timeout(250)
        canvas.hover()
        page.mouse.down()
        page.mouse.move(700, 400, steps=6)
        page.mouse.up()
        page.wait_for_timeout(250)
        zoom_out.click()
        page.wait_for_timeout(250)

    # The zoom that a broken handler cannot survive.
    zoom_in.click()
    page.wait_for_timeout(600)

    if len(errors) > before:
        failures.append(f"the map threw while being zoomed and panned: {errors[before:][:2]}")

    # And it is still a map: a handler that threw mid-view-change leaves the
    # layers where they were, so this asks whether anything is drawn at all.
    if page.locator(MARKERS).count() == 0 and page.locator(".leaflet-tile").count() == 0:
        failures.append("nothing is drawn on the map after zooming and panning")

    dismiss(page)
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


def search_narrows_everything(page, failures):
    """#19: the box narrows the Datos list, the map and the counts together.

    Scoped to our own box: Nextcloud's header has a "Buscar contactos" field of
    its own, and `get_by_label("Buscar")` finds three things.
    """
    box = page.locator(SEARCH)
    rows = page.locator(ROWS)
    markers = page.locator(MARKERS)

    def analysis_total():
        _pane(page, "Análisis")
        text = page.locator(".territorio-analisis h2").inner_text()
        _pane(page, "Mapa")
        return int(text.split()[0])

    before_rows = rows.count()

    # This run's two records and nothing else: one Place, which is one pin, and
    # one Sector, which is a polygon and so has no pin at all.
    box.fill(RUN)
    page.wait_for_timeout(500)
    if rows.count() != 2:
        failures.append(
            f"search found {rows.count()} of this run's 2 records: "
            f"{[row.inner_text().splitlines()[0] for row in rows.all()]}",
        )
    if markers.count() != 1:
        failures.append(f"the map shows {markers.count()} markers, expected the one Place")
    counted = analysis_total()
    if counted != 2:
        failures.append(f"Análisis counted {counted}, expected 2 — it is not reading the same set")

    # Case, in the running app rather than only in the predicate's unit test.
    box.fill(f"sECTOR nAVEGADOR {RUN}")
    page.wait_for_timeout(400)
    if rows.count() != 1:
        failures.append("search is case-sensitive in the browser")

    # Type, THEN toggle — the order a person actually uses, and the one where the
    # togglers used to return a bare {hidden} and drop the query on the floor.
    # Both reviews found that by reading; nothing here would have.
    box.fill(RUN)
    page.wait_for_timeout(300)
    # The label, not the input: NcCheckboxRadioSwitch draws a span over the box,
    # and a person clicks the word anyway. Untick Sector and this run's Sector
    # should leave, while its Place — and the query — stay.
    toggle_division(page, "Sector")
    if box.input_value() != RUN:
        failures.append("a Capas toggle wiped the search box")
    if rows.count() != 1:
        failures.append(f"toggle+query left {rows.count()} rows, expected the 1 Place")

    # Put Sector back, or the restore check below is comparing against a Capas
    # selection this function changed.
    toggle_division(page, "Sector")

    # Nothing matched: a message, not a bare empty list.
    box.fill("zzzz-no-existe")
    page.wait_for_timeout(400)
    if page.get_by_text("Nada coincide con la búsqueda").count() == 0:
        failures.append("an empty search result said nothing")

    # Clearing restores exactly what Capas alone selects.
    box.fill("")
    page.wait_for_timeout(400)
    if rows.count() != before_rows:
        failures.append(f"clearing did not restore the list ({rows.count()} vs {before_rows})")


def _folded(value):
    """Lower-cased and stripped of accents, exactly as `filters.js` folds.

    The Índice sorts with `localeCompare(…, { sensitivity: 'base' })`, which is
    the same rule: case and accent are not letters. Written out here rather than
    reached for through a locale, because the names this run makes are the ones
    being ordered and this says in four lines what is being claimed about them.
    """
    return "".join(
        character for character in unicodedata.normalize("NFD", value)
        if not unicodedata.combining(character)
    ).lower()


def _indice_table(page):
    """The table as it is drawn: the column keys, and a dict per row.

    By `data-key` and not by position — a hidden column shifts every index, and a
    check that read column 6 would quietly start asserting about the next one.
    """
    return page.evaluate(
        """() => {
            const table = document.querySelector('[data-testid="indice-table"]')
            if (table === null) {
                return { columns: [], rows: [] }
            }
            return {
                columns: [...table.querySelectorAll('thead th')].map((th) => th.dataset.key),
                rows: [...table.querySelectorAll('tbody tr')].map((tr) => Object.fromEntries(
                    [...tr.querySelectorAll('td')].map((td) => [td.dataset.key, td.innerText.trim()]),
                )),
            }
        }""",
    )


def indice_columns(page):
    """The Índice's column checkboxes, folded behind the «Columnas» disclosure: open it."""
    details = page.locator(".territorio-indice__columns-details")
    if not details.evaluate("node => node.open"):
        details.locator("summary").click()
    return page.locator(INDICE_COLUMNS)


def _pane(page, name):
    """Switch the main area to one pane through the topbar's tabs."""
    page.locator(".territorio-omnibox__tab", has_text=name).click()
    page.wait_for_timeout(300)


# What `locationQuality` is called on screen, kept in step with QUALITY_LABELS in
# src/indice.js — the criterion is that the Exactitud column says a word, and a
# check that read the stored value back off the screen would pass on either.
QUALITIES = {"exacta": "Exacta", "aproximada": "Aproximada", "sin_coordenada": "Sin coordenada"}


def the_indice_reads_the_registry(page, failures):
    """#105: a fourth pane showing the Registry as rows, sorted by its headers.

    The pane draws the one filtered set — the same array the map, the Datos list,
    the Análisis counts and Exportar read — so most of what is checked here is
    that it AGREES with them rather than that it can count. `filters.js` says why
    a second narrowing path would be the bug: the table would show one number
    while Exportar carried another.

    Asserted against the Registry as the change feed hands it back, not against
    the screen: a Sector column is the whole criterion when it holds a name and
    worthless when it holds the id that was stored, and both look like a table.

    Readability at comuna scale rides here too: every cell says its full text
    through a native `title`, the first SHOWN column stays pinned under
    horizontal scroll, and hiding «Nombre» moves that pin — and the open
    button — to the next shown column.
    """
    box = page.locator(SEARCH)

    # Whatever ran before this may have left a record open, and the check below
    # that clicking a row OPENS one would then be true before it clicked: the
    # sidebar is already there, holding somebody else's name.
    dismiss(page)

    # This run's records and nothing else, so what follows is exact rather than
    # "whatever the instance has accumulated".
    box.fill(RUN)
    page.wait_for_timeout(500)
    _pane(page, "Índice")

    drawn = _indice_table(page)
    names = [row["name"] for row in drawn["rows"]]

    # A sort over one row is true whatever the sort does. Fail rather than pass
    # quietly: this whole file exists because a green line hid an empty screen.
    if len(names) < 3:
        failures.append(
            f"the Índice drew {len(names)} rows for this run — too few to prove a sort, so "
            f"nothing below was really checked",
        )
        page.screenshot(path=str(SHOTS / "indice-empty.png"), full_page=False)

    if drawn["columns"][:3] != ["name", "category", "subcategory"]:
        failures.append(f"the Índice opened with columns {drawn['columns']}")

    # The same set the Datos list is showing, which is the set Exportar takes its
    # ids from (`exportableIds` is that list minus retired records, and none of
    # these is retired). One filtered array, four surfaces.
    listed = page.locator(ROWS).count()
    if len(names) != listed:
        failures.append(
            f"the Índice shows {len(names)} rows while Datos — and so Exportar — has {listed}",
        )

    # Every row is a record the Registry actually holds, by name. A table can
    # invent a row as easily as it can drop one.
    registry = {feature["name"]: feature for feature in registry_features(page)}
    for name in names:
        if name not in registry:
            failures.append(f"the Índice drew a row for {name!r}, which the Registry does not hold")
        elif registry[name]["retired"]:
            failures.append(f"the Índice drew {name!r}, which is retired — the map would not")

    # The criterion: the rendered order IS the order those names sort into.
    if names != sorted(names, key=_folded):
        failures.append(f"the Índice did not open sorted by Nombre: {names}")

    page.locator(f'{INDICE} th[data-key="name"] button').click()
    page.wait_for_timeout(200)
    flipped = [row["name"] for row in _indice_table(page)["rows"]]
    if flipped != sorted(names, key=_folded, reverse=True):
        failures.append(f"clicking Nombre again did not reverse the order: {flipped}")

    page.locator(f'{INDICE} th[data-key="name"] button').click()
    page.wait_for_timeout(200)
    if [row["name"] for row in _indice_table(page)["rows"]] != sorted(names, key=_folded):
        failures.append("a third click on Nombre did not come back to ascending")

    # Never an id (the criterion). Checked against what the Registry stored: the
    # Sector cell must be the NAME of the boundary whose id is on the record, or
    # «sin asignar» — and a cell holding the bare id would pass any check that
    # only looked at the screen.
    for row in _indice_table(page)["rows"]:
        stored = registry.get(row["name"])
        if stored is None:
            continue

        expected = "sin asignar"
        if stored["sectorId"] is not None:
            boundary = next(
                (f for f in registry.values() if f["id"] == stored["sectorId"]), None,
            )
            expected = boundary["name"] if boundary is not None else "sin asignar"
        if row["sector"] != expected:
            failures.append(
                f"{row['name']!r} shows Sector {row['sector']!r}; the Registry stores "
                f"{stored['sectorId']!r}, which is {expected!r}",
            )

        if row["category"].isdigit() or row["subcategory"].isdigit():
            failures.append(
                f"{row['name']!r} shows its classification as an id: "
                f"{row['category']!r} / {row['subcategory']!r}",
            )

        if row["quality"] != QUALITIES.get(stored["locationQuality"], ""):
            failures.append(
                f"{row['name']!r} shows Exactitud {row['quality']!r} for a record the Registry "
                f"stores as {stored['locationQuality']!r}",
            )

    # Hiding a column, and the criterion that the choice survives a pane switch.
    # The pane is rebuilt on every switch (v-if), so this is the whole of it.
    indice_columns(page).get_by_text("Teléfono", exact=True).click()
    page.wait_for_timeout(200)
    if "phone" in _indice_table(page)["columns"]:
        failures.append("unticking «Teléfono» left the column on screen")

    _pane(page, "Análisis")
    _pane(page, "Índice")
    if "phone" in _indice_table(page)["columns"]:
        failures.append("the hidden column came back on the way through Análisis")
    if "name" not in _indice_table(page)["columns"]:
        failures.append("switching panes lost the columns that were on")

    indice_columns(page).get_by_text("Teléfono", exact=True).click()
    page.wait_for_timeout(200)
    if "phone" not in _indice_table(page)["columns"]:
        failures.append("ticking «Teléfono» back did not bring the column back")

    # A clipped cell still says everything it holds: the native `title` carries
    # the full text, and an empty cell carries no attribute at all — a tooltip
    # on nothing is noise on a table where most cells ARE nothing.
    cells = page.evaluate(
        """() => {
            const table = document.querySelector('[data-testid="indice-table"]')
            if (table === null) {
                return []
            }
            return [...table.querySelectorAll('tbody tr')].map((tr) => Object.fromEntries(
                [...tr.querySelectorAll('td')].map((td) => [
                    td.dataset.key,
                    { text: td.innerText.trim(), title: td.getAttribute('title') },
                ]),
            ))
        }"""
    ) or []
    for row in cells:
        name = row["name"]["text"] if "name" in row else "?"
        for key, cell in row.items():
            if cell["text"] != "" and cell["title"] != cell["text"]:
                failures.append(
                    f"{name!r} shows {key} {cell['text']!r} under title {cell['title']!r}",
                )
            if cell["text"] == "" and cell["title"] is not None:
                failures.append(f"{name!r} carries a title on its empty {key} cell")

    # The first shown column stays at the pane's left edge under horizontal
    # scroll: a comuna's table is wider than the pane, and a row whose first
    # cell scrolled away is a row nobody can name. The viewport is narrowed
    # first because a pin nothing scrolled past proves nothing — at 1400px
    # every column can fit.
    page.set_viewport_size({"width": 800, "height": 900})
    page.wait_for_timeout(400)
    pinned = page.evaluate(
        """() => {
            const table = document.querySelector('[data-testid="indice-table"]')
            if (table === null) {
                return null
            }
            const pane = document.querySelector('.territorio-indice')
            pane.scrollLeft = pane.scrollWidth
            const corner = table.querySelector('thead th')
            const second = table.querySelector('tbody td:nth-child(2)')
            return {
                scrolled: pane.scrollLeft,
                paneLeft: pane.getBoundingClientRect().left,
                corner: corner === null ? null : corner.getBoundingClientRect().left,
                firsts: [...table.querySelectorAll('tbody td:first-child')]
                    .map((td) => td.getBoundingClientRect().left),
                second: second === null ? null : second.getBoundingClientRect().left,
            }
        }"""
    ) or {"scrolled": 0}
    if pinned["scrolled"] <= 0:
        failures.append(
            "the Índice pane would not scroll sideways at 800px — the pin went unproved",
        )
    else:
        if pinned["corner"] is None or pinned["corner"] < pinned["paneLeft"] - 1:
            failures.append("the pinned header scrolled off the pane's left edge")
        loose = [left for left in pinned["firsts"] if abs(left - pinned["corner"]) > 1]
        if loose:
            failures.append(f"{len(loose)} rows let their first cell off the pinned column")
        if pinned["second"] is not None and pinned["second"] >= pinned["corner"] - 1:
            failures.append("scrolling sideways moved nothing under the pinned column")
    page.evaluate("() => { document.querySelector('.territorio-indice').scrollLeft = 0 }")
    page.set_viewport_size({"width": 1400, "height": 900})
    page.wait_for_timeout(400)

    # Hiding «Nombre» moves the pin — and the open button — to the next shown
    # column: a row must stay named and openable whatever columns a person
    # keeps, not because one fixed column is special.
    indice_columns(page).get_by_text("Nombre", exact=True).click()
    page.wait_for_timeout(200)
    if _indice_table(page)["columns"][0] != "category":
        failures.append("hiding «Nombre» did not make «Categoría» the first shown column")
    moved = page.evaluate(
        """() => {
            const table = document.querySelector('[data-testid="indice-table"]')
            if (table === null) {
                return null
            }
            const corner = table.querySelector('thead th')
            return {
                key: corner === null ? null : corner.dataset.key,
                pinned: corner !== null
                    && corner.classList.contains('territorio-indice__cell--first'),
                buttons: table.querySelectorAll(
                    'tbody td:first-child .territorio-indice__open',
                ).length,
                rows: table.querySelectorAll('tbody tr').length,
            }
        }"""
    ) or {"key": None, "pinned": False, "buttons": -1, "rows": 0}
    if moved["key"] != "category" or not moved["pinned"]:
        failures.append(f"hiding «Nombre» left the pin on {moved['key']!r}")
    if moved["buttons"] != moved["rows"]:
        failures.append("the open button did not follow the first shown column")
    indice_columns(page).get_by_text("Nombre", exact=True).click()
    page.wait_for_timeout(200)
    if _indice_table(page)["columns"][0] != "name":
        failures.append("ticking «Nombre» back did not bring the column back")

    # Capas narrows the table, the Datos list and the Análisis counts together —
    # the criterion, and the reason there is no per-column filter here.
    toggle_division(page, "Sector")
    narrowed = len(_indice_table(page)["rows"])
    if narrowed >= len(names):
        failures.append(
            f"unticking Sector left the Índice at {narrowed} of {len(names)} rows — it is not "
            f"reading the one filtered set",
        )
    if narrowed != page.locator(ROWS).count():
        failures.append(
            f"Capas left the Índice at {narrowed} rows and Datos at {page.locator(ROWS).count()}",
        )

    _pane(page, "Análisis")
    counted = int(page.locator(".territorio-analisis h2").inner_text().split()[0])
    _pane(page, "Índice")
    if counted != narrowed:
        failures.append(f"Análisis counted {counted} while the Índice drew {narrowed}")

    toggle_division(page, "Sector")

    # Clicking a row opens that record. Scoped to the sidebar's own form: the
    # Índice draws a «Nombre» header and a «Nombre» checkbox, and an unscoped
    # label lookup would find three things.
    remaining = _indice_table(page)["rows"]
    if not remaining:
        failures.append("the Índice had no row left to open, so opening a record was not checked")
        box.fill("")
        _pane(page, "Mapa")
        return

    opening = remaining[0]["name"]
    page.locator(f"{INDICE} tbody tr").first.click()
    if not settles(page, lambda: page.locator(".territorio-detail").count() > 0, timeout=6_000):
        failures.append(f"clicking the row for {opening!r} opened nothing")
    else:
        edit(page)
        opened = page.locator(".territorio-detail").get_by_label("Nombre").input_value().strip()
        if opened != opening:
            failures.append(f"clicking the row for {opening!r} opened {opened!r}")
        page.screenshot(path=str(SHOTS / "indice.png"), full_page=False)
        dismiss(page)
        page.wait_for_timeout(300)

    box.fill("")
    page.wait_for_timeout(400)
    _pane(page, "Mapa")


def a_csv_imports_and_says_what_does_not_come_back_in(page, failures):
    """#101: a spreadsheet goes in through the dialog, and the records are there.

    The file is the one a Chilean clinic actually sends — saved by Excel in
    es-CL, so `;` between the cells and a decimal comma inside the coordinates,
    with accents in the names. Nothing below the browser exercises that path
    end to end: the unit tests read the bytes, the integration test calls the
    service, and this is the only check that goes through the upload the person
    actually uses.

    Asserted on the REGISTRY, not on the dialog's own success line. That line is
    the response to the POST and would read "1 creados" even if nothing had been
    written; `registry_feature` is the read a second browser would do.

    The notice is checked the way the KML one is, including the two negatives —
    not before a file is chosen, and not for a GeoJSON — because a notice that is
    always on the screen says nothing about the file in the box.
    """
    name = f"CESFAM Ñuñoa {RUN}"
    # Away from the cadastre and from the other checks' coordinates, nudged per
    # run: every record this gate makes stays (ADR-0005), and a fixed spot would
    # put this run's import on top of the last one's.
    lat, lon = round(-33.46 - NUDGE / 200, 4), round(-70.46 - NUDGE / 200, 4)
    # `;` between the cells and a comma inside the coordinates: in es-CL the
    # comma is the decimal separator, so Excel uses the semicolon to separate.
    # The address cell carries a comma too, which is the other half of the same
    # file — a reader that split on commas would shift every column after it.
    # The classification cells say what the Capas panel says — «Salud», «CESFAM»
    # — because that is what a person copies off the screen. `classify()` matches
    # on the SLUG, so a file typed this way is the one that used to land in Sin
    # clasificar without a count to explain it.
    csv = (
        "Nombre;Categoría;Subcategoría;Latitud;Longitud;Dirección;Teléfono\n"
        f"{name};Salud;CESFAM;{lat};{lon};Avenida Uno 123, local 4;229112233\n"
    ).replace(".", ",")

    # The KML check runs immediately before this one and leaves the dialog OPEN:
    # measured, not assumed — after `set_input_files` its closing Escape does not
    # reach the NcDialog, and the picture that check saves afterwards is a
    # picture of an open dialog. Nothing noticed because it used to be the last
    # thing to run before `browser.close()`. So this check closes what it finds
    # and opens its own: the first assertion below is about a dialog with no file
    # chosen, and a dialog still holding `mymaps.kml` would make it a lie.
    closer = page.get_by_role("dialog").get_by_role("button", name="Close")
    if closer.count() != 0:
        closer.first.click()
        page.wait_for_timeout(600)

    page.get_by_role("button", name="Importar / deshacer").click()
    page.wait_for_timeout(600)

    notice = page.get_by_text("no vuelve a entrar")
    if notice.count() != 0:
        failures.append("the CSV notice is shown before a file is even chosen")

    page.locator('input[type="file"]').set_input_files({
        "name": "algo.geojson",
        "mimeType": "application/geo+json",
        "buffer": b'{"type":"FeatureCollection","features":[]}',
    })
    page.wait_for_timeout(400)
    if notice.count() != 0:
        failures.append("the CSV notice is shown for a GeoJSON file")

    page.get_by_label("Conjunto de datos").fill(f"planilla-{RUN}")
    page.locator('input[type="file"]').set_input_files({
        "name": f"planilla-{RUN}.csv",
        "mimeType": "text/csv",
        # UTF-8, as a spreadsheet saving for a Spanish locale writes it.
        "buffer": csv.encode("utf-8"),
    })
    page.wait_for_timeout(500)
    if notice.count() == 0:
        failures.append("choosing a CSV said nothing about the export not coming back in")

    page.get_by_role("button", name="Importar", exact=True).click()
    page.wait_for_timeout(2500)
    page.screenshot(path=str(SHOTS / "csv-import.png"), full_page=False)
    dismiss(page)
    page.wait_for_timeout(800)

    stored = registry_feature(page, name)
    if stored is None:
        failures.append(f"{name!r} is not in the Registry, so the CSV imported nothing (#101)")
        return

    if stored.get("datasetId") is None:
        failures.append(f"{name!r} carries no datasetId, so it is not an imported record (#101)")

    # The decimal comma survived as a coordinate rather than as a shifted column:
    # geometry here is what the Registry stores, not what the file said.
    position = (stored.get("geometry") or {}).get("coordinates")
    if not position or abs(position[1] - lat) > 1e-6 or abs(position[0] - lon) > 1e-6:
        failures.append(
            f"the imported record sits at {position!r}, not at the [{lon}, {lat}] "
            "the decimal-comma cells described (#101)",
        )

    # Filed where the typed cells say, not in Sin clasificar. Read off the
    # taxonomy the app serves rather than off a number written here, because the
    # seeded ids are the instance's and not this file's.
    salud = next((entry for entry in served_taxonomy(page) or [] if entry["slug"] == "salud"), None)
    cesfam = next(
        (sub for sub in (salud or {}).get("subcategories", []) if sub["slug"] == "cesfam"), None,
    )
    if cesfam is None:
        failures.append("the app served no «Salud · CESFAM», so nothing could check where the CSV filed")
    elif stored.get("subcategoryId") != cesfam["id"]:
        failures.append(
            f"{name!r} was filed under Subcategory {stored.get('subcategoryId')!r} and not under "
            f"the {cesfam['id']!r} «Salud · CESFAM» the «Salud»/«CESFAM» cells named — a person "
            "typing what the app shows them lands in Sin clasificar (#101)",
        )
def a_kmz_imports_like_the_kml_inside_it(page, errors, failures):
    """#103: the dialog takes a KMZ, warns as it does for a KML, and imports it.

    The PHP tests pin the unzipping — the first `.kml` entry whatever it is
    called, the refusals, the temporary file. What only the running app can
    answer is that a person who picks a `.kmz` in the file dialog sees the
    lossiness notice before committing to it, and that the record comes out the
    other end. Both halves are asserted here: the notice on the screen, and the
    record in the REGISTRY rather than in the dialog's own summary, which is the
    server's own reply and would say "1 creado" whether or not anything was
    written.

    The archive is awkward in the two ways real ones are, because those are the
    criteria: named `.KMZ` in capitals, and holding an icon ahead of a document
    that nobody called `doc.kml`.

    The Place it imports is left behind, sited at this run's nudge like every
    other record these checks create. A leftover Place is inert — unlike a
    leftover Sector, which would claim every Place it covers, run after run.
    """
    name = f"Junta del KMZ {RUN}"
    lat, lon = -33.49 - NUDGE / 200, -70.49 - NUDGE / 200
    kml = (
        '<?xml version="1.0" encoding="UTF-8"?>'
        '<kml xmlns="http://www.opengis.net/kml/2.2"><Document><Folder><name>Salud</name>'
        f"<Placemark><name>{name}</name>"
        f"<Point><coordinates>{lon},{lat},0</coordinates></Point>"
        "</Placemark></Folder></Document></kml>"
    ).encode()

    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w") as archive:
        # Ahead of the KML on purpose: "the first entry" and "the first .kml
        # entry" are different rules, and only the second one is the format's.
        archive.writestr("images/icono.png", "no soy kml")
        archive.writestr("capa principal.kml", kml)
    kmz = buffer.getvalue()

    # The CSV check runs immediately before this one and leaves its dialog OPEN,
    # for the reason that check documents: after `set_input_files` an Escape does
    # not reach the NcDialog. Clicking the toolbar button behind an open modal
    # does not open a second one, so this check closes what it finds first.
    closer = page.get_by_role("dialog").get_by_role("button", name="Close")
    if closer.count() != 0:
        closer.first.click()
        page.wait_for_timeout(600)

    page.get_by_role("button", name="Importar / deshacer").click()
    page.wait_for_timeout(600)

    # The file picker offers it. Asserted on the attribute because Playwright
    # sets the file straight onto the input and would sail past an `accept` that
    # no longer listed the format — a person choosing the file by hand would not.
    accept = page.locator('input[type="file"]').get_attribute("accept") or ""
    if ".kmz" not in accept:
        failures.append(f"the import dialog does not offer .kmz in the file picker: {accept!r}")

    page.locator('input[type="file"]').set_input_files({
        "name": f"catastro-{RUN}.KMZ",
        "mimeType": "application/vnd.google-earth.kmz",
        "buffer": kmz,
    })
    page.wait_for_timeout(400)
    if page.get_by_text("No trae teléfono").count() == 0:
        failures.append("choosing a KMZ said nothing about what it cannot carry")

    # Kept: the notice is a sentence a person reads, and a count of zero is not
    # something anybody can check back on afterwards.
    page.screenshot(path=str(SHOTS / "kmz-import.png"), full_page=False)

    # A Dataset of this run's own, so re-running updates nothing from last time:
    # matching is on (Dataset, external id).
    page.get_by_label("Conjunto de datos").fill(f"kmz-{RUN}")
    page.get_by_role("button", name="Importar", exact=True).click()
    page.wait_for_timeout(2500)

    imported = registry_feature(page, name)
    if imported is None:
        failures.append(f"the KMZ import left no {name!r} in the Registry")
    elif (imported.get("geometry") or {}).get("type") != "Point":
        failures.append(f"the record from the KMZ arrived as {imported.get('geometry')!r}")

    # And a `.kmz` that is not an archive is answered with the sentence, not with
    # a stack trace or a silent nothing. Same dialog, still open.
    before = len(errors)
    page.locator('input[type="file"]').set_input_files({
        "name": f"roto-{RUN}.kmz",
        "mimeType": "application/vnd.google-earth.kmz",
        "buffer": b"no soy un zip",
    })
    page.wait_for_timeout(400)
    page.get_by_role("button", name="Importar", exact=True).click()
    page.wait_for_timeout(2000)
    if page.get_by_text("no es un KMZ").count() == 0:
        failures.append("a .kmz that is not an archive was not refused in words")

    # The refusal IS an HTTP 422 and the browser logs every failed response as a
    # console error, which main()'s run-wide gate would then report. Exactly the
    # entries this refusal added are dropped, and only the 422 among them — the
    # same scoping the Capas refusals use.
    errors[before:] = [message for message in errors[before:] if "422" not in message]

    dismiss(page)
    page.wait_for_timeout(400)


def a_file_already_in_files_imports_without_being_uploaded(page, errors, failures):
    """#104: the file is already in Nextcloud Files, and is imported from there.

    The whole point of the slice is the half no PHP test can reach: the request
    carries a PATH, and the bytes are read by the server from the caller's own
    tree. So the file is put into Files over WebDAV first — the way a person
    would have got it there — and then picked with the platform's own file
    picker, which is what `OC.dialogs.filepicker` opens.

    Asserted on the REGISTRY, like every other import check here: the dialog's
    success line is the server's own reply and would read "1 creados" whether or
    not a record was written.

    The picker is core's, not this app's, so its markup is the one thing in this
    function that can change under us. It is driven by the file's own name and a
    Choose/Elegir button, and it leaves a screenshot either way — if this fails
    on a selector rather than on the app, `files-picker.png` is what says so.

    A KML is picked BEFORE the GeoJSON that gets imported, because the reader is
    chosen from the name in both directions and the only place that can be seen
    is the browser: `chosenName` reads the suffix off the PATH when one is
    chosen and off the uploaded File when one is not, and nothing else in the
    repo loads a component. #103 added the lossiness notice precisely so a
    person is warned before importing, and a file reached through Files must
    warn them the same way. The same pass checks that choosing from Files clears
    the upload control — otherwise the control names one file while the hint
    names another, and only the hint is true.
    """
    name = f"Plaza de Archivos {RUN}"
    lat, lon = round(-33.47 - NUDGE / 200, 4), round(-70.47 - NUDGE / 200, 4)
    filename = f"catastro-{RUN}.geojson"
    geojson = json.dumps({
        "type": "FeatureCollection",
        "features": [{
            "type": "Feature",
            "geometry": {"type": "Point", "coordinates": [lon, lat]},
            "properties": {"uid": f"files:{RUN}", "name": name, "category": "salud",
                           "subcategory": "cesfam", "source": "prueba"},
        }],
    })

    # Into the user's own Files tree, over WebDAV, with the session this browser
    # already has. Not through the app: the criterion is that a file somebody
    # else put there imports, so nothing this app wrote may be involved.
    kml_name = f"catastro-{RUN}.kml"

    def into_files(path, body):
        return page.evaluate(
            """async ([path, body]) => {
                const response = await fetch(`/remote.php/dav/files/admin/${path}`, {
                    method: 'PUT',
                    headers: { requesttoken: window.OC.requestToken },
                    body,
                })
                return response.status
            }""",
            [path, body],
        )

    for path, body in ((filename, geojson), (kml_name, '<?xml version="1.0"?><kml/>')):
        written = into_files(path, body)
        if written not in (201, 204):
            failures.append(f"could not put {path!r} into Files over WebDAV: HTTP {written}")
            return

    closer = page.get_by_role("dialog").get_by_role("button", name="Close")
    if closer.count() != 0:
        closer.first.click()
        page.wait_for_timeout(600)

    page.get_by_role("button", name="Importar / deshacer").click()
    page.wait_for_timeout(600)
    page.get_by_label("Conjunto de datos").fill(f"archivos-{RUN}")

    # An upload chosen first, so that picking from Files has something stale to
    # clear. Without it the next assertion would pass on an input nobody filled.
    page.locator('input[type="file"]').set_input_files({
        "name": f"subido-{RUN}.geojson",
        "mimeType": "application/geo+json",
        "buffer": b'{"type":"FeatureCollection","features":[]}',
    })
    page.wait_for_timeout(400)
    if page.locator('input[type="file"]').evaluate("input => input.files.length") != 1:
        failures.append("the upload control did not take the file this check gave it (#104)")

    def choose_from_files(wanted):
        """Open core's picker and choose `wanted`; False if the picker is what moved."""
        page.get_by_role("button", name="Desde archivos de Nextcloud").click()

        # The picker is core's own dialog, opened on top of this app's.
        picker = page.get_by_role("dialog").last
        try:
            picker.get_by_text(wanted, exact=False).first.wait_for(timeout=20_000)
        except PlaywrightTimeout:
            page.screenshot(path=str(SHOTS / "files-picker.png"), full_page=False)
            failures.append(
                f"the Nextcloud file picker never listed {wanted!r} — see files-picker.png "
                "(the button opened nothing, or core's picker moved)",
            )
            return False

        picker.get_by_text(wanted, exact=False).first.click()
        page.screenshot(path=str(SHOTS / "files-picker.png"), full_page=False)
        choose = picker.get_by_role("button", name=re.compile(r"^(choose|elegir|select|seleccionar)", re.I))
        if choose.count() == 0:
            failures.append("core's file picker offered no Choose button — see files-picker.png")
            return False
        choose.first.click()
        page.wait_for_timeout(600)
        return True

    # The KML first: the reader is chosen from the name, and a person reaching a
    # KML through Files must get the same warning an uploaded KML raises (#103).
    if not choose_from_files(kml_name):
        return
    if page.get_by_text("No trae teléfono").count() == 0:
        failures.append("a KML chosen from Files said nothing about what it cannot carry (#104)")
    if page.locator('input[type="file"]').evaluate("input => input.files.length") != 0:
        failures.append(
            "choosing from Files left the upload control naming another file, so the "
            "control and the hint below it disagree (#104)",
        )

    # Then the GeoJSON that is actually imported. The warning going away is the
    # other half of the same branch: the suffix is read off the new path.
    if not choose_from_files(filename):
        return
    if page.get_by_text("No trae teléfono").count() != 0:
        failures.append("a GeoJSON chosen from Files still raised the KML warning (#104)")

    # The dialog says which file it is about to import, and says it as a PATH:
    # that sentence is the difference between a second upload and this slice.
    if not settles(page, lambda: page.get_by_text("ya está en sus archivos").count() != 0):
        failures.append("the import dialog never said the chosen file is already in Files")

    page.get_by_role("button", name="Importar", exact=True).click()
    page.wait_for_timeout(2500)
    page.screenshot(path=str(SHOTS / "files-import.png"), full_page=False)

    stored = registry_feature(page, name)
    if stored is None:
        failures.append(f"{name!r} is not in the Registry: importing from Files wrote nothing (#104)")
    elif stored.get("datasetId") is None:
        failures.append(f"{name!r} carries no datasetId, so it is not an imported record (#104)")

    # And a path nobody can reach is the Spanish 422 the dialog shows, never a
    # 500. Posted directly because no picker will offer another user's file.
    before = len(errors)
    refusal = page.evaluate(
        """async () => {
            const body = new FormData()
            body.append('path', '/no-existe-en-ninguna-parte.geojson')
            body.append('dataset', 'archivos-refusal')
            const response = await fetch('/index.php/apps/territorio/import', {
                method: 'POST',
                headers: { requesttoken: window.OC.requestToken },
                body,
            })
            return { status: response.status, body: await response.json() }
        }""",
    )
    if refusal["status"] != 422:
        failures.append(f"an unreachable path answered HTTP {refusal['status']}, not 422 (#104)")
    elif "Nextcloud" not in (refusal["body"].get("message") or ""):
        failures.append(f"the refusal for an unreachable path reads {refusal['body']!r} (#104)")

    # The refusal IS an HTTP 422 and the browser logs every failed response as a
    # console error, which main()'s run-wide gate would then report. Exactly the
    # entries this refusal added are dropped, and only the 422 among them — the
    # same scoping the KMZ refusal uses.
    errors[before:] = [message for message in errors[before:] if "422" not in message]

    dismiss(page)
    page.wait_for_timeout(400)

    # The file itself is not left in Files: it is not a Registry record and
    # nothing here retires it, so it would pile up one per run.
    page.evaluate(
        """async (paths) => {
            for (const path of paths) {
                await fetch(`/remote.php/dav/files/admin/${path}`, {
                    method: 'DELETE',
                    headers: { requesttoken: window.OC.requestToken },
                })
            }
        }""",
        [filename, kml_name],
    )


def occ_import_names_the_formats_it_takes(failures):
    """#103: `occ territorio:import` says KMZ is one of the formats it takes.

    Read off the running command rather than off the source, because the source
    is what it was written from: the criterion is that a person typing `--help`
    is told, and only the command can say what it prints.
    """
    printed = subprocess.run(
        ["docker", "compose", "-f", "compose.dev.yaml", "exec", "-T", "-u", "www-data",
         "nextcloud", "php", "occ", "territorio:import", "--help"],
        capture_output=True, timeout=120,
    )
    if printed.returncode != 0:
        skip("occ territorio:import --help did not run, so the formats it names are unchecked")
        return

    if "KMZ" not in printed.stdout.decode(errors="replace"):
        failures.append("occ territorio:import does not name KMZ among the formats it takes")




def kml_says_what_it_cannot_carry(page, failures):
    """#8: "The import preview states plainly which fields KML cannot carry."

    Before the import runs, not after — the point is that someone expecting
    their phone numbers can still choose a different file.
    """
    page.get_by_role("button", name="Importar / deshacer").click()
    page.wait_for_timeout(600)

    warning = page.get_by_text("No trae teléfono")
    if warning.count() != 0:
        failures.append("the KML warning is shown before a file is even chosen")

    # A GeoJSON must not raise it either, or the warning means nothing.
    page.locator('input[type="file"]').set_input_files({
        "name": "algo.geojson",
        "mimeType": "application/geo+json",
        "buffer": b'{"type":"FeatureCollection","features":[]}',
    })
    page.wait_for_timeout(400)
    if warning.count() != 0:
        failures.append("the KML warning is shown for a GeoJSON file")

    page.locator('input[type="file"]').set_input_files({
        "name": "mymaps.kml",
        "mimeType": "application/vnd.google-earth.kml+xml",
        "buffer": b'<?xml version="1.0"?><kml/>',
    })
    page.wait_for_timeout(400)
    if warning.count() == 0:
        failures.append("choosing a KML said nothing about what it cannot carry")

    dismiss(page)
    page.wait_for_timeout(400)


# What #37 settled the map pin at, and what the Datos list and the Capas legend
# deliberately stayed at. Named here rather than inlined because the point of
# the decision is that the three do NOT move together: the pin is the only mark
# with no label beside it, and "scale them all up" is the change this guards.
PIN_DOT, PIN_GLYPH = 26, 19
LIST_SWATCH, LIST_GLYPH = 18, 12
CAPAS_SWATCH, CAPAS_GLYPH = 16, 11


def a_pin_says_what_kind_of_place_it_is(page, failures):
    """#20: the pictogram, where it has to work — a pin with no label beside it.

    Hue only groups Categories into six families (ADR-0013), so this glyph is
    what tells a colegio from a farmacia, and it is the half a dichromacy cannot
    take away. On the map there is no text to fall back on.

    Since #37 it also measures the pin, because "26px with a 19px glyph" is not
    visible anywhere else: the size lives in a `divIcon` option and a stylesheet,
    and no unit test renders either. The anchor is measured with it — Leaflet
    centres a divIcon by writing negative margins from `iconAnchor`, so a dot
    resized without its anchor draws every pin off the coordinate it belongs to,
    silently and everywhere.

    Creates its own Salud record rather than trusting the instance to hold one:
    a check that cannot set up its own data has nothing to say. The expected fill
    is read out of the taxonomy the app serves for the same reason the Capas
    check reads it — a copy here could agree with itself while the app disagreed
    with both.
    """
    salud = _served_categories(page).get("salud")
    if salud is None:
        failures.append("the app serves no 'salud' Category, so the glyph check could not run")
        return

    name = f"CESFAM del Navegador {RUN}"

    page.get_by_role("button", name="Agregar", exact=True).click()
    page.get_by_label("Nombre").fill(name)
    page.get_by_label("Latitud").fill(f"-33.5{340 + NUDGE}")
    page.get_by_label("Longitud").fill(f"-70.5{340 + NUDGE}")

    # NcSelect labels its options "Category · Subcategory", because "Otro"
    # appears in nearly every Category and means something different in each.
    # The exact-matching of the locator itself is _pick's, for the «+» reason it
    # states.
    if not _pick(page, "Subcategoría", "Salud · CESFAM", failures, "the Salud place"):
        dismiss(page)
        return

    save(page, failures, "the Salud place")
    dismiss(page)
    page.wait_for_timeout(400)

    stored = registry_feature(page, name)
    if stored is None:
        failures.append(f"{name!r} did not reach the Registry, so its pin was not checked")
        return

    # Narrow the map to this one record: a pin inside a cluster is not rendered.
    page.locator(SEARCH).fill(name)
    page.wait_for_timeout(1200)

    pin = page.locator(".territorio-pin__dot").first
    if pin.count() == 0:
        failures.append(f"{name!r} is in the Registry but drew no pin")
        page.locator(SEARCH).fill("")
        return

    painted = pin.evaluate(
        """(dot) => {
            const svg = dot.querySelector('svg')
            const path = dot.querySelector('svg path')
            const rgb = getComputedStyle(dot).backgroundColor.match(/\\d+/g) || []
            const marker = dot.parentElement
            const box = dot.getBoundingClientRect()
            const glyphBox = svg ? svg.getBoundingClientRect() : null
            const margins = getComputedStyle(marker)
            return {
                fill: '#' + rgb.slice(0, 3).map((n) => (+n).toString(16).padStart(2, '0')).join(''),
                glyph: svg ? svg.id : null,
                glyphFill: path ? getComputedStyle(path).fill : null,
                dot: [Math.round(box.width), Math.round(box.height)],
                mark: glyphBox ? [Math.round(glyphBox.width), Math.round(glyphBox.height)] : null,
                anchor: [parseFloat(margins.marginLeft), parseFloat(margins.marginTop)],
            }
        }""",
    )

    if painted["glyph"] != "hospital":
        failures.append(
            f"a Salud pin carries the {painted['glyph']!r} glyph, expected 'hospital' — the "
            f"pictogram is what identifies a Category on the map",
        )
    if painted["fill"] != salud:
        failures.append(
            f"a Salud pin is filled {painted['fill']}, but the taxonomy the app serves says "
            f"{salud}",
        )
    if painted["glyphFill"] != "rgb(255, 255, 255)":
        failures.append(
            f"the glyph is drawn {painted['glyphFill']}, not white — the ramp clears 3:1 "
            f"against white and was never checked against anything else",
        )

    if painted["dot"] != [PIN_DOT, PIN_DOT]:
        failures.append(
            f"a pin draws {painted['dot']}px, not {PIN_DOT}px — #37 raised it because 22/14 was "
            f"reported as not easily visualised on a real basemap",
        )
    if painted["mark"] != [PIN_GLYPH, PIN_GLYPH]:
        failures.append(
            f"a pin's glyph draws {painted['mark']}px, not {PIN_GLYPH}px — the mark is what the "
            f"size was raised for, and a bigger dot around the same mark buys nothing",
        )

    # Derived from the dot that was just measured, not from PIN_DOT: this is
    # asking whether the anchor followed the size, and pinning both to the same
    # constant would make it agree with itself while every pin sat off its
    # coordinate.
    centred = [-painted["dot"][0] / 2, -painted["dot"][1] / 2]
    if painted["anchor"] != centred:
        failures.append(
            f"a {painted['dot']}px pin is anchored at {painted['anchor']}px, not {centred}px — "
            f"iconAnchor did not follow iconSize, so the pin no longer sits on its coordinate",
        )

    # "Comfortably readable on a real basemap" is the criterion #37 is judged on
    # and no assertion settles it. Saved over live OSM tiles, with the map
    # narrowed to this one record, so whoever lands it can look and say what they
    # saw — per the slice contract, and per #37's own "confirmed by a person".
    page.locator('[data-testid="map-canvas"]').screenshot(path=str(SHOTS / "pin-legibility.png"))

    # Retired again, so this run still leaves behind exactly the Place and the
    # Sector that the later count assertions are written against. Retiring rather
    # than deleting because ADR-0005 has no delete — and it is the app's own way
    # of taking something off the map, so it costs nothing to exercise it.
    retire_the_runs_record(page, name)


CLUSTER = ".territorio-cluster"
RING = ".territorio-cluster__ring"

# The bubble, and the count disc inside it. Each is written twice — `iconSize`
# in the component and `width`/`height` in its stylesheet — and #37 is the
# slice that learned what an unmeasured copy costs: a mark resized without its
# anchor draws every marker off the coordinate it belongs to, silently. So the
# numbers live here and the check below measures what the browser actually drew
# against them, rather than anybody keeping four copies in step by eye.
CLUSTER_DISC, CLUSTER_COUNT, CLUSTER_BAND = 36, 24, 4

# What markercluster's own stylesheet paints a bubble, by child count. Named
# here so the check can say "this is a count hue" rather than "this is not the
# colour I expected" — the whole of #22 is that these ever reached the map.
COUNT_HUES = {"#b5e28c", "#6ecc39", "#f1d357", "#f0c20c", "#fd9c73", "#f08017"}


def _a_clustered_place(page, name, subcategory, offset, failures, quality=None):
    """One Place, typed, a few metres from its neighbours, with its Exactitud.

    Metres apart on purpose: three records inside markercluster's 80px radius
    are one bubble at the zoom the map opens on, which is the only way to get a
    cluster to look at. The coordinate is typed rather than clicked for the
    reason create_a_place() gives — what is under a given pixel is not this
    check's subject.

    «Exactitud» is only offered once there is a coordinate to describe
    (`FeatureDetail.vue`, `v-if="hasCoordinates"`), so it is set last.
    """
    to_list(page)
    page.get_by_role("button", name="Agregar", exact=True).click()
    page.get_by_label("Nombre").fill(name)
    page.get_by_label("Latitud").fill(f"-33.5{300 + NUDGE + offset}")
    page.get_by_label("Longitud").fill(f"-70.5{300 + NUDGE + offset}")

    for label, option in (("Subcategoría", subcategory), ("Exactitud", quality)):
        if option is None:
            continue
        if not _pick(page, label, option, failures, f"{name!r}"):
            # Not saved: a record the pick never filed would land under «Sin
            # clasificar» and fail the map checks far from this cause.
            dismiss(page)
            page.wait_for_timeout(400)
            return

    save(page, failures, f"{name!r}")
    dismiss(page)
    page.wait_for_timeout(400)


def _only_on_the_map(page, query):
    """Narrow the Registry to one search, which narrows the map with it."""
    page.locator(SEARCH).fill(query)
    page.wait_for_timeout(1200)


def _pick(page, label, option, failures, what):
    """Commit an NcSelect choice through the keyboard, once its option is offered.

    The fill-and-Enter this replaces committed nothing whenever the dropdown
    had not rendered its options yet, and the record then filed under «Sin
    clasificar» with no refusal — the race that drew a grey marker in run
    6a57c7's cluster bubble, whose bands said one Salud record that the
    Registry held as «Sin clasificar · Otro».

    The offer is read out of the rendered rows by textContent — the same idiom
    `_picker_options()` states the wrap reason for — and NOT by accessible
    name: NcSelect paints option labels as `name-parts` spans, and the
    accessibility tree's name for those is not the label's text (probed:
    "Salud · Prueba 20f1af" offered verbatim, role-name match count 0 — while
    "Salud · CESFAM" matched, which is why only the just-added-subcategory
    filings failed). Enter on the FIELD rather than a click on the option, for
    the same reason: the drawer re-renders under the map's churn and vue-select
    rebuilds its menu between frames, so an option that is present can be
    forever unclickable, but the keyboard is not an element. The commitment is
    verified in `.vs__selected` and retried, so a pick that never lands fails
    loudly at the cause instead of misfiling the record.

    `exact=True` on the field for the reason the checks below state: the label
    "Subcategoría" is a substring of the accessible name of each of #44's
    twelve «+» buttons.
    """
    field = page.get_by_label(label, exact=True)
    rows = page.locator(".vs__dropdown-option")

    def offered():
        return any(option in said for said in rows.all_text_contents())

    for _ in range(3):
        field.click()
        field.fill(option)
        if settles(page, offered, timeout=5_000):
            field.press("Enter")
            page.wait_for_timeout(300)
            if any(option in said for said in page.locator(".vs__selected").all_text_contents()):
                return True
        # The dropdown's state is unknown here (a failed Enter may have closed
        # it), and an Escape pressed on a closed select bubbles into the
        # caller's draft — so close it only while it is really open.
        if rows.count() > 0:
            field.press("Escape")
            page.wait_for_timeout(300)
    failures.append(f"«{label}» never offered {option!r}, so {what} could not be filed")
    return False


def _ring_segments(gradient):
    """The bands of a computed `conic-gradient()`, as [(hex, from%, to%)].

    Read positionally rather than by splitting on commas: a colour stop IS
    commas, and browsers disagree about whether `#99564e 0% 66.67%` comes back
    as one double-position stop or as two single ones — and Chrome serialises
    the position in `deg` while the stylesheet wrote `%`. Every position is
    therefore attached to the nearest colour before it, normalised, and runs of
    the same colour are merged into one band.

    Returns None for a unit nothing here knows, so an unreadable gradient fails
    loudly instead of measuring as zero.
    """
    text = gradient or ""
    colours = [(m.start(), _as_hex(m.group(0))) for m in re.finditer(r"rgba?\([^)]*\)", text)]
    positions = []
    for match in re.finditer(r"(-?[\d.]+)(deg|%|turn)", text):
        value, unit = float(match.group(1)), match.group(2)
        positions.append((match.start(), {"%": value, "deg": value / 3.6, "turn": value * 100}[unit]))

    if not colours or re.search(r"[\d.]+(grad|rad)\b", text):
        return None

    bands = []
    for index, (at, colour) in enumerate(colours):
        until = colours[index + 1][0] if index + 1 < len(colours) else len(text)
        mine = [value for start, value in positions if at < start < until]
        if not mine:
            return None
        if bands and bands[-1][0] == colour:
            bands[-1] = (colour, bands[-1][1], max(mine))
        else:
            bands.append((colour, min(mine), max(mine)))

    return bands


# The ring every pin and cluster bubble wears: one fixed dark ink, not a theme
# variable. The basemap tiles are light in both themes, and this ink measures
# >= 10.6:1 over every surface and >= 3:1 over every legal fill
# (tools/ramp-check.py, map-legibility.md §2.2) — which is why the colour is
# asserted identical across both themes below.
PIN_RING_INK = "#171717"


def the_exactitud_is_drawn_on_the_pin(page, failures):
    """#23: an `aproximada` coordinate wears a dashed ring, an `exacta` one does not.

    `locationQuality` was stored, validated, imported, exported and historicised,
    and drawn nowhere — so the map asserted a precision the Registry never
    claimed, and a team deciding where to visit had no way to tell a street from
    a guess.

    Measured as computed style rather than as a screenshot, because remote OSM
    tiles under every picture would flake this into being ignored
    (`docs/design/map-legibility.md` § 6). Run in both themes because the
    treatment must not depend on which one is on — the tiles the ring sits on
    are light in both, so the border COLOUR is one fixed ink, asserted
    identical across both themes (§2.2): a dash is a pattern, and if the ink
    ever became a theme hue it would be joining the one channel this map
    already overloads (ADR-0013).

    The quality is read back out of the Registry first. That the sidebar can
    show «Aproximada» proves nothing about what was written, which is this
    gate's whole reason for existing.
    """
    expected = {
        f"Cúmulo {RUN} aproximado": "aproximada",
        f"Cúmulo {RUN} exacto": "exacta",
    }

    for name, quality in expected.items():
        stored = registry_feature(page, name)
        if stored is None:
            failures.append(f"{name!r} did not reach the Registry, so its ring was not checked")
            return
        if stored.get("locationQuality") != quality:
            failures.append(
                f"{name!r} is stored as {stored.get('locationQuality')!r}, not {quality!r} — "
                f"the form did not write the Exactitud, so the ring below would prove nothing",
            )
            return

    backgrounds = {}

    for scheme in ("light", "dark"):
        page.emulate_media(color_scheme=scheme)
        page.wait_for_timeout(300)
        backgrounds[scheme] = _as_hex(page.evaluate(
            "() => getComputedStyle(document.body).getPropertyValue('--color-main-background')",
        ))

        for name, quality in expected.items():
            _only_on_the_map(page, name)
            pin = page.locator(".territorio-pin__dot").first
            if pin.count() == 0:
                failures.append(f"{name!r} drew no pin in the {scheme} theme (#23)")
                continue

            ring = pin.evaluate(
                """(dot) => {
                    const style = getComputedStyle(dot)
                    return { style: style.borderTopStyle, colour: style.borderTopColor }
                }""",
            )
            wanted = "dashed" if quality == "aproximada" else "solid"

            if ring["style"] != wanted:
                failures.append(
                    f"an «{quality}» pin draws a {ring['style']} ring in the {scheme} theme, "
                    f"expected {wanted} — #23 is that the two look identical",
                )
            if _as_hex(ring["colour"]) != PIN_RING_INK:
                failures.append(
                    f"the {scheme}-theme ring on {name!r} is {ring['colour']}, not the fixed "
                    f"{PIN_RING_INK} both themes share — the tiles are light in both "
                    f"(map-legibility.md §2.2), and a theme variable here would be the "
                    f"channel ADR-0013 says a treatment must not use",
                )

            if quality == "aproximada":
                page.locator('[data-testid="map-canvas"]').screenshot(
                    path=str(SHOTS / f"approximate-pin-{scheme}.png"))

    page.emulate_media(color_scheme="no-override")
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)

    # A silent half-run, the way the app-icon check meets it: with a theme pinned
    # on the instance, `prefers-color-scheme` switches nothing and the loop above
    # checked one theme twice.
    if backgrounds["light"] == backgrounds["dark"]:
        skip("the dashed ring was only checked in one theme: this instance pins a theme, so "
             "prefers-color-scheme does not switch it")


def a_cluster_says_what_is_under_it(page, failures):
    """#22: a cluster is coloured by the Categories it holds, never by its size.

    markercluster buckets on `getChildCount()` alone, so green meant «Deporte y
    recreación» at one zoom and "more than 100 records" at the next — one channel
    carrying two unrelated meanings, on the same map.

    Three records a few metres apart, two Salud and one Educación, one of the
    Salud ones approximate. That is every criterion in one bubble: proportional
    bands rather than a count hue, a mix that cannot be confused with a single
    Category, a readable count, and #23's dashed ring inherited by a cluster.

    `getAllChildMarkers()` itself is not reachable from the page — Leaflet under
    webpack exports no global, and the map handle is deliberately a non-reactive
    instance property — so the children are established twice over instead: the
    count the bubble writes IS `getChildCount()`, and clicking the bubble expands
    it into the very markers `getAllChildMarkers()` returns, whose fills are then
    compared with the bands. A ring agreeing with both is a ring agreeing with
    its children.
    """
    served = _served_categories(page)
    salud, educacion = served.get("salud"), served.get("educacion")
    if salud is None or educacion is None:
        failures.append("the app serves no 'salud'/'educacion' Category, so the cluster check "
                        "could not run")
        return

    _only_on_the_map(page, f"Cúmulo {RUN}")

    bubbles = page.locator(RING)
    if not settles(page, lambda: bubbles.count() == 1, timeout=5_000):
        failures.append(
            f"three records a few metres apart drew {bubbles.count()} cluster bubbles, not one — "
            f"nothing measured the cluster icon (#22)",
        )
        page.locator(SEARCH).fill("")
        return

    painted = bubbles.first.evaluate(
        """(ring) => {
            const style = getComputedStyle(ring)
            const count = ring.querySelector('.territorio-cluster__count')
            const inside = count ? getComputedStyle(count) : null
            const box = ring.getBoundingClientRect()
            const countBox = count ? count.getBoundingClientRect() : null
            const margins = getComputedStyle(ring.parentElement)
            return {
                gradient: style.backgroundImage,
                border: style.borderTopStyle,
                count: count ? count.textContent.trim() : null,
                ink: inside ? inside.color : null,
                paper: inside ? inside.backgroundColor : null,
                disc: [Math.round(box.width), Math.round(box.height)],
                countDisc: countBox
                    ? [Math.round(countBox.width), Math.round(countBox.height)]
                    : null,
                rim: parseFloat(style.borderTopWidth),
                anchor: [parseFloat(margins.marginLeft), parseFloat(margins.marginTop)],
            }
        }""",
    )

    if painted["disc"] != [CLUSTER_DISC, CLUSTER_DISC]:
        failures.append(
            f"a cluster bubble draws {painted['disc']}px, not {CLUSTER_DISC}px — a bubble is "
            f"sized above the pin's {PIN_DOT}px so that it reads as more than one record "
            f"before the count inside it is read at all (#22)",
        )

    # Derived from the disc that was just measured, not from CLUSTER_DISC: what
    # is being asked is whether `iconAnchor` followed `iconSize`, and pinning
    # both to the same constant would make the check agree with itself while
    # every bubble sat off the records it covers. Exactly the pin's reasoning
    # above, for the same defect.
    centred = [-painted["disc"][0] / 2, -painted["disc"][1] / 2]
    if painted["anchor"] != centred:
        failures.append(
            f"a {painted['disc']}px cluster is anchored at {painted['anchor']}px, not "
            f"{centred}px — iconAnchor did not follow iconSize, so the bubble no longer sits "
            f"over the records it stands for",
        )

    if painted["countDisc"] != [CLUSTER_COUNT, CLUSTER_COUNT]:
        failures.append(
            f"the count disc draws {painted['countDisc']}px, not {CLUSTER_COUNT}px — it covers "
            f"the middle of the gradient, so its size is what is left of the Category bands",
        )
    else:
        # The stylesheet claims a 4px band of Category is left over: (36 - 2·rim
        # - 24) / 2. Measured rather than restated, because the band is what the
        # ring says a cluster's Categories with, and any of the three sizes
        # drifting eats it without anything going red.
        band = (painted["disc"][0] - 2 * painted["rim"] - painted["countDisc"][0]) / 2
        if band != CLUSTER_BAND:
            failures.append(
                f"the Category band around the count measures {band}px, not {CLUSTER_BAND}px — "
                f"a {painted['disc'][0]}px disc with a {painted['rim']}px rim and a "
                f"{painted['countDisc'][0]}px count leaves nothing of the gradient to read",
            )

    if painted["count"] != "3":
        failures.append(
            f"the bubble over three records reads {painted['count']!r} — the count is the one "
            f"thing hue was duplicating, and it has to stay readable (#22)",
        )

    bands = _ring_segments(painted["gradient"])
    expected = [(salud, 0.0, 66.67), (educacion, 66.67, 100.0)]

    if bands is None:
        failures.append(
            f"the cluster's ring is {painted['gradient']!r}, which holds no readable colour "
            f"stops — the bubble is not segmented by Category at all (#22)",
        )
    elif ([band[0] for band in bands] != [colour for colour, _, _ in expected]
            or any(abs(band[index] - want[index]) > 0.6
                   for band, want in zip(bands, expected) for index in (1, 2))):
        failures.append(
            f"a cluster of two Salud and one Educación draws {bands}, expected {expected} — "
            f"the bands are what say which Categories are under it, in what proportion",
        )

    # No hue that means a COUNT, read off the pixels the bubble really paints.
    # This used to read the ring's `backgroundColor`, which the stylesheet never
    # sets — only `background-image` — so it was always transparent and the
    # assertion could not fail, not even against the regression it names. The
    # bands are where a count hue would have to reappear to be seen.
    if bands is not None:
        reused = sorted({colour for colour, _, _ in bands if colour in COUNT_HUES})
        if reused:
            failures.append(
                f"the cluster's ring paints {reused}, which markercluster uses for its own "
                f"count buckets — a hue that means a number is back on the map (#22)",
            )
    for bucket in ("marker-cluster-small", "marker-cluster-medium", "marker-cluster-large"):
        if page.locator(f".{bucket}").count() > 0:
            failures.append(f"the map still draws a {bucket} bubble: hue is carrying the count again")

    if painted["border"] != "dashed":
        failures.append(
            f"a cluster holding an «aproximada» record draws a {painted['border']} ring, not a "
            f"dashed one — #22's last criterion is that it inherits #23's treatment",
        )

    # The count in ink on the theme's own paper: the pair that is legible in both
    # themes by construction, and that no Category colour can be mistaken for.
    if painted["ink"] == painted["paper"]:
        failures.append(f"the cluster's count is {painted['ink']} on {painted['paper']} — invisible")

    page.locator('[data-testid="map-canvas"]').screenshot(path=str(SHOTS / "cluster-ring.png"))

    # Now open it: what falls out is `getAllChildMarkers()`, drawn.
    pins = page.locator(".territorio-pin__dot")
    for _ in range(4):
        if pins.count() == 3:
            break
        if page.locator(CLUSTER).count() == 0:
            break
        page.locator(CLUSTER).first.click()
        page.wait_for_timeout(1200)

    if pins.count() != 3:
        failures.append(
            f"expanding the cluster revealed {pins.count()} pins, not 3 — its children could "
            f"not be compared with the ring (#22)",
        )
    else:
        children = sorted(
            _as_hex(pins.nth(index).evaluate("dot => getComputedStyle(dot).backgroundColor"))
            for index in range(3)
        )
        wanted = sorted([salud.lower(), salud.lower(), educacion.lower()])
        if children != wanted:
            failures.append(
                f"the cluster's own markers are filled {children}, while its ring claimed "
                f"{wanted} — the bubble is not drawn from the records under it (#22)",
            )

    # Back to the view every later check was written against: this one zoomed in
    # to open the cluster, and a reload is what restores the comuna fit.
    page.locator(SEARCH).fill("")
    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1000)

    for name in (f"Cúmulo {RUN} aproximado", f"Cúmulo {RUN} exacto", f"Cúmulo {RUN} colegio"):
        retire_the_runs_record(page, name)


def _focused(page):
    """What the keyboard is on right now, and how it is drawn.

    Read in one evaluate rather than through a locator: the thing being checked
    is precisely *which* element has focus, and a locator would ask about an
    element chosen beforehand.
    """
    return page.evaluate(
        """() => {
            const active = document.activeElement
            if (active === null) {
                return null
            }
            const style = getComputedStyle(active)
            return {
                tag: active.tagName.toLowerCase(),
                stroke: active.getAttribute('stroke'),
                role: active.getAttribute('role'),
                tabindex: active.getAttribute('tabindex'),
                label: active.getAttribute('aria-label'),
                outline: `${style.outlineStyle} ${style.outlineWidth} ${style.outlineColor}`,
            }
        }""",
    )


def _tab_to_the_shape(page, colour, presses=60):
    """Press Tab until the keyboard lands on the shape drawn in `colour`.

    Bounded, because the honest answer to "it never arrives" is a failure and not
    a loop. The list is filtered to one record first, so this is a handful of
    stops and not a comuna's worth.
    """
    for _ in range(presses):
        page.keyboard.press("Tab")
        focused = _focused(page)
        if focused is not None and focused["stroke"] == colour:
            return focused

    return None


def a_sector_can_be_reached_and_opened_with_the_keyboard(page, failures):
    """#25: a Sector could be clicked and reached by no other means.

    `L.Marker` defaults to `keyboard: true`, so a Place pin has always been
    tab-focusable; `L.Path` has no keyboard option at all, so every Zone and
    every Route in the Registry was mouse-only (docs/design/map-legibility.md
    D6). Only a browser can say whether a real Tab now lands on a polygon and a
    real Enter opens the record — the unit suite runs in node and loads no
    component, and the element does not even exist until the layer joins the map.

    Draws its own Sector, in a colour of its own, for the reason every other
    check here does: `path[stroke="…"]` is what identifies it among whatever
    else the instance holds, and a check that writes may only write to a record
    it made.
    """
    name = f"Sector Teclado {RUN}"
    colour = "#7c3aed"
    if not draw_a_sector(page, name, failures, colour=colour):
        return

    # A reload, and not Escape: nothing in this app closes a record on Escape
    # (there is no such handler — NcAppSidebar's own button is the way out), and
    # while a record is open its shape on the map is the geoman DRAFT layer.
    # `drawShapes` deliberately skips the feature being edited so nobody drags
    # the vertices of one polygon while looking at another on top of it — and
    # the draft layer is not the Registry's shape and gets no tab stop. Tabbing
    # to it would prove nothing about the map a person actually reads.
    #
    # A reload also starts this check from stored data rather than from the draw
    # that just happened, which is the state every other visit to the map is in.
    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1500)

    # From the Registry, so the name this check compares against is the one the
    # server stored and not the one this file typed: an aria-label built from a
    # local literal would agree with itself while the map announced something
    # else entirely.
    stored = registry_feature(page, name)
    if stored is None:
        failures.append(f"{name!r} did not reach the Registry, so the keyboard check could not run")
        retire_the_runs_record(page, name)
        return

    # Narrow everything to this one record. That leaves one shape on the map and
    # one row in the list, which is what makes "Tab reaches it" a handful of
    # presses instead of a walk through a comuna.
    page.locator(SEARCH).fill(name)
    page.wait_for_timeout(1200)

    shape = page.locator(f'.leaflet-overlay-pane path[stroke="{colour}"]')
    if not settles(page, lambda: shape.count() > 0, timeout=6_000):
        failures.append(f"{name!r} is in the Registry but drew no shape, so it could not be tabbed to")
        page.locator(SEARCH).fill("")
        retire_the_runs_record(page, name)
        return

    page.focus(SEARCH)
    reached = _tab_to_the_shape(page, colour)
    if reached is None:
        failures.append(
            f"Tab never reaches {stored['name']!r}: a Sector is selectable with a mouse and by "
            f"no other means (#25)",
        )
        page.screenshot(path=str(SHOTS / "keyboard-unreachable.png"), full_page=False)
        page.locator(SEARCH).fill("")
        retire_the_runs_record(page, name)
        return

    if reached["label"] != stored["name"]:
        failures.append(
            f"the focused Sector announces {reached['label']!r}, but the Registry holds "
            f"{stored['name']!r} — a screen reader would name the wrong record",
        )
    if reached["role"] != "button":
        failures.append(f"the focused Sector carries role={reached['role']!r}, not 'button'")

    # Reaching it is not the same as having a tab stop, measured here: Chromium
    # gives sequential focus to a bare SVG `<path>` in a Leaflet pane with no
    # `tabindex` at all — the 119 unpicked Limit lines walk past in the same Tab
    # order, and dropping the `tabindex` line from src/a11y.js still lets the
    # loop above arrive. Firefox and Safari do not, so the attribute is what the
    # criterion actually rests on and it is asserted rather than inferred from
    # this browser's generosity.
    if reached["tabindex"] != "0":
        failures.append(
            f"the Sector carries tabindex={reached['tabindex']!r}: Chromium focused it anyway, but "
            f"a browser that follows the spec would not (#25)",
        )

    # In both themes, because the ring is drawn in the theme's own primary
    # element colour and a fixed one would disappear into one of them.
    backgrounds = {}
    for scheme in ("light", "dark"):
        page.emulate_media(color_scheme=scheme)
        page.wait_for_timeout(300)
        backgrounds[scheme] = page.evaluate(
            "() => getComputedStyle(document.body).getPropertyValue('--color-main-background')",
        )
        outline = _focused(page)["outline"]
        if outline.startswith("none") or " 0px " in outline:
            failures.append(
                f"the focused Sector has no visible focus ring in the {scheme} theme "
                f"(outline: {outline!r})",
            )
        page.screenshot(path=str(SHOTS / f"keyboard-focus-{scheme}.png"), full_page=False)

    page.emulate_media(color_scheme="no-override")
    page.wait_for_timeout(300)
    if backgrounds["light"] == backgrounds["dark"]:
        skip("the focus ring was only seen in one theme: this instance pins a theme, so "
             "prefers-color-scheme does not switch it")

    # Tab has to keep going. A shape that swallowed the key would strand a
    # keyboard user on the map with the rest of the app behind it.
    page.keyboard.press("Tab")
    after = _focused(page)
    if after is not None and after["stroke"] == colour and after["label"] == stored["name"]:
        failures.append("Tab does not move on from a focused Sector — the map traps the keyboard")
    page.keyboard.press("Shift+Tab")
    if (_focused(page) or {}).get("label") != stored["name"]:
        failures.append("Shift+Tab does not come back to the Sector: the shapes are one-way")

    page.keyboard.press("Enter")
    if not settles(page, lambda: page.locator(".territorio-detail").count() > 0, timeout=6_000):
        failures.append(f"Enter on {stored['name']!r} opened nothing — the shape is focusable and inert (#25)")
        page.screenshot(path=str(SHOTS / "keyboard-enter-dead.png"), full_page=False)
        page.locator(SEARCH).fill("")
        retire_the_runs_record(page, name)
        return

    edit(page)
    opened = page.get_by_label("Nombre").input_value().strip()
    if opened != stored["name"]:
        failures.append(
            f"Enter opened {opened!r} while the keyboard was on {stored['name']!r} — the wrong record",
        )

    # With the record open the shape on the map is the draft layer, drawn in the
    # selected style. All this says is that the map and the sidebar agree about
    # which record is open — the tab stop is asserted after it closes, below,
    # because the draft layer is not the shape that carries one.
    if not shape.first.get_attribute("stroke-dasharray"):
        failures.append(
            f"Enter opened {opened!r} but the shape on the map is not drawn as selected — the "
            f"sidebar and the map disagree about what is open",
        )

    # Closing rebuilds the whole layer group: the element tabbed to a moment ago
    # is gone and this is a NEW path. That it carries the tab stop is the
    # criterion about surviving a rebuild — a poll-driven redraw swaps the group
    # the same way, and an attribute set once at start-up would be lost on the
    # first merge that arrived.
    dismiss(page)
    if not settles(page, lambda: page.locator(".territorio-detail").count() == 0, timeout=6_000):
        failures.append("the sidebar did not close, so the redraw after closing was not checked")
    else:
        redrawn = shape.first.evaluate(
            "(path) => ({ tabindex: path.getAttribute('tabindex'), label: path.getAttribute('aria-label') })",
        )
        if redrawn["tabindex"] != "0" or redrawn["label"] != stored["name"]:
            failures.append(
                "a redrawn Sector has no tab stop: the keyboard reaches the shapes only until the "
                f"first rebuild (#25) — tabindex={redrawn['tabindex']!r}, "
                f"aria-label={redrawn['label']!r}",
            )

    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)
    retire_the_runs_record(page, name)


def the_zoom_controls_are_in_spanish(page, failures):
    """#25: the zoom buttons' titles were Leaflet's English defaults.

    They are the only text on the map a screen reader reads out of a control
    rather than out of a record, in an app whose every other word is Spanish.
    `L.control.zoom` takes `zoomInTitle` / `zoomOutTitle`; nothing else does.
    """
    for selector, expected in ((".leaflet-control-zoom-in", "Acercar"),
                               (".leaflet-control-zoom-out", "Alejar")):
        button = page.locator(selector)
        if button.count() == 0:
            failures.append(f"there is no {selector} on the map, so its title was not checked")
            continue

        title = button.first.get_attribute("title")
        if title != expected:
            failures.append(f"the zoom control {selector} is titled {title!r}, expected {expected!r}")


def registry_features(page):
    """Every record the Registry holds, through the app's own change feed.

    Rather than the screen or the database: it is the read a second browser
    would do, so what comes back proves what was written rather than what a form
    still has values in it.
    """
    return page.evaluate(
        """async () => {
            const response = await fetch(
                '/index.php/apps/territorio/changes?since=0',
                { headers: { requesttoken: window.OC.requestToken } },
            )
            const body = await response.json()
            return body.features || []
        }""",
    )


def registry_feature(page, name):
    """One record as the Registry holds it, by name. None if it is not there."""
    return next(
        (feature for feature in registry_features(page) if feature.get("name") == name),
        None,
    )


def a_dragged_vertex_stays_with_its_own_record(page, failures):
    """#31: closing an edited Sector must not hand its shape to the next record.

    Geoman binds `remove` to `disable()`, and `disable()` fires `pm:update` when a
    vertex has been dragged. So tearing down the draft layer used to run the
    still-bound handler, emit `reshape` carrying the OLD polygon, and let App.vue
    write it onto the draft that had already become a different record — which
    then saved with somebody else's geometry, because a drawn shape wins over
    typed coordinates.

    Nothing caught it: no unit test loads a component, and the map's own checks
    never switch records mid-edit. It is the same shape of bug as `Guardar` doing
    nothing for eight slices, and it is why this gate exists.

    Asserted on the Registry, not the form. The screen would look right either
    way — the sidebar re-reads from the draft it was just handed.
    """
    place = f"Plaza del Navegador {RUN}"
    sector = f"Sector Navegador {RUN}"

    before = registry_feature(page, place)
    if before is None or (before.get("geometry") or {}).get("type") != "Point":
        skip(f"{place!r} is not a Point in the Registry, so the vertex-drag check did not run")
        return

    page.locator(SEARCH).fill(sector)
    page.wait_for_timeout(600)
    if page.locator(ROWS).count() == 0:
        skip("this run's Sector was not in the list, so the vertex-drag check did not run")
        page.locator(SEARCH).fill("")
        return

    open_first_row(page)
    page.wait_for_timeout(1200)

    handles = page.locator(".leaflet-marker-pane .marker-icon")
    if handles.count() == 0:
        failures.append("the open Sector had no draggable vertex, so nothing was dragged")
        dismiss(page)
        page.locator(SEARCH).fill("")
        return

    handle = handles.first.bounding_box()
    page.mouse.move(handle["x"] + handle["width"] / 2, handle["y"] + handle["height"] / 2)
    page.mouse.down()
    page.mouse.move(handle["x"] + 40, handle["y"] + 40, steps=6)
    page.mouse.up()
    page.wait_for_timeout(400)

    # Switch records WITHOUT saving the Sector. This is the whole point: the
    # teardown happens because the draft is being replaced.
    # The list is behind the open record, so the way to another one is the search
    # box, which is also how a person would leave this Sector half-edited.
    titles, problem = _omnibox_pick(page, place, place)
    if problem:
        failures.append(f"{place!r} could not be reached while switching records: {problem} (offered {titles})")
        return

    edit(page)
    save(page, failures, "the Place after leaving an edited Sector")

    after = registry_feature(page, place)
    kind = (after.get("geometry") or {}).get("type") if after else None
    if kind != "Point":
        failures.append(
            f"{place!r} was saved with a {kind} after an edited Sector was closed — it took "
            f"the Sector's shape",
        )
    elif after["geometry"]["coordinates"] != before["geometry"]["coordinates"]:
        failures.append(
            f"{place!r} moved from {before['geometry']['coordinates']} to "
            f"{after['geometry']['coordinates']} without anybody editing it",
        )

    dismiss(page)
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


# The neutral a Sector with no colour of its own is washed in, and the grey the
# Unidad Vecinal hairline is drawn in. Read from src/ rather than copied, for
# the reason the Category colours are read from the taxonomy the app serves
# (#42): a copy here would agree with itself while the app disagreed with both.
def _frontend_colour(name):
    source = (Path(__file__).resolve().parent.parent / "src" / "taxonomy.js").read_text("utf-8")
    match = re.search(name + r"\s*=\s*'(#[0-9a-fA-F]{6})'", source)

    return match.group(1).lower() if match else None


def _only_shape(page, name):
    """Narrow everything to one record and hand back its path on the map.

    The search box filters the ONE filtered set — the list, the map and Análisis
    read it together (#17) — so filtering by a unique name leaves exactly one
    shape in the overlay pane. That is what makes "the path" a thing this check
    can point at without guessing which of a comuna's polygons it has.
    """
    page.locator(SEARCH).fill(name)
    page.wait_for_timeout(1200)

    return page.locator(".leaflet-overlay-pane path")


def a_division_renders_as_a_division(page, failures):
    """#21, #24 and #29 together: figure, ground, and the affordances that follow.

    One function because they are one rule. A Unidad Vecinal is read from the
    national cadastre and never drawn by the clinic (CONTEXT.md) — enormous
    operational weight and, until this slice, zero visual weight: both divisions
    were drawn with the same fill, the same outline and the same hover tooltip,
    differing only in hue. Measured, that hue carried nothing: composited over
    OSM land at the shipped `fillOpacity: 0.15` all 55 Category pairs collapse
    (docs/design/map-legibility.md § 2).

    Asserted on COMPUTED STYLE and never on a screenshot, per § 6: remote OSM
    tiles under every picture would flake this into being ignored.

    The Unidad Vecinal is imported through occ, because there is no other way to
    make one — the sidebar files a drawn polygon as a Sector, and the
    Subcategoría picker is hidden for a division precisely so nothing is
    reclassified into the cadastre by hand. It is sited away from this
    instance's data and retired on the way out: a live Unidad Vecinal claims
    every Place it covers (ADR-0008) AND stretches the extent the map opens on,
    which is measured from exactly these.
    """
    uv = f"UV Navegador {RUN}"
    sector = f"Sector Catastro {RUN}"
    neutral = _frontend_colour("DEFAULT_SECTOR_COLOUR")
    south, west, north, east = -33.73, -70.44, -33.72, -70.43
    geojson = json.dumps({
        "type": "FeatureCollection",
        "features": [{
            "type": "Feature",
            "properties": {
                "id": f"uv-{RUN}",
                "name": uv,
                "category": "division_territorial",
                "subcategory": "unidad_vecinal",
            },
            "geometry": {
                "type": "Polygon",
                "coordinates": [[[west, south], [east, south], [east, north],
                                 [west, north], [west, south]]],
            },
        }],
    })

    if not import_dataset(geojson, f"uv-{RUN}", boundary=False):
        skip("could not import a Unidad Vecinal through occ; figure and ground is unchecked (#21)")
        return

    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1500)

    # The Registry first: a shape drawn correctly for a record that is not there
    # proves nothing, which is the whole reason this file reads the change feed.
    if registry_feature(page, uv) is None:
        failures.append(f"{uv!r} did not reach the Registry, so nothing was drawn to check (#21)")
        return

    # Not an assertion — the computed-style checks below are. «A Unidad Vecinal
    # recedes and a Sector reads as figure» is the one criterion in #21 that is a
    # judgement rather than a number, and § 6 forbids asserting it on a picture
    # because remote OSM tiles sit under every one. So the run leaves a picture
    # for a person (or the agent landing it) to look at and say what they saw,
    # in both themes. Taken here, before anything filters or zooms: the reload
    # above put the Unidad Vecinal toggle on screen for the first time — main()
    # could not have turned it off, there being no such toggle until this import
    # made one — so the cadastre is drawn and nothing is filtered.
    for scheme in ("light", "dark"):
        page.emulate_media(color_scheme=scheme)
        page.wait_for_timeout(1200)
        page.screenshot(path=str(SHOTS / f"figure-and-ground-{scheme}.png"), full_page=False)
    page.emulate_media(color_scheme="no-override")
    page.wait_for_timeout(400)

    _ground_is_drawn_as_ground(page, uv, failures)
    _the_ground_label_waits_for_its_zoom(page, uv, failures)
    _figure_is_drawn_as_figure(page, sector, neutral, failures)
    _the_list_draws_a_ring_and_the_form_offers_a_colour(page, uv, sector, failures)
    _ground_stays_ground_while_it_is_open(page, uv, failures)
    _the_chart_draws_a_division_neutral(page, neutral, failures)

    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)
    retire_the_runs_record(page, uv)

    # Reload rather than click back out. The label check above zooms the map
    # several levels in to cross the threshold, and every later check that
    # clicks the canvas is written against the view the app opens on — which is
    # measured from the Unidades Vecinales, one of which just went away.
    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1200)


def _ground_is_drawn_as_ground(page, uv, failures):
    """A Unidad Vecinal: no fill, a dashed hairline, and its name above a zoom."""
    shapes = _only_shape(page, uv)
    if shapes.count() == 0:
        failures.append(f"{uv!r} is in the Registry but drew no shape on the map (#21)")
        return

    drawn = shapes.first.evaluate(
        """(path) => {
            const style = getComputedStyle(path)
            return {
                fill: style.fill,
                dashes: style.strokeDasharray,
                weight: style.strokeWidth,
                opacity: style.strokeOpacity,
            }
        }""",
    )

    if drawn["fill"] != "none":
        failures.append(
            f"a Unidad Vecinal is filled {drawn['fill']!r}, not 'none' — it is read from the "
            f"cadastre and never drawn by the clinic, so it is ground (#21)",
        )
    # '2px, 3px' in Chromium, '2, 3' elsewhere: the numbers are the assertion.
    if re.findall(r"\d+", drawn["dashes"] or "")[:2] != ["2", "3"]:
        failures.append(
            f"a Unidad Vecinal's outline is {drawn['dashes']!r}, not the '2 3' dashed "
            f"hairline map-legibility § 2 specifies (#21)",
        )
    if float(re.findall(r"[\d.]+", drawn["weight"])[0]) > 1:
        failures.append(f"a Unidad Vecinal's outline is {drawn['weight']} wide, not a hairline (#21)")


def _the_ground_label_waits_for_its_zoom(page, uv, failures):
    """Fifty Unidad Vecinal labels at comuna zoom are a sheet of text, not a map."""
    label = page.locator(".leaflet-tooltip.territorio-label--ground")
    if label.count() == 0:
        failures.append(f"{uv!r} carries no permanent label at all, at any zoom (#21)")
        return

    out, into = page.locator(".leaflet-control-zoom-out"), page.locator(".leaflet-control-zoom-in")
    for _ in range(4):
        out.click()
        page.wait_for_timeout(250)

    if label.first.is_visible():
        failures.append(
            "a Unidad Vecinal is labelled at a zoom where the whole comuna is on screen — "
            "the threshold is what stands in for a collision library (#21)",
        )

    # Up to the threshold and no further than it takes to cross it.
    crossed = False
    for _ in range(12):
        into.click()
        page.wait_for_timeout(250)
        if label.first.is_visible():
            crossed = True
            break

    if not crossed:
        failures.append(
            "a Unidad Vecinal never shows its label, however far in the map is zoomed — "
            "the threshold hides it rather than delaying it (#21)",
        )
    elif label.first.inner_text().strip() != uv:
        failures.append(
            f"the ground label reads {label.first.inner_text().strip()!r} rather than {uv!r}",
        )
    else:
        # Here and not beside the Sector's: this is the only moment in the run
        # when a ground label is on screen, the zoom loop above having just put
        # it there.
        _label_colour_follows_the_tile(page, label.first, "a Unidad Vecinal's label", failures)


def _label_colour_follows_the_tile(page, label, what, failures):
    """A map label's colour is literal, never a Nextcloud variable (#21).

    The basemap is raster OpenStreetMap under BOTH themes, so a label that
    follows the theme is illegible in one of them: a dark-theme #ebebeb over OSM
    land #f2efe9 renders at 1.04:1. Measured rather than read off the
    stylesheet, because swapping a hex for `var(--color-main-text)` is a one-
    character edit that no unit test in this repo can see.

    Both labels go through it. They are two CSS blocks with two literal hexes —
    `.territorio-label` sits on a white plate and `--ground` sits directly on
    the tile with nothing behind it, which makes the ground label the one with
    more to lose — so checking the Sector's alone would leave the other free to
    drift to a variable and still pass.
    """
    painted = {}
    for scheme in ("light", "dark"):
        page.emulate_media(color_scheme=scheme)
        page.wait_for_timeout(300)
        painted[scheme] = label.evaluate("(node) => getComputedStyle(node).color")
    page.emulate_media(color_scheme="no-override")
    page.wait_for_timeout(200)

    if painted["light"] != painted["dark"]:
        failures.append(
            f"{what} is drawn {painted['light']} on light and {painted['dark']} on dark — "
            f"it sits on a raster OSM tile in both, so its colour follows the TILE (#21)",
        )


def _figure_is_drawn_as_figure(page, sector, neutral, failures):
    """A Sector: a subordinate wash, and a permanent label that is its identity."""
    shapes = _only_shape(page, sector)
    if shapes.count() == 0:
        skip(f"{sector!r} is not on the map, so the Sector half of #21 is unchecked")
        return

    drawn = shapes.first.evaluate(
        """(path) => {
            const style = getComputedStyle(path)
            return { fill: style.fill, alpha: style.fillOpacity }
        }""",
    )

    if _hex_of(drawn["fill"]) != neutral:
        failures.append(
            f"a Sector nobody tinted is washed {drawn['fill']!r}, not the neutral {neutral} — "
            f"CONTEXT.md says a clinic names its sectores words, numbers OR colours, so an "
            f"unset colour has to be neutral rather than a Category's (#21, #29)",
        )
    if abs(float(drawn["alpha"]) - 0.10) > 0.001:
        failures.append(
            f"a Sector's wash is at alpha {drawn['alpha']}, not 0.10 — at 0.15 the worst "
            f"dot-on-tint pair measures 2.90:1, under WCAG 1.4.11's 3:1 (#21)",
        )

    label = page.locator(".leaflet-tooltip.territorio-label").first
    if label.count() == 0 or not label.is_visible():
        failures.append(
            f"{sector!r} carries no permanent label — the label is what identifies a Sector "
            f"now that its colour is only a wash (#21)",
        )
        return

    if label.inner_text().strip() != sector:
        failures.append(f"the Sector label reads {label.inner_text().strip()!r}, not {sector!r}")

    # `direction: center` is what makes Leaflet 1.9 fall through to
    # PolyUtil.polygonCenter, a true area-weighted centroid — no plugin.
    classes = label.get_attribute("class") or ""
    if "leaflet-tooltip-center" not in classes:
        failures.append(
            f"the Sector label is placed {classes!r} rather than at the centroid (#21)",
        )

    _label_colour_follows_the_tile(page, label, "a Sector's label", failures)


def _the_list_draws_a_ring_and_the_form_offers_a_colour(page, uv, sector, failures):
    """#29 in the Datos list, and #24 in the sidebar, for both divisions."""
    contrast = _ramp_check().contrast

    for name, expected, wants_colour in ((uv, "dashed", False), (sector, "solid", True)):
        page.locator(SEARCH).fill(name)
        page.wait_for_timeout(900)
        if page.locator(ROWS).count() == 0:
            failures.append(f"{name!r} is not in the list, so its swatch was not checked (#29)")
            continue

        swatch = page.locator(".territorio-list__swatch").first
        for scheme in ("light", "dark"):
            page.emulate_media(color_scheme=scheme)
            page.wait_for_timeout(300)
            mark = swatch.evaluate(
                """(node) => {
                    const style = getComputedStyle(node)
                    return {
                        style: style.borderTopStyle,
                        border: style.borderTopColor,
                        fill: style.backgroundColor,
                        page: getComputedStyle(document.body)
                            .getPropertyValue('--color-main-background').trim(),
                    }
                }""",
            )

            if mark["style"] != expected:
                failures.append(
                    f"the list draws {name!r} with a {mark['style']!r} border, not a "
                    f"{expected!r} ring echoing the map ({scheme} theme, #29)",
                )
            if _opaque(mark["fill"]):
                failures.append(
                    f"the list still fills {name!r} with {mark['fill']!r} — a division is a "
                    f"ring, not a dot, and its `#334155` renders nowhere (#29)",
                )

            ring, behind = _hex_of(mark["border"]), _hex_of(mark["page"]) or mark["page"]
            if ring is None or not re.fullmatch(r"#[0-9a-fA-F]{6}", behind or ""):
                skip(f"could not measure {name!r}'s ring against the {scheme} page")
            elif contrast(ring, behind) < 3.0:
                failures.append(
                    f"{name!r}'s ring is {contrast(ring, behind):.2f}:1 against the {scheme} "
                    f"page — WCAG 1.4.11 asks 3:1 of non-text UI, which is the bar the "
                    f"`#334155` dot failed at 1.73:1 (#29)",
                )
        page.emulate_media(color_scheme="no-override")

        open_first_row(page)
        page.wait_for_timeout(1200)
        offered = page.locator('input[type="color"]').count() > 0
        if offered and not wants_colour:
            failures.append(
                f"{name!r} is offered a colour. A Unidad Vecinal is read from the cadastre and "
                f"never drawn by the clinic, and one edit puts a cadastre boundary back into "
                f"the figure — `colourOf` honours a Feature's own colour (#24)",
            )
        if wants_colour and not offered:
            failures.append(f"{name!r} is offered no colour, and a Sector still carries one (#24)")
        dismiss(page)
        page.wait_for_timeout(400)

    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)

def _ground_stays_ground_while_it_is_open(page, uv, failures):
    """The same Unidad Vecinal, measured AFTER its row is opened.

    A different code path entirely, and the reason this function exists rather
    than one more assertion above. Opening any shape record copies its geometry
    onto the draft (`draftFrom`, App.vue), so `drawShapes` stops drawing the
    Unidad Vecinal and the draft layer starts — and the draft layer used to
    style everything on it as figure, reasoning that a draft is something the
    clinic is drawing right now. True of a new polygon; false of a cadastre
    boundary somebody merely clicked. The checks above measure the path before
    any row is clicked, so they cannot see this.

    The LABEL is re-measured with the fill and the dashes, and for the same
    reason: `drawShapes` binds it and the draft layer has to reproduce
    everything `drawShapes` would have drawn. The style was carried across when
    this was first fixed and the label was not, so an open division was the one
    division on the map with no name on it — «a Sector carries a permanent label
    at its centroid» (#21) being false for precisely the record being worked on.
    """
    shapes = _only_shape(page, uv)
    if shapes.count() == 0:
        failures.append(f"{uv!r} drew no shape to open (#21)")
        return

    open_first_row(page)
    page.wait_for_timeout(1200)

    reopened = page.locator(".leaflet-overlay-pane path")
    if reopened.count() == 0:
        failures.append(f"opening {uv!r} left nothing drawn on the map at all (#21)")
    else:
        drawn = reopened.first.evaluate(
            """(path) => {
                const style = getComputedStyle(path)
                return { fill: style.fill, dashes: style.strokeDasharray }
            }""",
        )
        if drawn["fill"] != "none":
            failures.append(
                f"an OPEN Unidad Vecinal is filled {drawn['fill']!r} — it is read from the "
                f"cadastre and never drawn by the clinic, and being looked at does not turn "
                f"ground into figure (#21)",
            )
        if re.findall(r"\d+", drawn["dashes"] or "")[:2] != ["2", "3"]:
            failures.append(
                f"an OPEN Unidad Vecinal's outline is {drawn['dashes']!r}, not the '2 3' "
                f"dashed hairline it is drawn with when nothing is open (#21)",
            )

        # The zoom loop in _the_ground_label_waits_for_its_zoom left the map past
        # the threshold, so a ground label is due here — which is what makes its
        # absence an assertion rather than a coin toss.
        labels = page.locator(".leaflet-tooltip.territorio-label")
        if labels.count() == 0 or not labels.first.is_visible():
            failures.append(
                f"opening {uv!r} took its permanent label off the map — the draft layer draws "
                f"the record in place of drawShapes and has to say the same things about it, "
                f"the name included (#21)",
            )
        elif labels.first.inner_text().strip() != uv:
            failures.append(
                f"an OPEN Unidad Vecinal is labelled {labels.first.inner_text().strip()!r} "
                f"rather than {uv!r} (#21)",
            )
        elif "territorio-label--ground" not in (labels.first.get_attribute("class") or ""):
            failures.append(
                f"an OPEN Unidad Vecinal's label is drawn as a Sector's — being looked at "
                f"does not turn ground into figure, for the label any more than for the "
                f"fill (#21)",
            )

    dismiss(page)
    page.wait_for_timeout(400)
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


def _the_chart_draws_a_division_neutral(page, neutral, failures):
    """Análisis: the last surface the division Category's `#334155` can reach.

    Both rows of the section, not just the top one. The Category row and the
    Subcategory rows under it are separate bindings, and the first fix moved
    only the first — so a install with Sectores or Unidades Vecinales drew its
    `Sector` and `Unidad Vecinal` bars in the inert seed while the section
    header above them was correctly neutral.
    """
    _pane(page, "Análisis")
    page.wait_for_timeout(600)

    section = page.locator(".territorio-analisis__category").filter(
        has_text="División territorial",
    ).first
    if section.count() == 0:
        skip("Análisis shows no División territorial section, so its bars are unchecked (#29)")
        _pane(page, "Mapa")
        page.wait_for_timeout(400)
        return

    painted = section.locator(
        ".territorio-analisis__fill, .territorio-analisis__swatch",
    ).evaluate_all(
        "(nodes) => nodes.map((node) => getComputedStyle(node).backgroundColor)",
    )

    if len(painted) < 2:
        failures.append(
            "the División territorial section drew fewer than two bars, so the Subcategory "
            "rows — the ones that kept the seed colour — were not measured (#29)",
        )

    for colour in painted:
        if _hex_of(colour) != neutral:
            failures.append(
                f"a División territorial bar is painted {colour!r}, not the neutral "
                f"{neutral} — the seeded `#334155` measures 1.73:1 against the dark theme "
                f"and ADR-0013 leaves nowhere legible to move it to, so it renders "
                f"nowhere (#29)",
            )

    _pane(page, "Mapa")
    page.wait_for_timeout(400)


def _ramp_check():
    """tools/ramp-check.py as a module, for its arithmetic.

    Imported rather than re-implemented. The hyphen in the filename keeps it off
    the normal import path, and a second copy of the contrast formula here is
    exactly the drift this whole check exists to catch.

    What a Category IS coloured is never read from here: that comes from what the
    app serves (#42). What the seed reader still answers is the two things no
    surface serves at all — the spare list a new Category draws from and the
    neutral it falls back to (#45) — so `seeded_spares()` is called again, while
    the TAKEN set beside it is still the served one.
    """
    import importlib.util

    path = Path(__file__).resolve().parent / "ramp-check.py"
    spec = importlib.util.spec_from_file_location("ramp_check", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)

    return module


# The Category holding the territorial divisions, as src/taxonomy.js names it.
# Copied rather than read out of src/ the way _frontend_colour() reads a colour,
# because a divergence here cannot pass quietly: rename the slug in the app and
# its inert #334155 stays in the expected set while nothing paints it, so the
# "served but never painted" half goes red naming the colour.
DIVISIONS_SLUG = "division_territorial"


def _served_categories(page):
    """slug -> hex for the Categories the app is serving this browser.

    Read out of the initial state the page was rendered with — the element
    `@nextcloud/initial-state` reads, base64 JSON — because that IS the taxonomy
    Vue received. `PageController` provides it and nothing serves it over HTTP,
    and a route invented for a check would be a path the app never takes.

    Not out of the seed any more (#42). The seed was the right source only while
    the seed was the only source of Categories: a clinic may already recolour one
    (tests/integration/RampRepaintTest.php is the guard that a repaint stops at a
    colour it chose) and will soon be able to add one, and either turns every
    check below permanently red on a fact that has stopped being true. What the
    SEEDED ramp is worth — contrast against white and the dark theme, separation
    from its neighbours — is measured by tools/ramp-check.py, which is the only
    thing here that can and which this does not touch.

    The territorial divisions Category is dropped. Its `#334155` is inert (#29):
    a division draws a boundary on the map and a ring in the list, never a filled
    swatch, so its colour reaches no surface and would be reported missing on
    every run. `treeOf()` in src/taxonomy.js leaves it out of the tree for the
    same reason.

    Lower-cased once, here, because `getComputedStyle` always answers in lower
    case and a clinic typing `#B3261E` into the colour input is the same colour.
    """
    tree = page.evaluate(
        """() => {
            const element = document.getElementById('initial-state-territorio-taxonomy')
            return element === null ? null : JSON.parse(atob(element.value))
        }""",
    )

    return {
        category["slug"]: category["color"].lower()
        for category in tree or []
        if category["slug"] != DIVISIONS_SLUG
    }


def _hex_of(painted):
    """`rgb(148, 148, 148)` as `#949494`. Returns None for `none` and for blanks."""
    numbers = re.findall(r"\d+", painted or "")
    if len(numbers) < 3:
        return None

    return "#" + "".join(f"{int(n):02x}" for n in numbers[:3])


def _opaque(painted):
    """Whether a computed colour paints anything at all.

    `rgba(0, 0, 0, 0)` is what an unset background computes to, and reading only
    its first three numbers would report a transparent element as filled black —
    which is the exact assertion #29 turns on.
    """
    parts = re.findall(r"[\d.]+", painted or "")

    return len(parts) >= 3 and (len(parts) < 4 or float(parts[3]) > 0)


def _as_hex(painted):
    """A colour as #rrggbb, whatever notation it was read in.

    Both notations turn up: `getComputedStyle().backgroundColor` is always
    `rgb()`, while a CSS variable read with `getPropertyValue` comes back exactly
    as the theme wrote it, which for Nextcloud is a hex. A hex is returned as it
    stands rather than run through the digit regex — `#0b6bcb` holds three
    "numbers" that have nothing to do with its channels.
    """
    value = (painted or "").strip().lower()
    if value.startswith("#"):
        return value if len(value) != 4 else "#" + "".join(c * 2 for c in value[1:])

    numbers = re.findall(r"[\d.]+", value)
    if len(numbers) < 3:
        return value

    return "#" + "".join(f"{int(float(n)):02x}" for n in numbers[:3])


def every_category_reaches_the_browser_with_its_colour(page, failures):
    """The colours the app serves are the colours on the screen.

    Compares against the served values, not against an invariant. The first
    version of this check asserted only "non-blank and all different", and a
    review pointed out that the old palette satisfies both — eleven distinct,
    non-blank colours — so the check could not fail for the exact reason its own
    docstring gave for existing. It was theatre.

    The failure it catches: a Category the app serves that never becomes a
    swatch, or becomes one in somebody else's colour. Both halves are asserted —
    a served colour nobody painted, and a painted colour nobody serves — because
    a Category silently dropped on the way into Vue and a swatch drawing the
    wrong row are the same defect seen from either end, and only one of the two
    comparisons sees each.

    It no longer compares against the seed (#42). It could, while the seed was
    the only source of Categories; a clinic that recolours one — or adds one —
    is not drift, so a check reading the seed would go red on a correct install
    and stay red. What the seeded ramp is worth is measured by
    tools/ramp-check.py, and that a seed change reaches an existing install is
    proved by tests/integration/RampRepaintTest.php. Neither is this check's job
    any more, and saying so is the point of the slice.

    Asserts on what the Registry served, per SKILL.md § 5: these colours come out
    of the database, through initial state, into Vue. Nothing else here reads
    that path end to end.
    """
    served = _served_categories(page)

    # Named separately from "no swatches", rather than left to the comparison
    # below, because the comparison would blame the wrong half: eleven correct
    # swatches against an empty expected set reads as "the browser painted
    # eleven colours nobody serves". `loadState` leaves the element in the DOM
    # in the version this app ships — checked, not assumed — and this is what
    # says so out loud on the day a version stops doing that.
    if not served:
        failures.append(
            "the page carries no served taxonomy at #initial-state-territorio-taxonomy, so "
            "nothing could be compared with what the Capas panel painted",
        )
        return

    swatches = page.locator(".territorio-capas__swatch")
    count = swatches.count()
    if count == 0:
        failures.append("the Capas panel drew no Category swatches at all")
        return

    painted = {
        _as_hex(swatches.nth(i).evaluate("e => getComputedStyle(e).backgroundColor"))
        for i in range(count)
    }
    expected = set(served.values())

    stale = expected - painted
    if stale:
        failures.append(
            f"the app serves {sorted(stale)} but the browser never painted them — a Category in "
            f"the taxonomy the Registry served reached no swatch",
        )

    unexpected = painted - expected
    if unexpected:
        failures.append(
            f"the browser painted {sorted(unexpected)}, which no Category the app serves carries",
        )

    # Whether the Sector default is far enough from a Category is NOT checked
    # here. It is a question about perceptual distance — the first attempt at
    # that fix shipped a grey sitting dE 0.026 from `sin_clasificar`, which no
    # equality test can see — and `tools/ramp-check.py` owns it, because it is
    # the only thing here that can measure OKLab. This gate proves the round
    # trip; that check proves the colour.


def _swatch_glyphs(page, selector, swatch_px, glyph_px, where, failures):
    """One swatch surface: the glyph is white, and it is still the smaller size.

    White is asserted on the `<path>`'s computed fill rather than on any rule,
    because the defect #37 found is invisible in the source: `NcIconSvgWrapper`
    ships `.icon-vue[data-v-…] svg { fill: currentColor }` at specificity
    (0,2,1), a scoped `:deep(svg) { fill: #fff }` compiles to (0,2,1) as well,
    and the tie went to whichever stylesheet loaded last. Both files carried a
    comment saying these glyphs were white while they rendered in the theme's
    dark text colour. Only the browser can tell those two apart.

    The size is asserted with it for the opposite reason: #37 raised the pin
    alone, on purpose, and "make them match" is the tidy-looking change that
    would undo it.
    """
    swatches = page.locator(selector)
    if swatches.count() == 0:
        failures.append(f"{where}: no swatches are on screen, so nothing was checked")
        return

    measured = swatches.first.evaluate(
        """(swatch) => {
            const svg = swatch.querySelector('svg')
            const path = swatch.querySelector('svg path')
            const box = swatch.getBoundingClientRect()
            const glyphBox = svg ? svg.getBoundingClientRect() : null
            return {
                glyphFill: path ? getComputedStyle(path).fill : null,
                swatch: [Math.round(box.width), Math.round(box.height)],
                mark: glyphBox ? [Math.round(glyphBox.width), Math.round(glyphBox.height)] : null,
            }
        }""",
    )

    if measured["mark"] is None:
        failures.append(f"{where}: a swatch holds no glyph at all")
        return

    if measured["glyphFill"] != "rgb(255, 255, 255)":
        failures.append(
            f"{where}: the glyph is drawn {measured['glyphFill']}, not white — it is sitting on a "
            f"data colour that does not follow the theme, and the ramp clears 3:1 against white "
            f"and against nothing else",
        )
    if measured["swatch"] != [swatch_px, swatch_px]:
        failures.append(
            f"{where}: the swatch draws {measured['swatch']}px, not {swatch_px}px — #37 raised "
            f"the map pin alone, because only the pin has no label beside it",
        )
    if measured["mark"] != [glyph_px, glyph_px]:
        failures.append(
            f"{where}: the glyph draws {measured['mark']}px, not {glyph_px}px",
        )


def the_glyphs_beside_a_label_are_white_too(page, failures):
    """#37: the Datos list and the Capas legend, which rendered dark for months.

    `--dot`, not any swatch. Since #29 a territorial division draws a RING with
    no pictogram inside it — a division has no glyph of its own and would fall
    back to `circle-stroked`, an outlined circle inside an outlined ring. So
    "the first swatch on screen" is only sometimes a thing with a glyph in it,
    and this check measured [0, 0]px the first time a Sector sorted to the top.
    Narrowing it keeps #37's assertion exactly as strong — the class is emitted
    by `markFor`, so a swatch with no dots on screen still fails as "nothing was
    checked" rather than passing quietly.
    """
    _swatch_glyphs(
        page, ".territorio-list__swatch--dot", LIST_SWATCH, LIST_GLYPH, "the Datos list", failures,
    )
    _swatch_glyphs(
        page, ".territorio-capas__swatch", CAPAS_SWATCH, CAPAS_GLYPH, "the Capas legend", failures,
    )


# The one file the header, the app menu and the favicon all draw (#26). Named
# here so a fallback to core's generic icon, or a slip to the dark variant, is a
# failure with a name rather than a picture nobody looks at. Matched as a suffix:
# the served path starts with `/custom_apps/` on this instance and `/apps/` on one
# where the app was installed from the store, and neither is the point.
APP_ICON = "/territorio/img/app.svg"


def _the_icon_paints_the_right_colour(page, selector, contrast_var, where, failures):
    """One icon surface: the right file, the white bytes, the right painted colour.

    The painted colour is derived rather than sampled. Core draws the app icon
    through `filter: var(--primary-invert-if-bright)` (AppItem) or
    `var(--background-image-invert-if-bright)` (AppMenu), and both resolve to
    `invert(100%)` exactly when the theme's own text colour for that surface is
    black — `Util::getTextColor()` and `Util::invertTextColor()` are the same
    predicate. So: the source glyph is white, the computed filter says whether it
    is flipped, and the result has to equal the colour the theme says foreground
    is on that surface. A sampled pixel would say the same thing less reliably,
    through a mask that fades the glyph's alpha to 45%.
    """
    icon = page.locator(selector).first
    if icon.count() == 0:
        failures.append(f"{where}: no app icon is rendered at all ({selector})")
        return

    source = icon.get_attribute("src") or ""
    if APP_ICON not in source:
        failures.append(
            f"{where}: the icon served is {source!r}, not {APP_ICON} — either the app ships no "
            f"icon and core substituted its own, or the dark variant reached a surface that "
            f"asks for the light one",
        )
        return

    served = page.evaluate("async url => (await fetch(url)).text()", source)
    if 'fill="#fff"' not in served:
        failures.append(
            f"{where}: the server served {source} without a white fill, so the glyph is black "
            f"on a coloured surface. The file in img/ is not what the app is serving.",
        )

    painted, expected = icon.evaluate(
        """(element, variable) => {
            const style = getComputedStyle(element)
            const inverted = style.filter.includes('invert')
            return [inverted ? '#000000' : '#ffffff',
                    style.getPropertyValue(variable).trim().toLowerCase()]
        }""",
        contrast_var,
    )

    if expected not in ("#ffffff", "#000000"):
        failures.append(f"{where}: {contrast_var} is {expected!r}, which is neither black nor white")
    elif painted != expected:
        failures.append(
            f"{where}: the glyph paints {painted} while the theme puts {expected} text on that "
            f"surface — the icon is the colour of its own background",
        )


def the_app_icon_is_legible_in_both_themes(page, failures):
    """#26: the app's own icon, on the two surfaces that draw it, in both themes.

    Both files existed and were byte-identical, which meant one of them was
    always wrong and nothing said which. `img/app.svg` is the white one — the
    header's current-app button and the waffle grid both ask
    `AppManager::getAppIcon()` without `$dark` — and this is the half no unit
    test can reach: that those bytes are what the running server serves and what
    core actually paints.

    The theme is flipped with `prefers-color-scheme` rather than with
    `occ user:setting … enabled-themes`, because the default theme ships its dark
    half under exactly that media query (`DarkTheme::getMediaQuery()`) and the
    emulation leaves no state behind on the instance to restore.
    """
    backgrounds = {}

    for scheme in ("light", "dark"):
        page.emulate_media(color_scheme=scheme)
        page.wait_for_timeout(300)
        backgrounds[scheme] = page.evaluate(
            "() => getComputedStyle(document.body).getPropertyValue('--color-main-background')",
        )

        _the_icon_paints_the_right_colour(
            page, ".app-menu__current-app-icon", "--color-background-plain-text",
            f"the header's current-app button ({scheme} theme)", failures,
        )

        page.locator(".app-menu__waffle").click()
        page.wait_for_selector(".app-menu__popover", timeout=10_000)
        _the_icon_paints_the_right_colour(
            page, '.app-item[href*="/apps/territorio"] .app-item__icon',
            "--color-primary-element-text",
            f"the app menu's Territorio tile ({scheme} theme)", failures,
        )
        page.screenshot(path=str(SHOTS / f"app-icon-{scheme}.png"), full_page=False)
        dismiss(page)
        page.wait_for_timeout(300)

    page.emulate_media(color_scheme="no-override")

    # A silent half-run is the failure this project keeps meeting: if the admin
    # has pinned a theme, the dark CSS is served unconditionally and emulating
    # the media query changes nothing, so the loop above checked one theme twice.
    if backgrounds["light"] == backgrounds["dark"]:
        skip("the app icon was only checked in one theme: this instance pins a theme, so "
             "prefers-color-scheme does not switch it")


def _a_boundary_box(south, west):
    """A small square, a tenth of a degree on a side, sited where nothing is.

    An imported division claims every Place it covers (ADR-0008), so a check
    that needs one has to put it somewhere it can claim nothing.
    """
    north, east = south + 0.01, west + 0.01
    return {
        "type": "Polygon",
        "coordinates": [[[west, south], [east, south], [east, north],
                         [west, north], [west, south]]],
    }


def _imported(name, category, subcategory, geometry):
    """One record under a given Subcategory, through the import occ already offers.

    Through an import rather than a POST because `ImportService::classify()`
    files a row under any Subcategory by name, so this needs no id out of the
    taxonomy and no second way of writing a Feature. A Route and a Unidad
    Vecinal are two of the four shapes the sidebar has to handle and neither
    exists on this instance for a check to open.
    """
    return import_dataset(json.dumps({
        "type": "FeatureCollection",
        "features": [{
            "type": "Feature",
            "properties": {"id": f"{subcategory}-{RUN}", "name": name,
                           "category": category, "subcategory": subcategory},
            "geometry": geometry,
        }],
    }), f"{subcategory}-{RUN}", boundary=False)


def _contrast(front, back):
    """WCAG 2 contrast ratio between two `rgb(r, g, b)` strings.

    The gate had nothing that could see a colour it could not name, and the one
    thing it could not name was a destructive control painted in a colour that
    is not a foreground colour: Nextcloud's `--color-error` is #FFE7E7, the pale
    tint an error BANNER is filled with, and `--color-error-text` (#8A0000) is
    the readable one. Written as `--color-error`, «Retirar del mapa» came out
    near-white on white — a ratio of 1.06 — and every assertion in this file
    passed, because they all ask where the button is and none what it looks like.
    """
    def channels(value):
        numbers = [float(n) for n in re.findall(r"[\d.]+", value)[:3]]
        return [n / 255 for n in numbers]

    def luminance(rgb):
        linear = [c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4 for c in rgb]
        return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2]

    light, dark = sorted((luminance(channels(front)), luminance(channels(back))), reverse=True)
    return (light + 0.05) / (dark + 0.05)


def _the_destructive_action_is_legible(page, failures):
    """#35 criterion 2: «Retirar del mapa» reads as a destructive outline.

    Measured rather than looked at, because looking at it is what nearly did not
    happen. Both the text and the border are asserted against the bar's own
    background at the 4.5:1 WCAG AA text threshold — a border painted in an
    invisible colour is no outline at all — and the filled Guardar beside it is
    asserted too, so "destructive outline" cannot be satisfied by making both of
    them the same thing.
    """
    painted = page.evaluate(
        """() => {
            const bar = document.querySelector('.territorio-detail__actions')
            const retire = bar && bar.querySelector('.territorio-detail__retire')
            const save = bar && bar.querySelector('button[type="submit"]')
            if (!bar || !retire || !save) return null
            const read = el => getComputedStyle(el)
            return {
                bar: read(bar).backgroundColor,
                retireText: read(retire).color,
                retireBorder: read(retire).borderTopColor,
                retireFill: read(retire).backgroundColor,
                saveFill: read(save).backgroundColor,
            }
        }""",
    )
    if painted is None:
        failures.append("no action group to judge the destructive styling of (#35)")
        return

    for what, colour in (("text", painted["retireText"]), ("outline", painted["retireBorder"])):
        ratio = _contrast(colour, painted["bar"])
        if ratio < 4.5:
            failures.append(
                f"«Retirar del mapa»'s {what} is {colour} on {painted['bar']} — a contrast of "
                f"{ratio:.2f}:1, under the 4.5:1 it needs to be seen at all (#35)",
            )

    # Filled beside outlined: Guardar is painted, the retire button is not.
    if _contrast(painted["saveFill"], painted["bar"]) < 1.5:
        failures.append(
            f"Guardar is {painted['saveFill']} on {painted['bar']} — it does not read as the "
            "filled primary action beside the destructive one (#35)",
        )
    if "rgba(0, 0, 0, 0)" not in painted["retireFill"]:
        failures.append(
            f"«Retirar del mapa» is filled with {painted['retireFill']} — it is meant to be an "
            "outline beside the filled Guardar, not a second filled button (#35)",
        )


def the_retire_control_is_reachable_without_scrolling(page, failures):
    """#35: «Retirar del mapa» is inside the viewport, whatever the sidebar holds.

    Reported as "there is no button that allows deletion". The button was there
    the whole time, 721px below the bottom of a 620px screen — which is the one
    kind of failure nothing in this file could see, because every other check
    asks whether a thing EXISTS and Playwright happily clicks what a person
    would have to scroll to find.

    So this one measures. `bounding_box()` against the viewport height, at the
    two sizes the issue names, for one record of each shape the sidebar draws: a
    Sector (the Limit editor, the tall one), a Place, a Route and a Unidad
    Vecinal.

    The Sector is this run's own, given 30 tramos first rather than a second
    Sector drawn beside it. 30 is the length the issue measured reordering alone
    failing at, and the Limit is the only thing here that can quietly invalidate
    this check by getting longer — so the check states the length it was proven
    at instead of inheriting whatever the run happened to leave.

    Then it scrolls. At scroll 0 the action group is the first child of the form
    and is on screen with or without `position: sticky`, so that measurement
    alone grades the reorder twice and the sticky bar not at all. The Sector is
    measured a second time with the sidebar scrolled to its end, which is the
    only position from which the two can be told apart.
    """
    sector = registry_feature(page, f"Sector Navegador {RUN}")
    if sector is None:
        skip("this run has no Sector to lengthen; the above-the-fold measurement is unchecked")
        return

    lengthened = page.evaluate(
        """async ({ id, body }) => {
            const response = await fetch(
                `/index.php/apps/territorio/features/${id}`,
                {
                    method: 'PUT',
                    headers: {
                        requesttoken: window.OC.requestToken,
                        'content-type': 'application/json',
                    },
                    body: JSON.stringify(body),
                },
            )
            return response.status
        }""",
        {
            "id": sector["id"],
            "body": {
                "name": sector["name"],
                "subcategoryId": sector["subcategoryId"],
                # Added to what is already recorded, never instead of it: the
                # picked parts are what the Limit checks above left behind.
                "limit": [*(sector.get("limit") or []),
                          *({"name": f"tramo {n} del cerro"} for n in range(30))],
            },
        },
    )
    if lengthened != 200:
        failures.append(f"could not give the Sector 30 tramos to grow the sidebar: HTTP {lengthened}")
        return

    route, unidad = f"Ciclovía Navegador {RUN}", f"UV Navegador {RUN}"
    made = {
        route: _imported(route, "movilidad_transporte", "ciclovia", {
            "type": "LineString",
            "coordinates": [[-70.43, -33.73], [-70.42, -33.73], [-70.42, -33.72]],
        }),
        unidad: _imported(unidad, "division_territorial", "unidad_vecinal",
                          _a_boundary_box(-33.73, -70.43)),
    }
    for name, ok in made.items():
        if not ok:
            skip(f"could not import {name!r}; that shape's above-the-fold measurement is unchecked")

    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1200)
    # The Unidad Vecinal is only in the list while its division is shown, and
    # main() turns that toggle off before anything draws.
    show_divisions(page, ["Sector", "Unidad Vecinal"])

    opened = [f"Sector Navegador {RUN}", f"Plaza del Navegador {RUN}",
              *(name for name, ok in made.items() if ok)]

    # 1366x768 is the smallest desktop this app is used on; 1362x620 is the
    # viewport the issue was measured in.
    for width, height in ((1366, 768), (1362, 620)):
        page.set_viewport_size({"width": width, "height": height})
        page.wait_for_timeout(400)

        for name in opened:
            page.locator(SEARCH).fill(name)
            page.wait_for_timeout(800)
            if page.locator(ROWS).count() == 0:
                failures.append(f"{name!r} is not in the list to open at {width}x{height} (#35)")
                continue

            open_first_row(page)
            page.wait_for_timeout(1200)

            control = page.get_by_role("button", name="Retirar del mapa")
            if not settles(page, lambda: control.count() > 0, timeout=5_000):
                failures.append(f"{name!r} offers no way to retire it at {width}x{height} (#35)")
                dismiss(page)
                continue

            box = control.first.bounding_box()
            if box is None:
                failures.append(f"«Retirar del mapa» is not rendered for {name!r} (#35)")
            elif box["y"] + box["height"] > height:
                failures.append(
                    f"«Retirar del mapa» ends {round(box['y'] + box['height'])}px down a "
                    f"{height}px screen for {name!r} — below the fold (#35)",
                )

            # The picture the criterion is judged on, taken where it is hardest:
            # the tall sidebar, on the smallest screen, with the control in it.
            # Saved for whoever lands this to look at, per SKILL.md § 5.
            if (width, height) == (1362, 620) and name.startswith("Sector "):
                page.screenshot(path=str(SHOTS / "retire-above-the-fold.png"), full_page=False)
                _the_destructive_action_is_legible(page, failures)

            # Everything above was measured at scroll 0, where the action group
            # is the first child of the form and is on screen whether or not it
            # is sticky — the reorder alone satisfies it, 30 tramos and all. What
            # only `position: sticky` can do is keep the group there once the
            # form scrolls, so the Sector — the one record tall enough to scroll
            # — is measured again at the bottom of the sidebar. Without this the
            # sticky half of the fix is guarded by nothing that can observe it.
            if box is not None and name.startswith("Sector "):
                # The docked panel's sheet is the element that scrolls: it is
                # `overflow-y: auto` in App.vue and holds the whole record. If it
                # stops being the scroller the branch below says so.
                sidebar = page.locator(".territorio-docked-panel__sheet")
                if sidebar.count() == 0:
                    failures.append(
                        f"no panel sheet to scroll for {name!r} at {width}x{height}, so "
                        "nothing measured whether «Retirar del mapa» stays put (#35)",
                    )
                    dismiss(page)
                    page.wait_for_timeout(300)
                    continue

                scrolled = sidebar.first.evaluate(
                    "el => { el.scrollTop = el.scrollHeight; return el.scrollTop }",
                )
                page.wait_for_timeout(400)
                # What makes the pair of measurements a test of sticky rather
                # than of luck: a group that merely sat somewhere in the middle
                # of the form would need `y + height <= height` at scroll 0 AND
                # `y >= scrolled` at the end, which is only possible when the
                # sidebar scrolls less than one screenful. So too short a scroll
                # is a failure, not a pass — it is the one way this check could
                # report green having observed nothing.
                if scrolled < height:
                    failures.append(
                        f"the sidebar scrolled only {round(scrolled)}px on a {height}px screen "
                        f"for {name!r}: less than a screenful, so the measurement below cannot "
                        "tell a stuck group from one that happens to be in view (#35)",
                    )
                else:
                    stuck = control.first.bounding_box()
                    if stuck is None:
                        where = "not rendered any more"
                    else:
                        top, bottom = round(stuck["y"]), round(stuck["y"] + stuck["height"])
                        where = "y={}..{}px on a {}px screen".format(top, bottom, height)
                    if stuck is None or stuck["y"] < 0 or stuck["y"] + stuck["height"] > height:
                        failures.append(
                            f"«Retirar del mapa» scrolled away with the form for {name!r} at "
                            f"{width}x{height} ({where}) — the action group is not stuck (#35)",
                        )
                    elif (width, height) == (1362, 620):
                        # The picture for criterion 3: the bar still under the
                        # sidebar header with the form scrolled to its end.
                        page.screenshot(path=str(SHOTS / "retire-still-stuck.png"),
                                        full_page=False)

            dismiss(page)
            page.wait_for_timeout(300)

    page.locator(SEARCH).fill("")
    page.set_viewport_size({"width": 1400, "height": 900})
    page.wait_for_timeout(400)

    # A Unidad Vecinal is a boundary: left behind it claims every Place inside it
    # on the instance the integration suite reads, exactly as the imported Sector
    # does. The Ciclovía is not a boundary and is left like every other Place.
    if made[unidad]:
        retire_the_runs_record(page, unidad)


def served_taxonomy(page):
    """The Category tree as a browser opening the app fresh would receive it.

    Through the app's own page rather than through the screen: initial state is
    what every surface in this app reads the taxonomy from, so this is the read
    a colleague who loads the app next does. It is the taxonomy's equivalent of
    registry_feature() above, and it exists for the same reason — a label that
    is only on screen looks exactly like one that was saved.

    Decoded the way `loadState` decodes it (`JSON.parse(atob(value))`), which is
    safe for accented labels because PHP escapes non-ASCII as \\uXXXX.
    """
    return page.evaluate(
        """async () => {
            const response = await fetch('/index.php/apps/territorio/', {
                headers: { requesttoken: window.OC.requestToken },
            })
            const html = await response.text()
            const node = new DOMParser()
                .parseFromString(html, 'text/html')
                .getElementById('initial-state-territorio-taxonomy')
            return node === null ? null : JSON.parse(atob(node.getAttribute('value')))
        }""",
    )


def _capas_category(page, label):
    """One Category branch of the Capas panel, by the label it currently shows.

    `:text-is` and not a substring: «Salud» is the start of «Salud mental», and
    the Subcategory rows carry no `__name` class precisely so this cannot pick
    one of them up.
    """
    open_capas(page)

    return page.locator(
        f'.territorio-capas__category:has(.territorio-capas__name:text-is("{label}"))',
    )


def _rename_in_capas(page, row, label, what, failures):
    """Press the pencil on one Capas row, type a name and press Enter.

    `.first` is the row's own control: a Category's pencil sits in its
    `<summary>`, before the Subcategory rows the open branch adds below it.
    """
    pencil = row.locator(CAPAS_RENAME).first
    if pencil.count() == 0:
        failures.append(f"the Capas panel offers no way to rename {what}")
        return False

    pencil.click()
    field = page.locator(CAPAS_INPUT)
    if not settles(page, lambda: field.count() > 0, timeout=4_000):
        failures.append(f"the rename control on {what} opened no field to type in")
        return False

    field.fill(label)
    field.press("Enter")
    page.wait_for_timeout(700)

    return True


SEEDED_CATEGORY, SEEDED_SUB = "Salud", "CESFAM"


def _open_capas_branch(page, row):
    """Open one Category branch of the Capas panel, and only if it is closed.

    Clicking a `<summary>` toggles it, so a branch another check left open would
    be folded shut here, and every locator below would then wait for a row that
    is in the DOM and not on the screen.
    """
    if not row.evaluate("node => node.open"):
        row.locator(".territorio-capas__name").click()
        page.wait_for_timeout(400)


def _seeded_pair(page):
    """What the Registry calls «salud» and its «cesfam» right now, or None.

    By slug, because the label is the thing in doubt: a run that stopped part
    way through the rename below left this instance under a name no variable
    here remembers, and the slug is the one thing a rename does not move (#40).
    """
    served = served_taxonomy(page)
    if served is None:
        return None

    category = next((entry for entry in served if entry["slug"] == "salud"), None)
    if category is None:
        return None

    sub = next(
        (entry for entry in category["subcategories"] if entry["slug"] == "cesfam"),
        None,
    )

    return category["label"], None if sub is None else sub["label"]


def _the_seeded_names_are_put_back(page, failures):
    """Rename «salud» and «cesfam» back to the labels the seed gave them.

    Nothing on the instance does this by itself — `testARenameSurvivesAnUpgrade`
    proves exactly that an upgrade leaves a renamed Category renamed — so a name
    left changed here stays changed for every later run: the check below would
    find no «Salud» to press the pencil on, for ever, and every other check that
    picks "Salud · CESFAM" out of the picker by name would lose its option.

    So this runs at both ends of the rename. On the way out it runs from a
    `finally`, and addresses the rows by what the Registry calls the slugs NOW
    rather than by a name a path that returned early never set. On the way in it
    heals what an earlier run left behind — a slow patch, a flaky `settles`, a
    Ctrl-C — instead of the check failing identically for ever.
    """
    names = _seeded_pair(page)
    if names is None:
        failures.append(
            "the app served no taxonomy for «salud», so nothing could tell whether the seeded "
            "names need putting back",
        )
        return

    category_label, sub_label = names
    # A missing «cesfam» is not something a rename can cause — it is reported
    # against the Registry — and here it only means there is no name to put back.
    wanted = (SEEDED_CATEGORY, None if sub_label is None else SEEDED_SUB)
    if names == wanted:
        return

    # The Subcategory first: it is addressed inside its Category's branch, and
    # renaming the Category would move the branch out from under this locator.
    if sub_label is not None and sub_label != SEEDED_SUB:
        row = _capas_category(page, category_label)
        if row.count() == 0:
            failures.append(
                f"the Capas panel shows no «{category_label}» Category, so «{sub_label}» cannot "
                f"be renamed back",
            )
        else:
            _open_capas_branch(page, row)
            child = row.locator(".territorio-capas__child").filter(has_text=sub_label)
            if child.count() == 0:
                failures.append(
                    f"«{sub_label}» is not in the Capas panel to be renamed back",
                )
            else:
                _rename_in_capas(
                    page, child.first, SEEDED_SUB, "the Subcategory back", failures
                )

    if category_label != SEEDED_CATEGORY:
        row = _capas_category(page, category_label)
        if row.count() == 0:
            failures.append(
                f"the Capas panel shows no «{category_label}» Category to rename back",
            )
        else:
            _rename_in_capas(page, row, SEEDED_CATEGORY, "the Category back", failures)

    if not settles(page, lambda: _seeded_pair(page) == wanted, timeout=6_000):
        failures.append(
            f"the seeded taxonomy is left changed on this shared instance: the Registry calls "
            f"«salud» and «cesfam» {_seeded_pair(page)}, not «{SEEDED_CATEGORY}» and "
            f"«{SEEDED_SUB}», and no seed or upgrade puts them back — every later run will find "
            f"no «{SEEDED_CATEGORY}» Category to rename",
        )


def a_category_can_be_renamed_without_a_reload(page, errors, failures):
    """#41: rename a Category and a Subcategory, and see it everywhere at once.

    "Without a reload" is the criterion, and it is the one thing no other suite
    can see: the integration suite proves the write and the served tree, but
    only a browser can show that the Capas panel, the Subcategoría picker and
    Análisis all moved without the page being loaded again. So nothing here
    reloads — if a reload turned out to be needed, these assertions fail.

    What was WRITTEN is still asserted against the Registry, per SKILL.md § 5:
    served_taxonomy() re-reads the app's initial state, which is the read a
    second browser does, and the slugs are checked there rather than the labels
    on screen — a rename that moved a slug would take every Feature filed under
    it with it (#40), and no screen shows a slug.

    Renames «Salud» and its «CESFAM», because this run's clustered records are
    filed there, and Análisis draws no section for a Category holding nothing.
    This instance is shared and several checks here pick "Salud · CESFAM" out of
    the picker by name, so the names are put back by
    `_the_seeded_names_are_put_back` — from a `finally`, because a rename that
    fails halfway has changed the instance just as much as one that succeeds.
    """
    renamed_category = f"{SEEDED_CATEGORY} {RUN}"
    renamed_sub = f"{SEEDED_SUB} {RUN}"

    # Análisis counts the filtered set, and an earlier check may have left a
    # query in the box.
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)

    # Before anything looks for «Salud» by name: an earlier run that died mid
    # rename left it called something else, and only this puts it back.
    _the_seeded_names_are_put_back(page, failures)

    # Análisis draws a section only for a Category holding something, and the
    # cluster check immediately above retires the three records it filed under
    # «Salud» on its way out — so by the time this runs, «Salud» holds nothing
    # live and Análisis has nothing to name. This check therefore files its own
    # record and retires it again in the `finally`, rather than borrowing
    # another check's leftovers and going red the day that check tidies up.
    filed_here = f"Cúmulo {RUN} análisis"
    _a_clustered_place(
        page, filed_here, f"{SEEDED_CATEGORY} · {SEEDED_SUB}", 4, failures
    )

    try:
        row = _capas_category(page, SEEDED_CATEGORY)
        if not settles(page, lambda: row.count() > 0, timeout=4_000):
            failures.append(
                f"the Capas panel shows no «{SEEDED_CATEGORY}» Category to rename"
            )
            return
        if not _rename_in_capas(
            page, row, renamed_category, f"«{SEEDED_CATEGORY}»", failures
        ):
            return

        renamed_row = _capas_category(page, renamed_category)
        if not settles(page, lambda: renamed_row.count() > 0, timeout=6_000):
            failures.append(
                f"the Capas panel still reads «{SEEDED_CATEGORY}» after a rename — it did not "
                f"update without a reload",
            )
            return

        # Open the branch, so the Subcategory rows are on screen to rename.
        _open_capas_branch(page, renamed_row)
        child = renamed_row.locator(".territorio-capas__child").filter(
            has_text=SEEDED_SUB
        )
        if child.count() == 0:
            failures.append(
                f"«{SEEDED_SUB}» is not under «{renamed_category}» in the Capas panel"
            )
            return
        if not _rename_in_capas(
            page, child.first, renamed_sub, f"«{SEEDED_SUB}»", failures
        ):
            return

        if not settles(
            page,
            lambda: (
                _capas_category(page, renamed_category)
                .locator(".territorio-capas__child")
                .filter(has_text=renamed_sub)
                .count()
                > 0
            ),
            timeout=6_000,
        ):
            failures.append(
                f"the Capas panel still reads «{SEEDED_SUB}» after a rename — it did not update "
                f"without a reload",
            )

        _the_picker_offers_the_new_name(
            page, f"{renamed_category} · {renamed_sub}", failures
        )
        _analisis_reads_the_new_name(page, renamed_category, renamed_sub, failures)

        served = served_taxonomy(page)
        _the_registry_kept_the_slugs(served, renamed_category, renamed_sub, failures)

        _a_blank_name_is_refused_in_the_panel(page, renamed_category, errors, failures)
    finally:
        # Every exit above has already renamed something, or may have: the write
        # can land and the panel still not settle. So the restore runs on all of
        # them, and reports if a name would not go back.
        _the_seeded_names_are_put_back(page, failures)
        # And the record this check filed so Análisis had a section to draw:
        # left behind it is one more live Place under «Salud» per run, on an
        # instance the integration suite counts.
        retire_the_runs_record(page, filed_here)


def _add_in_capas(page, category_label, label, failures):
    """Press the «+» on one Category row, type a name and press Enter.

    By its accessible name and not by a class: the control's `aria-label` names
    the Category it belongs to, so this cannot pick up the pencil beside it or
    the same control on another branch — and it is the string a screen reader
    reads, which is a better thing to pin than a class nobody sees.
    """
    add = page.get_by_role(
        "button", name=f"Agregar subcategoría en «{category_label}»", exact=True,
    )
    if add.count() == 0:
        failures.append(
            f"the Capas panel offers no way to add a Subcategory under «{category_label}»",
        )
        return False

    add.first.click()
    field = page.locator(CAPAS_INPUT)
    if not settles(page, lambda: field.count() > 0, timeout=4_000):
        failures.append(
            f"the add control on «{category_label}» opened no field to type in",
        )
        return False

    field.fill(label)
    field.press("Enter")
    page.wait_for_timeout(900)

    return True


def _capas_child(page, category_label, label):
    """One Subcategory row of one open Category branch, by the label it shows."""
    return (
        _capas_category(page, category_label)
        .locator(".territorio-capas__child")
        .filter(has_text=label)
    )


def a_subcategory_can_be_added_without_a_reload(page, errors, failures):
    """#44: add a Subcategory from the Capas panel and file a record under it.

    The criteria this is the only seam for are the ones about *appearing*: in the
    panel, in the Subcategoría picker and in Análisis, with nothing reloaded. The
    integration suite proves the write, the derived slug and the unique index
    against the real schema; it cannot prove that a person sees any of it.

    What was written is still asserted against the Registry per SKILL.md § 5:
    `served_taxonomy()` is the read a second browser does, and the slug is
    checked there, since no screen shows one and the slug is the thing every
    record filed under this Subcategory will be keyed on.

    Named per run, because this one cannot take itself away again: it files a
    record and retires it at the end, and #47 counts retired records, so the
    removal path is correctly refused for it. A fixed name would be added by the
    first run and refused as a duplicate by every run after it — and a check that
    only works once is not a check. The cost is one extra Subcategory under
    «Salud» per run on the dev instance, which `make instance-clean` resets.
    """
    # ponytail: the Subcategory this adds is left behind — one per run on the dev
    # instance. #47 gave the app a removal path, but not one this check can use:
    # the retired record it files below still counts (ADR-0016), so emptying it
    # would mean reclassifying that record through the sidebar first.
    added = f"Prueba {RUN}"
    slug = f"prueba_{RUN}"
    filed = f"Registro {RUN} en Prueba"

    row = _capas_category(page, SEEDED_CATEGORY)
    if not settles(page, lambda: row.count() > 0, timeout=4_000):
        failures.append(
            f"the Capas panel shows no «{SEEDED_CATEGORY}» Category to add a Subcategory under",
        )
        return

    if not _add_in_capas(page, SEEDED_CATEGORY, added, failures):
        return

    try:
        if not settles(
            page,
            lambda: _capas_child(page, SEEDED_CATEGORY, added).count() > 0,
            timeout=6_000,
        ):
            failures.append(
                f"«{added}» is not in the Capas panel under «{SEEDED_CATEGORY}» after being "
                f"added — it did not appear without a reload",
            )
            return

        if not _capas_child(page, SEEDED_CATEGORY, added).first.is_visible():
            failures.append(
                f"«{added}» is in the Capas panel's markup but not on the screen: the branch it "
                f"was added to was left folded shut",
            )

        # The assertions above are the gate; this is so a person can see what
        # the gate approved, which is the habit SKILL.md § 5 asks for on any
        # criterion about something appearing. Scrolled to first: the panel is
        # a scrolling column and an element screenshot of it captures what is
        # in view, so without this the picture shows the branch and not the row
        # it is supposed to be evidence of.
        _capas_child(page, SEEDED_CATEGORY, added).first.scroll_into_view_if_needed()
        page.wait_for_timeout(200)
        capas_shot(page, str(SHOTS / "44-capas-panel.png"))

        served = served_taxonomy(page)
        stored = None if served is None else next(
            (entry for entry in served if entry["slug"] == "salud"), None,
        )
        if stored is None:
            failures.append(
                "the app served no «salud» in initial state, so nothing confirmed the Subcategory "
                "was written rather than only painted",
            )
            return

        written = next(
            (entry for entry in stored["subcategories"] if entry["label"] == added), None,
        )
        if written is None:
            failures.append(
                f"the Capas panel shows «{added}» but the Registry does not: it was painted and "
                f"not written",
            )
            return
        if written["slug"] != slug:
            failures.append(
                f"the Registry filed «{added}» under the slug {written['slug']!r} and not "
                f"{slug!r}: the slug is not being derived from the name (#40)",
            )

        _a_clustered_place(page, filed, f"{SEEDED_CATEGORY} · {added}", 6, failures)
        record = registry_feature(page, filed)
        if record is None:
            failures.append(
                f"{filed!r} is not in the Registry: a record could not be filed under a "
                f"Subcategory that had just been added",
            )
        elif record.get("subcategoryId") != written["id"]:
            failures.append(
                f"{filed!r} was filed under Subcategory {record.get('subcategoryId')!r} and not "
                f"under the {written['id']!r} that was just added",
            )

        _analisis_reads_the_new_name(page, SEEDED_CATEGORY, added, failures,
                                     shot="44-analisis.png")
        _the_same_name_twice_is_refused(page, added, errors, failures)
    finally:
        # The record, not the Subcategory: removing it would be refused while
        # this record is filed under it, retired or not (ADR-0016), and the
        # docstring above says why this one stays.
        retire_the_runs_record(page, filed)


def _add_a_category_in_capas(page, label, failures):
    """Press «Agregar categoría» under the tree, type a name and press Enter.

    By its accessible name, like `_add_in_capas()`: «Agregar categoría» is the
    string a screen reader reads, and `exact=True` keeps it clear of the Datos
    column's «Agregar» and of #44's «Agregar subcategoría en «X»».
    """
    open_capas(page)
    add = page.get_by_role("button", name="Agregar categoría", exact=True)
    if add.count() == 0:
        failures.append("the Capas panel offers no way to add a Category")
        return False

    add.first.click()
    field = page.locator(CAPAS_INPUT)
    if not settles(page, lambda: field.count() > 0, timeout=4_000):
        failures.append("«Agregar categoría» opened no field to type in")
        return False

    field.fill(label)
    field.press("Enter")
    page.wait_for_timeout(900)

    return True


def _colour_owed_to_a_new_category(page):
    """What the app owes the next Category it is asked to create (ADR-0017).

    The first spare the seed ships that no Category already holds, and the
    neutral once they are gone. Derived here rather than hard-coded to a spare,
    because this check adds a Category per run it cannot take away again (the
    ponytail note below says why) — so the instance walks down the list over
    time, and a fixed expectation would go red on an install doing exactly what
    it should. Deriving it also means the
    exhaustion branch below starts being exercised for real on a long-lived
    instance rather than only in the integration suite.

    The spares and the neutral are read out of the repo's own two sources, the
    way `_ramp_check()` reads everything else here: a third copy of either in
    this file would be the drift the whole arrangement exists to prevent.
    """
    ramp = _ramp_check()
    taken = {
        category["color"].lower() for category in served_taxonomy(page) or []
    }

    return next(
        (spare for spare in ramp.seeded_spares() if spare.lower() not in taken),
        ramp.javascript_colours()["DEFAULT_SECTOR_COLOUR"],
    )


def a_category_can_be_added_without_a_reload(page, errors, failures):
    """#45: add a Category from the Capas panel and use it straight away.

    The criteria only a browser can prove are the ones about *appearing* — in
    the panel, in the Subcategoría picker and in Análisis, with nothing reloaded
    — and the one about the neutral not reading as a failure, which is a question
    about which note card it lands in. The integration suite proves the write,
    the derived slug and the unique index against the real schema; none of that
    says a person can see any of it. The walk down the spare list moved to
    `TaxonomyColourTest` in #134 — and #131 put every spare back within reach:
    the Category is taken away again at the end of
    `a_category_can_be_given_a_pictogram` (see the note below), so a run spends
    one spare and returns it rather than holding every spare before long.

    What was written is asserted against the Registry per SKILL.md § 5, through
    `served_taxonomy()` — the read a second browser does. The colour is pinned to
    the exact one the app OWED, which is the whole of ADR-0017's rule: an
    assertion that merely checked "some spare" would pass against an
    implementation that hands out the first one every time.

    Named per run for the reason #44's check gives: this Category cannot take
    itself away, so a fixed name would be added by the first run and refused as a
    duplicate by every run after it.
    """
    # The Category cannot be taken away here: `a_category_can_be_given_a_pictogram`
    # runs on it next, so this finally retires the record only and that check's
    # teardown reclaims the Category itself (`reclaim_the_runs_category`, #131).
    # A run killed between the two still spends the spare — `make instance-clean`
    # remains the reset for that.
    added = f"Comité {RUN}"
    slug = f"comite_{RUN}"
    filed = f"Registro {RUN} en Comité"

    owed = _colour_owed_to_a_new_category(page)
    neutral = _ramp_check().javascript_colours()["DEFAULT_SECTOR_COLOUR"]

    if not _add_a_category_in_capas(page, added, failures):
        return

    try:
        row = _capas_category(page, added)
        if not settles(page, lambda: row.count() > 0, timeout=6_000):
            failures.append(
                f"«{added}» is not in the Capas panel after being added — it did not appear "
                f"without a reload",
            )
            return
        if not row.first.is_visible():
            failures.append(
                f"«{added}» is in the Capas panel's markup but not on the screen",
            )

        # «It renders a pictogram rather than a blank». The glyph lookup has no
        # mark for a slug it has never seen and falls back to the neutral one —
        # a blank would read as a rendering failure, which is the thing #45 says
        # must not happen. `a_category_can_be_given_a_pictogram` below picks a
        # mark for this same Category, so the pair reads as one story: the
        # fallback first, and then the choice that replaces it.
        mark = row.locator(".territorio-capas__swatch").first.evaluate(
            """(swatch) => {
                const box = swatch.querySelector('svg path')?.getBoundingClientRect()
                return box ? [Math.round(box.width), Math.round(box.height)] : null
            }""",
        )
        if mark is None or min(mark) == 0:
            failures.append(
                f"the swatch for «{added}» draws no pictogram ({mark}): a clinic-added Category "
                f"is showing a blank, which reads as a rendering failure rather than as "
                f"unclassified",
            )

        row.first.scroll_into_view_if_needed()
        page.wait_for_timeout(200)
        capas_shot(page, str(SHOTS / "45-capas-panel.png"))

        written = next(
            (entry for entry in served_taxonomy(page) or [] if entry["slug"] == slug), None,
        )
        if written is None:
            failures.append(
                f"the Capas panel shows «{added}» but the Registry serves no Category under "
                f"{slug!r}: it was painted and not written, or the slug was not derived from "
                f"the name (#40)",
            )
            return
        if written["label"] != added:
            failures.append(f"the Registry filed the new Category as {written['label']!r}")
        if written["color"].lower() != owed.lower():
            failures.append(
                f"«{added}» took {written['color']!r} where the next unused spare was "
                f"{owed!r} — a colour is not being assigned as ADR-0017 says it is",
            )
        if [sub["slug"] for sub in written["subcategories"]] != ["otro"]:
            failures.append(
                f"«{added}» arrived with {[sub['slug'] for sub in written['subcategories']]} "
                f"rather than the «Otro» that makes it usable: nothing can be filed under it, "
                f"so it can reach neither the picker nor Análisis",
            )
            return

        # Created correctly, so nothing about it may be dressed as a refusal —
        # including the exhausted-spares case, which ADR-0017 says in as many
        # words must not read as an error.
        if page.locator(".territorio-capas .notecard--error").count() > 0:
            failures.append(
                f"adding «{added}» succeeded and the Capas panel answered with an error note "
                f"card: a Category that was created correctly is being announced as a refusal",
            )
        said = page.locator(".territorio-capas .notecard")
        if written["color"].lower() == neutral.lower():
            if said.count() == 0 or "pictograma" not in (said.first.text_content() or ""):
                failures.append(
                    f"«{added}» took the neutral because no spare was left and the panel said "
                    f"nothing about it — a person is left assuming the grey means something "
                    f"broke (ADR-0017)",
                )
        elif said.count() > 0:
            failures.append(
                f"«{added}» took the spare {written['color']!r} and the panel explained itself "
                f"anyway: {said.first.text_content()!r}",
            )

        _the_picker_offers_the_new_name(page, f"{added} · Otro", failures)

        _a_clustered_place(page, filed, f"{added} · Otro", 7, failures)
        record = registry_feature(page, filed)
        if record is None:
            failures.append(
                f"{filed!r} is not in the Registry: a record could not be filed under a Category "
                f"that had just been added",
            )
        elif record.get("subcategoryId") != written["subcategories"][0]["id"]:
            failures.append(
                f"{filed!r} was filed under Subcategory {record.get('subcategoryId')!r} and not "
                f"under the {written['subcategories'][0]['id']!r} the new Category arrived with",
            )

        _analisis_reads_the_new_name(page, added, "Otro", failures, shot="45-analisis.png")
        _the_same_category_name_twice_is_refused(page, added, slug, errors, failures)
    finally:
        # The record, not the Category: removing that would be refused while this
        # record is filed under it, retired or not, and the ponytail note above
        # says why this one stays.
        retire_the_runs_record(page, filed)


# Not one of the eleven the seed ships, so nothing below can pass by accident —
# and one whose meaning is unmistakable at 19px, which is what the preview is for.
PICKED_MARK = "bakery"


def _mark_named(locator):
    """The Maki name of the mark inside one element, or None.

    Off the rendered `<svg>`'s own id, which Maki writes into every icon — and
    which survives ONLY where this app inlines the markup itself, which is the
    map pin's `divIcon`. Everywhere else the mark goes through
    `NcIconSvgWrapper`, and that component removes the root id on purpose
    (`svgDocument.documentElement.removeAttribute('id')`), so use `_mark_shape`
    there. A check that read the id off a swatch would compare '' with '' and
    pass on any mark at all.
    """
    return locator.evaluate("(node) => node.querySelector('svg')?.id || null")


def _mark_shape(locator):
    """The geometry of the mark inside one element, or None.

    Every `<path d>` under it, joined. This is what identifies a pictogram
    wherever `NcIconSvgWrapper` has taken the name off it, and it is the right
    comparison for «the same mark on the map, in the list and in the legend»:
    two surfaces drawing one record have to draw the same SHAPE, which is the
    thing a person sees, not the same attribute.
    """
    return locator.evaluate(
        """(node) => {
            const paths = [...node.querySelectorAll('svg path')]
            return paths.length === 0
                ? null
                : paths.map((path) => path.getAttribute('d')).join('|')
        }""",
    )


def a_pictogram_can_be_picked_while_a_category_is_named(page, failures):
    """#46, the half of «chosen when adding» that only a browser can answer.

    The control sits beside the field the name is typed into, and reaching for it
    must not throw the name away: the field commits on Enter and CANCELS on blur
    (#45), so without `@mousedown.prevent` on the picker, picking a mark would
    close the add and discard what was typed. Nothing but a browser has a focus
    to lose.

    It clicks the picker's TITLE as well as a cell, because the guard is about
    the whole picker and not about the cells: the title, the picker's padding
    and the gaps between the wrapped 26px circles are most of what a hand lands
    on, and a check that only clicks a cell dead centre cannot tell a root guard
    from a per-cell one.

    It stops short of committing, and that is deliberate rather than an omission:
    a Category cannot be removed until #47, so a second one per run would spend a
    second vetted spare (ADR-0017) that nothing hands back. That the picked mark
    actually travels on the POST is settled in `CategoryCrudTest` against the real
    schema, and the body key is pinned in `CategoryControllerTest`.
    """
    open_capas(page)
    add = page.get_by_role("button", name="Agregar categoría", exact=True)
    if add.count() == 0:
        failures.append("the Capas panel offers no way to add a Category")
        return

    add.first.click()
    field = page.locator(CAPAS_INPUT)
    if not settles(page, lambda: field.count() > 0, timeout=4_000):
        failures.append("«Agregar categoría» opened no field to type in")
        return

    typed = f"Comité en borrador {RUN}"
    field.fill(typed)

    swatch = page.locator(".territorio-capas__new .territorio-capas__swatch")
    if swatch.count() == 0:
        failures.append(
            "a Category being named offers no pictogram control, so a mark can only be "
            "chosen after the Category exists — #46 asks for both",
        )
        dismiss(page)
        return

    swatch.first.click()
    mark = page.locator(f'.territorio-capas__glyph[title="{PICKED_MARK}"]')
    if not settles(page, lambda: mark.count() > 0, timeout=4_000):
        failures.append(
            f"the pictogram control beside the new Category's name opened no grid holding "
            f"{PICKED_MARK!r}",
        )
        dismiss(page)
        return

    # The title first, and the gap between two cells after it: the hazard is
    # not the cell, it is everything around it. A cell click is the one path a
    # per-cell `@mousedown.prevent` protects, so a check that only clicks a cell
    # passes over the cell-only version of this guard — which is how the first
    # cut of #46 shipped with the title, the padding and the 4px gaps live.
    # Clicking the title is the seeded violation SKILL.md § 6 asks for.
    page.locator(".territorio-capas__picker-title").click()
    page.wait_for_timeout(200)
    surviving = page.locator(CAPAS_INPUT)
    if surviving.count() == 0 or surviving.first.input_value() != typed:
        failures.append(
            "a mousedown on the picker's own title threw the new Category away — the guard "
            "has to sit on the picker root, where it also covers the padding and the gaps "
            "between the cells",
        )
        dismiss(page)
        return

    # Before the click, for the reason the other check gives: choosing closes
    # the grid and the cell is gone.
    chosen_shape = _mark_shape(mark.first)
    mark.first.click()
    page.wait_for_timeout(300)

    still = page.locator(CAPAS_INPUT)
    if still.count() == 0 or still.first.input_value() != typed:
        failures.append(
            "picking a pictogram while naming a Category threw the typed name away — the "
            "field cancels on blur, so the picker has to keep the focus where it is",
        )
        dismiss(page)
        return

    # By shape and not by name: this swatch is an `NcIconSvgWrapper`, which
    # removes the root id, so there is no name left on it to read. Compared
    # against the cell that was clicked, which is the only honest question —
    # does the swatch now draw the mark that was picked.
    if _mark_shape(swatch.first) != chosen_shape:
        failures.append(
            f"the new Category's swatch does not draw {PICKED_MARK!r} after it was picked: "
            f"nothing says what is about to be created",
        )

    capas_shot(page, str(SHOTS / "46-picking.png"))
    # Escape, not Enter: nothing is created here. See the docstring.
    dismiss(page)
    page.wait_for_timeout(400)
    if page.locator(CAPAS_INPUT).count() > 0:
        failures.append("Escape left the new-Category field open")


def a_category_can_be_given_a_pictogram(page, failures):
    """#46: the chosen mark reaches the Registry, the map, the list and the legend.

    Runs on the Category `a_category_can_be_added_without_a_reload` just added,
    which arrives with no mark of its own — so this check watches it go from the
    neutral fallback to a pictogram somebody chose, which is the whole of the
    slice. Reusing that Category rather than adding one is what keeps this run
    spending one vetted spare instead of two (ADR-0017) — and this check's
    teardown is what gives the spare back: it reclaims the Category (#131), so
    neither check leaves anything behind.

    What was chosen is asserted against the REGISTRY through `served_taxonomy()`,
    per SKILL.md § 5, before anything is said about the screen: a mark that is
    only painted looks exactly like one that was stored.

    The preview is measured, not looked at. ADR-0017 asks for it «at the size the
    map pin actually draws», amended by #37 from 12px to 19px — and the amendment
    is the reason: three of the seeded eleven had to be re-picked because a mark
    judged at desk size failed on a basemap. So the grid's own box is compared
    against the same PIN_DOT/PIN_GLYPH that `a_pin_says_what_kind_of_place_it_is`
    measures the real pin against, and the two would have to be wrong together.
    """
    added = f"Comité {RUN}"
    slug = f"comite_{RUN}"
    filed = f"Registro {RUN} con pictograma"

    row = _capas_category(page, added)
    if row.count() == 0:
        failures.append(
            f"«{added}» is not in the Capas panel, so #46 had no Category to pick a mark for "
            f"— the add check above did not leave one",
        )
        return

    before = _mark_shape(row.locator(".territorio-capas__swatch").first)
    if before is None:
        failures.append(f"the Capas swatch for «{added}» holds no mark at all")

    row.locator(".territorio-capas__swatch").first.click()
    picker = page.locator(".territorio-capas__picker")
    if not settles(page, lambda: picker.count() > 0, timeout=4_000):
        failures.append(f"the swatch on «{added}» opened no pictogram picker")
        return

    offered = page.locator(".territorio-capas__glyph")
    if offered.count() <= 11:
        failures.append(
            f"the picker offers {offered.count()} marks: eleven imports is the seeded set, not "
            f"the catalogue #46 asks a Category to be chosen from",
        )

    mark = page.locator(f'.territorio-capas__glyph[title="{PICKED_MARK}"]')
    if mark.count() == 0:
        failures.append(f"the picker does not offer {PICKED_MARK!r}, so #46 could not pick one")
        return

    # «A Category with no chosen mark still draws the neutral fallback rather than
    # a blank». The grid's own «Sin pictograma» cell IS that fallback — it draws
    # what choosing it produces — so the two are compared rather than a third copy
    # of `circle-stroked` being named here.
    neutral = _mark_shape(page.locator('.territorio-capas__glyph[title="Sin pictograma"]').first)
    if neutral is None:
        failures.append("the picker offers no way back to no pictogram at all")
    elif before != neutral:
        failures.append(
            f"«{added}» was not drawing the neutral fallback before anything was picked — "
            f"ADR-0017 promises exactly that for a Category nobody has chosen for, and #45's "
            f"check passes on any non-empty mark",
        )

    # The criterion in as many words: previewed at the size the map pin draws.
    # Measured off the rendered boxes, because the size lives in a custom
    # property the divIcon writes and a stylesheet that reads it — neither of
    # which any unit test renders.
    preview = mark.first.evaluate(
        """(cell) => {
            const box = cell.getBoundingClientRect()
            const svg = cell.querySelector('svg')
            const glyph = svg ? svg.getBoundingClientRect() : null
            return {
                dot: [Math.round(box.width), Math.round(box.height)],
                mark: glyph ? [Math.round(glyph.width), Math.round(glyph.height)] : null,
            }
        }""",
    )
    if preview["dot"] != [PIN_DOT, PIN_DOT]:
        failures.append(
            f"a mark is previewed on a {preview['dot']}px dot, not the {PIN_DOT}px the map pin "
            f"draws — ADR-0017 asks for the pin's size and #37 is what happens when a mark is "
            f"judged at any other",
        )
    if preview["mark"] != [PIN_GLYPH, PIN_GLYPH]:
        failures.append(
            f"a mark is previewed at {preview['mark']}px, not the {PIN_GLYPH}px the map pin "
            f"draws it at — a choice judged at list size can dissolve at the size it is used",
        )

    capas_shot(page, str(SHOTS / "46-picker.png"))

    # Before the click: choosing closes the grid, so the cell is gone afterwards.
    chosen_shape = _mark_shape(mark.first)
    mark.first.click()
    page.wait_for_timeout(900)

    try:
        written = next(
            (entry for entry in served_taxonomy(page) or [] if entry["slug"] == slug), None,
        )
        if written is None:
            failures.append(f"the Registry serves no Category under {slug!r} after a pick")
            return
        if written.get("glyph") != PICKED_MARK:
            failures.append(
                f"«{added}» was given {PICKED_MARK!r} and the Registry serves "
                f"{written.get('glyph')!r}: the mark was painted and not written, so a colleague "
                f"loading the app sees the neutral",
            )
            return

        # The Capas legend, without a reload: the served tree is swapped in, and
        # every surface reads the mark out of it.
        legend = _mark_shape(_capas_category(page, added).locator(".territorio-capas__swatch").first)
        if legend != chosen_shape:
            failures.append(
                f"the Capas legend does not draw {PICKED_MARK!r} for «{added}» after it was "
                f"chosen — it still draws what it drew before, so nothing followed without a "
                f"reload",
            )

        _a_clustered_place(page, filed, f"{added} · Otro", 9, failures)
        if registry_feature(page, filed) is None:
            failures.append(f"{filed!r} is not in the Registry, so no pin was drawn to check")
            return

        # The map. A pin inside a cluster is not rendered, so the Registry is
        # narrowed to this one record first — the same thing the #37 pin check does.
        _only_on_the_map(page, filed)
        pin = page.locator(".territorio-pin__dot").first
        on_pin = None
        if pin.count() == 0:
            failures.append(f"{filed!r} drew no pin, so the chosen mark did not reach the map")
        else:
            # The pin is the one place the Maki name survives: this app builds
            # that markup itself, so nothing has stripped the svg's id.
            named = _mark_named(pin)
            on_pin = _mark_shape(pin)
            if named != PICKED_MARK:
                failures.append(
                    f"the map pin for {filed!r} draws {named!r}, not the {PICKED_MARK!r} its "
                    f"Category was given — the chosen mark does not reach the map, which is the "
                    f"one surface with no label beside it (ADR-0013)",
                )
            if chosen_shape is not None and on_pin != chosen_shape:
                failures.append(
                    f"the map pin for {filed!r} draws a different shape from the cell that was "
                    f"clicked: the preview and the pin are not the same pictogram",
                )
            page.locator('[data-testid="map-canvas"]').screenshot(
                path=str(SHOTS / "46-pin.png"),
            )

        # The Datos list, filtered to the same record. By shape against the pin
        # rather than by name: the list's mark goes through NcIconSvgWrapper,
        # which takes the name off it — and «the same mark in both places» is the
        # criterion anyway.
        swatch = page.locator(".territorio-list__swatch").first
        if swatch.count() == 0:
            failures.append(f"{filed!r} has no row in the Datos list")
        elif on_pin is not None and _mark_shape(swatch) != on_pin:
            failures.append(
                f"the Datos list and the map draw different pictograms for {filed!r}: the same "
                f"record is two different things depending on where it is read",
            )
        page.locator(SEARCH).fill("")
        page.wait_for_timeout(600)
    finally:
        # Both records and then the Category itself: this is the teardown that
        # takes «Comité {RUN}» off the instance, because this check is the last
        # to need it — the add check above retires its own record only, for this
        # check's sake (#131).
        reclaim_the_runs_category(page, added, f"Registro {RUN} en Comité", filed)


def _the_same_category_name_twice_is_refused(page, label, slug, errors, failures):
    """The refusal reaches the person who typed, and nothing is written twice.

    `terr_cat_slug_idx` is unique on the slug alone and the integration suite
    proves that; what only a browser can show is that the refusal arrives in the
    panel as a sentence rather than as a 500, and that the Registry did not
    quietly grow a second row while the panel looked unchanged.
    """
    before = len(errors)

    if not _add_a_category_in_capas(page, f"  {label.upper()}  ", failures):
        return

    if page.locator(".territorio-capas .notecard--error").count() == 0:
        failures.append(
            f"adding «{label}» a second time said nothing: a duplicate was either accepted or "
            f"refused somewhere the person cannot see",
        )
    carrying = [entry for entry in served_taxonomy(page) or [] if entry["slug"] == slug]
    if len(carrying) != 1:
        failures.append(
            f"{len(carrying)} Categories now carry the slug {slug!r}: the refusal wrote one anyway",
        )

    # The refusal IS an HTTP 422 and the browser logs every failed response as a
    # console error, which main()'s run-wide gate would then report. Exactly the
    # entries this refusal added are dropped, and only the 422 among them.
    errors[before:] = [message for message in errors[before:] if "422" not in message]

    # Leave the panel as it was found: the note card names no row, so one left
    # behind reads as a refusal of whatever the next check does.
    page.get_by_role("button", name="Agregar categoría", exact=True).first.click()
    page.wait_for_timeout(300)
    page.locator(CAPAS_INPUT).press("Escape")
    page.wait_for_timeout(500)


def _the_same_name_twice_is_refused(page, label, errors, failures):
    """The duplicate refusal, where the person typed it — and nothing written.

    The unique index is `(category_id, slug)` and the integration suite proves
    that; what only a browser can show is that the refusal reaches the panel
    instead of arriving as a 500 nobody can act on, and that the row does not
    quietly double.
    """
    before_rows = _capas_child(page, SEEDED_CATEGORY, label).count()
    before = len(errors)

    if not _add_in_capas(page, SEEDED_CATEGORY, label, failures):
        return

    if page.locator(".territorio-capas .notecard--error").count() == 0:
        failures.append(
            f"adding «{label}» a second time under «{SEEDED_CATEGORY}» said nothing: a duplicate "
            f"was either accepted or refused somewhere the person cannot see",
        )
    if _capas_child(page, SEEDED_CATEGORY, label).count() != before_rows:
        failures.append(
            f"«{label}» now appears a different number of times under «{SEEDED_CATEGORY}»: the "
            f"refusal wrote a row anyway",
        )

    # The refusal IS an HTTP 422 and the browser logs every failed response as a
    # console error, which main()'s run-wide gate would then report. Exactly the
    # entries this refusal added are dropped, and only the 422 among them.
    errors[before:] = [message for message in errors[before:] if "422" not in message]

    # Leave the panel as it was found: the note card names no row, so one left
    # behind reads as a refusal of whatever the next check does.
    page.get_by_role(
        "button", name=f"Agregar subcategoría en «{SEEDED_CATEGORY}»", exact=True,
    ).first.click()
    page.wait_for_timeout(300)
    page.locator(CAPAS_INPUT).press("Escape")
    page.wait_for_timeout(500)


def _picker_options(page, typed):
    """Open a draft, type into the Subcategoría picker, and read what it offers.

    The mechanical half of the two checks that assert on the picker — the
    positive one after a rename (#41) and the negative one after a removal
    (#47). Only the assertion differs, so only the assertion is at the call
    sites; the two were a line-for-line copy of each other and could drift.

    The picker exists only inside a record's form, so this opens a draft and
    throws it away. It returns TWO lists: what is offered with `typed` in the
    box, and what is offered with the box cleared. The second is the fixture —
    a "no longer offers X" assertion over an empty list is true for every reason
    including a renamed `.vs__dropdown-option` class or a picker that never
    opened, which is this repo's own lesson about asserting over an empty
    fixture.
    """
    to_list(page)
    page.get_by_role("button", name="Agregar", exact=True).click()
    page.wait_for_timeout(400)
    # exact=True for the reason given in a_pin_says_what_kind_of_place_it_is():
    # #44's twelve «+» buttons are all named "Agregar subcategoría en «X»".
    field = page.get_by_label("Subcategoría", exact=True)
    field.click()
    field.fill(typed)
    page.wait_for_timeout(700)

    # `all_text_contents()` and not `all_inner_texts()`: innerText is the
    # RENDERED text, and an option as long as "Salud X · CESFAM X" wraps inside
    # the narrow dropdown, so the browser handed back "Salud X · CES\nFAM X" and
    # no comparison against the name that was typed could ever have matched.
    offered = page.locator(".vs__dropdown-option").all_text_contents()

    field.fill("")
    page.wait_for_timeout(700)
    everything = page.locator(".vs__dropdown-option").all_text_contents()

    # Twice: the first closes the open dropdown, the second the draft itself.
    dismiss(page)
    page.wait_for_timeout(200)
    dismiss(page)
    page.wait_for_timeout(400)
    return offered, everything


def _the_picker_offers_the_new_name(page, expected, failures):
    """The Subcategoría picker, which is the other surface #41 names."""
    offered, _ = _picker_options(page, expected)
    if not any(expected in text for text in offered):
        failures.append(
            f"the Subcategoría picker does not offer {expected!r} — it is still showing the "
            f"names from before the rename (offered: {offered[:5]})",
        )


def _analisis_reads_the_new_name(page, category, subcategory, failures, shot=None):
    """Análisis: the report somebody is about to show a colleague (#40, story 9).

    `shot` names a screenshot to save of the section, for a criterion whose
    wording is about what a person sees; the assertions do not depend on it.
    """
    _pane(page, "Análisis")
    page.wait_for_timeout(700)

    section = page.locator(".territorio-analisis__category").filter(has_text=category)
    if section.count() == 0:
        failures.append(
            f"Análisis has no «{category}» section: either it is still drawing the old name, or "
            f"this run's records are not filed under it",
        )
    elif subcategory not in section.first.text_content():
        failures.append(
            f"Análisis draws «{category}» but still calls its Subcategory by the old name — the "
            f"chart reads the Subcategory rows through a separate binding",
        )

    if shot is not None and section.count() > 0:
        section.first.screenshot(path=str(SHOTS / shot))

    _pane(page, "Mapa")


def _the_registry_kept_the_slugs(served, category, subcategory, failures):
    """The half no screen can show: the labels moved and the slugs did not."""
    if served is None:
        failures.append(
            "the app served no taxonomy in initial state, so nothing confirmed the rename was "
            "written rather than only painted",
        )
        return

    stored = next((entry for entry in served if entry["slug"] == "salud"), None)
    if stored is None:
        failures.append(
            "no Category carries the slug «salud» any more — a rename moved the slug, and every "
            "Feature, glyph and colour keyed on it is now adrift (#40)",
        )
        return

    if stored["label"] != category:
        failures.append(
            f"the served taxonomy still calls «salud» {stored['label']!r}: the panel changed and "
            f"the Registry did not",
        )

    sub = next(
        (entry for entry in stored["subcategories"] if entry["slug"] == "cesfam"), None
    )
    if sub is None:
        failures.append(
            "no Subcategory under «salud» carries the slug «cesfam» any more"
        )
    elif sub["label"] != subcategory:
        failures.append(
            f"the served taxonomy still calls «cesfam» {sub['label']!r}",
        )


def _a_blank_name_is_refused_in_the_panel(page, label, errors, failures):
    """A refusal has to reach the person who typed, where they typed it."""
    row = _capas_category(page, label)
    pencil = row.locator(CAPAS_RENAME).first
    if pencil.count() == 0:
        failures.append("the renamed Category lost its rename control")
        return

    pencil.click()
    field = page.locator(CAPAS_INPUT)
    if not settles(page, lambda: field.count() > 0, timeout=4_000):
        failures.append("the rename control opened no field for the blank-name check")
        return

    before = len(errors)
    field.fill("   ")
    field.press("Enter")
    page.wait_for_timeout(800)

    refusal = page.locator(".territorio-capas .notecard--error")
    if refusal.count() == 0:
        failures.append(
            "a name of spaces was not refused where it was typed: the Capas panel showed no "
            "reason at all",
        )
    if _capas_category(page, label).count() == 0:
        failures.append("a refused rename changed the name in the panel anyway")

    # The refusal IS an HTTP 422, and the browser logs every failed response as
    # a console error — so main()'s run-wide console-error gate would fail on
    # the one refusal this check exists to provoke. Exactly the entries this
    # refusal added are dropped, and only the 422 among them: anything else it
    # logged is a real fault and stays for that gate to report.
    errors[before:] = [message for message in errors[before:] if "422" not in message]

    # A refusal that outlives the rename it came from reads as a refusal of
    # whatever is on screen now — and this panel holds eleven Categories and
    # some fifty Subcategories, so the note names no row. Abandoning the rename
    # has to take it away.
    _capas_category(page, label).locator(CAPAS_RENAME).first.click()
    page.wait_for_timeout(300)
    page.locator(CAPAS_INPUT).press("Escape")
    page.wait_for_timeout(500)
    if page.locator(".territorio-capas .notecard--error").count() > 0:
        failures.append(
            "the Capas panel still shows the last refusal after the rename was abandoned: a "
            "stale reason reads as a current one",
        )


def _remove_in_capas(page, row, what, failures, confirm=True):
    """Press the bin on one Capas row, then answer the question it asks (#47).

    `.first` is the row's own control, for the reason `_rename_in_capas()` gives:
    a Category's bin sits in its `<summary>`, before the Subcategory rows an open
    branch adds below it.

    The two-step is not incidental here — it IS a criterion. ADR-0016 says
    removing asks first, in the same inline shape retiring a record uses and
    never a `confirm()`, so a panel that acted on the first click is a failure
    even when it removes the right thing. `confirm=False` answers «Cancelar»
    instead, which is how the other half of that criterion gets proved: asking
    and then doing it anyway is not asking.

    Returns False when a control is missing, so the caller stops rather than
    asserting against a panel that never did anything.
    """
    bin_ = row.locator(CAPAS_REMOVE).first
    if bin_.count() == 0:
        failures.append(f"the Capas panel offers no way to remove {what}")
        return False

    bin_.click()
    question = row.locator(".territorio-capas__confirm").first
    if not settles(page, lambda: question.count() > 0, timeout=4_000):
        failures.append(
            f"the remove control on {what} acted on the first click without asking — ADR-0016 "
            f"says removing asks first, in the two-step inline shape retiring a record uses",
        )
        return False

    question.get_by_role(
        "button", name="Quitar" if confirm else "Cancelar", exact=True,
    ).click()
    page.wait_for_timeout(900)

    return True


def _served_category(page, slug):
    """One Category as a browser opening the app fresh would receive it, or None."""
    return next(
        (entry for entry in served_taxonomy(page) or [] if entry["slug"] == slug), None,
    )


def _the_picker_no_longer_offers(page, gone, failures):
    """The Subcategoría picker, the second surface a removal has to leave (#47).

    The mirror of `_the_picker_offers_the_new_name()`, sharing its mechanics
    through `_picker_options()`. Not a vacuous assertion: a Category with no
    records still appears here, because the picker is a flat list of «Categoría ·
    Subcategoría» read straight off the taxonomy, which is exactly what Análisis
    is not (see the check below).

    The fixture is asserted before the absence is. `offered` being empty is the
    EXPECTED shape here — nothing matches what was typed, which is the point —
    so the non-empty assertion goes on `everything`, the same picker with the
    box cleared. Without it, a renamed `.vs__dropdown-option` class or a picker
    that never opened reads exactly like a successful removal.
    """
    offered, everything = _picker_options(page, gone)
    if not everything:
        failures.append(
            f"the Subcategoría picker offered nothing at all with an empty box, so it never "
            f"opened or its options are no longer «.vs__dropdown-option» — this check cannot "
            f"tell that {gone!r} is absent from a list it could not read",
        )
        return
    if any(gone in text for text in offered):
        failures.append(
            f"the Subcategoría picker still offers {gone!r} after the Category was removed — a "
            f"record could be filed under a Subcategory id nothing answers to (offered: "
            f"{offered[:5]})",
        )


def the_territorial_division_says_it_cannot_be_removed(page, failures):
    """#47: «División territorial» is visibly unremovable, and says why.

    The criterion is worded as an experience — "I do not spend time trying and
    then wonder whether I did it wrong" — so the assertion is about the panel,
    not about the server. The server refuses these too, and the integration suite
    proves that; a refusal at save time is the backstop for a request the panel
    did not make, and it is exactly what this criterion says must not be how a
    person finds out.

    "Where the control would otherwise be" is the toggles block: `treeOf` leaves
    the division out of the Category tree, because CONTEXT.md puts one toggle per
    territorial division BESIDE the tree rather than inside it. So there is no
    Category row for it — which this also checks, because a division drawn as a
    branch would be #15's regression and would carry a bin like every other row.

    The label is read out of the Registry rather than typed here: a clinic may
    rename it, and a hard-coded «División territorial» would silently look for a
    row nobody has and pass.
    """
    division = _served_category(page, "division_territorial")
    if division is None:
        failures.append(
            "the app serves no «division_territorial» Category: the seed did not run, and this "
            "check would assert nothing",
        )
        return

    divisions = page.locator(".territorio-capas__divisions")
    if divisions.count() == 0:
        failures.append("the Capas panel draws no territorial-division block at all")
        return

    hint = divisions.locator(".territorio-capas__hint").first
    if hint.count() == 0 or not hint.is_visible():
        failures.append(
            "the territorial-division block says nothing about why it has no «quitar»: every "
            "other row in the panel has one, so a person tries and then wonders whether they "
            "did it wrong (#47)",
        )
    elif "no se puede quitar" not in (hint.text_content() or "").lower():
        failures.append(
            f"the territorial-division block shows {hint.text_content()!r}, which does not say "
            f"it cannot be removed",
        )

    if divisions.locator(CAPAS_REMOVE).count() > 0:
        failures.append(
            "the territorial-division block offers a remove control: a Sector and a Unidad "
            "Vecinal cannot be saved without these Subcategories and the seed restores them on "
            "every upgrade (ADR-0016)",
        )

    if _capas_category(page, division["label"]).count() > 0:
        failures.append(
            f"«{division['label']}» is drawn as a Category branch in the Capas panel — it is the "
            f"territorial division and belongs beside the tree (ADR-0015, CONTEXT.md), and as a "
            f"branch it carries a «quitar» like every other row",
        )

    # The BLOCK and not the whole panel: this criterion is judged by a person
    # looking at the shot, and the panel is taller than its own scroll box, so a
    # whole-panel capture framed the Category tree and left the hint this check
    # is about outside the image.
    divisions.screenshot(path=str(SHOTS / "47-divisiones.png"))


def a_category_can_be_removed_without_a_reload(page, errors, failures):
    """#47: a Category the clinic does not use leaves, and it asks first.

    Adds its own Category and takes it away again, so this check leaves the
    instance as it found it — the first of the taxonomy checks that can.

    Three things only a browser can show: that the panel ASKS before acting and
    that «Cancelar» really cancels; that the row leaves the Capas panel without a
    reload; and that it leaves the Subcategoría picker with it, which is the
    surface that would otherwise let somebody file a record under a Subcategory
    id nothing answers to. Each is also checked against the Registry through
    `served_taxonomy()` per SKILL.md § 5, because a row that merely left the
    screen looks identical to one that was never written.

    **Análisis is deliberately not asserted here, and the criterion naming it is
    only half covered.** Análisis draws a section per Category that HOLDS
    RECORDS, and a Category that can be removed at all is by definition holding
    none — so "it is gone from Análisis" is true before the removal too, and an
    assertion on it would pass against an implementation that did nothing. The
    honest statement is that Análisis reads the same `taxonomy` prop the panel
    and the picker do, and those two are proved here.
    """
    added = f"Efímera {RUN}"
    slug = f"efimera_{RUN}"
    before = len(errors)

    if not _add_a_category_in_capas(page, added, failures):
        return

    row = _capas_category(page, added)
    if not settles(page, lambda: row.count() > 0, timeout=6_000):
        failures.append(f"«{added}» was added but is not in the Capas panel, so nothing can "
                        f"be removed from it")
        return

    # Asking and then doing it anyway is not asking.
    if not _remove_in_capas(page, row, f"«{added}»", failures, confirm=False):
        return
    if _capas_category(page, added).count() == 0 or _served_category(page, slug) is None:
        failures.append(
            f"«{added}» was removed after «Cancelar»: the question the panel asks is decoration, "
            f"and a click meant as tidying takes a Category away",
        )
        return

    if not _remove_in_capas(page, _capas_category(page, added), f"«{added}»", failures):
        return

    if not settles(page, lambda: _capas_category(page, added).count() == 0, timeout=6_000):
        failures.append(
            f"«{added}» is still in the Capas panel after being removed — it did not leave "
            f"without a reload",
        )
    if page.locator(".territorio-capas .notecard--error").count() > 0:
        said = page.locator(".territorio-capas .notecard--error").first.text_content()
        failures.append(
            f"removing «{added}» was refused although nothing is filed under it: {said!r}",
        )
        return
    if _served_category(page, slug) is not None:
        failures.append(
            f"the Capas panel dropped «{added}» but the Registry still serves {slug!r}: the "
            f"panel repainted and nothing was written",
        )
        return

    _the_picker_no_longer_offers(page, f"{added} · Otro", failures)

    # Nothing here is a refused request: «Cancelar» sends none and the removal is
    # a 200, so unlike the refusal check below this one scrubs nothing and every
    # console error it added is a real one. An unused `errors` parameter is what
    # hid the missing scrub in the refusal check, so this one spends it.
    if len(errors) > before:
        failures.append(
            f"removing «{added}» logged console errors the panel did not show: "
            f"{errors[before:][:3]}",
        )
    capas_shot(page, str(SHOTS / "47-capas-panel.png"))


def a_category_in_use_is_refused_and_says_where_to_look(page, errors, failures):
    """#47 and ADR-0016: refused while records are filed under it, retired ones
    included — and the refusal is a next step rather than a dead end.

    Run twice against the same Category, with the record live and then retired.
    The second half is the criterion the issue calls out and the obvious way to
    get it wrong: a retired record is not in the count Análisis shows, it is not
    in the Datos list, and it can be restored — so an implementation that reused
    the display count destroys it here while every screen looks right. The
    integration suite proves the same thing against the real schema; this proves
    a person is told.

    What the refusal SAYS is asserted, not merely that there was one: the number,
    and the pointer to where those records can be seen. "84 records" without
    saying where they are leaves a person stuck with a number, which is the half
    of ADR-0016 that is easiest to drop.
    """
    # The two refusals are the behaviour under test, so the Category cannot be
    # removed mid-check: ADR-0016 counts the retired record too, and both
    # removals are correctly refused. The finally is where the #131 leak closed
    # — `reclaim_the_runs_category` empties the Category the way the refusal
    # sentence itself prescribes and takes it away, so the run spends its spare
    # and puts it back instead of walking the instance into the neutral branch.
    before = len(errors)
    added = f"En uso {RUN}"
    filed = f"Registro {RUN} en uso"

    if not _add_a_category_in_capas(page, added, failures):
        return
    if not settles(page, lambda: _capas_category(page, added).count() > 0, timeout=6_000):
        failures.append(f"«{added}» was added but is not in the Capas panel")
        return

    _a_clustered_place(page, filed, f"{added} · Otro", 8, failures)
    if registry_feature(page, filed) is None:
        failures.append(
            f"{filed!r} is not in the Registry, so nothing is filed under «{added}» and this "
            f"check would prove that an EMPTY Category is refused",
        )
        return

    try:
        for state in ("live", "retired"):
            if state == "retired":
                retire_the_runs_record(page, filed)

            if not _remove_in_capas(page, _capas_category(page, added), f"«{added}»", failures):
                return

            said = page.locator(".territorio-capas .notecard--error")
            if said.count() == 0 or not said.first.is_visible():
                failures.append(
                    f"removing «{added}» with a {state} record filed under it said nothing: either "
                    f"it was allowed and the record is orphaned, or the refusal never reached the "
                    f"panel",
                )
                return

            text = said.first.text_content() or ""
            if "1 registro" not in text:
                failures.append(
                    f"the refusal for «{added}» ({state} record) does not say how many records are "
                    f"filed under it: {text!r}",
                )
            if "Capas" not in text:
                failures.append(
                    f"the refusal for «{added}» ({state} record) says a number and not where to go "
                    f"and see them, so it is a dead end (ADR-0016): {text!r}",
                )
            if _capas_category(page, added).count() == 0 or _served_category(
                page, f"en_uso_{RUN}",
            ) is None:
                failures.append(
                    f"«{added}» was removed anyway with a {state} record filed under it — the "
                    f"record now points at a Subcategory nothing answers to, and no foreign key "
                    f"would have caught it",
                )
                return

            if state == "live":
                # Scrolled to, for the same reason as the divisions shot above: the
                # note card is asserted visible in Playwright's sense (it has a box),
                # which does not put it inside the panel's scroll box, and the shot
                # is what a person reads this refusal off.
                said.first.scroll_into_view_if_needed()
                page.wait_for_timeout(300)
                page.locator(".territorio-capas").screenshot(
                    path=str(SHOTS / "47-refusal.png"),
                )
    finally:
        # The #131 leak closed here: this Category used to stay behind, one per
        # run, because the in-check removals are (correctly) refused and nothing
        # emptied it afterwards. The reclaim walks the refusal sentence's own
        # route, so the check proves both refusals and still leaves nothing
        # behind — including on the early returns above.
        reclaim_the_runs_category(page, added, filed)

    # The refusal IS an HTTP 422 and the browser logs every failed response as a
    # console error, which main()'s run-wide gate would then report. Exactly the
    # entries these two refusals added are dropped, and only the 422 among them.
    errors[before:] = [message for message in errors[before:] if "422" not in message]

    # Not `is not None and …`: the record was filed and asserted present above, so
    # its absence now is not a reason to skip the assertion — it is the ADR-0005
    # violation (nothing hard-deletes a record) worth shouting about, and it is
    # also the only state in which "a retired record still counts" was measured
    # against nothing at all.
    record = registry_feature(page, filed)
    if record is None:
        failures.append(
            f"{filed!r} is gone from the Registry after the two refusals: it was filed and "
            f"asserted present before them, so either the refused removal took it or something "
            f"hard-deleted it (ADR-0005) — and this check measured nothing about retired records",
        )
    elif not record.get("retired"):
        failures.append(
            f"{filed!r} is not retired, so the second half of this check measured a LIVE record "
            f"and proved nothing about retired ones",
        )

    # Leave the panel as it was found, the way
    # _the_same_category_name_twice_is_refused() does: the note card names no
    # row, so one left behind reads as a refusal of whatever the next check
    # does. Without this the check is only safe because of where it sits in
    # main()'s call order, which is a fact about the caller.
    page.get_by_role(
        "button", name=f"Agregar subcategoría en «{SEEDED_CATEGORY}»", exact=True,
    ).first.click()
    page.wait_for_timeout(300)
    page.locator(CAPAS_INPUT).press("Escape")
    page.wait_for_timeout(500)


OMNIBOX_INPUT = ".territorio-omnibox__input"
OMNIBOX_ITEM = ".territorio-omnibox__item"


def _omnibox_pick(page, typed, title, nth=0):
    """Type into the search box the way a person does, and click the row titled `title`.

    Typed key by key, because the box must hand back what was typed: bound to a
    value that lags the keystroke, it re-rendered with the old text and ate
    characters. `nth` picks among rows that share a title. Returns the rows it
    offered, so the caller can say what was there.
    """
    box = page.locator(OMNIBOX_INPUT)
    box.fill("")
    box.press_sequentially(typed, delay=40)
    shown = box.input_value()
    page.wait_for_timeout(400)
    rows = page.locator(OMNIBOX_ITEM)
    titles = [row.locator(".territorio-omnibox__item-title").inner_text().strip()
              for row in rows.all()]
    if shown != typed:
        return titles, f"typed {typed!r} but the box shows {shown!r}"
    row = rows.filter(has=page.locator(".territorio-omnibox__item-title", has_text=title))
    if row.count() == 0:
        return titles, f"no row titled {title!r}"
    try:
        row.nth(nth).click(timeout=5_000)
    except PlaywrightTimeout:
        return titles, f"the row {title!r} is there but cannot be clicked"
    page.wait_for_timeout(1200)
    return titles, None


def the_redesigned_controls_work(page, errors, failures):
    """The topbar of the Google-Maps layout, driven the way a person drives it.

    Why this exists: the redesign (#171) wired App.vue to events and props its
    children never declared. Vue drops both silently, so the search box, the
    view tabs, «Dibujar Sector», «Todas»/«Limpiar» and the taxonomy drawer all did
    nothing, and every other check here still passed, because they measure the
    layout that was replaced. tests/js/appWiring.test.js now fails the build on
    that mismatch; this measures what it cannot — that each control, once
    wired, does the thing.

    The records are seeded through occ, named for the run, and sited away from
    anything else: a Place, a Polygon, and a street. Where the map went is read
    off Leaflet (VIEW_RECORDER), not off the control.
    """
    place, polygon, street = (f"Cesfam Rediseño {REDESIGN}", f"Colegio Rediseño {REDESIGN}",
                              f"Calle Rediseño {REDESIGN}")
    point = (-33.70, -70.70)  # latitude, longitude
    # One name, two kilometres apart, so the box must offer two rows and the map
    # must land on the one chosen: La Florida's sixteen «Pasaje Dos», in miniature.
    lines = [(-33.90, -70.60, -70.59), (-33.92, -70.60, -70.59)]
    square = _a_boundary_box(-33.80, -70.80)
    seeded = (
        _imported(place, "salud", "cesfam", {"type": "Point", "coordinates": [point[1], point[0]]}),
        _imported(polygon, "educacion", "colegio", square),
        import_dataset(json.dumps({"type": "FeatureCollection", "features": [{
            "type": "Feature", "properties": {"id": f"rediseno-{REDESIGN}-{line[0]}", "name": street},
            "geometry": {"type": "LineString", "coordinates": [[-70.60, line[0]], [-70.59, line[0]]]},
        } for line in lines]}), f"rediseno-{REDESIGN}", boundary=True),
    )
    if not all(seeded):
        failures.append("the redesign check could not import its Place, Polygon and street through occ")
        return

    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_selector(OMNIBOX_INPUT, timeout=10_000)
    if not page.evaluate(VIEW_RECORDER):
        failures.append("the page has no window.L, so nothing could read where the search took the map")
        return

    def last_view():
        views = page.evaluate("() => window.__territorioViews")
        return views[-1] if views else None

    def centre(view):
        return (view["south"] + view["north"]) / 2, (view["west"] + view["east"]) / 2

    # A taxonomy drawer that is "closed" but mounted covers the whole screen.
    if page.locator(".territorio-taxonomy-drawer").count() != 0:
        failures.append("the taxonomy drawer is on the page before anyone opened it")

    # --- the search box: an asset, a polygon and a street ---
    titles, problem = _omnibox_pick(page, f"Rediseño {REDESIGN}", place)
    if problem:
        failures.append(f"Omnibox, picking the Place: {problem} (rows: {titles})")
    elif not {place, polygon, street} <= set(titles):
        failures.append(f"Omnibox offered {titles}, which lacks one of the Place, Polygon or street")
    else:
        view = last_view()
        lat, lon = centre(view) if view else (None, None)
        if view is None or abs(lat - point[0]) > 0.005 or abs(lon - point[1]) > 0.005:
            failures.append(f"picking the Place left the map on {view}, not on {point}")
        if not page.locator(".territorio-docked-panel__sheet").get_by_text(place).first.is_visible():
            failures.append("picking the Place did not open its record in the side panel")

    # An opened record has a way back to the list.
    back = page.locator(".territorio-detail__back")
    if back.count() == 0:
        failures.append("an opened record has no «Volver a la lista»")
    else:
        back.click()
        if not settles(page, lambda: page.locator(".territorio-list__sort-bar").count() > 0, timeout=5_000):
            failures.append("«Volver a la lista» did not bring the list back")

    _, problem = _omnibox_pick(page, f"Colegio Rediseño {REDESIGN}", polygon)
    view = last_view()
    if problem:
        failures.append(f"Omnibox, picking the Polygon: {problem}")
    elif view is None or not (view["south"] <= -33.80 and view["north"] >= -33.79
                              and view["west"] <= -70.80 and view["east"] >= -70.79):
        failures.append(f"picking the Polygon left the map on {view}, which does not hold {square['coordinates'][0]}")

    # Two rows of one name, told apart by how much of it there is, and the pick lands on
    # the second one and not the first.
    for index, line in enumerate(lines):
        titles, problem = _omnibox_pick(page, f"Calle Rediseño {REDESIGN}", street, nth=index)
        view = last_view()
        if problem:
            failures.append(f"Omnibox, picking street row {index + 1}: {problem}")
        elif titles.count(street) != 2:
            failures.append(f"Omnibox offered {titles.count(street)} rows for the street, not 2")
        elif view is None or not _holds(view, line) or _holds(view, lines[1 - index]):
            failures.append(f"picking street row {index + 1} left the map on {view}, not on {line}")

    # --- the view tabs ---
    # With a query typed the Índice lists only what matches it, and what was typed
    # last names a street, which is not a record.
    page.locator(OMNIBOX_INPUT).fill("")
    page.wait_for_timeout(500)
    tabs = {"Índice": INDICE, "Análisis": ".territorio-fullscreen-view",
            "Posibles duplicados": ".territorio-fullscreen-view", "Mapa": '[data-testid="map-canvas"]'}
    for tab, shown in tabs.items():
        try:
            page.locator(".territorio-omnibox__tab", has_text=tab).click(timeout=5_000)
        except PlaywrightTimeout:
            failures.append(f"the «{tab}» tab cannot be clicked")
            continue
        page.wait_for_timeout(400)
        if not page.locator(shown).first.is_visible():
            failures.append(f"the «{tab}» tab did not bring up {shown}")
        selected = page.locator(".territorio-omnibox__tab--active").inner_text().strip()
        if selected != tab:
            failures.append(f"after clicking «{tab}» the highlighted tab is «{selected}»")

    # Picking a record while the Índice is up takes the person to the map and the
    # record: the panel and the map are hidden behind the table until it does.
    page.locator(".territorio-omnibox__tab", has_text="Índice").click()
    page.wait_for_timeout(400)
    page.locator(f"{INDICE} tbody tr").first.click()
    if not settles(page, lambda: page.locator(".territorio-docked-panel__sheet").is_visible(), timeout=5_000):
        failures.append("picking a row in the Índice did not open the record on the map")

    # --- the layers menu ---
    # On a fresh page: the street pick above left the map two kilometres from every
    # record, so no pin is in view to count, and an open record keeps its handles.
    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_selector(".territorio-layers-button", timeout=10_000)
    page.wait_for_timeout(1000)
    layers = page.locator(".territorio-layers-button")
    layers.click()
    panel = page.locator(".territorio-layers-panel")
    if not panel.is_visible():
        failures.append("«Tipo de Activo» did not open its menu")
    else:
        text = panel.inner_text()
        if "CESFAM" in text.split("División Territorial")[-1]:
            failures.append("the division toggles still say «CESFAM»: they should use the taxonomy's own labels")
        if "Sector" not in text.split("División Territorial")[-1]:
            failures.append("the layers menu has no toggle for the Sector division")
        # `.marker-icon` is Geoman's vertex handle on the record still open from
        # the picks above, not a place; a record's own pin or bubble is the rest.
        pins = f"{MARKERS}:not(.marker-icon)"
        panel.get_by_role("button", name="Limpiar").click()
        if not settles(page, lambda: page.locator(pins).count() == 0, timeout=5_000):
            failures.append("«Limpiar» left markers on the map")
        panel.get_by_role("button", name="Todas").click()
        if not settles(page, lambda: page.locator(pins).count() > 0, timeout=5_000):
            failures.append("«Todas» did not bring the markers back")

        # --- the taxonomy drawer ---
        panel.get_by_role("button", name="Configurar taxonomía y capas").click()
        drawer = page.locator(".territorio-taxonomy-drawer")
        if not drawer.is_visible():
            failures.append("«Configurar taxonomía y capas» did not open the drawer")
        else:
            drawer.get_by_role("button", name="Cerrar").click()
            page.wait_for_timeout(300)
            if page.locator(".territorio-taxonomy-drawer").count() != 0:
                failures.append("the taxonomy drawer did not close")

    # --- «Dibujar Sector» ---
    draw = page.locator(".territorio-draw-sector__button")
    draw.click()
    page.wait_for_timeout(300)
    if "Cancelar Dibujo" not in draw.inner_text():
        failures.append("«Dibujar Sector» did not go into drawing mode")
    draw.click()
    page.wait_for_timeout(300)
    if "Dibujar Sector" not in draw.inner_text():
        failures.append("«Cancelar Dibujo» did not leave drawing mode")

    page.screenshot(path=str(SHOTS / "redesign.png"), full_page=False)



def main():
    SHOTS.mkdir(exist_ok=True)
    failures = []

    with sync_playwright() as p:
        # The resolver rule is the second layer behind the routes: if a request ever slipped
        # past them it would fail to resolve, not reach OpenStreetMap.
        browser = p.chromium.launch(args=["--host-resolver-rules=MAP openstreetmap.org ~NOTFOUND, MAP *.openstreetmap.org ~NOTFOUND"])
        context = browser.new_context(viewport={"width": 1400, "height": 900})
        tiles = mock_osm(context)
        page = context.new_page()
        errors = console_errors(page)

        log_in(page)
        page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
        if not the_instance_starts_without_a_comuna(page, failures):
            browser.close()
            return _report(errors, failures)

        # `--only extent` is the quick check of where the map opens on a chosen Comuna.
        if ONLY == "extent":
            run(the_map_opens_on_the_comuna_extent, page, tiles, failures)
            browser.close()
            return _report(errors, failures)

        # The redesigned topbar first: it seeds three records of its own and ends
        # with the page reloaded and the filter reset. `--only redesign` stops here.
        run(the_redesigned_controls_work, page, errors, failures)
        if ONLY == "redesign":
            browser.close()
            return _report(errors, failures)

        # Before anything pans or draws: the basemap is what every later check stands on.
        run(the_basemap_is_painted, page, failures)
        run(the_tiles_follow_the_policy, page, tiles, failures)
        run(the_attribution_is_visible, page, failures)
        run(the_search_flies_without_a_tile_storm, page, tiles, failures)
        run(the_map_survives_its_tiles_failing_and_recovers, page, tiles, errors, failures)
        run(the_blocked_basemap_is_invisible_to_the_app, page, tiles, errors, failures)
        if ONLY == "tiles":
            browser.close()
            return _report(errors, failures)
        # Right after it, before anything pans or draws: the first fix recentres
        # the map to zoom 16, and the check puts the view back with a reload.
        run(the_locate_control_can_ask_where_the_person_is, page, errors, failures)

        # Hide the cadastre first, which is what a person drawing a boundary does
        # anyway. With a comuna's Unidades Vecinales on screen the whole map is
        # polygons: clicks land on them and geoman snaps new vertices to their
        # edges, so the drawing step becomes a coin toss for reasons that have
        # nothing to do with what is being checked.
        if division_is_shown(page, "Unidad Vecinal"):
            toggle_division(page, "Unidad Vecinal")

        # Before any check that names «Salud» or «Salud · CESFAM»: a run killed
        # between the rename below and its restore left those names changed, and
        # no seed or upgrade puts them back. Healing here rather than only inside
        # the rename check means one poisoned run costs one run, not every run.
        run_in_capas(_the_seeded_names_are_put_back, page, failures)

        run_in_capas(every_category_reaches_the_browser_with_its_colour, page, failures)
        run(the_app_icon_is_legible_in_both_themes, page, failures)

        run(create_a_place, page, f"Plaza del Navegador {RUN}", failures)
        run(draw_a_sector, page, f"Sector Navegador {RUN}", failures)

        # Reload rather than trust the screen: a record that only exists in the
        # browser's memory looks identical to one that was saved.
        page.reload(wait_until="domcontentloaded")
        page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)

        for name in (f"Plaza del Navegador {RUN}", f"Sector Navegador {RUN}"):
            if page.get_by_text(name).count() == 0:
                failures.append(f"{name!r} is not in the list after a reload")

        page.screenshot(path=str(SHOTS / "after-reload.png"), full_page=False)

        # Here, and not at the top: the Datos list needs a record in it, and this
        # run has just made one and proved it survived a reload.
        run_in_capas(the_glyphs_beside_a_label_are_white_too, page, failures)

        # Before anything picks a Limit for it: this run's Sector was drawn
        # freehand and has one revision, which is #50's first case.
        run(a_sector_says_how_its_boundary_came_to_be, page, "drawn", failures)

        # Before the polygonize check below, which grows this run's Sector to four
        # kilometres across: a vertex of the small drawn one is on screen and
        # draggable, which is what this needs.
        run(a_dragged_vertex_stays_with_its_own_record, page, failures)
        run(a_pin_says_what_kind_of_place_it_is, page, failures)
        run(a_pin_opens_a_record_while_the_retired_list_is_shown, page, failures)

        run(a_sector_can_be_reached_and_opened_with_the_keyboard, page, failures)
        run(the_zoom_controls_are_in_spanish, page, failures)

        run(clicking_places_the_coordinate, page, failures)
        run(the_assignment_is_shown_and_not_editable, page, failures)
        run(search_narrows_everything, page, failures)
        run(the_limit_can_be_picked, page, failures)
        run(the_duplicate_queue_works, page, failures)
        run(exports_ask_for_a_password_only_when_earned, page, failures)
        run(an_export_of_osm_data_names_the_licence, page, failures)
        run(the_export_offers_the_live_set_with_retirados_on, page, failures)
        # The failure case first: the check that succeeds retires the Sector both
        # of them open, because a four-kilometre Sector left on a shared instance
        # reassigns half the Registry to itself.
        run(the_editor_says_so_when_the_lines_do_not_close, page, failures)
        run(a_sector_can_be_built_from_picked_lines, page, failures)
        run(the_limit_is_kept_and_survives_adjustment, page, failures)
        # Which has just dragged a vertex and saved, so the line is now newer
        # than the Limit — #50's third case, the one a person would ask about.
        run(a_sector_says_how_its_boundary_came_to_be, page, "adjusted", failures)
        # After both: this one retires a line the Sector's Limit cites, and every
        # check above needs those lines live to pick and generate from.
        run(a_gone_line_says_so_and_keeps_its_name, page, failures)
        # The third origin, which this run has to create for itself: neither
        # Sector above came from a Dataset, and an imported one's history is
        # indistinguishable from the hand-drawn one's.
        run(an_imported_sector_says_it_came_from_the_catastro, page, failures)
        # After it, and reusing the Sector it just imported: this one needs a
        # SAVED Sector nobody tinted, to read the neutral wash off, and it
        # imports the Unidad Vecinal beside it.
        run(a_division_renders_as_a_division, page, failures)
        # After the checks that need this run's Sector: this one draws its own
        # and leaves the list filtered while it works.
        run(a_tramo_typed_before_the_first_save_is_kept, page, failures)

        run(the_map_survives_churn, page, errors, failures)
        page.screenshot(path=str(SHOTS / "search.png"), full_page=False)

        # Here, and not earlier: the Índice is worth checking over several records
        # with different names, and by now this run has made a Place and three
        # Sectores. Before the cluster records below, which sit eleven metres apart
        # and add nothing a table can show.
        run(the_indice_reads_the_registry, page, failures)

        # Three records a few metres apart, made once and read by both checks
        # below: one bubble to measure the ring on, and two pins to measure the
        # dashed treatment on their own.
        run(_a_clustered_place, page, f"Cúmulo {RUN} aproximado", "Salud · CESFAM", 1,
                           failures, quality="Aproximada")
        run(_a_clustered_place, page, f"Cúmulo {RUN} exacto", "Salud · CESFAM", 2, failures)
        run(_a_clustered_place, page, f"Cúmulo {RUN} colegio", "Educación · Colegio", 3,
                           failures)
        # Reloaded first, which is what puts the view back on the comuna the
        # install serves: eleven metres is under a pixel there, so the three
        # records are one bubble. Left zoomed in by an earlier check they would
        # each draw their own pin, and the cluster check would measure nothing.
        page.reload(wait_until="domcontentloaded")
        page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
        page.wait_for_timeout(1000)
        run(the_exactitud_is_drawn_on_the_pin, page, failures)
        run(a_cluster_says_what_is_under_it, page, failures)

        # After the cluster records above, because it renames the Category they
        # are filed under and Análisis draws no section for a Category holding
        # nothing. It puts both names back when it is done.
        run_in_capas(a_category_can_be_renamed_without_a_reload, page, errors, failures)
        # After it, and not before: that check renames «Salud» and puts it back,
        # and this one addresses the Category by that name throughout.
        run_in_capas(a_subcategory_can_be_added_without_a_reload, page, errors, failures)
        # After both: this one adds a Category it cannot take away again, and
        # every check above that
        # addresses «Salud» by name would otherwise have one more branch in the
        # panel to scroll past. It files its own record, so Análisis has a
        # section to read.
        run_in_capas(a_category_can_be_added_without_a_reload, page, errors, failures)
        # After it, and on the Category it just added: #46 watches that Category
        # go from the neutral fallback to a pictogram somebody chose, and reusing
        # it is what keeps this run spending one vetted spare instead of two.
        # Before the #47 block below rather than after it: neither of these adds
        # a Category — the add-time one stops at Escape — so they leave the
        # taxonomy exactly as «Agregar categoría» left it, which is what the #47
        # comment asks for.
        run_in_capas(a_category_can_be_given_a_pictogram, page, failures)
        run_in_capas(a_pictogram_can_be_picked_while_a_category_is_named, page, failures)
        # #47, after every check that ADDS to the taxonomy: this one reads the
        # panel with the divisions block on screen, and the two below add
        # Categories of their own — and take their own away again (the first
        # through the empty removal; #45/#46 and the second through
        # `reclaim_the_runs_category`, #131), so no taxonomy check here leaves
        # anything behind.
        run_in_capas(the_territorial_division_says_it_cannot_be_removed, page, failures)
        run_in_capas(a_category_can_be_removed_without_a_reload, page, errors, failures)
        run_in_capas(a_category_in_use_is_refused_and_says_where_to_look, page, errors, failures)

        # After every check that reads this run's Limit, because it adds 30
        # tramos to it, and before the retires below, because it needs the
        # Sector open one last time.
        run(the_retire_control_is_reachable_without_scrolling, page, failures)

        # Last, and after every check that needs this run's Sector: it is left
        # four kilometres across by the polygonize check above.
        run(retire_the_runs_record, page, f"Sector Navegador {RUN}")
        run(retire_the_runs_record, page, f"Sector a mano {RUN}")
        # Imported, not drawn, but a Sector all the same: left behind it claims
        # every Place it covers, run after run, on the instance the integration
        # suite reads. Siting it away from the data limits today's damage and does
        # not make the record go away.
        run(retire_the_runs_record, page, f"Sector Catastro {RUN}")

        run(kml_says_what_it_cannot_carry, page, failures)
        page.screenshot(path=str(SHOTS / "kml-notice.png"), full_page=False)
        run(a_csv_imports_and_says_what_does_not_come_back_in, page, failures)
        run(a_kmz_imports_like_the_kml_inside_it, page, errors, failures)
        run(a_file_already_in_files_imports_without_being_uploaded, page, errors, failures)
        occ_import_names_the_formats_it_takes(failures)
        # Last of all: it leaves the Comuna chosen, and a chosen Comuna refuses the
        # files of any other, which every import above would then trip on.
        run(the_map_opens_on_the_comuna_extent, page, tiles, failures)
        browser.close()

    return _report(errors, failures)


def _report(errors, failures):
    if errors:
        failures.append(f"console errors: {errors[:5]}")

    for line in failures:
        print(f"FAIL  {line}")
    if failures:
        return 1

    if ONLY == "redesign":
        print("OK    the redesigned topbar works: search, tabs, layers menu, drawer, drawing")
        print(f"      screenshot: {SHOTS / 'redesign.png'}")
        return 0

    if ONLY == "extent":
        print("OK    the map opens on the chosen Comuna's extent")
        return 0

    if ONLY == "tiles":
        print("OK    the tiles follow OpenStreetMap's policy and the credit stays readable")
        return 0

    print("OK    a Place and a Sector were created in the browser and survived a reload")
    print(f"      screenshot: {SHOTS / 'after-reload.png'}")
    for reason in SKIPPED:
        print(f"      NOT CHECKED: {reason}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
