# Third-party notices — territorio

This software is licensed under the GNU Affero General Public License v3.0 or later
(see [`LICENSE`](LICENSE)). It incorporates the third-party components listed below,
which are licensed by their own authors under their own terms. This file exists to
satisfy the attribution those licences require.

Generated from the dependency manifests. Regenerate with
`python3 repo-docs/scripts/docs.py licences territorio` after changing dependencies.


## Bundled JavaScript dependencies

These are compiled into the files under `js/` that ship with this app.

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
inherited from what it links, not chosen.

Full licence texts for every component above are in `node_modules/<name>/LICENSE` in a
development checkout, and the build preserves each component's own copyright banner in
the `*.LICENSE.txt` file emitted next to each bundle.
