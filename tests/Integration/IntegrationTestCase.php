<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Integration;

use Minos\Client\Signature;
use Minos\WordPress\Meta;
use Minos\WordPress\Settings;
use Minos\WordPress\Submission;

/**
 * The plugin on a real WordPress: WordPress's own test library and a MySQL database whose
 * every change a test makes is rolled back, with the plugin loaded by its main file
 * (`bootstrap.php`). Comments come in through the comment form's handler, verdicts through
 * WordPress's own REST server.
 *
 * The gateway is the mock of `minos-moderation/client-php` on PHP's built-in server, never a
 * real one. Its worker delivers to a capture server (`fixtures/capture.php`), which keeps
 * each delivery as it arrived; the test serves it here, byte for byte, where the test's
 * database transaction is. Every comment is invented test text.
 */
abstract class IntegrationTestCase extends \WP_UnitTestCase
{
    /** A key shaped like a real one; the mock's, not a real one. */
    protected const KEY = 'wgb2b_integracja_klucz_000000000000';

    /** A webhook secret for tests only. */
    protected const SECRET = 'integracja-sekret-webhooka-0123456789';

    /** The e-mail address of the post's author (the site's own user, invented). */
    protected const AUTHOR_EMAIL = 'autor.wpisu@example.invalid';

    /** @var int The post the comments are written under. */
    protected $postId = 0;

    /** @var int Its author. */
    protected $authorId = 0;

    /** @var array<int,array<string,mixed>> The decoded body of every request the plugin sent to the gateway. */
    protected $sent = [];

    /** @var string This test's temporary directory. */
    protected $dir = '';

    /** @var array<string,string> The mock's environment, for its worker. */
    private $mockEnv = [];

    /** @var array<int,array{0:resource,1:int}> Started servers and their ports. */
    private $servers = [];

    /** @var int Visitors so far: each comment gets an address and an e-mail of its own. */
    private static $visitors = 0;

    public function set_up()
    {
        parent::set_up();
        $this->dir = sys_get_temp_dir() . '/minos-wp-integration-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/mock', 0700, true);
        mkdir($this->dir . '/deliveries', 0700, true);

        // A fresh REST server per test, one that keeps the status and the body it sent.
        $GLOBALS['wp_rest_server'] = null;
        add_filter('wp_rest_server_class', static function (): string {
            return 'Spy_REST_Server';
        });
        reset_phpmailer_instance();
        add_action('http_api_debug', function ($response, $context, $class, $args, $url): void {
            if (is_string($url) && substr($url, -strlen(Submission::PATH)) === Submission::PATH) {
                $this->sent[] = json_decode((string)($args['body'] ?? ''), true);
            }
        }, 10, 5);

