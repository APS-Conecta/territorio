# ADR-0020: REST is the published surface

- Status: accepted
- Date: 2026-09-23
- Amends: [ADR-0014](0014-injection-for-cross-app-reads.md)
- Deciders: developer
- Tags: api, ocs, org

## Context

ADR-0014 settled how apps on one instance consume each other: inject the
service, call it in-process — faster, transactional, no authentication
surface. Issue #18 went further and said "no OCS route at all", which was
the right posture when the only consumers were sibling apps we controlled.

The org has since grown a second kind of consumer: apps and integrations
that are NOT on the instance, and sibling apps that prefer the wire to
injection. farmacia (ADR — its `ApiController`, and epidemiologia's
`ADR-0006` before it) already publish typed OCS surfaces with generated
specs; territorio was the one own app with no published surface at all,
which made "how do apps talk" have two different answers across the org.

## Decision

**REST over OCS is the common published surface for cross-app reads.**

- ApiController registers exactly three `ApiRoute` routes — `counts`,
  `features/{id}`, `subcategories/{category}/{subcategory}/features` —
  over `RegistryService`, read-only, `#[NoAdminRequired]`, rate-limited.
- Routes register **by attribute**, never through an `ocs` section in
  `routes.php` — the same doctrine farmacia states at its own route table.
- The spec (`openapi.json`) is **generated** by `scripts/openapi.sh` from
  the controller's attributes and docblocks, committed, never hand-edited.
- **Injection remains available** and preferred for in-instance
  transactional calls — ADR-0014's reasons all still hold there. This ADR
  amends, not replaces: injection for transactions, REST for publication.

`RoutesTest` enforces the shape: exactly the three routes, on purpose —
the old "no OCS ever" tripwire flipped meaning rather than deleted,
because the thing it guarded against (an OCS surface arriving by copying
a route block, with no decision behind it) is still worth guarding
against.

## Consequences

- A consumer off the instance can read the Registry with an app password
  and the `OCS-APIRequest` header; no new authentication surface beyond
  OCS itself.
- The three payloads are typed in docblocks and pinned by the generated
  spec; a shape change regenerates the spec, and the committed diff is
  the contract change, reviewable as one.
- farmacia's taxonomy (resource-per-endpoint for mutable things, from
  epidemiologia's `ADR-0006`) and epi's route shape are the precedents
  this surface follows — the org's third OCS app, not a new pattern.
- `RegistryService::inSubcategory()` now splits null (no such pair) from
  `[]` (nothing filed under a real pair) so the door can 404 the first
  and 200 the second — the unknown-address discipline both siblings
  already follow.
