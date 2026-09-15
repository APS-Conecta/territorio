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

    page.keyboard.press("Escape")
    page.locator(SEARCH).fill("")
    page.wait_for_timeout(400)

    the_server_refuses_an_unconfirmed_export(page, failures)
    the_administrator_can_read_the_export_log(page, failures)


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

    page.keyboard.press("Escape")
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

    if generate.is_disabled():
        failures.append("Generar is greyed out on lines picked a moment ago")
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

    picker = page.locator('[data-testid="boundary-picker"]')
    # The button, not the parts list: that list counts hand-typed tramos too, so it
    # says "this Sector has a Limit" where what is needed is "this Sector has picked
    # LINES". The button renders on `pickedIds`, which is exactly that.
    generate = picker.get_by_role("button", name="Generar el polígono")
    if generate.count() == 0:
        skip("the reopened Sector has no picked lines, so the reopen check did not run")
        page.keyboard.press("Escape")
        page.locator(SEARCH).fill("")
        return

    # Greyed out until the shapes are back, so waiting for it is the assertion.
    if not settles(page, lambda: not generate.is_disabled(), timeout=10_000):
        failures.append(
            "a reopened Sector never became generatable, though its Limit's lines are on screen (#49)",
        )
        page.keyboard.press("Escape")
        page.locator(SEARCH).fill("")
        return

    generate.click()
    page.wait_for_timeout(400)
    canvas = page.locator('[data-testid="map-canvas"]')
    size = canvas.bounding_box()
    canvas.click(position={"x": size["width"] / 2, "y": size["height"] / 2})
    page.wait_for_timeout(1200)

    # The whole point of the ticket: no message may blame the streets when the
    # cache was what was empty.
    if page.get_by_text("no cierran", exact=False).count() != 0:
        failures.append("generating from a reopened Limit still says the lines do not close (#49)")

    # Discarded, not saved: the shape it just made is the same square the
    # polygonize check already asserted, and this run has written enough.
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
    so there is no import path to drive this from.
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

    page.keyboard.press("Escape")
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
        page.keyboard.press("Escape")
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

    page.locator(ROWS).first.click()
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
    if painted["fill"] != salud.lower():
        failures.append(f"a Salud pin is filled {painted['fill']}, but the seed says {salud}")
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
    page.locator(".app-sidebar__close").first.click()
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


# The neutral a Sector with no colour of its own is washed in, and the grey the
# Unidad Vecinal hairline is drawn in. Read from src/ rather than copied, for
# the reason the Category colours are read from the seed: a copy here would
# agree with itself while the app disagreed with both.
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

        page.locator(ROWS).first.click()
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
        page.keyboard.press("Escape")
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

    page.locator(ROWS).first.click()
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

    page.keyboard.press("Escape")
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
    switch = page.locator(".territorio-main__switch")
    switch.get_by_role("button", name="Análisis").click()
    page.wait_for_timeout(600)

    section = page.locator(".territorio-analisis__category").filter(
        has_text="División territorial",
    ).first
    if section.count() == 0:
        skip("Análisis shows no División territorial section, so its bars are unchecked (#29)")
        switch.get_by_role("button", name="Mapa").click()
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

    switch.get_by_role("button", name="Mapa").click()
    page.wait_for_timeout(400)


def _ramp_check():
    """tools/ramp-check.py as a module, for its seed reader and its arithmetic.

    Imported rather than re-implemented. The hyphen in the filename keeps it off
    the normal import path, and a second copy of the regex — or of the contrast
    formula — here is exactly the drift this whole check exists to catch.
    """
    import importlib.util

    path = Path(__file__).resolve().parent / "ramp-check.py"
    spec = importlib.util.spec_from_file_location("ramp_check", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)

    return module


