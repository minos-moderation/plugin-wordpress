<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\EndToEnd;

use Minos\WordPress\Meta;
use Minos\WordPress\Platform;
use Minos\WordPress\Plugin;
use Minos\WordPress\Settings;
use PHPUnit\Framework\TestCase;
use WpStub;

/**
 * The plugin against the mock gateway of `minos-moderation/client-php`, over real HTTP.
 *
 * This process posts comments through the plugin's hooks; `wp_remote_post` sends them to
 * the mock's API on PHP's built-in server. The mock's worker then delivers the verdicts,
 * signed, to a second built-in server that runs the plugin's receiver on the same stubbed
 * site state (`fixtures/site.php`). The verdicts come from the mock's `[minos:…]` markers:
 * no comment here is real.
 */
final class EndToEndTest extends TestCase
{
    /** The key and the secret the mock is started with; test values only. */
    private const KEY = 'wgb2b_e2e_klucz_000000000000000000';
    private const SECRET = 'e2e-sekret-webhooka-0123456789abcdef';

    /** @var array<int,array{0:resource,1:int}> Started servers and their ports. */
    private $servers = [];

    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        WpStub::reset();
        $this->dir = sys_get_temp_dir() . '/minos-wp-e2e-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/mock', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as [$server, $port]) {
            proc_terminate($server);
            proc_close($server);
            // A server that outlives its test is a leak.
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            self::assertFalse($socket, "the server on port {$port} is still running");
        }
        foreach ([$this->dir . '/mock', $this->dir] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        WpStub::reset();
    }

    public function testCommentsGoToTheMockAndTheSignedVerdictsAreApplied(): void
    {
        $mock = self::mockDirectory();
        $state = $this->dir . '/state.json';
        $sitePort = $this->serve(__DIR__ . '/fixtures', 'site.php', ['MINOS_SITE_STATE' => $state]);
        $env = [
            'MINOS_MOCK_WEBHOOK_URL'    => "http://127.0.0.1:{$sitePort}/wp-json/minos/v1/webhook",
            'MINOS_MOCK_WEBHOOK_SECRET' => self::SECRET,
            'MINOS_MOCK_KEY'            => self::KEY,
            'MINOS_MOCK_DATA_DIR'       => $this->dir . '/mock',
            'MINOS_MOCK_DELAY_S'        => '0',
        ];
        $mockPort = $this->serve($mock . '/public', null, $env);

        WpStub::$options[Settings::OPTION] = ['enabled' => true, 'gateway_url' => "http://127.0.0.1:{$mockPort}",
            'failure_mode' => Settings::FAIL_CLOSED, 'blocked_mode' => Settings::HOLD] + Settings::defaults();
        WpStub::$options[Settings::KEY_OPTION] = self::KEY;
        WpStub::$options[Settings::SECRET_OPTION] = self::SECRET;
        WpStub::$network = true;
        (new Plugin(new Platform()))->hook(__DIR__ . '/../../minos-moderation.php');

        $ids = [
            'safe'      => WpStub::postComment(['comment_content' => 'Uprzejmy komentarz testowy.']),
            'blocked'   => WpStub::postComment(['comment_content' => 'Testowy <b>atak</b> [minos:blokuj] [minos:kategoria=nekanie]']),
            'censored'  => WpStub::postComment(['comment_content' => 'To jest [[paskudny]] komentarz [minos:cenzuruj]']),
            'none'      => WpStub::postComment(['comment_content' => 'Komentarz bez werdyktu [minos:nieocenione]']),
            'support'   => WpStub::postComment(['comment_content' => 'Komentarz testowy [minos:kategoria=samookaleczenie]']),
            'twice'     => WpStub::postComment(['comment_content' => 'Doręczony dwa razy [minos:dwa-razy]']),
            'forged'    => WpStub::postComment(['comment_content' => 'Podrobiony podpis [minos:zly-podpis]']),
            'stale'     => WpStub::postComment(['comment_content' => 'Stary podpis [minos:stary-podpis]']),
        ];
        foreach ($ids as $name => $id) {
            self::assertNotSame('', WpStub::$meta[$id][Meta::SUBMITTED_AT] ?? '', "{$name}: the mock did not accept it");
        }
        self::assertCount(count($ids), WpStub::$requests);
        WpStub::save($state);

        // Two passes: the second delivers the repeat of `dwa-razy`.
        $this->worker($mock, $env);
        $this->worker($mock, $env);
        WpStub::load($state);

        $approved = static function (int $id): string {
            return WpStub::$comments[$id]['comment_approved'];
        };
        $status = static function (int $id): ?string {
            return WpStub::$meta[$id][Meta::STATUS] ?? null;
        };
        self::assertSame(['1', 'bezpieczne'], [$approved($ids['safe']), $status($ids['safe'])]);
        self::assertSame(['0', 'zablokowane'], [$approved($ids['blocked']), $status($ids['blocked'])]);
        self::assertSame('nekanie', WpStub::$meta[$ids['blocked']][Meta::CATEGORIES]);
        self::assertSame(['1', 'ocenzurowane'], [$approved($ids['censored']), $status($ids['censored'])]);
        self::assertSame('To jest ████████ komentarz [minos:cenzuruj]', WpStub::$comments[$ids['censored']]['comment_content']);
        self::assertSame('To jest [[paskudny]] komentarz [minos:cenzuruj]', WpStub::$meta[$ids['censored']][Meta::ORIGINAL]);
        self::assertSame(['0', Meta::UNASSESSED], [$approved($ids['none']), $status($ids['none'])]);
        self::assertSame('1', WpStub::$meta[$ids['support']][Meta::SUPPORT]);
        self::assertSame(['1', 'bezpieczne'], [$approved($ids['twice']), $status($ids['twice'])]);
        self::assertCount(1, array_filter(WpStub::$statusChanges, static function (array $change) use ($ids): bool {
            return $change[0] === $ids['twice'];
        }), 'the repeated delivery changed nothing');
        foreach (['forged', 'stale'] as $name) {
            self::assertSame(['0', Meta::PENDING], [$approved($ids[$name]), $status($ids[$name])], $name);
        }

        // Every delivery reached the receiver: 8 first attempts and the repeat; the two bad
        // signatures were refused (the mock retries them later).
        $answers = array_count_values(file($state . '.log', FILE_IGNORE_NEW_LINES) ?: []);
        self::assertSame(['200' => 7, '401' => 2], $answers);
    }

    /**
     * The mock gateway bundled with the client library. It is part of the dependency, so a
     * missing one is a broken install, not a reason to skip.
     */
    private static function mockDirectory(): string
    {
        $dir = __DIR__ . '/../../vendor/minos-moderation/client-php/mock-gateway';
        self::assertFileExists($dir . '/public/index.php', 'run composer install');
        self::assertFileExists($dir . '/bin/worker.php', 'run composer install');
        return $dir;
    }

    /**
     * One pass of the mock's delivery worker.
     *
     * @param array<string,string> $env The mock's variables.
     */
    private function worker(string $mock, array $env): void
    {
        $process = proc_open([PHP_BINARY, $mock . '/bin/worker.php', '--once'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv() + $env);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string)$output);
    }

    /**
     * Starts PHP's built-in server and waits until it answers.
     *
     * @param array<string,string> $env Variables for the server.
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
