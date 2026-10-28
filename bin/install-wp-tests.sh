#!/bin/bash
# Installs a real WordPress and its PHPUnit test library for the integration suite
# (tests/Integration, phpunit-integration.xml.dist), and creates the test database.
#
#   bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]
#
# The arguments are those of the standard script (`wp scaffold plugin-tests`): db-host may
# carry a port (`127.0.0.1:3306`), wp-version is a release (`6.0`, `6.0.0`, `7.1.3`) or
# `latest`, the newest one. It installs into WP_CORE_DIR (default $TMPDIR/wordpress) and
# WP_TESTS_DIR (default $TMPDIR/wordpress-tests-lib); tests/Integration/bootstrap.php reads
# the latter.
#
# Unlike the standard script it needs neither Subversion nor wordpress.org, and downloads
# nothing else (no database drop-in). Everything comes from a release TAG, fetched by its
# full ref name, never from a branch: WordPress from its official Git mirror
# (github.com/WordPress/WordPress, the files of the release zip), the test library
# (tests/phpunit/includes and data, the sample test config) from
# github.com/WordPress/wordpress-develop. The two name the first release of a branch
# differently (`6.0` in the mirror, `6.0.0` in wordpress-develop, whose `6.0` is the moving
# 6.0 branch), so `6.0` and `6.0.0` both mean the release 6.0 here, where the standard
# script reads `6.0` as the newest test library of the 6.0 branch. A tag missing from
# either repository is refused. The tags and commits it resolved are printed.
#
# The database is created when missing, never dropped: WordPress's test installer drops and
# recreates its own `wptests_` tables on every run, and every test is rolled back. Nor is
# any directory removed but the ones it creates: an installation goes to a missing or empty
# WP_CORE_DIR / WP_TESTS_DIR, and is reused there only for the same release and test
# configuration (anything else is refused).
set -euo pipefail

if [ $# -lt 3 ]; then
  echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]" >&2
  exit 1
fi

db_name="$1"
db_user="$2"
db_pass="$3"
db_host="${4:-localhost}"
wp_version="${5:-latest}"
skip_db="${6:-false}"

tmp="${TMPDIR:-/tmp}"
tmp="${tmp%/}"
core_dir="${WP_CORE_DIR:-$tmp/wordpress}"
core_dir="${core_dir%/}"
tests_dir="${WP_TESTS_DIR:-$tmp/wordpress-tests-lib}"
tests_dir="${tests_dir%/}"

core_repo='https://github.com/WordPress/WordPress'
develop_repo='https://github.com/WordPress/wordpress-develop'

if ! printf '%s' "$db_name" | grep -Eq '^[A-Za-z0-9_]+$'; then
  echo "the database name may hold letters, digits and underscores only: '$db_name'" >&2
  exit 1
fi

# A release: digits only (`6.0`, `7.1.3`), never a branch or a beta.
if [ "$wp_version" = 'latest' ]; then
  wp_version="$(git ls-remote --tags --refs "$core_repo" \
    | sed 's|.*refs/tags/||' | grep -E '^[0-9]+\.[0-9]+(\.[0-9]+)?$' | sort -V | tail -n 1)"
fi
if ! printf '%s' "$wp_version" | grep -Eq '^[0-9]+\.[0-9]+(\.[0-9]+)?$'; then
  echo "not a WordPress release: '$wp_version'" >&2
  exit 1
fi
major_minor="$(printf '%s' "$wp_version" | cut -d. -f1-2)"
patch="$(printf '%s' "$wp_version" | cut -s -d. -f3)"
patch="${patch:-0}"
if [ "$patch" -eq 0 ]; then
  core_tag="$major_minor"
else
  core_tag="$major_minor.$patch"
fi
develop_tag="$major_minor.$patch"
for pair in "$core_repo $core_tag" "$develop_repo $develop_tag"; do
  read -r repo tag <<< "$pair"
  if ! git ls-remote --exit-code --tags "$repo" "refs/tags/$tag" > /dev/null; then
    echo "no tag $tag in $repo: not a WordPress release" >&2
    exit 1
  fi
done

