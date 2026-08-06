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
unique id. Its boundary comes from the national cadastre — the clinic reads it, never draws it.
_Avoid_: UV as a bare number, neighbourhood, barrio, junta de vecinos

**Sector**:
A division of its own territory that a CESFAM draws for itself, to assign teams. Its boundary
follows streets, it is named however that clinic names things — words, numbers or colours — and
it is independent of Unidades Vecinales: neither one nests inside the other.
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
its own and it appears in the panel and in reports at once.
_Avoid_: type, group, layer

**Subcategory**:
The second level, and the finest thing a person can switch on or off — colegio, farmacia, iglesia,
taller, plaza, ciclovía.
_Avoid_: subtype, tag, class

There is no separate "layer" object: the panel a user toggles is the Category tree itself, plus
one toggle per territorial division.

### How a boundary is expressed

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

### Distinctions that matter

**Junta de Vecinos**:
The neighbourhood association — an organisation with members, an address and a president. It is
a Place, not a boundary, and it is not the same thing as the Unidad Vecinal it is named after.
_Avoid_: using "unidad vecinal" to mean the organisation
