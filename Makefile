# Local dev/test for territorio.
#
# Two harnesses, on purpose:
#   `make test`       — the deterministic core in a bare PHP container, no Nextcloud. Fast, the default.
#   `make instance-*` — a throwaway Nextcloud 34 + PG 18 instance (compose.dev.yaml) for the parts the
#                       bare container cannot run: settings pages, IAppConfig, occ. Not the gestion stack.

IMG := territorio-test
# aps-common is consumed as a path repository (../aps-common); the test
# container needs it mounted where composer resolves it from /app. The
# sibling must exist beside this repo (org layout) — aps-sync names the fix.
APS ?= $(CURDIR)/../aps-common
DOCKER := docker run --rm -v "$(CURDIR)":/app -v "$(APS)":/aps-common -w /app $(IMG)
COMPOSE := docker compose -f compose.dev.yaml
OCC := $(COMPOSE) exec --user www-data nextcloud php occ

.PHONY: test test-image test-integration test-js test-browser deps build watch lint lint-js notices clean instance-up instance-occ instance-down instance-clean aps-sync aps-drift openapi

test-image:
	docker build -q -f Dockerfile.test -t $(IMG) . >/dev/null

deps: test-image
	$(DOCKER) composer install --no-interaction --no-progress

# The tested core depends on no OCP class, so this needs no Nextcloud at all.
test: test-image
	$(DOCKER) sh -c "composer install --no-interaction --no-progress -q && vendor/bin/phpunit --testdox"

# The frontend's pure logic — the filter reducer and the Análisis counts. Needs
# no browser and no Nextcloud; it is the JS half of what `make test` is for PHP.
test-js:
	npm run test

# Round-trip the mappers against the dev instance's real Postgres. Proves what a
# mocked mapper cannot: that camelCase Entity properties reach the snake_case
# columns, with the right types. Needs `make instance-up` first.
test-integration:
	$(COMPOSE) exec --user www-data -w /var/www/html/custom_apps/territorio nextcloud \
		php vendor/bin/phpunit -c phpunit.integration.xml --testdox

# One rule that matters: a name used but never imported. `listFor` was called in
# App.vue with no import and took the whole Datos column down, past a green build
# and three green suites — none of which loads a component. Style rules are off.
lint-js:
	npx eslint src tests/js

# Drive the running app the way a person would. Needs `make instance-up` and a
# current `make build`. This is the only thing in the repo that opens the app —
# `Guardar` did nothing for eight slices and every other check passed.
test-browser:
	python3 tools/browser-check.py

# Syntax-check every PHP file. Cheap standing in for a static analyser we do not have yet.
# Must FAIL on a parse error: piping into `grep -v … || true` throws the exit status
# away and makes this target permanently green, which is worse than having no target.
#
# The ramp check rides here rather than in a fifth suite: it guards the Category
# palette's contrast and separation (docs/design/map-legibility.md), which is a
# cheap standing check that must fail loudly — exactly what this target is for.
# It runs on the host, not in $(DOCKER): that image is PHP, and this is Python.
#
# tools/openapi is pruned: `make openapi` fetches the extractor and its vendor
# tree there (gitignored, absent on CI), and that tree's own test fixtures do
# not parse under PHP 8.3 — so `make lint` failed on every box that had ever
# regenerated the spec, and passed on every box that had not.
lint: test-image
	$(DOCKER) sh -c 'find lib tests tools -path tools/openapi -prune -o -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null'
	python3 tools/ramp-check.py
	python3 -I tools/comuna-extents.py --self-test

build:
	npm run build

# What is actually inside js/, and whether THIRD-PARTY-NOTICES.md names it. Fails naming
# every package that ships without a row there.
#
# It depends on `build` because it reads the bundles' source maps, which are gitignored and
# exist only after a build — and because a stale bundle would answer for last week's
# dependencies. It runs on the host, not in $(DOCKER): that image is PHP, and this is jq.
#
# Not part of `lint`: linting is cheap and runs constantly, and this rebuilds the bundles.
# CI runs it in the js job, right after the drift check that has already built them.
notices: build
	tools/notices.sh

# Regenerate openapi.json from ApiController's attributes and docblocks
# (farmacia's script, adapted). The extractor is fetched into tools/ on first
# run; the file is committed and never hand-edited.
openapi:
	scripts/openapi.sh

