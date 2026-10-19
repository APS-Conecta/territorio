# Threat model

*What an attacker would want from this repository, and where they would try. Read before any security
work.*

## Data this repository holds

- **Territorial records:** features, categories, subcategories, boundaries, duplicates, import
  batches, revisions and a change cursor. Tables: `territorio_feature`, `territorio_category`,
  `territorio_subcategory`, `territorio_dataset`, `territorio_duplicate`, `territorio_import`,
  `territorio_revision`, `territorio_cursor` (`lib/Migration/`).
- **Personal data:** the names, mobile numbers and e-mail addresses of junta de vecinos presidents
  and secretaries — "personal data under Chilean law"
  (`docs/adr/0006-contact-exports-are-confirmed-and-recorded.md:7`).
- **The export log** (`territorio_export`): who took contact data out, and when
  (`docs/adr/0012-the-export-log-is-its-own-table-not-a-revision.md`).
- **No patient data.**

## What an attacker wants

1. **The contact list**, in bulk: an export of contact fields without the password confirmation, or
   without a log line, or a read path that leaks them.
2. **Patient data elsewhere in the same Nextcloud.** The path is acting in a staff session: XSS
   through stored names, notes or imported KML/CSV rendered on the map, or a forged request.
3. **Silent tampering**: changing or deleting features, categories or boundaries without a revision,
   or reverting an import, so the map the CESFAM uses is wrong.
4. **Admin-only actions** (comuna, the export log) by a non-admin.

## Entry points

A route without `#[NoAdminRequired]` is admin-only: that attribute is what opens a route to every user.

| Entry point | Who can call it | Guard |
|---|---|---|
| Pages: `GET /` (`PageController`) | logged-in users | `#[NoAdminRequired]`, `#[NoCSRFRequired]` (GET) |
| Features: `POST /features`, `PUT/DELETE /features/{id}`, restore, history, retired (`FeatureController`) | **every logged-in user** (ADR-0005: same rights for all) | `#[NoAdminRequired]`; CSRF on writes |
| Categories: `POST /categories`, `PUT/DELETE /categories/{slug}`; subcategories: `POST /categories/{slug}/subcategories`, `PUT/DELETE /categories/{slug}/subcategories/{subSlug}` (`CategoryController`) | every logged-in user | `#[NoAdminRequired]`; removal refused while records exist (ADR-0016) |
| Import: `POST /import`, `GET /import/batches`, `POST /import/{id}/revert` (`ImportController`) | every logged-in user | `#[NoAdminRequired]`; parsers `KmlReader` (`LIBXML_NONET`, #153), `CsvReader`, `Kmz` |
| Export: `POST /export`, **`POST /export/contacts`** (`ExportController`) | every logged-in user | contact export: `#[PasswordConfirmationRequired(strict: true)]` plus a log row (ADR-0006, ADR-0012) |
| Export log: `GET /export/history` (`ExportController::history`) | **admins only** | no `#[NoAdminRequired]`, deliberately (`lib/Controller/ExportController.php:64-68`) |
| Duplicates: list, merge, dismiss (`DuplicateController`) | every logged-in user | `#[NoAdminRequired]` |
| Boundaries: `GET /boundaries`, `GET /boundaries/names`; changes: `GET /changes` | every logged-in user | `#[NoAdminRequired]` |
| OCS `GET /api/v1/counts`, `/api/v1/features/{id}`, `/api/v1/subcategories/{category}/{subcategory}/features` (`ApiController`) | logged-in users | `#[NoAdminRequired]`, `#[UserRateLimit]` |
| Admin settings: `PUT /settings/comuna` (`AdminSettingsController`) | **admins only** | no `#[NoAdminRequired]` and no `#[NoCSRFRequired]`, on purpose (see the `AdminSettingsController` class docblock) |
| CLI `occ territorio:import` (`lib/Command/Import.php`) | server operators | shell access |

## Trust boundaries

- **Imported files are untrusted:** KML/KMZ (zip), CSV and XML from any staff member. Parse with
  network access off; reject entity expansion and zip bombs.
- **Stored text is untrusted on output:** names and notes are shown in Leaflet popups, and Leaflet
  writes string content with `innerHTML`. Popups get a DOM text node, which cannot be parsed as
  markup (`TerritorioMap.vue`, the `bindTooltip(document.createTextNode(…))` calls, #62). The map's credit is markup the app owns, a constant in
  `src/basemap.js`, never stored text.
- **The basemap is OpenStreetMap's tile service, loaded by every user's browser** (ADR-0021). No
  setting names it, so an administrator cannot point browsers at a host of their choosing: there is
  no tile-injection or server-side-request surface from configuration, and the page's
  Content-Security-Policy allows images from that one host and adds nothing else.
- **Tile privacy:** each tile request tells OpenStreetMap which tile is wanted and which instance
  asked. A tile coordinate places a viewport to roughly 300 m at zoom 17, and the instance's origin
  travels as `Referer` (`referrerPolicy: 'origin'`, which the Tile Usage Policy asks for). No record,
  name or contact detail does. This is accepted with the dependency; the alternative was a
  self-hosted archive (ADR-0019, superseded).
- **The platform (Nextcloud)** owns authentication, sessions, CSRF tokens and password confirmation.

## Accepted risks

- **Every logged-in user has the same rights to view, edit and delete territorial records.** This is
  ADR-0005; the exception is the contact export (ADR-0006).
- **Viewing contacts is unrestricted** inside the app, "because phoning a junta is the point"
  (ADR-0006). Only exports are confirmed and logged.
