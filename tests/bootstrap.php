<?php

/*
 * The test bootstrap: Composer's autoloader, then the WordPress stubs (they define ABSPATH,
 * which every plugin file checks before it runs).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/stubs/wordpress.php';
