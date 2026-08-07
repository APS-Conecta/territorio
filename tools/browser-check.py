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

import sys
import uuid
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


def log_in(page):
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


def save(page, failures, what):
    """Press Guardar and report what the app said if it refused.

    Without this the gate could only say a record was missing afterwards, which
    is the symptom rather than the reason — and the app already puts the reason
    on screen.
    """
    page.get_by_role("button", name="Guardar").click()
    page.wait_for_timeout(800)

    refusal = page.locator(".notecard--error, .notecard--warning")
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
    page.get_by_role("button", name="Agregar").click()
    page.get_by_label("Nombre").fill(name)
    page.get_by_label("Latitud").fill(f"-33.5{300 + NUDGE}")
    page.get_by_label("Longitud").fill(f"-70.5{300 + NUDGE}")
    save(page, failures, "the Place")
    # Dismiss the sidebar so it does not overlay the map for the next click.
    #
    # Escape rather than its close button, deliberately: closing it properly
    # widens the map canvas, and every position-based click after that lands
    # somewhere else than the one before it. Escape leaves the geometry alone.
    page.keyboard.press("Escape")
    page.wait_for_timeout(400)


def toggle_division(page, label):
    """Tick or untick one of the Capas division toggles."""
    page.locator(".territorio-capas__divisions").get_by_text(label, exact=True).click()
    page.wait_for_timeout(400)


def draw_a_sector(page, name, failures, colour="#16a34a"):
    """The #10 criterion: draw a polygon, name it, colour it, save it."""
    # The icon is a div inside the button's anchor, not the anchor itself.
    page.locator(".leaflet-pm-icon-polygon").click()

    canvas = page.locator('[data-testid="map-canvas"]')
    left, top = 180 + NUDGE, 160 + (NUDGE % 60)
    for x, y in [(left, top), (left + 110, top), (left + 110, top + 90), (left, top + 90)]:
        canvas.click(position={"x": x, "y": y})
    # Geoman's own Finalizar, rather than clicking the first vertex again: the
    # vertex is an 8px marker and hitting it is a coin toss.
    page.locator(".leaflet-pm-action.action-finish").click()

    # The sidebar for a Sector carries a colour input and no coordinate fields.
    # Waiting for it here turns "the polygon never became a draft" into a sentence
    # instead of a timeout thirty seconds later on an unrelated locator.
    #
    # ponytail: the draw is clicks on a canvas that may be covered in boundaries,
    # and geoman snaps to them, so it does occasionally not take. It fails
    # honestly rather than silently; give it a retry if that starts costing time.
    try:
        page.wait_for_selector('input[type="color"]', timeout=5_000)
    except PlaywrightTimeout:
        failures.append("drawing the polygon did not open a Sector draft")
        return

    page.get_by_label("Nombre").fill(name)
    # The colour input is native, so set it directly — a colour picker cannot be
    # driven by clicking.
    page.locator('input[type="color"]').fill(colour)
    save(page, failures, "the Sector")


def clicking_places_the_coordinate(page, failures):
    """A click on the map belongs to the draft that is waiting for a coordinate.

    The regression this exists for: once a comuna's Unidades Vecinales are
    imported they tile the whole view, so a click landed on a boundary and
    selected it — the draft was replaced and the next save edited the cadastre.
    One Unidad Vecinal was renamed that way before this was fixed.
    """
    page.get_by_role("button", name="Agregar").click()
    page.get_by_label("Nombre").fill(f"Prueba de clic {RUN}")

    page.locator('[data-testid="map-canvas"]').click(position={"x": 420, "y": 300})
    page.wait_for_timeout(400)

    if page.get_by_label("Latitud").input_value().strip() == "":
        failures.append("clicking the map did not fill the open draft's coordinate")
    if page.get_by_label("Nombre").input_value() != f"Prueba de clic {RUN}":
        failures.append("clicking the map replaced the open draft with another record")

    # Nothing is saved: this is about where the click went, and leaving a record
    # behind for it would only be more to tidy.
    page.keyboard.press("Escape")
    page.wait_for_timeout(400)


def the_assignment_is_shown_and_not_editable(page, failures):
    """#11: Sector and Unidad Vecinal are shown, never typed (ADR-0008)."""
    page.locator(SEARCH).fill(f"Plaza del Navegador {RUN}")
    page.wait_for_timeout(400)
    page.locator(ROWS).first.click()
    page.wait_for_timeout(600)

    sidebar = page.locator(".territorio-detail")
    if sidebar.get_by_text("Unidad Vecinal:").count() == 0:
        failures.append("the sidebar does not say which Unidad Vecinal the record is in")

    # The criterion is that there is nowhere to type one. Any input carrying the
    # word would be exactly the promise ADR-0008 says the app cannot keep.
    for label in ("Sector", "Unidad Vecinal"):
        if page.get_by_role("textbox", name=label, exact=True).count() > 0:
            failures.append(f"{label} is an editable field, which ADR-0008 forbids")

    page.keyboard.press("Escape")
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


