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

import json
import re
import subprocess
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
    page.keyboard.press("Escape")
    page.wait_for_timeout(400)


def toggle_division(page, label):
    """Tick or untick one of the Capas division toggles."""
    page.locator(".territorio-capas__divisions").get_by_text(label, exact=True).click()
    page.wait_for_timeout(400)


def division_toggle(page, label):
    """The checkbox in Capas for one territorial division."""
    return (page.locator(".territorio-capas__divisions .checkbox-radio-switch")
            .filter(has_text=label)
            .locator("input[type=checkbox]"))


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
        box = division_toggle(page, label)
        if box.count() > 0 and box.first.is_checked():
            toggle_division(page, label)
            hidden.append(label)

    return hidden


def show_divisions(page, labels):
    """Put back exactly what hide_divisions took away."""
    for label in labels:
        box = division_toggle(page, label)
        if box.count() > 0 and not box.first.is_checked():
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
        page.keyboard.press("Escape")
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

    # Reopened, because the point is that it outlives the draft it was typed on.
    page.locator(SEARCH).fill(name)
    page.wait_for_timeout(800)
    if page.locator(ROWS).count() == 0:
        failures.append(f"{name!r} is not in the list after saving")
        page.locator(SEARCH).fill("")
        return

    page.locator(ROWS).first.click()
    page.wait_for_timeout(1500)
    kept = page.locator(".territorio-picker__parts li")
    if kept.count() == 0 or tramo not in kept.first.inner_text():
        failures.append(f"a tramo typed before the first save did not survive it: {tramo!r}")

    page.keyboard.press("Escape")
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
        page.keyboard.press("Escape")
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
        lines.first.click(force=True)
        page.wait_for_timeout(500)

        after = picker.locator(".territorio-picker__parts li")
        if after.count() != 1:
            failures.append(f"clicking a line picked {after.count()} things, expected 1")

        lines.first.click(force=True)
        page.wait_for_timeout(500)
        if picker.locator(".territorio-picker__parts li").count() != 0:
            failures.append("clicking the same line again did not unpick it")

    page.keyboard.press("Escape")
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

    page.keyboard.press("Escape")
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

    shape.first.click(force=True)
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
        page.get_by_role("button", name="Guardar").click()
        page.wait_for_timeout(1500)

        refusal = page.locator(".notecard--error")
        if refusal.count() > 0:
            failures.append(
                f"saving with «Ver retirados» on reported {refusal.first.inner_text().strip()!r} (#67)",
            )
        if page.locator(".territorio-detail").count() == 0:
            failures.append("saving with «Ver retirados» on closed the sidebar (#67)")

    page.keyboard.press("Escape")
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
    page.keyboard.press("Escape")
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

    button = page.locator(".territorio-main__switch").get_by_role("button", name="Posibles duplicados")
    if "(" not in button.inner_text():
        failures.append(
            f"the pane button does not say how many are outstanding: {button.inner_text()!r}",
        )
    button.click()
    page.wait_for_timeout(800)

    # Scoped to BOTH of this run's names. A card naming one of them and a record
    # from some earlier run is a real candidate pair and none of this check's
    # business; asserting on "any card mentioning this name" made the check fail
    # for a queue that was working correctly.
    card = (page.locator(".territorio-duplicados__pair")
            .filter(has_text=twin)
            .filter(has_text=partner))
    if card.count() == 0:
        failures.append("the imported pair was not offered in the queue")
        page.locator(".territorio-main__switch").get_by_role("button", name="Mapa").click()
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

    page.locator(".territorio-main__switch").get_by_role("button", name="Mapa").click()
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
    page.keyboard.press("Escape")
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

    page.keyboard.press("Escape")
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)

    the_server_refuses_an_unconfirmed_export(page, failures)
    the_administrator_can_read_the_export_log(page, failures)


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

    if not import_boundaries(geojson, f"cuadro-{RUN}"):
        skip("could not import boundary lines through occ; polygonize unchecked")
        return

    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1500)

    # Open the Sector this run drew earlier, which is what opens the picker.
    if not open_the_runs_sector(page, failures):
        return

    picker = page.locator('[data-testid="boundary-picker"]')
    # By label: the picker has a second field now, for the stretches of a limit
    # that follow nothing anybody could pick (issue #15).
    box = picker.get_by_label("Buscar una calle")
    box.fill(name)
    page.wait_for_timeout(800)
    offered = picker.locator(".territorio-picker__suggestions li")
    if offered.count() == 0:
        skip("the imported square is not in view; polygonize unchecked")
        page.keyboard.press("Escape")
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

    generate.click()
    page.wait_for_timeout(400)
    if picker.get_by_text("Haga clic dentro del sector").count() == 0:
        failures.append("pressing Generar did not ask where the sector is")

    canvas = page.locator('[data-testid="map-canvas"]')
    size = canvas.bounding_box()
    canvas.click(position={"x": size["width"] / 2, "y": size["height"] / 2})
    page.wait_for_timeout(1200)

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
    if picker.get_by_role("button", name="Generar el polígono").is_disabled():
        failures.append("saving a Sector lost the shapes of the lines just picked (#49)")

    page.keyboard.press("Escape")
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
    generating_from_a_reopened_limit_is_refused_honestly(page, failures)


