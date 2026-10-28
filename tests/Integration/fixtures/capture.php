<?php

/*
 * The webhook address the mock gateway delivers to in the integration suite, run by PHP's
 * built-in server as a router:
 *
 *   php -S 127.0.0.1:<port> tests/Integration/fixtures/capture.php
 *
 * It keeps every delivery exactly as it arrived (the raw body, the method, the path and the
 * headers) as one file in MINOS_CAPTURE_DIR, and answers 200. The test then serves each one,
 * byte for byte, through WordPress's own REST server in its process, where the database
 * transaction of the test is.
 */

declare(strict_types=1);

$dir = (string)getenv('MINOS_CAPTURE_DIR');
$headers = [];
foreach ($_SERVER as $name => $value) {
    if (strpos($name, 'HTTP_') === 0 || in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
        $headers[$name] = (string)$value;
    }
}
$delivery = [
    'method'  => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
    'path'    => (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
    'headers' => $headers,
    'body'    => base64_encode((string)file_get_contents('php://input')),
];
// Named by arrival, so the test serves them in order.
$name = sprintf('%s/%020.6f-%s.json', $dir, microtime(true), bin2hex(random_bytes(4)));
file_put_contents($name, json_encode($delivery), LOCK_EX);
http_response_code(200);