def the_limit_can_be_picked(page, failures):
    """#13: pick the lines a Sector's Limit runs along, by name or by clicking.

    Needs boundary features on the instance; skipped with a word rather than a
    false pass when there are none.
    """
    page.locator(SEARCH).fill(f"Sector Navegador {RUN}")
    page.wait_for_timeout(400)
    page.locator(ROWS).first.click()
    page.wait_for_timeout(1200)

    picker = page.locator(".territorio-picker")
    if picker.count() == 0:
        failures.append("opening a Sector did not open the Limit picker")
        return

    box = picker.locator("input")
    # A letter, not a word: the loaded names are whatever streets this comuna
    # actually has, and the check should not need to know them.
    box.fill("a")
    page.wait_for_timeout(600)

    offered = picker.locator(".territorio-picker__suggestions li")
    if offered.count() == 0:
        print("SKIP  no boundary features in view to pick from")
        page.keyboard.press("Escape")
        page.locator(SEARCH).fill("")
        return

    # Picking by name puts it in the chosen list…
    offered.first.locator("button").click()
    page.wait_for_timeout(500)
    chosen = picker.locator(".territorio-picker__picked li")
    if chosen.count() != 1:
        failures.append(f"picking a name chose {chosen.count()} things, expected 1")

    # …and removing it takes it out again.
    chosen.first.get_by_role("button", name="Quitar").click()
    page.wait_for_timeout(500)
    if picker.locator(".territorio-picker__picked li").count() != 0:
        failures.append("removing a picked line did not remove it")

    # Clicking a line on the map is the other half of the same criterion, and the
    # half nothing else exercises. It is also where a Sector polygon sitting on
    # top swallowed the click, selected itself, and took the whole selection with
    # it — so this asserts the picks survive as much as that one arrives.
    lines = page.locator(".leaflet-pane[class*=pickable] path")
    if lines.count() == 0:
        print("SKIP  no boundary line on screen to click")
    else:
        box.fill("")
        page.wait_for_timeout(300)
        lines.first.click(force=True)
        page.wait_for_timeout(500)

        after = picker.locator(".territorio-picker__picked li")
        if after.count() != 1:
            failures.append(f"clicking a line picked {after.count()} things, expected 1")

        lines.first.click(force=True)
        page.wait_for_timeout(500)
        if picker.locator(".territorio-picker__picked li").count() != 0:
            failures.append("clicking the same line again did not unpick it")

    page.keyboard.press("Escape")
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
    page.locator(ROWS).first.click()
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

    page.keyboard.press("Escape")
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
        # Scoped to our own switch: with a record open the sidebar carries a
        # "Mapa" control too, and an unscoped role lookup finds both.
        switch = page.locator(".territorio-main__switch")
        switch.get_by_role("button", name="Análisis").click()
        page.wait_for_timeout(200)
        text = page.locator(".territorio-analisis h2").inner_text()
        switch.get_by_role("button", name="Mapa").click()
        page.wait_for_timeout(200)
        return int(text.split()[0])

    before_rows = rows.count()

    # This run's two records and nothing else: one Place, which is one pin, and
    # one Sector, which is a polygon and so has no pin at all.
    box.fill(RUN)
    page.wait_for_timeout(500)
    if rows.count() != 2:
        failures.append(f"search found {rows.count()} of this run's 2 records")
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
    page.locator(".territorio-capas__divisions").get_by_text("Sector", exact=True).click()
    page.wait_for_timeout(500)
    if box.input_value() != RUN:
        failures.append("a Capas toggle wiped the search box")
    if rows.count() != 1:
        failures.append(f"toggle+query left {rows.count()} rows, expected the 1 Place")

    # Put Sector back, or the restore check below is comparing against a Capas
    # selection this function changed.
    page.locator(".territorio-capas__divisions").get_by_text("Sector", exact=True).click()
    page.wait_for_timeout(300)

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

    page.keyboard.press("Escape")
    page.wait_for_timeout(400)


def main():
    SHOTS.mkdir(exist_ok=True)
    failures = []

    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page(viewport={"width": 1400, "height": 900})
        errors = console_errors(page)

        log_in(page)
        page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)

        # Hide the cadastre first, which is what a person drawing a boundary does
        # anyway. With a comuna's Unidades Vecinales on screen the whole map is
        # polygons: clicks land on them and geoman snaps new vertices to their
        # edges, so the drawing step becomes a coin toss for reasons that have
        # nothing to do with what is being checked.
        if page.locator(".territorio-capas__divisions").get_by_text(
            "Unidad Vecinal", exact=True,
        ).count() > 0:
            toggle_division(page, "Unidad Vecinal")

        create_a_place(page, f"Plaza del Navegador {RUN}", failures)
        draw_a_sector(page, f"Sector Navegador {RUN}", failures)

        # Reload rather than trust the screen: a record that only exists in the
        # browser's memory looks identical to one that was saved.
        page.reload(wait_until="domcontentloaded")
        page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)

        for name in (f"Plaza del Navegador {RUN}", f"Sector Navegador {RUN}"):
            if page.get_by_text(name).count() == 0:
                failures.append(f"{name!r} is not in the list after a reload")

        page.screenshot(path=str(SHOTS / "after-reload.png"), full_page=False)

        clicking_places_the_coordinate(page, failures)
        the_assignment_is_shown_and_not_editable(page, failures)
        search_narrows_everything(page, failures)
        the_limit_can_be_picked(page, failures)
        the_map_survives_churn(page, errors, failures)
        page.screenshot(path=str(SHOTS / "search.png"), full_page=False)

        kml_says_what_it_cannot_carry(page, failures)
        page.screenshot(path=str(SHOTS / "kml-notice.png"), full_page=False)
        browser.close()

    if errors:
        failures.append(f"console errors: {errors[:5]}")

    for line in failures:
        print(f"FAIL  {line}")
    if failures:
        return 1

    print("OK    a Place and a Sector were created in the browser and survived a reload")
    print(f"      screenshot: {SHOTS / 'after-reload.png'}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
