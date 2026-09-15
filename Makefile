# Local dev/test for territorio.
#
# Two harnesses, on purpose:
#   `make test`       — the deterministic core in a bare PHP container, no Nextcloud. Fast, the default.
#   `make instance-*` — a throwaway Nextcloud 34 + PG 18 instance (compose.dev.yaml) for the parts the
#                       bare container cannot run: settings pages, IAppConfig, occ. Not the gestion stack.

IMG := territorio-test
DOCKER := docker run --rm -v "$(CURDIR)":/app -w /app $(IMG)
COMPOSE := docker compose -f compose.dev.yaml
OCC := $(COMPOSE) exec --user www-data nextcloud php occ

.PHONY: test test-image test-integration test-js test-browser deps build watch lint lint-js notices clean instance-up instance-occ instance-down instance-clean

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
lint: test-image
	$(DOCKER) sh -c 'find lib tests tools -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null'
	python3 tools/ramp-check.py

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

watch:
	npm run watch

# js/ is committed (Nextcloud apps ship built assets), so it is not cleaned here —
# removing it would just dirty the working tree. `npm run build` overwrites it.
clean:
	rm -rf vendor node_modules js/*.map .phpunit.cache

# ── Throwaway Nextcloud instance (compose.dev.yaml) ────────────────────────────────────────────
# First run pulls images and installs Nextcloud; a couple of minutes. After that, seconds.
# http://localhost:8083 — admin / dev.
instance-up:
	$(COMPOSE) up -d --wait
	$(OCC) app:enable territorio
	@echo "Instance ready at http://localhost:8083 (admin / dev)"

# Run any occ command:  make instance-occ CMD="config:app:get territorio tile_url"
instance-occ:
	$(OCC) $(CMD)

instance-down:
	$(COMPOSE) down

# Wipe the instance including its database — next instance-up installs from scratch.
instance-clean:
	$(COMPOSE) down -v