# Fetches one tag of a repository, by its full ref name (`clone --branch` would take a
# branch of the same name first), into a new directory; with paths, only those are checked
# out, and the rest of the tree is never fetched.
fetch_tag() {
  local repo="$1" tag="$2" dir="$3"
  shift 3
  git init --quiet "$dir"
  git -C "$dir" remote add origin "$repo"
  if [ $# -gt 0 ]; then
    git -C "$dir" config core.sparseCheckout true
    printf '%s\n' "$@" > "$dir/.git/info/sparse-checkout"
    git -C "$dir" fetch --quiet --depth 1 --filter=blob:none origin "refs/tags/$tag"
  else
    git -C "$dir" fetch --quiet --depth 1 origin "refs/tags/$tag"
  fi
  git -C "$dir" -c advice.detachedHead=false checkout --quiet FETCH_HEAD
  echo "$repo tag $tag: commit $(git -C "$dir" rev-parse HEAD)"
}

# What this script leaves in each directory it installs, with what it installed: a
# directory without it is never removed or written into, and one with it is reused only for
# the same release and the same test configuration.
marker='.minos-install-wp-tests'

# Directories and files this run created and has not put in place yet; removed on exit.
created=()
cleanup() {
  local path
  for path in ${created[@]+"${created[@]}"}; do
    rm -rf "$path"
  done
}
trap cleanup EXIT

refuse() {
  echo "$1" >&2
  exit 1
}

# Whether a path is free for an installation: missing, or an empty directory.
vacant() {
  [ ! -e "$1" ] || { [ -d "$1" ] && [ -z "$(ls -A "$1")" ]; }
}

# Refuses a directory this script did not install that holds anything; checked for both
# before anything is fetched or written.
claimable() {
  if [ ! -f "$1/$marker" ] && ! vacant "$1"; then
    refuse "$1 is not empty and this script did not install it: choose another $2"
  fi
}

# A new directory beside a target, on the same file system, for this run to fill.
staging_for() {
  local parent
  parent="$(dirname "$1")"
  mkdir -p "$parent"
  mktemp -d "$parent/.$(basename "$1").XXXXXX"
}

# Puts a filled staging directory at its target, which is missing or empty.
place() {
  if [ -d "$2" ]; then
    rmdir "$2"
  fi
  mv "$1" "$2"
}

# Writes the test configuration from the sample: its placeholders replaced as PHP literals,
# so a password may hold any character.
write_config() {
  php -- "$1" "$2" "$core_dir/" "$db_name" "$db_user" "$db_pass" "$db_host" <<'PHP'
<?php
[, $sample, $file, $core, $name, $user, $pass, $host] = $argv;
$config = (string)file_get_contents($sample);
$values = [
    "dirname( __FILE__ ) . '/src/'" => $core,
    "__DIR__ . '/src/'"             => $core,
    "'youremptytestdbnamehere'"     => $name,
    "'yourusernamehere'"            => $user,
    "'yourpasswordhere'"            => $pass,
    "define( 'DB_HOST', 'localhost' )" => null,
];
foreach ($values as $placeholder => $value) {
    if ($value === null) {
        $config = str_replace($placeholder, "define( 'DB_HOST', " . var_export($host, true) . ' )', $config);
        continue;
    }
    $config = str_replace($placeholder, var_export($value, true), $config);
}
foreach (['youremptytestdbnamehere', 'yourusernamehere', 'yourpasswordhere', "'/src/'"] as $left) {
    if (strpos($config, $left) !== false) {
        fwrite(STDERR, "wp-tests-config.php: {$left} was not replaced\n");
        exit(1);
    }
}
file_put_contents($file, $config);
PHP
}

install_core() {
  if [ -f "$core_dir/$marker" ]; then
    local installed
    installed="$(sed -n "s/^\$wp_version = '\(.*\)';/\1/p" "$core_dir/wp-includes/version.php")"
    if [ "$installed" != "$core_tag" ]; then
      refuse "$core_dir holds WordPress $installed, not $core_tag: remove it to install another release"
    fi
    echo "WordPress $installed already in $core_dir"
    return
  fi
  local stage
  stage="$(staging_for "$core_dir")"
  created+=("$stage")
  fetch_tag "$core_repo" "$core_tag" "$stage"
  rm -rf "$stage/.git"
  printf 'wordpress %s\n' "$core_tag" > "$stage/$marker"
  place "$stage" "$core_dir"
}

install_test_library() {
  if [ -f "$tests_dir/$marker" ]; then
    local installed wanted
    installed="$(sed -n 's/^test-library //p' "$tests_dir/$marker")"
    if [ "$installed" != "$develop_tag" ]; then
      refuse "$tests_dir holds the test library $installed, not $develop_tag: remove it to install another release"
    fi
    wanted="$(mktemp)"
    created+=("$wanted")
    write_config "$tests_dir/wp-tests-config-sample.php" "$wanted"
    if ! cmp -s "$wanted" "$tests_dir/wp-tests-config.php"; then
      refuse "$tests_dir/wp-tests-config.php names another database or WordPress directory: remove $tests_dir to write it again"
    fi
    echo "Test library $installed already in $tests_dir"
    return
  fi
  local checkout stage
  checkout="$(mktemp -d)"
  created+=("$checkout")
  fetch_tag "$develop_repo" "$develop_tag" "$checkout" \
    /tests/phpunit/includes/ /tests/phpunit/data/ /wp-tests-config-sample.php
  stage="$(staging_for "$tests_dir")"
  created+=("$stage")
  cp -R "$checkout/tests/phpunit/includes" "$checkout/tests/phpunit/data" "$stage/"
  cp "$checkout/wp-tests-config-sample.php" "$stage/wp-tests-config-sample.php"
  write_config "$stage/wp-tests-config-sample.php" "$stage/wp-tests-config.php"
  printf 'test-library %s\n' "$develop_tag" > "$stage/$marker"
  place "$stage" "$tests_dir"
}

install_database() {
  if [ "$skip_db" = 'true' ]; then
    return
  fi
  local args=(--user="$db_user" --password="$db_pass")
  local host="${db_host%%:*}"
  local extra=''
  if [ "$host" != "$db_host" ]; then
    extra="${db_host#*:}"
  fi
  if [ -n "$extra" ] && printf '%s' "$extra" | grep -Eq '^[0-9]+$'; then
    args+=(--host="$host" --port="$extra" --protocol=tcp)
  elif [ -n "$extra" ]; then
    args+=(--socket="$extra")
  elif [ -n "$host" ] && [ "$host" != 'localhost' ]; then
    args+=(--host="$host" --protocol=tcp)
  fi
  mysql "${args[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$db_name\`"
  echo "Database $db_name ready"
}

claimable "$core_dir" WP_CORE_DIR
claimable "$tests_dir" WP_TESTS_DIR
install_core
install_test_library
install_database
