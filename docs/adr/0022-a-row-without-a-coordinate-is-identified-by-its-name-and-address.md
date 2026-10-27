# A row without a coordinate is identified by its name and address

- **Status:** accepted (2026-10-27)
- **Cites:** [ADR-0003](0003-local-overrides-outrank-the-cadastre.md), which makes import
  idempotent on the `<source>:<hash>` external id. It fixes that shape, not what goes into the
  hash, and is not amended.

## Context

A row with no explicit `uid` or `id` gets an id derived from its source, its name and its position.
A row with no coordinate hashed its position as the constant `sin-coordenadas`, so its id came down
to source and name. The import collapses rows by id, so fifteen «Multicancha» rows at fifteen
addresses, none located, imported as one record and fourteen «duplicados en el archivo», on every
run (#202). Every CSV row without Latitud and Longitud, every KML placemark whose geometry cannot be
read and every GeoJSON feature with neither geometry nor id was exposed.

The id decides what a re-import matches, so changing what goes into it changes which records an
upstream correction updates and which it duplicates. Stored ids, earlier exports and the
`externalId` published over REST ([ADR-0020](0020-rest-is-the-published-surface.md)) all hold the
old value. Nextcloud cannot downgrade an app.

## Decision

**A place is its name plus the best "where" the row has: the coordinate; without one, the address;
without an address, the notes.** `GeoJsonReader::derivedId()` is the one derivation; the CSV and
KML readers reach it through `GeoJsonReader`.

1. **Without a coordinate, name and address.** Notes count only where there is no address. Notes is
   free text and the column most likely to change between files: if it were part of the id, every
   notes edit would leave the record, and its Local override, behind on an orphan (ADR-0003), and
   the Candidate pair scan does not look at coordinate-less records, so nothing would flag the
   duplicate. A KML placemark has no address and its description is read as notes, so same-named
   placemarks with different descriptions are different places. A row with no address and no notes
   keeps the id it had, byte for byte: there is nothing new to hash.
2. **The same name at the same coordinate is one place**, whatever its address or phone says. The
   last row wins and the batch counts the rest as duplicates. Every coordinate-bearing id keeps its
   bytes, so the 865-feature catastro matches as before.
3. **Records already imported are re-keyed once**, by the idempotent repair step
   `RekeyCoordinateLessRecords`, registered for `install` and `post-migration` beside
   `EnsureSeedData`. There is one matching key, not an old and a new one. The step acts only on a
   record whose `official` has no coordinate and whose stored id is exactly the old
   `<source>:sha1(name|sin-coordenadas)`; it derives the new id from the stored official name,
   address and notes. It writes no Revision, does not move the cursor and touches nothing but the
   external id. A record whose new id another record of the same Dataset already holds is skipped
   and counted rather than failing the upgrade: a development or cloned instance can import before
   the step runs, and an export made before the upgrade can be re-imported after it. A legacy-shaped
   explicit uid on a record that has a coordinate is left alone, because its own file would then
   create a duplicate.

## Consequences

- An address correction upstream reads as a new record, the same trade a coordinate correction
  already makes. A notes edit on a row that has an address updates the record.
- A GeoJSON export made before the upgrade carries the old uid of a re-keyed record, so
  re-importing it creates a second record. Export again after upgrading.
- The `externalId` published over REST changes once for every re-keyed record.
- Same-named coordinate-less rows with the same address still collapse, and so do same-named KML
  placemarks with no geometry and no description, even in different folders.
- A collision the step skipped leaves two records for one place; the upgrade output says how many.
