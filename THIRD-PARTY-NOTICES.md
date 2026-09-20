# Third-party notices — territorio

This software is licensed under the GNU Affero General Public License v3.0 or later
(see [`LICENSE`](LICENSE)). It incorporates the third-party components listed below,
which are licensed by their own authors under their own terms. This file exists to
satisfy the attribution those licences require.

## Where this comes from

```
make notices
```

It builds, then measures the bundles and checks this file against what it found. It exits
non-zero naming every package that is inside `js/` and has no row here, so the answer to
"is this file current?" is a command and not a reading. Run it after any dependency change.
The `.map` files it reads are gitignored and exist only after a build, which is why the
target depends on `build`.

It reads three things, because no one of them is complete — and the incompleteness is the
whole history of this file:

1. **`jq -r '.sources[]' js/*.map`** — every module webpack compiled in, whether or not any
   manifest of ours mentions it. 50 packages today.
2. **`.sourcesContent[]`, the *text* of those modules.** A dependency published as an esbuild
   bundle arrives with its own dependencies already concatenated into one file, so they are
   modules of ours that no resolution of ours ever performed and no path of ours names.
   esbuild leaves a `// node_modules/.pnpm/<pkg>@<version>/…` comment at every boundary it
   merged, and that comment is the literal string that finds them. 16 more packages today,
   all inside `@geoman-io/leaflet-geoman-free`.
3. **A literal-byte probe**, for a package with no JavaScript at all. `@mapbox/maki` reaches
   the bundle as SVG markup that webpack inlines as an asset, and an asset module carries no
   source-map entry whichever loader emits it. Its bytes are in `js/` or they are not, so
   `tools/notices.sh` asks exactly that: it derives both forms webpack can emit — the markup
   itself, which is what `asset/source` leaves, and the file's base64, which is what
   `asset/inline` leaves inside a data URI — and greps the bundles for either.

Licences are read from each package's own licence **file**, never from the `license` field of
its `package.json`: `splaytree-ts` declares `"BDS-3-Clause"`, a string no SPDX list knows,
and its file is a correct BSD 3-Clause. Where a package publishes no licence file at all the
row is marked † and says so, rather than being left blank — a blank row is how a package goes
unattributed.

### What is not enough, each of which was this file's method at some point

