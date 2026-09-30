#!/bin/bash
# Builds the installable plugin: build/minos-moderation.zip, with vendor/ inside (WordPress
# runs no composer, so the bundled client must ship in the plugin directory).
#
#   bin/build-zip.sh [output-directory]
#
# Only the runtime files go in: the entry points, src/, the licence, the README, composer's
# production autoloader and the client library's src/. Everything else of the library is
# stripped — above all its mock gateway, whose public/index.php would otherwise be a
# reachable URL on the forum's server. composer.json and composer.lock are needed only for
# the install and are removed after it: the plugin directory is served, and they would
# publish the exact dependency versions.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-$root/build}"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
plugin="$stage/minos-moderation"

mkdir -p "$plugin" "$out"
cp "$root/minos-moderation.php" "$root/uninstall.php" "$root/LICENSE" "$root/README.md" \
  "$root/composer.json" "$root/composer.lock" "$plugin/"
cp -R "$root/src" "$plugin/src"

composer install --working-dir="$plugin" --no-dev --optimize-autoloader --classmap-authoritative \
  --no-interaction --no-progress --prefer-dist >&2

rm -f "$plugin/composer.json" "$plugin/composer.lock"
library="$plugin/vendor/minos-moderation/client-php"
find "$library" -mindepth 1 -maxdepth 1 ! -name src ! -name LICENSE -exec rm -rf {} +
rm -rf "$plugin/vendor/bin"

# Guards: the zip must work and must carry nothing it should not.
test -f "$plugin/vendor/autoload.php"
test -f "$library/src/Signature.php"
if find "$plugin" \( -name mock-gateway -o -name tests -o -name .git -o -name .github \
  -o -name CLAUDE.md -o -name phpunit -o -name composer.json -o -name composer.lock \) | grep -q .; then
  echo "build-zip: a development file made it into the package" >&2
  exit 1
fi
find "$plugin" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

rm -f "$out/minos-moderation.zip"
(cd "$stage" && zip -qr -X "$out/minos-moderation.zip" minos-moderation)
echo "$out/minos-moderation.zip"
