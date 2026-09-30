<?php

/*
 * Runs when an administrator deletes the plugin: removes its options, its comment meta and
 * its scheduled events (Minos\WordPress\Uninstaller).
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/vendor/autoload.php';

\Minos\WordPress\Uninstaller::run(new \Minos\WordPress\Platform());