- **`package.json`.** It names 12 packages. 67 ship. Only the manifests were read until
  2026-08-08, which is exactly how three components shipped unattributed — the words "Apache"
  and "MPL" did not appear in this file while a dual-licensed component was in every page
  load. The organisation's docs gate
  ([`APS-Conecta/repo-docs`](https://github.com/APS-Conecta/repo-docs)) can summarise the
  manifests with `docs.py licences territorio`, but it reads manifests only and would not have
  caught it either.
- **`js/territorio-main.js.LICENSE.txt` and `js/territorio-admin.js.LICENSE.txt`.** Webpack
  writes an entry there only for code carrying a `/*!` banner, and most packages carry none.
  Those two files are still committed and still ship — they are notices that travel inside the
  distribution — but as a *census* they saw 18 of 51 when #87 was filed. Reconciling this file
  against them, which is what this section told you to do until today, reproduces that gap
  forever.
- **The source maps alone.** They cannot see the 16 packages inlined into a published `dist`,
  and they cannot see a package that ships as bytes rather than as a module.

### Two corrections to what this section used to say

**The `node_modules/.pnpm/…` path is real, and it is not a path in this repository.** This repo
installs with npm — `package-lock.json`, no `pnpm-lock.yaml`, no `node_modules/.pnpm`. That path
is a *string inside `@geoman-io/leaflet-geoman-free`'s published `dist`*, left there by the
esbuild run on its authors' machine. It therefore appears in the map's `.sourcesContent[]` and
never in its `.sources[]`: the earlier claim that "`js/territorio-main.js.map` names
`node_modules/.pnpm/splaytree-ts@1.0.2/…` outright" was true of the file and false of the field
anyone would look in. Following that up is what turned three inlined packages into the sixteen
listed below.

**Deriving the package from a source path needs the *last* `/node_modules/`, not any.** A
checkout whose `node_modules` is a symlink out of the tree produces map paths with the repo
prefix repeated inside them, and `grep -o 'node_modules/…'` over those invents a package called
`opt`. `tools/notices.sh` takes the last segment, which is also the right answer for a genuinely
nested `a/node_modules/b`.

### The count

Measured 2026-09-18 by `make notices`: **76 packages are compiled into `js/`, and 76 are named
here.** The tables below add up to that number — 59 from the source maps, 16 inlined, 1 probed —
and `make notices` fails if they ever do not.

Nine of those arrived in one go on 2026-09-18 with `protomaps-leaflet` (ADR-0019): the library
itself, `@protomaps/basemaps`, `pmtiles`, `pbf`, `@mapbox/point-geometry`, `@mapbox/vector-tile`,
`color2k`, `potpack` and `fflate`. `make notices` named all nine on the first run after the
install, which is the check doing its job — one `npm install` is nine attribution obligations.

`vue` is named as well and is not one of the 67: `import { createApp } from 'vue'` resolves to a
bundler build that re-exports `@vue/runtime-dom`, and what reaches `js/` is those four `@vue/*`
packages, each MIT, each listed. The wrapper leaves no module of its own behind.

`composer.json` declares no runtime dependency, so this app ships no third-party PHP: `phpunit`
and `nextcloud/ocp` are `require-dev` and are not distributed.

## Declared in `package.json`, and compiled into `js/`

| Licence | Component |
|---|---|
| AGPL-3.0-or-later † | `@nextcloud/vue` |
| BSD-2-Clause | `leaflet` |
| BSD-3-Clause | `protomaps-leaflet` |
| CC0-1.0 | `@mapbox/maki` |
| GPL-3.0-or-later | `@nextcloud/axios` |
| GPL-3.0-or-later | `@nextcloud/initial-state` |
| GPL-3.0-or-later | `@nextcloud/router` |
| MIT | `@geoman-io/leaflet-geoman-free` |
| MIT | `leaflet.markercluster` |
| MIT | `@nextcloud/password-confirmation` |
| MIT | `@turf/boolean-point-in-polygon` |
| MIT | `@turf/polygonize` |
| MIT | `vue` |

`@mapbox/maki` is the probed one: eleven of its icons are imported by `src/glyphs.js` and are
inlined into the bundle as assets rather than as modules, so no source map names it. `@nextcloud/password-confirmation`
publishes a REUSE-style `LICENSES/` directory holding `MIT.txt` and `CC0-1.0.txt`.

## Also compiled into `js/`, named by no manifest of ours

Transitive: we did not choose them, they are in the bundles regardless, so they are owed the same
attribution. Every one of these is named by `.sources[]` in a bundle's source map.

| Licence | Component |
|---|---|
| BSD-3-Clause | `@mapbox/vector-tile` |
| BSD-3-Clause † | `@protomaps/basemaps` |
| BSD-3-Clause | `pbf` |
| BSD-3-Clause † | `pmtiles` |
| ISC | `@mapbox/point-geometry` |
| ISC | `potpack` |
| MIT | `color2k` |
| MIT | `fflate` |
| GPL-3.0-or-later | `@nextcloud/auth` |
| GPL-3.0-or-later | `@nextcloud/browser-storage` |
| GPL-3.0-or-later | `@nextcloud/capabilities` |
| GPL-3.0-or-later | `@nextcloud/l10n` |
| GPL-3.0-or-later | `@nextcloud/logger` |
| GPL-3.0-or-later † | `@nextcloud/event-bus` |
| ISC | `semver` |
| MIT | `axios` |
| MIT | `css-loader` |
| MIT | `debounce` |
| MIT | `escape-html` |
| MIT | `@floating-ui/core` |
| MIT | `@floating-ui/dom` |
| MIT | `@floating-ui/utils` |
| MIT | `floating-vue` |
| MIT | `focus-trap` |
| MIT | `linkifyjs` |
| MIT | `@nextcloud/vue-select` |
| MIT | `point-in-polygon-hao` |
| MIT | `process` |
| MIT | `splitpanes` |
| MIT | `style-loader` |
| MIT | `tabbable` |
| MIT | `@turf/bbox` |
| MIT | `@turf/bbox-polygon` |
| MIT | `@turf/envelope` |
| MIT | `@turf/helpers` |
| MIT | `@turf/invariant` |
| MIT | `@turf/meta` |
| MIT | `vue-loader` |
| MIT | `@vue/reactivity` |
| MIT | `vue-router` |
| MIT | `@vue/runtime-core` |
| MIT | `@vue/runtime-dom` |
| MIT | `@vue/shared` |
| MIT | `@vueuse/components` |
| MIT | `@vueuse/core` |
| MIT | `@vueuse/shared` |
| MPL-2.0 OR Apache-2.0 | `dompurify` |
| Unlicense | `robust-predicates` |

`css-loader`, `style-loader`, `vue-loader` and `process` are build-time packages whose *emitted
runtime helpers* genuinely ship: the source maps name `style-loader/dist/runtime/injectStylesIntoStyleTag.js`,
`css-loader/dist/runtime/api.js`, `vue-loader/dist/exportHelper.js` and `process/browser.js`, all of
which run in the browser. A build tool that leaves nothing behind is not listed; these are listed
because they leave something behind.

## Inlined into `@geoman-io/leaflet-geoman-free`'s published `dist`

Geoman publishes a `dist` that esbuild produced with its dependencies already concatenated in, so
these arrive in our bundle without our `node_modules` ever resolving them and without any source
path of ours naming them. The version is the one esbuild recorded at the boundary, not one we
chose or can bump.

| Licence | Component | Version |
|---|---|---|
| BSD-3-Clause | `splaytree-ts` | 1.0.2 |
| MIT | `bignumber.js` | 9.3.1 |
| MIT | `lodash` | 4.18.1 |
| MIT | `polyclip-ts` | 0.16.8 |
| MIT | `rbush` | 3.0.1 |
| MIT | `sweepline-intersections` | 1.5.0 |
| MIT | `@turf/boolean-contains` | 7.3.5 |
| MIT | `@turf/boolean-point-on-line` | 7.3.5 |
| MIT | `@turf/distance` | 7.3.5 |
| MIT | `@turf/geojson-rbush` | 7.3.5 |
| MIT | `@turf/kinks` | 7.3.5 |
| MIT | `@turf/line-intersect` | 7.3.5 |
| MIT | `@turf/line-segment` | 7.3.5 |
| MIT | `@turf/line-split` | 7.3.5 |
| MIT | `@turf/nearest-point-on-line` | 7.3.5 |
| MIT | `@turf/truncate` | 7.3.5 |

Seven more are inlined there too — `point-in-polygon-hao`, `robust-predicates`, `@turf/bbox`,
`@turf/boolean-point-in-polygon`, `@turf/helpers`, `@turf/invariant`, `@turf/meta` — and are
listed in the tables above instead, because we also depend on them ourselves and ship both
copies. They are counted once.

`sweepline-intersections` is the one package in this file with no copy in a development
checkout: nothing we install depends on it, so there is no `node_modules/sweepline-intersections`
to read. Its licence was read from the `LICENSE` file inside the published tarball
(`npm pack sweepline-intersections@1.5.0`), which is MIT, Copyright (c) 2019 Rowan Winsemius.
`make notices` prints it as `NOT RESOLVED HERE` rather than guessing.

## Licence texts

Every text below is copied from the licence file of a package that ships, unmodified. MIT,
BSD-2-Clause, BSD-3-Clause and ISC all require their notice to be reproduced in a binary
redistribution, which the bundles under `js/` are; Apache-2.0 §4(a) requires a copy of the
licence to reach recipients; GPL-3.0 §4 requires the same, and its text is `LICENSES/GPL-3.0-or-later.txt`
beside this file for a reason given in that section. CC0-1.0 and the Unlicense are dedications
rather than licences with conditions: neither requires a notice, and neither is reproduced.

AGPL-3.0-or-later covers `@nextcloud/vue` and this app itself; its text is
[`LICENSE`](LICENSE) at the root of this repository and ships with the app, so it is not
duplicated here.

### MIT

MIT requires its copyright notice and its permission notice to be included in all copies of the
software — the bundles under `js/` are copies — so both are here: every MIT component's own
copyright line, then the permission text, which is one text they all carry. Copyright lines are
copied from each package's licence file by `tools/notices.sh`'s own reading of it, not retyped.

```
color2k
    Copyright (c) 2020 Rico Kahler
fflate
    Copyright (c) 2026 Arjun Barrett
leaflet.markercluster
    Copyright 2012 David Leaver
bignumber.js
    Copyright © `<2025>` `Michael Mclaughlin`
escape-html
    Copyright (c) 2012-2013 TJ Holowaychuk
    Copyright (c) 2015 Andreas Lubbe
    Copyright (c) 2015 Tiancheng "Timothy" Gu
process
    Copyright (c) 2013 Roman Shtylman <shtylman@gmail.com>
axios
    Copyright (c) 2014-present Matt Zabriskie & Collaborators
focus-trap
    Copyright (c) 2015-2016 David Clark
tabbable
    Copyright (c) 2015 David Clark
@nextcloud/vue-select
    Copyright (c) 2016 Jeff Sagal & vue-select contributors
rbush
    Copyright (c) 2016 Vladimir Agafonkin
@geoman-io/leaflet-geoman-free
    Copyright (c) 2017 Sumit Kumar
@turf/bbox, @turf/bbox-polygon, @turf/boolean-contains, @turf/boolean-point-in-polygon, @turf/boolean-point-on-line, @turf/distance, @turf/envelope, @turf/geojson-rbush, @turf/helpers, @turf/invariant, @turf/kinks, @turf/line-intersect, @turf/line-segment, @turf/line-split, @turf/meta, @turf/nearest-point-on-line, @turf/polygonize, @turf/truncate
    Copyright (c) 2017 TurfJS
splitpanes
    Copyright (c) 2018 Antoni Andre
floating-vue
    Copyright (c) 2018 Guillaume Chau (alias Akryum)
vue, @vue/reactivity, @vue/runtime-core, @vue/runtime-dom, @vue/shared
    Copyright (c) 2018-present, Yuxi (Evan) You
@vueuse/components, @vueuse/core, @vueuse/shared
    Copyright (c) 2019-PRESENT Anthony Fu<https://github.com/antfu>
vue-router
    Copyright (c) 2019-present Eduardo San Martin Morote
vue-loader
    Copyright (c) 2019-present, Yuxi (Evan) You
sweepline-intersections
    Copyright (c) 2019 Rowan Winsemius
@floating-ui/core, @floating-ui/dom, @floating-ui/utils
    Copyright (c) 2021-present Floating UI contributors
point-in-polygon-hao
    Copyright (c) 2021 Rowan Winsemius
polyclip-ts
    Copyright (c) 2022 Luiz Felipe Machado Barboza
linkifyjs
    Copyright (c) 2024 Nick Frasser
debounce
    Copyright (c) Ben Carpenter, Billy Moon, Josh Goldberg, Julian Gruber, Kristofer Selbekk, Matthew Mueller, Nathan Rajlich, Oleg Pudeyev, Stephen Mathieson, TJ Holowaychuk, suhaotian, ven
    Copyright (c) Sindre Sorhus <sindresorhus@gmail.com> (https://sindresorhus.com)
@nextcloud/password-confirmation
    Copyright (c) Nextcloud GmbH and Nextcloud contributors
      — its LICENSES/MIT.txt is the unfilled REUSE template; the holder is the one
        its SPDX-FileCopyrightText headers carry, which is what webpack copied into
        js/territorio-main.js.LICENSE.txt
css-loader, style-loader
    Copyright JS Foundation and other contributors
lodash
    Copyright OpenJS Foundation and other contributors <https://openjsf.org/>
    Based on Underscore.js, copyright Jeremy Ashkenas,
    DocumentCloud and Investigative Reporters & Editors <http://underscorejs.org/>
```

```
Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
```

### `splaytree-ts` — BSD 3-Clause, and a licence string that does not exist

It ships. Its `package.json` declares `"license": "BDS-3-Clause"` — a typo for **BSD-3-Clause**,
and not a string any SPDX list knows, so a scanner will report it as unrecognised on every run
until someone allowlists it. That is what issue #27 asked to have written down, and it is why
this file reads licence files and not manifest fields.

Its real licence is a correct, unmodified BSD 3-Clause, whose second clause requires this notice
be reproduced wherever the code is redistributed in binary form — which the bundles under `js/`
do.

It arrives through `@geoman-io/leaflet-geoman-free`, which publishes a `dist` with its own
dependencies already inlined. Issue #27 named `@turf/polygonize` as the parent; that is wrong,
and `@turf/polygonize`'s own dependency list does not reach it.

**A correction, recorded because the method is the lesson.** This section first said whether the
code reached `js/` was *"not established"*, on the evidence that the `SplayTree*` symbols appear
in neither bundle. Minification erases symbol names — that evidence could not have shown presence
even if present, which is the same mistake as grepping a file for a value that lives in a process.
What settles it survives minification: the string literals `Concurrent modification during
iteration` and `Bad state: Too many element` each appear once in `js/territorio-main.js`.

```
BSD 3-Clause License

Copyright (c) 2022, Luiz Felipe Machado Barboza
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this
   list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

3. Neither the name of the copyright holder nor the names of its
   contributors may be used to endorse or promote products derived from
   this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
```

### BSD 3-Clause — the Protomaps basemap stack

Five packages arrived with `protomaps-leaflet` under BSD-3-Clause (ADR-0019). Its second clause
requires this notice be reproduced wherever the code is redistributed in binary form, which the
bundles under `js/` are.

```
protomaps-leaflet
    Copyright 2021-2024 Protomaps LLC
@mapbox/vector-tile
    Copyright (c) 2024, Mapbox
pbf
    Copyright (c) 2024, Mapbox
@protomaps/basemaps †
    no licence file published; `package.json` declares BSD-3-Clause
pmtiles †
    no licence file published; `package.json` declares BSD-3-Clause
```

**The two marked †** are the case this file's own rule was written for: they ship no licence file
at all, so there is no text of theirs to read and none to copy. Their `package.json` declares
`BSD-3-Clause`, and that declaration is all the evidence there is — recorded as a declaration
rather than as a reading, because the distinction is the whole reason this file reads files. Both
are published by Protomaps LLC, the same author as `protomaps-leaflet`, whose file *is* below.

The text, from `protomaps-leaflet`'s own licence file, unmodified:

```
Copyright 2021-2024 Protomaps LLC

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

3. Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
```


### BSD 2-Clause — `leaflet`

Leaflet's second clause requires the notice below be reproduced in a redistribution in binary
form, which `js/territorio-main.js` is.

```
BSD 2-Clause License

Copyright (c) 2010-2023, Volodymyr Agafonkin
Copyright (c) 2010-2011, CloudMade
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this
   list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
```

### ISC — `semver`, `@mapbox/point-geometry`, `potpack`

`semver` reaches the bundle through `@nextcloud/event-bus`, which vendors it under its own
`node_modules`. The other two arrived with `protomaps-leaflet` (ADR-0019): it does its geometry
in `@mapbox/point-geometry` and packs its label glyphs with `potpack`.

ISC is one text with three different copyright lines, so the lines are here and the text is
below once:

```
@mapbox/point-geometry
    Copyright (c) 2024, Mapbox
potpack
    Copyright (c) 2018, Mapbox
semver
    Copyright (c) Isaac Z. Schlueter and Contributors
```

`semver`'s own file is reproduced in full below; the other two carry the same permission and
disclaimer text verbatim under the copyright lines above.

```
The ISC License

Copyright (c) Isaac Z. Schlueter and Contributors

Permission to use, copy, modify, and/or distribute this software for any
purpose with or without fee is hereby granted, provided that the above
copyright notice and this permission notice appear in all copies.

THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF OR
IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
```

### The Unlicense — `robust-predicates`

A dedication to the public domain, not a licence with conditions: it requires no notice, no
attribution and no reproduction of its text. `robust-predicates` is named here because this file
is a census of what ships. Its text is `node_modules/robust-predicates/LICENSE` in a development
checkout.

### CC0-1.0 — `@mapbox/maki`

CC0 is a waiver rather than a licence with conditions: it requires no attribution and no notice.
`@mapbox/maki` is named in this file because the file is a census of what ships, not because CC0
asks for it, and its 116-line legal code is not reproduced for the same reason. It is
`node_modules/@mapbox/maki/LICENSE.txt` in a development checkout.
`@nextcloud/password-confirmation` ships a CC0-1.0 text beside its MIT one, covering its non-code
files under the REUSE convention.

### `dompurify` — dual-licensed, and the text this file reproduces

Copyright (c) Cure53 and other contributors.

DOMPurify's SPDX expression is `(MPL-2.0 OR Apache-2.0)`: it is offered under either licence and a
recipient takes the one they prefer. The banner webpack copies into the bundle phrases the same
thing as "released under the Apache license 2.0 and Mozilla Public License 2.0". The package
itself publishes one licence file and it is the Apache 2.0 text, reproduced below — Apache-2.0
§4(a) requires a copy to reach recipients, and this is that copy. Nothing here narrows the
recipient's choice; the MPL-2.0 text is at <https://www.mozilla.org/MPL/2.0/>.

```
                                 Apache License
                           Version 2.0, January 2004
                        http://www.apache.org/licenses/

   TERMS AND CONDITIONS FOR USE, REPRODUCTION, AND DISTRIBUTION

   1. Definitions.

      "License" shall mean the terms and conditions for use, reproduction,
      and distribution as defined by Sections 1 through 9 of this document.

      "Licensor" shall mean the copyright owner or entity authorized by
      the copyright owner that is granting the License.

      "Legal Entity" shall mean the union of the acting entity and all
      other entities that control, are controlled by, or are under common
      control with that entity. For the purposes of this definition,
      "control" means (i) the power, direct or indirect, to cause the
      direction or management of such entity, whether by contract or
      otherwise, or (ii) ownership of fifty percent (50%) or more of the
      outstanding shares, or (iii) beneficial ownership of such entity.

      "You" (or "Your") shall mean an individual or Legal Entity
      exercising permissions granted by this License.

      "Source" form shall mean the preferred form for making modifications,
      including but not limited to software source code, documentation
      source, and configuration files.

      "Object" form shall mean any form resulting from mechanical
      transformation or translation of a Source form, including but
      not limited to compiled object code, generated documentation,
      and conversions to other media types.

      "Work" shall mean the work of authorship, whether in Source or
      Object form, made available under the License, as indicated by a
      copyright notice that is included in or attached to the work
      (an example is provided in the Appendix below).

      "Derivative Works" shall mean any work, whether in Source or Object
      form, that is based on (or derived from) the Work and for which the
      editorial revisions, annotations, elaborations, or other modifications
      represent, as a whole, an original work of authorship. For the purposes
      of this License, Derivative Works shall not include works that remain
      separable from, or merely link (or bind by name) to the interfaces of,
      the Work and Derivative Works thereof.

      "Contribution" shall mean any work of authorship, including
      the original version of the Work and any modifications or additions
      to that Work or Derivative Works thereof, that is intentionally
      submitted to Licensor for inclusion in the Work by the copyright owner
      or by an individual or Legal Entity authorized to submit on behalf of
      the copyright owner. For the purposes of this definition, "submitted"
      means any form of electronic, verbal, or written communication sent
      to the Licensor or its representatives, including but not limited to
      communication on electronic mailing lists, source code control systems,
      and issue tracking systems that are managed by, or on behalf of, the
      Licensor for the purpose of discussing and improving the Work, but
      excluding communication that is conspicuously marked or otherwise
      designated in writing by the copyright owner as "Not a Contribution."

      "Contributor" shall mean Licensor and any individual or Legal Entity
      on behalf of whom a Contribution has been received by Licensor and
      subsequently incorporated within the Work.

   2. Grant of Copyright License. Subject to the terms and conditions of
      this License, each Contributor hereby grants to You a perpetual,
      worldwide, non-exclusive, no-charge, royalty-free, irrevocable
      copyright license to reproduce, prepare Derivative Works of,
      publicly display, publicly perform, sublicense, and distribute the
      Work and such Derivative Works in Source or Object form.

   3. Grant of Patent License. Subject to the terms and conditions of
      this License, each Contributor hereby grants to You a perpetual,
      worldwide, non-exclusive, no-charge, royalty-free, irrevocable
      (except as stated in this section) patent license to make, have made,
      use, offer to sell, sell, import, and otherwise transfer the Work,
      where such license applies only to those patent claims licensable
      by such Contributor that are necessarily infringed by their
      Contribution(s) alone or by combination of their Contribution(s)
      with the Work to which such Contribution(s) was submitted. If You
      institute patent litigation against any entity (including a
      cross-claim or counterclaim in a lawsuit) alleging that the Work
      or a Contribution incorporated within the Work constitutes direct
      or contributory patent infringement, then any patent licenses
      granted to You under this License for that Work shall terminate
      as of the date such litigation is filed.

   4. Redistribution. You may reproduce and distribute copies of the
      Work or Derivative Works thereof in any medium, with or without
      modifications, and in Source or Object form, provided that You
      meet the following conditions:

      (a) You must give any other recipients of the Work or
          Derivative Works a copy of this License; and

      (b) You must cause any modified files to carry prominent notices
          stating that You changed the files; and

      (c) You must retain, in the Source form of any Derivative Works
          that You distribute, all copyright, patent, trademark, and
          attribution notices from the Source form of the Work,
          excluding those notices that do not pertain to any part of
          the Derivative Works; and

      (d) If the Work includes a "NOTICE" text file as part of its
          distribution, then any Derivative Works that You distribute must
          include a readable copy of the attribution notices contained
          within such NOTICE file, excluding those notices that do not
          pertain to any part of the Derivative Works, in at least one
          of the following places: within a NOTICE text file distributed
          as part of the Derivative Works; within the Source form or
          documentation, if provided along with the Derivative Works; or,
          within a display generated by the Derivative Works, if and
          wherever such third-party notices normally appear. The contents
          of the NOTICE file are for informational purposes only and
          do not modify the License. You may add Your own attribution
          notices within Derivative Works that You distribute, alongside
          or as an addendum to the NOTICE text from the Work, provided
          that such additional attribution notices cannot be construed
          as modifying the License.

      You may add Your own copyright statement to Your modifications and
      may provide additional or different license terms and conditions
      for use, reproduction, or distribution of Your modifications, or
      for any such Derivative Works as a whole, provided Your use,
      reproduction, and distribution of the Work otherwise complies with
      the conditions stated in this License.

   5. Submission of Contributions. Unless You explicitly state otherwise,
      any Contribution intentionally submitted for inclusion in the Work
      by You to the Licensor shall be under the terms and conditions of
      this License, without any additional terms or conditions.
      Notwithstanding the above, nothing herein shall supersede or modify
      the terms of any separate license agreement you may have executed
      with Licensor regarding such Contributions.

   6. Trademarks. This License does not grant permission to use the trade
      names, trademarks, service marks, or product names of the Licensor,
      except as required for reasonable and customary use in describing the
      origin of the Work and reproducing the content of the NOTICE file.

   7. Disclaimer of Warranty. Unless required by applicable law or
      agreed to in writing, Licensor provides the Work (and each
      Contributor provides its Contributions) on an "AS IS" BASIS,
      WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or
      implied, including, without limitation, any warranties or conditions
      of TITLE, NON-INFRINGEMENT, MERCHANTABILITY, or FITNESS FOR A
      PARTICULAR PURPOSE. You are solely responsible for determining the
      appropriateness of using or redistributing the Work and assume any
      risks associated with Your exercise of permissions under this License.

   8. Limitation of Liability. In no event and under no legal theory,
      whether in tort (including negligence), contract, or otherwise,
      unless required by applicable law (such as deliberate and grossly
      negligent acts) or agreed to in writing, shall any Contributor be
      liable to You for damages, including any direct, indirect, special,
      incidental, or consequential damages of any character arising as a
      result of this License or out of the use or inability to use the
      Work (including but not limited to damages for loss of goodwill,
      work stoppage, computer failure or malfunction, or any and all
      other commercial damages or losses), even if such Contributor
      has been advised of the possibility of such damages.

   9. Accepting Warranty or Additional Liability. While redistributing
      the Work or Derivative Works thereof, You may choose to offer,
      and charge a fee for, acceptance of support, warranty, indemnity,
      or other liability obligations and/or rights consistent with this
      License. However, in accepting such obligations, You may act only
      on Your own behalf and on Your sole responsibility, not on behalf
      of any other Contributor, and only if You agree to indemnify,
      defend, and hold each Contributor harmless for any liability
      incurred by, or claims asserted against, such Contributor by reason
      of your accepting any such warranty or additional liability.

   END OF TERMS AND CONDITIONS

   APPENDIX: How to apply the Apache License to your work.

      To apply the Apache License to your work, attach the following
      boilerplate notice, with the fields enclosed by brackets "[]"
      replaced with your own identifying information. (Don't include
      the brackets!)  The text should be enclosed in the appropriate
      comment syntax for the file format. We also recommend that a
      file or class name and description of purpose be included on the
      same "printed page" as the copyright notice for easier
      identification within third-party archives.

   Copyright [yyyy] [name of copyright owner]

   Licensed under the Apache License, Version 2.0 (the "License");
   you may not use this file except in compliance with the License.
   You may obtain a copy of the License at

       http://www.apache.org/licenses/LICENSE-2.0

   Unless required by applicable law or agreed to in writing, software
   distributed under the License is distributed on an "AS IS" BASIS,
   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
   See the License for the specific language governing permissions and
   limitations under the License.
```

### GPL-3.0-or-later — the Nextcloud packages

`@nextcloud/auth`, `@nextcloud/axios`, `@nextcloud/browser-storage`, `@nextcloud/capabilities`,
`@nextcloud/event-bus`, `@nextcloud/initial-state`, `@nextcloud/l10n`, `@nextcloud/logger` and
`@nextcloud/router` are GPL-3.0-or-later, and GPL-3 §4 requires a copy of the licence to travel
with the program. It does: **[`LICENSES/GPL-3.0-or-later.txt`](LICENSES/GPL-3.0-or-later.txt)**,
a byte-for-byte copy of `@nextcloud/router`'s own `LICENSE`, committed and shipped with the app.
Seven of the eight packages that publish the file publish byte-identical copies of it;
`@nextcloud/axios` ships an older copy differing only in `http://fsf.org/` for `https://fsf.org/`.

It is the one text here that sits beside this file rather than inside it, and the reason is worth
recording rather than hiding: the organisation's docs gate reads every fenced block in a governed
`.md` as commands, and the GPL says "make sure", "make you", "make such" — eight phrases it
reports as `make` targets that do not exist. Modifying a licence text to please a linter is not an
option, and silencing a rule that is right the rest of the time is worse, so the text is a `.txt`
file, which is how it travels anyway.

## Copyleft components

`@nextcloud/vue` is AGPL-3.0-or-later and is compiled into the bundles under `js/`.
The Nextcloud packages under GPL-3.0-or-later are likewise linked into them. This is why
this app is AGPL-3.0-or-later and could not be licensed otherwise: the obligation is
inherited from what it links, not chosen — see
[ADR-0010 in gestion](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0010-agpl-across-the-org.md).

## †  No licence file in the published package

`@nextcloud/vue` and `@nextcloud/event-bus` publish no licence file of any kind — no `LICENSE`,
no REUSE `LICENSES/` directory. Their licence here is the `license` field of their
`package.json`, which is the one source this file otherwise refuses to trust, and there is
nothing better to read. `make notices` prints them as `MANIFEST-ONLY` on every run so the
exception stays visible instead of dissolving into the table.