def generating_from_a_reopened_limit_is_refused_honestly(page, failures):
    """#49: a reopened Sector knows the names its Limit runs along, not the shapes.

    `draftFrom()` derives `picked` from the stored Limit — which is what #15 put
    the names there for — and has nothing to fill `pickedGeometry` with. Asked to
    generate anyway, `faceAround` got an empty list, returned null, and the app
    said «Las calles elegidas no cierran alrededor de ese punto». About streets
    that do close, so the person went and re-picked streets that were already
    right.

    Run after the polygonize check, which is what leaves this run's Sector with a
    stored Limit to reopen. Asserts the refusal is plain rather than a failure:
    the button is there and disabled, with the reason beside it (ADR-0007 keeps
    hand-drawing available either way).
    """
    if not open_the_runs_sector(page, failures):
        return

    picker = page.locator('[data-testid="boundary-picker"]')
    parts = picker.locator(".territorio-picker__parts li")
    if parts.count() == 0:
        skip("the reopened Sector has no stored Limit, so the reopen check did not run")
        page.keyboard.press("Escape")
        page.locator(SEARCH).fill("")
        return

    generate = picker.get_by_role("button", name="Generar el polígono")
    if generate.count() == 0:
        failures.append("a reopened Sector with a Limit does not offer Generar at all")
    elif not generate.is_disabled():
        failures.append(
            "a reopened Sector offers Generar, which can only fail and blame the streets (#49)",
        )

    if picker.get_by_text("no se guardan sus trazados").count() == 0 \
            and picker.get_by_text("no sus trazados").count() == 0:
        failures.append("nothing says why Generar is unavailable on a reopened Sector (#49)")

    if page.get_by_text("no cierran", exact=False).count() != 0:
        failures.append("a reopened Sector still blames the streets (#49)")

    page.keyboard.press("Escape")
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

    handles = page.locator(".leaflet-marker-pane .marker-icon")
    if handles.count() == 0:
        failures.append("the generated polygon has no draggable vertices")
        page.keyboard.press("Escape")
        page.locator(SEARCH).fill("")
        return

    handle = handles.first.bounding_box()
    page.mouse.move(handle["x"] + handle["width"] / 2, handle["y"] + handle["height"] / 2)
    page.mouse.down()
    page.mouse.move(handle["x"] + 60, handle["y"] + 60, steps=8)
    page.mouse.up()
    page.wait_for_timeout(600)

    save(page, failures, "the dragged sector")
    page.keyboard.press("Escape")
    page.wait_for_timeout(600)

    moved = exported_corners(page, f"Sector Navegador {RUN}", failures)
    if moved is not None and moved == square:
        failures.append("dragging a vertex of the generated polygon changed nothing")


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
    page.locator(SEARCH).fill(name)
    page.wait_for_timeout(700)
    if page.locator(ROWS).count() == 0:
        return

    page.locator(ROWS).first.click()
    page.wait_for_timeout(1200)

    # Two clicks: retiring asks first, because nothing else in this app does.
    ask = page.get_by_role("button", name="Retirar del mapa")
    if ask.count() > 0:
        ask.first.click()
        page.wait_for_timeout(400)
        page.get_by_role("button", name="Retirar", exact=True).first.click()
        page.wait_for_timeout(1500)

    page.keyboard.press("Escape")
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


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
        page.keyboard.press("Escape")
        page.locator(SEARCH).fill("")
        return

    offered.first.locator("button").click()
    page.wait_for_timeout(600)
    picker.get_by_role("button", name="Generar el polígono").click()
    page.wait_for_timeout(400)

    canvas = page.locator('[data-testid="map-canvas"]')
    size = canvas.bounding_box()
    canvas.click(position={"x": size["width"] * 0.8, "y": size["height"] * 0.8})
    page.wait_for_timeout(1000)

    if page.get_by_text("no cierran", exact=False).count() == 0:
        failures.append("lines that do not close said nothing when generating")

    # ADR-0007: hand-drawing is available throughout, including after this.
    if page.locator(".leaflet-pm-icon-polygon").count() == 0:
        failures.append("hand-drawing is no longer available after a failed generate")

    page.keyboard.press("Escape")
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

    page.locator(ROWS).first.click()
    page.wait_for_timeout(1500)
    if page.locator('[data-testid="boundary-picker"]').count() == 0:
        failures.append("opening a Sector did not open the Limit picker")
        page.keyboard.press("Escape")
        page.locator(SEARCH).fill("")
        return False

    return True


