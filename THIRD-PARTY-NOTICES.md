# Third-party notices — territorio

This software is licensed under the GNU Affero General Public License v3.0 or later
(see [`LICENSE`](LICENSE)). It incorporates the third-party components listed below,
which are licensed by their own authors under their own terms. This file exists to
satisfy the attribution those licences require.

## Where this comes from

Authored, from two sources, because neither alone is complete:

- **`package.json`** names what we chose to depend on. That is the first table.
- **`js/territorio-main.js.LICENSE.txt`** and **`js/territorio-admin.js.LICENSE.txt`** name what is
  actually inside the shipped files. Webpack writes them next to each bundle, from the banners the
  components carry, so they see transitive code that no manifest of ours mentions. That is the
  second table.

To bring this file up to date after a dependency change: `make build`, then read those two banner
files and reconcile both tables against them.

Only the manifests were read until 2026-08-08, which is exactly how three components shipped
unattributed — the words "Apache" and "MPL" did not appear in this file while a dual-licensed
component was in every page load. The organisation's docs gate
([`APS-Conecta/repo-docs`](https://github.com/APS-Conecta/repo-docs)) can summarise the manifests
with `docs.py licences territorio`, but it reads manifests only and would not have caught it either.

`composer.json` declares no runtime dependency, so this app ships no third-party PHP: `phpunit`
and `nextcloud/ocp` are `require-dev` and are not distributed.

## Declared dependencies, compiled into `js/`

| Licence | Component |
|---|---|
| AGPL-3.0-or-later | `@nextcloud/vue` |
| BSD-2-Clause | `leaflet` |
| CC0-1.0 | `@mapbox/maki` |
| GPL-3.0-or-later | `@nextcloud/axios` |
| GPL-3.0-or-later | `@nextcloud/initial-state` |
| GPL-3.0-or-later | `@nextcloud/router` |
| MIT | `@geoman-io/leaflet-geoman-free` |
| MIT | `@nextcloud/password-confirmation` |
| MIT | `@turf/boolean-point-in-polygon` |
| MIT | `@turf/polygonize` |
| MIT | `leaflet.markercluster` |
| MIT | `vue` |

## Also compiled into `js/`, named by no manifest of ours

Transitive: we did not choose them, and they are in the bundles regardless, so they are owed the
same attribution.

| Licence | Component | Arrives with |
|---|---|---|
| MPL-2.0 OR Apache-2.0 | `dompurify` 3.4.13 | `@nextcloud/vue`, `@nextcloud/password-confirmation` |
| MIT | `focus-trap` 8.2.2 | `@nextcloud/vue` |
| MIT | `tabbable` 6.5.0 | `focus-trap` |
| MIT | `escape-html` 1.0.3 | `@nextcloud/vue`, `@nextcloud/password-confirmation` |

`@vue/runtime-dom` and `@vue/shared` also carry their own banners in the bundle; they are Vue's own
packages, published under the same MIT licence as `vue` above.

## DOMPurify — dual-licensed

Copyright (c) Cure53 and other contributors.

DOMPurify's own SPDX expression is `(MPL-2.0 OR Apache-2.0)`: it is offered under either licence and
a user takes the one they prefer. The banner webpack copies into the bundle phrases the same thing
as "released under the Apache license 2.0 and Mozilla Public License 2.0". Full texts of both are in
`node_modules/dompurify/LICENSE` in a development checkout.

## Leaflet — BSD 2-Clause

Copyright (c) 2010-2023, Volodymyr Agafonkin
Copyright (c) 2010-2011, CloudMade
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are
permitted provided that the following conditions are met:

 1. Redistributions of source code must retain the above copyright notice, this list of
    conditions and the following disclaimer.

 2. Redistributions in binary form must reproduce the above copyright notice, this list
    of conditions and the following disclaimer in the documentation and/or other materials
    provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY
EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL
THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT
OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)
HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR
TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

## Copyleft components

`@nextcloud/vue` is AGPL-3.0-or-later and is compiled into the bundles under `js/`.
The Nextcloud packages under GPL-3.0-or-later are likewise linked into them. This is why
this app is AGPL-3.0-or-later and could not be licensed otherwise: the obligation is
inherited from what it links, not chosen — see
[ADR-0010 in gestion](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0010-agpl-across-the-org.md).

Full licence texts for every component above are in `node_modules/<name>/LICENSE` in a
development checkout.

## One component a licence scanner will flag, wrongly

`splaytree-ts` 1.0.2 declares `"license": "BDS-3-Clause"` in its own `package.json` — a typo
for **BSD-3-Clause**. Its `LICENSE` file is a correct, unmodified BSD 3-Clause text
(© 2022 Luiz Felipe Machado Barboza), so the dependency tree is clean and nothing here is
owed a different notice. Recorded because the string is not a licence any SPDX list knows,
and a scanner will report it as unrecognised on every run until someone allowlists it.

It reaches the closure transitively, through
`@geoman-io/leaflet-geoman-free` → `polyclip-ts` → `splaytree-ts`. Issue #27 named
`@turf/polygonize` as the parent; that is wrong — `@turf/polygonize`'s dependencies are the
other `@turf/*` packages, `@types/geojson` and `tslib`, and none of them reach it.

**Not established:** whether its code is compiled into `js/`. The `SplayTree*` symbols do not
appear in either bundle, but the bundles are minified, so their absence is not evidence. It is
listed here rather than in a table above for that reason — the tables above state what ships,
and this entry does not know.
