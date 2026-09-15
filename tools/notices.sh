#!/bin/sh
# What is actually inside js/, measured — and whether THIRD-PARTY-NOTICES.md names it.
#
# Run it through `make notices`, which builds first: the `.map` files this reads are
# gitignored and only exist after a build.
#
# Three sources, because no one of them is complete, and the incompleteness is the
# whole history of that file:
#
#   1. `.sources[]` of the bundles' own source maps — every module webpack compiled
#      in, whether or not any manifest of ours mentions it. A manifest walk cannot
#      do this: `package.json` names 12 packages and 67 ship.
#   2. `.sourcesContent[]` — the *text* of those modules. A dependency published as
#      an esbuild bundle (`@geoman-io/leaflet-geoman-free`) arrives with its own
#      dependencies already concatenated into one file, so they are modules of ours
#      that no resolution of ours ever performed and no source of ours names. esbuild
#      leaves a `// node_modules/…` comment at each boundary it merged — carrying the
#      version too, `.pnpm/<pkg>@<version>/`, when the parent was bundled under pnpm as
#      this one was — and that comment is the literal string that finds them.
#   3. A literal-byte probe, for a package with no JavaScript at all: `@mapbox/maki`
#      reaches the bundle as SVG markup, inlined by webpack as an asset. An asset
#      module carries no source-map entry whichever loader emits it, so nothing above
#      can see it. Its bytes are in `js/` or they are not.
#
# Licences are read from each package's own licence FILE, never from the `license`
# field: `splaytree-ts` declares `"BDS-3-Clause"`, a string no SPDX list knows, and
# its file is a correct BSD 3-Clause. A package with no licence file is printed as
# MANIFEST-ONLY rather than skipped — a blank row is how a package goes unattributed.
set -eu

cd "$(dirname "$0")/.."

NOTICES=THIRD-PARTY-NOTICES.md

# Every package whose bytes must be in the bundle although nothing names it as a
# module. One line per package: <package> <file whose bytes are encoded into js/>.
# The probe is derived from the file, not a hardcoded literal, so it keeps working
# across versions and fails loudly if the package stops shipping.
PROBES='@mapbox/maki node_modules/@mapbox/maki/icons/circle-stroked.svg'

# Both forms webpack can emit an inlined asset in, derived from the file rather than
# written down: its longest run of characters a JS string carries unescaped (what
# `asset/source` leaves, which is what `webpack.config.js` asks for), and the base64 of
# the whole file (what `asset/inline` leaves as a data URI). Checking both means the
# probe survives a loader change instead of going quiet or crying wolf at one.
# An EMPTY line here would be a pattern `grep -f` matches against everything, which is a
# probe that passes without looking: neither form is printed unless it has content, and a
# file that yields neither fails the probe rather than sailing through it.
probe_forms() {
	grep -o "[^<>\"'\\\\]\{40,\}" "$1" | awk '{ if (length($0) > length(b)) b = $0 } END { if (length(b)) print b }'
	base64 -w0 "$1" | grep . || true
}

maps=$(ls js/*.js.map 2>/dev/null || true)
[ -n "$maps" ] || { echo "notices: no js/*.js.map — run 'make build' first." >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# The package that owns a path: everything after the LAST `node_modules/`, cut to one
# segment, or two when scoped. The last one and not the first — a nested
# `a/node_modules/b` belongs to b, and a checkout whose node_modules is a symlink out of
# the tree produces paths with the repo prefix repeated inside them.
owner() { sed -E -n 's#^(.*/)?node_modules/##p' | sed -E 's#^(@[^/]+/[^/]+|[^/]+)(/.*)?$#\1#'; }

# shellcheck disable=SC2086  # $maps is a deliberate word-split list of files
jq -r '.sources[]' $maps | owner | sort -u > "$work/mapped"

