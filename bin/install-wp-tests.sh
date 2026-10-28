#!/bin/bash
# Installs a real WordPress and its PHPUnit test library for the integration suite
# (tests/Integration, phpunit-integration.xml.dist), and creates the test database.
#
#   bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]
#
# The arguments are those of the standard script (`wp scaffold plugin-tests`): db-host may
# carry a port (`127.0.0.1:3306`), wp-version is a release (`6.0`, `7.1.3`) or `latest`, the
# newest one. It installs into WP_CORE_DIR (default $TMPDIR/wordpress) and WP_TESTS_DIR
# (default $TMPDIR/wordpress-tests-lib); tests/Integration/bootstrap.php reads the latter.
#
# Unlike the standard script it needs neither Subversion nor wordpress.org, and downloads
# nothing else (no database drop-in): WordPress is the release tag of its official Git
# mirror (github.com/WordPress/WordPress, the files of the release zip), the test library
# (tests/phpunit/includes and data, the sample test config) the same tag of
# github.com/WordPress/wordpress-develop. The commits it resolved are printed.
#
# The database is created when missing, never dropped: WordPress's test installer drops and
# recreates its own `wptests_` tables on every run, and every test is rolled back.
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

# A release tag: digits only (`6.0`, `7.1.3`), never a branch or a beta.
if [ "$wp_version" = 'latest' ]; then
  wp_version="$(git ls-remote --tags --refs "$core_repo" \
    | sed 's|.*refs/tags/||' | grep -E '^[0-9]+\.[0-9]+(\.[0-9]+)?$' | sort -V | tail -n 1)"
fi
if ! printf '%s' "$wp_version" | grep -Eq '^[0-9]+\.[0-9]+(\.[0-9]+)?$'; then
  echo "not a WordPress release: '$wp_version'" >&2
  exit 1
fi

install_core() {
  if [ -f "$core_dir/wp-settings.php" ]; then
    echo "WordPress already in $core_dir: $(sed -n "s/^\$wp_version = '\(.*\)';/\1/p" "$core_dir/wp-includes/version.php")"
    return
  fi
  rm -rf "$core_dir"
  git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$wp_version" "$core_repo" "$core_dir"
  echo "WordPress $wp_version: $(git -C "$core_dir" rev-parse HEAD) ($core_repo)"
  rm -rf "$core_dir/.git"
}

install_test_library() {
  if [ -f "$tests_dir/includes/bootstrap.php" ] && [ -f "$tests_dir/wp-tests-config.php" ]; then
    echo "The test library already in $tests_dir"
    return
  fi
  local checkout
  checkout="$(mktemp -d)"
  # Only the test library's paths are checked out; the rest of the tree is never fetched.
  git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$wp_version" \
    --filter=blob:none --sparse "$develop_repo" "$checkout"
  git -C "$checkout" sparse-checkout set tests/phpunit/includes tests/phpunit/data
  echo "Test library $wp_version: $(git -C "$checkout" rev-parse HEAD) ($develop_repo)"
  rm -rf "$tests_dir"
  mkdir -p "$tests_dir"
  cp -R "$checkout/tests/phpunit/includes" "$checkout/tests/phpunit/data" "$tests_dir/"
  cp "$checkout/wp-tests-config-sample.php" "$tests_dir/wp-tests-config.php"
  rm -rf "$checkout"

  # The sample's placeholders, replaced as PHP literals: a password may hold any character.
  php -- "$tests_dir/wp-tests-config.php" "$core_dir/" "$db_name" "$db_user" "$db_pass" "$db_host" <<'PHP'
<?php
[, $file, $core, $name, $user, $pass, $host] = $argv;
$config = (string)file_get_contents($file);
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

install_core
install_test_library
install_database
