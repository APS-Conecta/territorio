#!/usr/bin/env bash
#
# Regenerates openapi.json from the attributes and docblocks on ApiController.
#
# farmacia's script, adapted (S11's doctrine, the org's second OCS surface):
# the spec is the contract a consumer reads, so it must never be written by
# hand — a hand-edited spec drifts from the code silently, which is worse
# than no spec at all. It is generated, committed, and regenerated whenever
# the controller changes.
#
# The generator is a *dev* dependency, fetched into tools/ like the unit
# container's composer and gitignored the same way. The app itself keeps no
# PHP dependencies and no composer autoloader at runtime — that property is
# deliberate and unchanged.
#
# Usage: scripts/openapi.sh     (override with TERRITORIO_CONTAINER=…)
set -euo pipefail

CONTAINER="${TERRITORIO_CONTAINER:-territorio-dev-nextcloud-1}"
APP=/var/www/html/custom_apps/territorio

# Root, not www-data: this writes tools/ and openapi.json in the repo bind
# mount, which the host owns — www-data cannot write there (and nothing here
# touches Nextcloud's own files, so root-in-container is only the generator's).
run() { docker exec -w "$APP" -u root "$CONTAINER" "$@"; }

if ! run test -f tools/composer.phar; then
	printf 'Downloading Composer…\n'
	run mkdir -p tools
	run sh -c 'curl -sSL -o tools/composer.phar https://getcomposer.org/composer-stable.phar'
fi

if ! run test -f tools/openapi/vendor/bin/generate-spec; then
	printf 'Installing nextcloud/openapi-extractor…\n'
	run mkdir -p tools/openapi
	run sh -c 'cd tools/openapi && COMPOSER_HOME=/tmp/composer php ../composer.phar \
		require --quiet --no-interaction nextcloud/openapi-extractor'
fi

printf 'Generating openapi.json…\n'
run sh -c 'php tools/openapi/vendor/bin/generate-spec . openapi.json'

# The extractor reads a version out of composer.json, and this app deliberately
# has none — epi's exact lesson, where the published contract advertised 0.0.1
# while the app shipped 0.4.0. Taking the version from info.xml here keeps it a
# generated field rather than a hand-edited one: regenerating re-applies it
# instead of undoing it. tests/unit/MetadataTest.php fails if the two ever
# disagree again.
run sh -c 'php -r "
	\$version = (string)simplexml_load_file(\"appinfo/info.xml\")->version;
	\$spec = json_decode(file_get_contents(\"openapi.json\"), true);
	\$spec[\"info\"][\"version\"] = \$version;
	file_put_contents(\"openapi.json\", json_encode(\$spec, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL);
"'

printf '\n'
run sh -c 'php -r "\$s=json_decode(file_get_contents(\"openapi.json\"),true);
	printf(\"  %s v%s — %d routes\\n\", \$s[\"info\"][\"title\"], \$s[\"info\"][\"version\"], count(\$s[\"paths\"]));
	foreach (array_keys(\$s[\"paths\"]) as \$p) { printf(\"    %s\\n\", \$p); }"'