# shellcheck disable=SC2086
jq -r '
	.sources as $s
	| (.sourcesContent // [])
	| to_entries[]
	| select(.value != null and (.value | test("// node_modules/")))
	| ($s[.key]) as $parent
	| (.value | [scan("// (node_modules/(?:\\.pnpm/[^/]+/node_modules/)?(?:@[^/]+/[^/]+|[^@/][^/]*))")] | flatten | unique)[]
	| $parent + "\t" + .
' $maps | sort -u > "$work/inlined-raw"

# <package>\t<version>\t<parent package>
while IFS="$(printf '\t')" read -r parent path; do
	[ -n "${path:-}" ] || continue
	pkg=$(printf '%s\n' "$path" | owner)
	# The version is esbuild's own record of what it merged, when the parent was bundled
	# under pnpm and the breadcrumb carries it. A parent bundled under npm leaves the
	# package name alone; the package still ships and is still listed, unversioned.
	version=$(printf '%s\n' "$path" | sed -n 's#^node_modules/\.pnpm/\(.*\)/node_modules/.*#\1#p' | sed 's#.*@##')
	[ -n "$version" ] || version='unrecorded'
	printf '%s\t%s\t%s\n' "$pkg" "$version" "$(printf '%s\n' "$parent" | owner)"
done < "$work/inlined-raw" | sort -u > "$work/inlined"

# A gate that measures nothing passes silently. 12 packages are declared and every
# one of them pulls transitives, so a plausible bundle names dozens; a handful means
# the maps were not read, not that the bundle shrank.
mapped_count=$(wc -l < "$work/mapped")
[ "$mapped_count" -ge 20 ] || {
	echo "notices: the source maps named only $mapped_count packages — that is not a built bundle." >&2
	exit 1
}

licence() {
	dir="node_modules/$1"
	[ -d "$dir" ] || {
		printf 'NOT RESOLVED HERE — inlined only, licence read from the package itself'
		return
	}
	file=$(ls -1 "$dir" | grep -iE '^(mit-)?(licen[sc]e|copying)' | head -1 || true)
	[ -n "$file" ] || {
		printf 'MANIFEST-ONLY — no licence file; package.json says %s' \
			"$(jq -r '.license // "nothing"' "$dir/package.json")"
		return
	}
	# REUSE-style LICENSES/ directory: the file names are the SPDX identifiers.
	[ -d "$dir/$file" ] && { printf '%s/ — %s' "$file" "$(ls -1 "$dir/$file" | tr '\n' ' ')"; return; }
	printf '%s — %s' "$file" "$(grep -m1 -v '^[[:space:]]*$' "$dir/$file" | sed 's/^[[:space:]]*//' | cut -c1-60)"
}

echo "Compiled into js/, named by the bundles' source maps ($mapped_count):"
while read -r pkg; do
	printf '  %-34s %s\n' "$pkg" "$(licence "$pkg")"
done < "$work/mapped"

echo
echo "Inlined into a published dist by its parent, named by the esbuild breadcrumbs in it:"
cut -f1 "$work/inlined" | sort -u > "$work/inlined-names"
while IFS="$(printf '\t')" read -r pkg version parent; do
	printf '  %-24s %-10s inside %-32s %s\n' "$pkg" "$version" "$parent" "$(licence "$pkg")"
done < "$work/inlined"

echo
echo "No module of its own — found by a literal-byte probe:"
: > "$work/probed"
: > "$work/probe-failed"
printf '%s\n' "$PROBES" | while read -r pkg file; do
	[ -n "$pkg" ] || continue
	if probe_forms "$file" | grep -q -F -f - js/*.js; then
		printf '  %-34s %s\n' "$pkg" "$(licence "$pkg")"
		printf '%s\n' "$pkg" >> "$work/probed"
	else
		printf '%s\t%s\n' "$pkg" "$file" >> "$work/probe-failed"
	fi
done

# A probe that stops matching is never good news: either the package stopped shipping, and
# the file has a row for something it does not redistribute, or the bundling of it changed
# and this script has gone blind to it. Both need a person; neither is a pass.
if [ -s "$work/probe-failed" ]; then
	echo >&2
	while IFS="$(printf '\t')" read -r pkg file; do
		echo "notices: the bytes of $file are not in js/, so the probe for $pkg proves nothing." >&2
		echo "         Either drop it from $NOTICES, or fix the probe to match how it ships now." >&2
	done < "$work/probe-failed"
	exit 1
fi

sort -u "$work/mapped" "$work/inlined-names" "$work/probed" > "$work/ship"
ship_count=$(wc -l < "$work/ship")

: > "$work/missing"
while read -r pkg; do
	grep -qF "\`$pkg\`" "$NOTICES" || printf '%s\n' "$pkg" >> "$work/missing"
done < "$work/ship"
named=$((ship_count - $(wc -l < "$work/missing")))

echo
echo "$ship_count packages ship. $NOTICES names $named of them."

# ponytail: one direction only — it cannot see a row for something that stopped
# shipping. Backticked names in that file include paths and filenames, so the reverse
# check needs the tables parsed rather than grepped; write it when a stale row
# actually survives a dependency removal.
[ -s "$work/missing" ] || exit 0
echo
echo "Not named — every one of these is redistributed with no attribution:" >&2
sed 's/^/  /' "$work/missing" >&2
exit 1
