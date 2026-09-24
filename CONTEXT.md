# Territorio

The territorial knowledge base a CESFAM keeps about the population it serves: which official
subdivisions exist, how the clinic divides them among its teams, and what is physically out
there — schools, plazas, health posts, community organisations. This glossary fixes the words;
decisions live in [`docs/adr/`](docs/adr/).

## Language

### Territorial divisions

**Comuna**:
The Chilean municipality an install is scoped to, identified by its CUT code. Exactly one per
install, chosen at setup; every record in the install belongs to it.
_Avoid_: municipality, district, city, territory

**Territorio**:
The part of the Comuna one CESFAM is responsible for. Always a portion of it, never the whole of
it, and never reaching into a second Comuna.
_Avoid_: catchment, coverage area, jurisdiction, the comuna

**Unidad Vecinal**:
An official municipal subdivision of a Comuna, numbered within it and carrying a nationally
unique id. It exists to organise neighbours who share needs and nearby spaces, and it is the basis
on which a Junta de Vecinos is formed — it is a unit of *community* organisation, not of clinical
work. Its boundary comes from the national cadastre: the clinic reads it, never draws it, and uses
it as **reference**. The clinic's own working division is the Sector.
_Avoid_: UV as a bare number, neighbourhood, barrio, junta de vecinos

**Sector**:
A division of its own territory that a CESFAM draws for itself. It divides the **population** as
much as the ground: each Sector has one fixed team, so the same families are seen by the same
people over time, which is the whole point of drawing one. Its boundary follows streets, it is
named however that clinic names things — words, numbers or colours — and it is independent of
Unidades Vecinales: neither one nests inside the other.
_Avoid_: zone, area, quadrant, sub-territory

### Shared vs personal

**Registry**:
The single shared body of territorial records the install holds. There is exactly one, everyone
edits the same one, and an edit by one person is an edit for everyone.
_Avoid_: my map, the database, the dataset

**View**:
What one person currently has on screen — which layers are shown, the extent, the active filters.
Personal to that user and never shared; changing a View changes nothing for anyone else.
_Avoid_: map state, session, workspace

### What is on the map

**Feature**:
One record in the Registry that has a geometry — a school, a plaza, a cycle path, a Sector.
Every Feature is exactly one Kind and carries exactly one Subcategory.
_Avoid_: point, marker, item, entity, POI

**Kind**:
The shape a Feature takes on the map: a **Place** (a single location), a **Zone** (an area) or a
**Route** (a line). Independent of what the Feature is about.
_Avoid_: geometry type, feature type, shape

**Category**:
The top level of the taxonomy that says what a Feature is about — Salud, Educación, Deporte y
recreación, Seguridad, Medioambiente, Organización social, Servicio público, Movilidad y
transporte, Religión, Comercio y economía, Sin clasificar. Editable data, not code: a clinic adds
its own and it appears in the panel and in reports at once. A Category always carries at least an
«Otro» Subcategory, including one a clinic just added: a Feature travels as a Subcategory id, so a
Category with none is one nothing can be filed under and one neither the picker nor Análisis shows.
_Avoid_: type, group, layer

**Subcategory**:
The second level, and the finest thing a person can switch on or off — colegio, farmacia, iglesia,
taller, plaza, ciclovía.
_Avoid_: subtype, tag, class

There is no separate "layer" object: the panel a user toggles is the Category tree itself, plus
one toggle per territorial division.

### How a boundary is expressed

**The Limit records intent; the geometry records the agreed line.**
They match when a Sector is generated from picked features and stop matching the moment somebody
drags a vertex. That is expected and is never drift to be corrected: the geometry is where the
line actually runs, and the Limit is what the team says when asked where the sector ends.


**Limit**:
What bounds a Sector, stated as the real-world features it runs along — a street, a river, a rail
line — plus any stretch drawn by hand where no such feature exists. It is what a person would say
out loud when asked where the sector ends, and it is kept even after the boundary is drawn.
_Avoid_: border, edge, perimeter, outline

**Boundary feature**:
Any line in the territory a Limit can follow. Streets are the common case; rivers, canals and
railways are equally valid, and some Limits follow none of them.
_Avoid_: street, road, calle

### Origin and stewardship

**Dataset**:
One named collection inside the Registry whose records share an origin. An install holds many.
_Avoid_: layer, source file, table

**Cadastral Dataset**:
A Dataset whose records come from an authority outside the clinic and can be re-imported when
that authority publishes a new release.
_Avoid_: official layer, reference data, static data

**Curated Dataset**:
A Dataset whose records are born inside the clinic — typed in or drawn by its own staff. Nothing
outside ever republishes them.
_Avoid_: manual layer, custom data

**Local override**:
An edit a person makes to a record belonging to a Cadastral Dataset. It states what is true on
the ground, so it survives re-import and takes precedence over the incoming value.
_Avoid_: local change, dirty record, patch

**Location quality**:
How well a record's coordinate is known — exact, approximate, or absent. A record with no
coordinate is still a valid record.
_Avoid_: geocoded, accuracy, precision

### Change over time

**Revision**:
The single ever-increasing number the Registry stamps on every change. It answers both "what has
changed since I last looked" and "who changed this, and when".
_Avoid_: version, timestamp, sequence

**Import batch**:
Everything one run of an import created or changed, held together so it can be undone as a unit
without disturbing anything edited since.
_Avoid_: import job, upload, transaction

**Retired**:
The state of a record a person has deleted. It leaves the map and every default listing, keeps
its history, and can be brought back.
_Avoid_: deleted, archived, inactive, soft-deleted

**Candidate pair**:
Two records that might be the same place, put together for somebody to decide about. Nothing is
merged on similarity (ADR-0011): both stay visible and usable until the pair is resolved, either
by merging them or by saying they are different places.
_Avoid_: calling an unresolved pair "a duplicate" — in prose or on screen. Whether the two are one
place is the question, not the answer, which is why the pane says "Posibles duplicados".
_Avoid_: match, conflict

**Merged**:
The state of a record somebody decided was the same place as another one. It is retired and points
at the survivor, so it can be brought back like any retired record and the mapping is kept.
_Avoid_: deduplicated, absorbed, deleted

**Export log**:
The record of who took contact details out of the app, when, in what format and how many records.
Kept forever (ADR-0006) and separate from the change log, because an export changes nothing
(ADR-0012).
_Avoid_: audit, download history

### Distinctions that matter

**Junta de Vecinos**:
The neighbourhood association — an organisation with members, an address and a president. It is
a Place, not a boundary, and it is not the same thing as the Unidad Vecinal it is named after.
_Avoid_: using "unidad vecinal" to mean the organisation

### Failure modes

**Throw at the write door, null at the read helper.**
A mapper's `find()` that guards a WRITE throws (`FeatureMapper::find()` — writing to a
record nobody holds must fail loudly). A mapper's read that merely ENRICHES answers
null (`DuplicateMapper::findMergeOf()`, `ImportBatchMapper::findRevertOf()`) — a read
helper has a caller that can say "then none". Batch walkers choose the fail mode by
what the batch is: an import fails HARD (the whole file is refused before a single
row is written — "imports nothing" is a promise), and a revert fails SOFT (each
record it cannot put back is left as it is and counted, because a half-undone batch
that says what it reached is worth more than an aborted one).
_Avoid_: a try/catch per caller; "it depends" as the whole answer to null-or-throw.
