#!/usr/bin/env bash
# Proves a release archive's properties before .github/workflows/release.yml publishes it:
# no development file inside, the licence inside, and the version the plugin declares equal
# to the tag. Every failed property is reported before the script exits 1.
#
#   .github/scripts/check-release-archive.sh <archive.zip> <tag>
#
# Needs unzip and php. By hand: bin/build-zip.sh, then this script on build/minos-moderation.zip.
set -euo pipefail

if [ "$#" -ne 2 ]; then
  echo "usage: $0 <archive.zip> <tag>" >&2
  exit 2
fi
archive="$1"
tag="$2"

# The licence's entry, and the file whose plugin header WordPress reads the version from.
licence='minos-moderation/LICENSE'
version_file='minos-moderation/minos-moderation.php'

failed=0
fail() {
  echo "check-release-archive: $*" >&2
  failed=1
}

if ! [[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.+-]+)?$ ]]; then
  echo "check-release-archive: the tag '$tag' is not v<major>.<minor>.<patch>[-<suffix>]" >&2
  exit 1
fi
expected="${tag#v}"

listing="$(unzip -Z1 "$archive")"
if [ -z "$listing" ]; then
  echo "check-release-archive: $archive lists no entry" >&2
  exit 1
fi
echo "Entries of $archive:"
printf '%s\n' "$listing"

# Development files, matched on whole path segments of every entry, case-insensitively.
absent() {
  local hits
  hits="$(grep -E -i -- "$2" <<<"$listing" || true)"
  if [ -n "$hits" ]; then
    fail "$1 in the archive:"$'\n'"$hits"
  fi
}
absent 'composer.lock' '(^|/)composer\.lock$'
absent 'a tests/ directory' '(^|/)tests(/|$)'
absent 'a phpunit file' '(^|/)\.?phpunit[^/]*(/|$)'
absent 'the mock gateway' '(^|/)mock[-_]?gateway(/|$)'
absent 'a .git* entry' '(^|/)\.git[^/]*(/|$)'
absent 'a CLAUDE.md' '(^|/)CLAUDE\.md$'
absent 'a .claude/ directory' '(^|/)\.claude(/|$)'
absent 'a .github/ directory' '(^|/)\.github(/|$)'

if ! grep -F -x -q -- "$licence" <<<"$listing"; then
  fail "the licence $licence is not in the archive"
fi

# The version as WordPress reads it: get_file_data() on the first 8 KiB of the main file.
version=''
if grep -F -x -q -- "$version_file" <<<"$listing"; then
  version="$(unzip -p "$archive" "$version_file" | php -r '
    $head = substr((string) stream_get_contents(STDIN), 0, 8192);
    if (preg_match("/^(?:[ \t]*<\?php)?[ \t\/*#@]*Version:(.*)$/mi", $head, $m)) {
        echo trim(preg_replace("/\s*(?:\*\/|\?>).*/", "", $m[1]));
    }')"
fi
if [ -z "$version" ]; then
  fail "no Version: header in $version_file of the archive (the tag is $tag)"
elif [ "$version" != "$expected" ]; then
  fail "the plugin declares Version: $version ($version_file) but the tag is $tag (expects $expected)"
fi

if [ "$failed" -ne 0 ]; then
  exit 1
fi
echo "check-release-archive: $archive holds version $version, the licence and no development file"