def _seeded_categories():
    """The colours the seed ships, straight out of the PHP."""
    return _ramp_check().seeded_categories()


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
        page.keyboard.press("Escape")
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

            page.locator(ROWS).first.click()
            page.wait_for_timeout(1200)

            control = page.get_by_role("button", name="Retirar del mapa")
            if not settles(page, lambda: control.count() > 0, timeout=5_000):
                failures.append(f"{name!r} offers no way to retire it at {width}x{height} (#35)")
                page.keyboard.press("Escape")
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
                # `.app-sidebar` is the element that scrolls, measured on this
                # instance rather than read off the stylesheet: one match,
                # `overflow-y: auto`, scrollHeight 1336 over clientHeight 562 on
                # a 620px screen, while `.app-sidebar-tabs__content` inside it is
                # `overflow-y: visible`. If a later @nextcloud/vue moves the
                # scroller this stops matching and the branch below says so.
                sidebar = page.locator(".app-sidebar")
                if sidebar.count() == 0:
                    failures.append(
                        f"no .app-sidebar to scroll for {name!r} at {width}x{height}, so "
                        "nothing measured whether «Retirar del mapa» stays put (#35)",
                    )
                    page.keyboard.press("Escape")
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

            page.keyboard.press("Escape")
            page.wait_for_timeout(300)

    page.locator(SEARCH).fill("")
    page.set_viewport_size({"width": 1400, "height": 900})
    page.wait_for_timeout(400)

    # A Unidad Vecinal is a boundary: left behind it claims every Place inside it
    # on the instance the integration suite reads, exactly as the imported Sector
    # does. The Ciclovía is not a boundary and is left like every other Place.
    if made[unidad]:
        retire_the_runs_record(page, unidad)


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
        the_app_icon_is_legible_in_both_themes(page, failures)

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

        # Here, and not at the top: the Datos list needs a record in it, and this
        # run has just made one and proved it survived a reload.
        the_glyphs_beside_a_label_are_white_too(page, failures)

        # Before anything picks a Limit for it: this run's Sector was drawn
        # freehand and has one revision, which is #50's first case.
        a_sector_says_how_its_boundary_came_to_be(page, "drawn", failures)

        # Before the polygonize check below, which grows this run's Sector to four
        # kilometres across: a vertex of the small drawn one is on screen and
        # draggable, which is what this needs.
        a_dragged_vertex_stays_with_its_own_record(page, failures)
        a_pin_says_what_kind_of_place_it_is(page, failures)
        a_pin_opens_a_record_while_the_retired_list_is_shown(page, failures)

        a_sector_can_be_reached_and_opened_with_the_keyboard(page, failures)
        the_zoom_controls_are_in_spanish(page, failures)

        clicking_places_the_coordinate(page, failures)
        the_assignment_is_shown_and_not_editable(page, failures)
        search_narrows_everything(page, failures)
        the_limit_can_be_picked(page, failures)
        the_duplicate_queue_works(page, failures)
        exports_ask_for_a_password_only_when_earned(page, failures)
        an_export_of_osm_data_names_the_licence(page, failures)
        # The failure case first: the check that succeeds retires the Sector both
        # of them open, because a four-kilometre Sector left on a shared instance
        # reassigns half the Registry to itself.
        the_editor_says_so_when_the_lines_do_not_close(page, failures)
        a_sector_can_be_built_from_picked_lines(page, failures)
        the_limit_is_kept_and_survives_adjustment(page, failures)
        # Which has just dragged a vertex and saved, so the line is now newer
        # than the Limit — #50's third case, the one a person would ask about.
        a_sector_says_how_its_boundary_came_to_be(page, "adjusted", failures)
        # After both: this one retires a line the Sector's Limit cites, and every
        # check above needs those lines live to pick and generate from.
        a_gone_line_says_so_and_keeps_its_name(page, failures)
        # The third origin, which this run has to create for itself: neither
        # Sector above came from a Dataset, and an imported one's history is
        # indistinguishable from the hand-drawn one's.
        an_imported_sector_says_it_came_from_the_catastro(page, failures)
        # After it, and reusing the Sector it just imported: this one needs a
        # SAVED Sector nobody tinted, to read the neutral wash off, and it
        # imports the Unidad Vecinal beside it.
        a_division_renders_as_a_division(page, failures)
        # After the checks that need this run's Sector: this one draws its own
        # and leaves the list filtered while it works.
        a_tramo_typed_before_the_first_save_is_kept(page, failures)

        the_map_survives_churn(page, errors, failures)
        page.screenshot(path=str(SHOTS / "search.png"), full_page=False)

        # After every check that reads this run's Limit, because it adds 30
        # tramos to it, and before the retires below, because it needs the
        # Sector open one last time.
        the_retire_control_is_reachable_without_scrolling(page, failures)

        # Last, and after every check that needs this run's Sector: it is left
        # four kilometres across by the polygonize check above.
        retire_the_runs_record(page, f"Sector Navegador {RUN}")
        retire_the_runs_record(page, f"Sector a mano {RUN}")
        # Imported, not drawn, but a Sector all the same: left behind it claims
        # every Place it covers, run after run, on the instance the integration
        # suite reads. Siting it away from the data limits today's damage and does
        # not make the record go away.
        retire_the_runs_record(page, f"Sector Catastro {RUN}")

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
