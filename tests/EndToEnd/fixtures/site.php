<?php

/*
 * A stand-in WordPress site for EndToEndTest, run by PHP's built-in server as a router:
 *
 *   php -S 127.0.0.1:<port> tests/EndToEnd/fixtures/site.php
 *
 * It loads the stubbed WordPress state from MINOS_SITE_STATE, hooks the REAL plugin in,
 * dispatches a request to the REST route the plugin registered the way the REST server
 * does (the permission callback, then the handler with the raw body and the headers),
 * saves the state back and appends the answered status to MINOS_SITE_STATE.log.
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../../stubs/wordpress.php';

use Minos\WordPress\Platform;
use Minos\WordPress\Plugin;

$state = (string)getenv('MINOS_SITE_STATE');
$lock = fopen($state . '.lock', 'c');
flock($lock, LOCK_EX);
WpStub::load($state);
(new Plugin(new Platform()))->hook(__DIR__ . '/../../../minos-moderation.php');
do_action('rest_api_init');

$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$status = 404;
foreach (WpStub::$routes as $route) {
    if ($path !== '/wp-json/' . $route['namespace'] . $route['route']
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== $route['args']['methods']) {
        continue;
    }
    if (call_user_func($route['args']['permission_callback']) !== true) {
        $status = 401;
        break;
    }
    $request = new WP_REST_Request('POST', $route['route']);
    $request->set_body((string)file_get_contents('php://input'));
    foreach ($_SERVER as $name => $value) {
        if (strpos($name, 'HTTP_') === 0) {
            $request->set_header(substr($name, 5), (string)$value);
        }
    }
    $status = call_user_func($route['args']['callback'], $request)->get_status();
}

WpStub::save($state);
file_put_contents($state . '.log', $status . "\n", FILE_APPEND);
flock($lock, LOCK_UN);
http_response_code($status);
