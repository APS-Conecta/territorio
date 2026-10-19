# The surface other apps read is a PHP service, not REST

- **Status:** accepted (2026-08-06), recorded 2026-08-08; amended by
  [ADR-0020](0020-rest-is-the-published-surface.md)
- **Corrects a premise of:** [ADR-0001](0001-standalone-app-not-nextcloud-maps.md)

[ADR-0001](0001-standalone-app-not-nextcloud-maps.md) justified writing our own app partly on
Territorio having "a REST surface other apps read". Issue #18 built that surface and it is not
REST: it is `OCA\Territorio\Service\RegistryService`, injected into the caller's constructor, and
the app registers no OCS route at all. The reversal happened on 2026-08-06 and was recorded in
neither ADR, which left the repository's oldest decision claiming a mechanism the code forbids —
so this ADR exists to say which of the two is true.

Every consumer we have is another app on the same Nextcloud, and for those an HTTP call is the
worse mechanism on every axis: it serialises and re-parses what is already in memory, it leaves
the caller's transaction so a read cannot be consistent with the caller's own writes, and it has
to authenticate a request that only exists because we made it. Injection has none of those costs.
An OCS route would also be a decision about the *public internet*, not about app-to-app reads:
`ocs` is the mechanism that puts a handler outside the instance, and Territorio holds contact
details for community organisations.

## Consequences

- A consumer outside this Nextcloud is not served. That is the accepted cost, and adding a route
  is a decision somebody takes deliberately — it would be a new controller over the same service,
  and it would need this ADR revisited rather than a copied route block.
- [`tests/unit/RoutesTest.php`](../../tests/unit/RoutesTest.php) fails if an `ocs` section appears,
  and lists every route the app does register so that adding one has to be done on purpose.
- The judgement lives in the service, never in a controller, precisely so that a future REST
  surface would be a thin adapter over an answer that is already correct.
- `RegistryService` is the app's public API, so it is bound by the same rule as any published
  surface: it reads through `FeatureMapper::findAll()`, which is the one place that decides what
  the Registry shows, rather than restating that rule for external callers.
