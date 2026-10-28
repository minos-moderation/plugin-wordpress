<?php

/*
 * The integration suite's bootstrap: WordPress's own PHPUnit test library, installed with
 * its WordPress by bin/install-wp-tests.sh (WP_TESTS_DIR, by default in the temporary
 * directory), and the plugin loaded through its main file before any other plugin, as a
 * must-use plugin would be. No stubs: tests/stubs/wordpress.php belongs to the unit suite.
 */

declare(strict_types=1);

$testsDir = getenv('WP_TESTS_DIR');
if (!is_string($testsDir) || $testsDir === '') {
    $testsDir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}
if (!is_file($testsDir . '/includes/functions.php')) {
    fwrite(STDERR, "No WordPress test library in {$testsDir}: run bin/install-wp-tests.sh first.\n");
    exit(1);
}

// WordPress's test bootstrap requires the PHPUnit Polyfills (a dev dependency).
define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname(__DIR__, 2) . '/vendor/yoast/phpunit-polyfills');

require_once $testsDir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/minos-moderation.php';
});

require $testsDir . '/includes/bootstrap.php';