        // WordPress itself would publish a visitor's comment: no manual approval, no
        // "previously approved author" rule.
        update_option('comment_moderation', '0');
        update_option('comment_previously_approved', '0');
        $this->authorId = self::factory()->user->create(['role' => 'author', 'user_email' => self::AUTHOR_EMAIL]);
        $this->postId = self::factory()->post->create(['post_author' => $this->authorId, 'comment_status' => 'open']);
    }

    public function tear_down()
    {
        foreach ($this->servers as [$server, $port]) {
            proc_terminate($server);
            proc_close($server);
            // A server that outlives its test is a leak.
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            self::assertFalse($socket, "the server on port {$port} is still running");
        }
        $this->servers = [];
        foreach ([$this->dir . '/mock', $this->dir . '/deliveries', $this->dir] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        $GLOBALS['wp_rest_server'] = null;
        parent::tear_down();
    }

    /**
     * Switches the plugin on with a key and a secret.
     *
     * @param array<string,mixed> $settings Settings over the defaults.
     * @return void
     */
    protected function configure(array $settings = []): void
    {
        update_option(Settings::KEY_OPTION, self::KEY);
        update_option(Settings::SECRET_OPTION, self::SECRET);
        update_option(Settings::OPTION, $settings + ['enabled' => true, 'failure_mode' => Settings::FAIL_CLOSED]
            + Settings::defaults());
    }

    /**
     * Starts the mock gateway and the capture server its worker delivers to, and switches
     * the plugin on with the mock as its gateway.
     *
     * @param array<string,mixed>  $settings Settings over the defaults.
     * @param array<string,string> $mockEnv  More variables for the mock.
     * @return void
     */
    protected function startGateway(array $settings = [], array $mockEnv = []): void
    {
        $mock = self::mockDirectory();
        $capture = $this->serve(__DIR__ . '/fixtures', 'capture.php', ['MINOS_CAPTURE_DIR' => $this->dir . '/deliveries']);
        $this->mockEnv = $mockEnv + [
            'MINOS_MOCK_WEBHOOK_URL'    => "http://127.0.0.1:{$capture}/wp-json/minos/v1/webhook",
            'MINOS_MOCK_WEBHOOK_SECRET' => self::SECRET,
            'MINOS_MOCK_KEY'            => self::KEY,
            'MINOS_MOCK_DATA_DIR'       => $this->dir . '/mock',
            'MINOS_MOCK_DELAY_S'        => '0',
        ];
        $port = $this->serve($mock . '/public', null, $this->mockEnv);
        $this->configure($settings + ['gateway_url' => "http://127.0.0.1:{$port}"]);
    }

    /**
     * Posts a comment as a visitor through the comment form's handler
     * (`wp_handle_comment_submission`, what `wp-comments-post.php` calls), which runs
     * `wp_new_comment`, its filters and `comment_post`. Each visitor has an address and an
     * e-mail of their own, so WordPress's flood check never trips.
     *
     * @param string              $text   The comment.
     * @param array<string,mixed> $fields Form fields over the defaults.
     * @return int The comment id.
     */
    protected function post(string $text, array $fields = []): int
    {
        $visitor = ++self::$visitors;
        $_SERVER['REMOTE_ADDR'] = sprintf('198.%d.%d.%d', 18 + intdiv($visitor, 65536) % 2,
            intdiv($visitor, 256) % 256, $visitor % 256);
        $comment = wp_handle_comment_submission($fields + [
            'comment_post_ID' => $this->postId,
            'author'          => 'Gość testowy ' . $visitor,
            'email'           => 'gosc' . $visitor . '@example.invalid',
            'url'             => '',
            'comment'         => $text,
        ]);
        self::assertInstanceOf(\WP_Comment::class, $comment, is_wp_error($comment) ? $comment->get_error_code() : '');
        return (int)$comment->comment_ID;
    }

    /**
     * One pass of the mock's delivery worker: what is due goes to the capture server.
     *
     * @return void
     */
    protected function runMockWorker(): void
    {
        $process = proc_open([PHP_BINARY, self::mockDirectory() . '/bin/worker.php', '--once'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv() + $this->mockEnv);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string)$output);
    }

    /**
     * The deliveries the capture server received since the last call, in order of arrival.
     *
     * @return array<int,array{method:string,path:string,headers:array<string,string>,body:string}>
     */
    protected function deliveries(): array
    {
        $files = glob($this->dir . '/deliveries/*.json') ?: [];
        sort($files, SORT_STRING);
        $deliveries = [];
        foreach ($files as $file) {
            $delivery = json_decode((string)file_get_contents($file), true);
            self::assertIsArray($delivery, $file);
            $delivery['body'] = (string)base64_decode((string)$delivery['body'], true);
            $deliveries[] = $delivery;
            unlink($file);
        }
        return $deliveries;
    }

    /**
     * Serves a request to the plugin's route through WordPress's own REST server, as
     * `rest_api_loaded()` does for a request over HTTP: the method and the headers from
     * `$_SERVER`, the raw body from `$HTTP_RAW_POST_DATA`, which `WP_REST_Server::get_raw_data()`
     * reads before `php://input`.
     *
     * @param string               $body    The raw body.
     * @param array<string,string> $headers `$_SERVER` names (`HTTP_…`, `CONTENT_TYPE`) → values.
     * @param string               $method  The HTTP method.
     * @return int The HTTP status the server answered with.
     */
    protected function serveWebhook(string $body, array $headers, string $method = 'POST'): int
    {
        $server = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = $method;
        foreach ($headers as $name => $value) {
            $_SERVER[$name] = $value;
        }
        $GLOBALS['HTTP_RAW_POST_DATA'] = $body;
        try {
            $rest = rest_get_server();
            self::assertInstanceOf(\Spy_REST_Server::class, $rest);
            $rest->serve_request('/minos/v1/webhook');
            return (int)$rest->status;
        } finally {
            unset($GLOBALS['HTTP_RAW_POST_DATA']);
            $_SERVER = $server;
        }
    }

    /**
     * Serves one delivery the mock made, exactly as it arrived.
     *
     * @param array{method:string,path:string,headers:array<string,string>,body:string} $delivery
     * @return int The HTTP status the server answered with.
     */
    protected function serveDelivery(array $delivery): int
    {
        self::assertSame('/wp-json/minos/v1/webhook', $delivery['path']);
        return $this->serveWebhook($delivery['body'], $delivery['headers'], $delivery['method']);
    }

    /**
     * Serves a verdict signed with the test secret, as the gateway signs it.
     *
     * @param array<string,mixed> $payload The payload.
     * @return int The HTTP status the server answered with.
     */
    protected function serveSigned(array $payload): int
    {
        $body = (string)json_encode($payload, JSON_UNESCAPED_UNICODE);
        return $this->serveWebhook($body, [
            'CONTENT_TYPE'                    => 'application/json',
            self::serverName(Signature::HEADER) => Signature::sign(self::SECRET, $body, time()),
        ]);
    }

    /**
     * A verdict payload for a comment, as the gateway builds it.
     *
     * @param int                 $id            The comment.
     * @param string              $qualification `bezpieczne`, `ocenzurowane` or `zablokowane`.
     * @param array<string,mixed> $extra         Fields over the defaults.
     * @return array<string,mixed> The payload.
     */
    protected static function verdict(int $id, string $qualification, array $extra = []): array
    {
        return $extra + [
            'id'           => 'wp:' . $id,
            'status'       => 'ocenione',
            'kwalifikacja' => $qualification,
            'kategorie'    => [],
            'wsparcie'     => false,
            'wersja'       => '3f0c9a41d2b7e8c5',
        ];
    }

    /**
     * The `$_SERVER` name of an HTTP header.
     *
     * @param string $header The header.
     * @return string The name.
     */
    protected static function serverName(string $header): string
    {
        return 'HTTP_' . strtoupper(str_replace('-', '_', $header));
    }

    /**
     * A comment as stored, read past every cache.
     *
     * @param int $id The comment.
     * @return array<string,string> Its row.
     */
    protected static function row(int $id): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->comments} WHERE comment_ID = %d", $id), ARRAY_A);
        self::assertIsArray($row, "comment {$id} is gone");
        return $row;
    }

    /**
     * A comment's meta value as stored, read past every cache.
     *
     * @param int    $id  The comment.
     * @param string $key The meta key.
     * @return string|null The value, or null when there is none.
     */
    protected static function stored(int $id, string $key): ?string
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->commentmeta} WHERE comment_id = %d AND meta_key = %s", $id, $key));
        return $value === null ? null : (string)$value;
    }

    /**
     * Moves every moment the plugin stored for a comment back, as if that much time had
     * passed since: the plugin's clock is the real one.
     *
     * @param int $id      The comment.
     * @param int $seconds How long ago.
     * @return void
     */
    protected static function age(int $id, int $seconds): void
    {
        foreach ([Meta::HELD_AT, Meta::SUBMITTED_AT, Meta::RETRY_AT, Meta::DECIDED_AT] as $key) {
            $value = get_comment_meta($id, $key, true);
            if ($value !== '') {
                update_comment_meta($id, $key, (int)$value - $seconds);
            }
        }
    }

    /**
     * The e-mails WordPress sent in this test, as `[to, subject]`.
     *
     * @return array<int,array{0:string,1:string}>
     */
    protected static function mails(): array
    {
        $mails = [];
        foreach (tests_retrieve_phpmailer_instance()->mock_sent as $mail) {
            $to = [];
            foreach ($mail['to'] as $recipient) {
                $to[] = $recipient[0];
            }
            $mails[] = [implode(',', $to), (string)$mail['subject']];
        }
        return $mails;
    }

    /**
     * The scheduled events of an action, as WordPress keeps them.
     *
     * @param string $hook The action.
     * @return array<int,array{at:int,args:array}>
     */
    protected static function events(string $hook): array
    {
        $events = [];
        foreach (_get_cron_array() ?: [] as $at => $hooks) {
            foreach ($hooks[$hook] ?? [] as $event) {
                $events[] = ['at' => (int)$at, 'args' => $event['args']];
            }
        }
        return $events;
    }

    /**
     * The mock gateway bundled with the client library. It is part of the dependency, so a
     * missing one is a broken install, not a reason to skip.
     *
     * @return string Its directory.
     */
    private static function mockDirectory(): string
    {
        $dir = dirname(__DIR__, 2) . '/vendor/minos-moderation/client-php/mock-gateway';
        self::assertFileExists($dir . '/public/index.php', 'run composer install');
        self::assertFileExists($dir . '/bin/worker.php', 'run composer install');
        return $dir;
    }

    /**
     * Starts PHP's built-in server and waits until it answers.
     *
     * @param string               $docroot The document root.
     * @param string|null          $router  A router script in it.
     * @param array<string,string> $env     Variables for the server.
     * @return int The port.
     */
    private function serve(string $docroot, ?string $router, array $env): int
    {
        for ($try = 0; $try < 5; $try++) {
            $port = random_int(20000, 40000);
            $command = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot];
            if ($router !== null) {
                $command[] = $docroot . '/' . $router;
            }
            // An array, not a string: a string runs through `sh -c`, and terminating the
            // shell would leave PHP's server running.
            $server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes, null, getenv() + $env);
            self::assertIsResource($server);
            for ($wait = 0; $wait < 50; $wait++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    $this->servers[] = [$server, $port];
                    return $port;
                }
                usleep(100000);
            }
            proc_terminate($server);
            proc_close($server);
        }
        self::fail('the built-in server did not start');
    }
}