def import_boundaries(geojson, dataset):
    """Load lines as a Boundary Dataset, the only way there is: occ."""
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
         f"--dataset={dataset}", "--boundary"],
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
    page.keyboard.press("Escape")
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
        page.keyboard.press("Escape")
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
    page.keyboard.press("Escape")
    page.wait_for_timeout(600)
    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector('[data-testid="map-canvas"]', timeout=30_000)
    page.wait_for_timeout(1200)
    if not open_the_runs_sector(page, failures):
        return

    if page.locator('[data-testid="boundary-picker"]').get_by_text(stretch, exact=False).count() == 0:
        failures.append("adjusting the geometry by hand lost the Limit")

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


def a_pin_says_what_kind_of_place_it_is(page, failures):
    """#20: the pictogram, where it has to work — a pin with no label beside it.

    Hue only groups Categories into six families (ADR-0013), so this glyph is
    what tells a colegio from a farmacia, and it is the half a dichromacy cannot
    take away. On the map there is no text to fall back on.

    Creates its own Salud record rather than trusting the instance to hold one:
    a check that cannot set up its own data has nothing to say. The expected fill
    is read out of the seed for the same reason the Capás check reads it —
    a copy here could agree with itself while the app disagreed with both.
    """
    salud = _seeded_categories().get("salud")
    if salud is None:
        failures.append("the seed has no 'salud' Category, so the glyph check could not run")
        return

    name = f"CESFAM del Navegador {RUN}"

    page.get_by_role("button", name="Agregar", exact=True).click()
    page.get_by_label("Nombre").fill(name)
    page.get_by_label("Latitud").fill(f"-33.5{340 + NUDGE}")
    page.get_by_label("Longitud").fill(f"-70.5{340 + NUDGE}")

    # NcSelect labels its options "Category · Subcategory", because "Otro"
    # appears in nearly every Category and means something different in each.
    page.get_by_label("Subcategoría").click()
    page.get_by_label("Subcategoría").fill("Salud · CESFAM")
    page.wait_for_timeout(500)
    page.keyboard.press("Enter")
    page.wait_for_timeout(300)

    save(page, failures, "the Salud place")
    page.keyboard.press("Escape")
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
            return {
                fill: '#' + rgb.slice(0, 3).map((n) => (+n).toString(16).padStart(2, '0')).join(''),
                glyph: svg ? svg.id : null,
                glyphFill: path ? getComputedStyle(path).fill : null,
            }
        }""",
    )

    if painted["glyph"] != "hospital":
        failures.append(
            f"a Salud pin carries the {painted['glyph']!r} glyph, expected 'hospital' — the "
            f"pictogram is what identifies a Category on the map",
        )
    if painted["fill"] != salud.lower():
        failures.append(f"a Salud pin is filled {painted['fill']}, but the seed says {salud}")
    if painted["glyphFill"] != "rgb(255, 255, 255)":
        failures.append(
            f"the glyph is drawn {painted['glyphFill']}, not white — the ramp clears 3:1 "
            f"against white and was never checked against anything else",
        )

    # Retired again, so this run still leaves behind exactly the Place and the
    # Sector that the later count assertions are written against. Retiring rather
    # than deleting because ADR-0005 has no delete — and it is the app's own way
    # of taking something off the map, so it costs nothing to exercise it.
    retire_the_runs_record(page, name)


def registry_feature(page, name):
    """One record as the Registry holds it, by name. None if it is not there.

    Through the app's own change feed rather than the screen or the database:
    it is the read a second browser would do, so it proves the record was
    written rather than that a form still has values in it.
    """
    features = page.evaluate(
        """async () => {
            const response = await fetch(
                '/index.php/apps/territorio/changes?since=0',
                { headers: { requesttoken: window.OC.requestToken } },
            )
            const body = await response.json()
            return body.features || []
        }""",
    )

    return next((feature for feature in features if feature.get("name") == name), None)


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

    page.locator(ROWS).first.click()
    page.wait_for_timeout(1200)

    handles = page.locator(".leaflet-marker-pane .marker-icon")
    if handles.count() == 0:
        failures.append("the open Sector had no draggable vertex, so nothing was dragged")
        page.keyboard.press("Escape")
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
    page.locator(SEARCH).fill(place)
    page.wait_for_timeout(600)
    if page.locator(ROWS).count() == 0:
        failures.append(f"{place!r} vanished from the list while switching records")
        page.locator(SEARCH).fill("")
        return

    page.locator(ROWS).first.click()
    page.wait_for_timeout(800)
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

    page.keyboard.press("Escape")
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)


def _seeded_categories():
    """The colours the seed ships, borrowed from tools/ramp-check.py.

    Imported rather than re-parsed. The hyphen in the filename keeps it off the
    normal import path, and a second copy of the regex here is exactly the drift
    this whole check exists to catch.
    """
    import importlib.util

    path = Path(__file__).resolve().parent / "ramp-check.py"
    spec = importlib.util.spec_from_file_location("ramp_check", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)

    return module.seeded_categories()


def every_category_reaches_the_browser_with_its_colour(page, failures):
    """The colours in the seed are the colours on the screen.

    Compares against the seeded values, not against an invariant. The first
    version of this check asserted only "non-blank and all different", and a
    review pointed out that the old palette satisfies both — eleven distinct,
    non-blank colours — so the check could not fail for the exact reason its own
    docstring gave for existing. It was theatre.

    The failure it now actually catches: `ensureTaxonomy()` returns early on an
    install that already has rows, so a seed change reaches an existing install
    only through `ensureRamp()`. Narrow that guard and the screen keeps serving
    the old palette while every other suite stays green — which is the shape of
    bug this gate was built for (`Guardar` did nothing for eight slices).

    Asserts on what the Registry served, per SKILL.md § 5: these colours come out
    of the database, through initial state, into Vue. Nothing else here reads
    that path end to end.
    """
    seeded = _seeded_categories()
    swatches = page.locator(".territorio-capas__swatch")
    count = swatches.count()

    if count == 0:
        failures.append("the Capas panel drew no Category swatches at all")
        return

    def as_hex(painted):
        numbers = re.findall(r"\d+", painted or "")
        if len(numbers) < 3:
            return painted
        return "#" + "".join(f"{int(n):02x}" for n in numbers[:3])

    painted = {
        as_hex(swatches.nth(i).evaluate("e => getComputedStyle(e).backgroundColor"))
        for i in range(count)
    }
    expected = {colour.lower() for colour in seeded.values()}

    stale = expected - painted
    if stale:
        failures.append(
            f"the seed ships {sorted(stale)} but the browser never painted them — the taxonomy "
            f"the Registry served is not the one EnsureSeedData holds",
        )

    unexpected = painted - expected
    if unexpected:
        failures.append(
            f"the browser painted {sorted(unexpected)}, which no Category in the seed carries",
        )

    # Whether the Sector default is far enough from a Category is NOT checked
    # here. It is a question about perceptual distance — the first attempt at
    # that fix shipped a grey sitting dE 0.026 from `sin_clasificar`, which no
    # equality test can see — and `tools/ramp-check.py` owns it, because it is
    # the only thing here that can measure OKLab. This gate proves the round
    # trip; that check proves the colour.


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

        every_category_reaches_the_browser_with_its_colour(page, failures)

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

        # Before the polygonize check below, which grows this run's Sector to four
        # kilometres across: a vertex of the small drawn one is on screen and
        # draggable, which is what this needs.
        a_dragged_vertex_stays_with_its_own_record(page, failures)
        a_pin_says_what_kind_of_place_it_is(page, failures)
        a_pin_opens_a_record_while_the_retired_list_is_shown(page, failures)

        clicking_places_the_coordinate(page, failures)
        the_assignment_is_shown_and_not_editable(page, failures)
        search_narrows_everything(page, failures)
        the_limit_can_be_picked(page, failures)
        the_duplicate_queue_works(page, failures)
        exports_ask_for_a_password_only_when_earned(page, failures)
        # The failure case first: the check that succeeds retires the Sector both
        # of them open, because a four-kilometre Sector left on a shared instance
        # reassigns half the Registry to itself.
        the_editor_says_so_when_the_lines_do_not_close(page, failures)
        a_sector_can_be_built_from_picked_lines(page, failures)
        the_limit_is_kept_and_survives_adjustment(page, failures)
        # After the checks that need this run's Sector: this one draws its own
        # and leaves the list filtered while it works.
        a_tramo_typed_before_the_first_save_is_kept(page, failures)

        the_map_survives_churn(page, errors, failures)
        page.screenshot(path=str(SHOTS / "search.png"), full_page=False)

        # Last, and after every check that needs this run's Sector: it is left
        # four kilometres across by the polygonize check above.
        retire_the_runs_record(page, f"Sector Navegador {RUN}")
        retire_the_runs_record(page, f"Sector a mano {RUN}")

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
    for reason in SKIPPED:
        print(f"      NOT CHECKED: {reason}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
