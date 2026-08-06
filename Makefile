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

.PHONY: test test-image deps build watch lint clean instance-up instance-occ instance-down instance-clean

test-image:
	docker build -q -f Dockerfile.test -t $(IMG) . >/dev/null

deps: test-image
	$(DOCKER) composer install --no-interaction --no-progress

# The tested core depends on no OCP class, so this needs no Nextcloud at all.
test: test-image
	$(DOCKER) sh -c "composer install --no-interaction --no-progress -q && vendor/bin/phpunit --testdox"

# Syntax-check every PHP file. Cheap standing in for a static analyser we do not have yet.
lint: test-image
	$(DOCKER) sh -c 'find lib tests -name "*.php" -print0 | xargs -0 -n1 php -l' | grep -v "^No syntax errors" || true

build:
	npm run build

watch:
	npm run watch

clean:
	rm -rf vendor node_modules js/*.js js/*.map js/*.txt .phpunit.cache

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