watch:
	npm run watch

# Copy the aps-common package into its committed homes: vendor/aps/common/src
# (the subtree the tarball ships), the OC\Hooks\Emitter test stub, and the js
# half under src/aps/ (the committed bundle the browser code imports). The
# sibling checkout must exist beside this repo (org layout).
aps-sync:
	@test -d "$(APS)/src" || { echo "aps-common not found at $(APS) — clone the sibling first"; exit 1; }
	rm -rf vendor/aps/common/src src/aps
	mkdir -p vendor/aps/common tests/stubs/OC/Hooks src/aps
	git -C "$(APS)" archive HEAD src | tar -x -C vendor/aps/common
	cp "$(APS)/stub/OC/Hooks/Emitter.php" tests/stubs/OC/Hooks/Emitter.php
	git -C "$(APS)" archive HEAD js | tar -x -C src/aps --strip-components=1
	rm -f src/aps/*.test.js

# vendor/aps/common/src and src/aps are committed, so they can drift from the
# package the way js/ can drift from src/. `git status --porcelain` (not
# diff --exit-code) so a rename that deletes one file and adds another is
# seen — the same lesson the js/ CI arm records.
aps-drift: aps-sync
	@drift="$$(git status --porcelain -- vendor/aps/common/src src/aps tests/stubs/OC/Hooks/Emitter.php)"; \
	if [ -n "$$drift" ]; then \
		printf '%s\n' "$$drift"; \
		echo "::error::aps-common artifacts are behind the package. Run 'make aps-sync' and commit the result."; \
		exit 1; \
	fi

# js/ is committed (Nextcloud apps ship built assets), so it is not cleaned here —
# removing it would just dirty the working tree. `npm run build` overwrites it.
# vendor/aps/common/src is COMMITTED too (the js/ doctrine) — it is not
# cleaned either, removing it would just dirty the tree. git clean removes every
# untracked/ignored build product under vendor/ and leaves tracked files alone.
clean:
	git clean -xfd -- vendor
	rm -rf node_modules js/*.map .phpunit.cache

# ── Throwaway Nextcloud instance (compose.dev.yaml) ────────────────────────────────────────────
# First run pulls images and installs Nextcloud; a couple of minutes. After that, seconds.
# http://localhost:8083 — admin / dev.
# The restart after the setting is not optional, for two reasons: APCu is
# per-process (README, "Two traps"), so a value written by occ is invisible to
# the web process until it restarts; and `up --wait` warms the web workers via
# status.php BEFORE `app:enable`, so on a FRESH instance they hold a route
# collection and an enabled-apps state without this app for an hour (CachingRouter,
# 3600 s) — every /apps/territorio/ page answers 404 until they restart
# (measured on farmacia-dev, farmacia#2). Provisioning owns the step rather
# than leaving it to memory.
#
# The basemap is OpenStreetMap's own tile service, which is what the app serves when
# nothing is configured. The two deletes clear what an earlier `instance-up` pinned (a
# PMTiles archive on a tailnet host), which a persisted volume would otherwise keep serving
# to a browser that cannot reach it. The deletes are idempotent: a missing key is not an error.
#
# The status line is for the browser gate. Nextcloud's front end asks for the signed-in user's
# status on every page and answers a user who never set one with a 404, which it then logs as a
# console error; the gate counts console errors, so a fresh `admin` would fail every run on a
# message that is Nextcloud's own and says nothing about this app.
instance-up:
	$(COMPOSE) up -d --wait
	$(OCC) app:enable territorio
	$(OCC) config:app:delete territorio tile_url
	$(OCC) config:app:delete territorio tile_attribution
	$(COMPOSE) restart nextcloud
	$(COMPOSE) up -d --wait
	$(COMPOSE) exec -T nextcloud curl -s -o /dev/null -u admin:dev -H 'OCS-APIRequest: true' \
		-X PUT http://localhost/ocs/v2.php/apps/user_status/api/v1/user_status/status -d statusType=online
	@echo "Instance ready at http://localhost:8083 (admin / dev)"

# Run any occ command:  make instance-occ CMD="config:app:get territorio comuna_cut"
instance-occ:
	$(OCC) $(CMD)

instance-down:
	$(COMPOSE) down

# Wipe the instance including its database — next instance-up installs from scratch.
instance-clean:
	$(COMPOSE) down -v
