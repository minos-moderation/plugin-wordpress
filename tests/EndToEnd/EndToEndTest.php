<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\EndToEnd;

use Minos\WordPress\Log;
use Minos\WordPress\Meta;
use Minos\WordPress\Platform;
use Minos\WordPress\Plugin;
use Minos\WordPress\Settings;
use Minos\WordPress\Submission;
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
        [$mock, $state, $env] = $this->start([]);

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
        $this->assertTheMaskIsPublishedOnlyWhenItFits($ids['censored'], 'To jest [[paskudny]] komentarz [minos:cenzuruj]');
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

    public function testCommentsHeldDuringThePauseOfAFullQueueGoToTheMockInOneRequest(): void
    {
        // The mock's queue takes two comments; the third is refused with `kolejka_pelna`.
        $offset = 0;
        [$mock, $state, $env] = $this->start(['MINOS_MOCK_QUEUE_MAX' => '2'], static function () use (&$offset): int {
            return time() + $offset;
        });
        $ids = [];
        foreach (['Pierwszy', 'Drugi', 'Trzeci', 'Czwarty'] as $word) {
            $ids[] = WpStub::postComment(['comment_content' => $word . ' komentarz testowy w kolejce.']);
        }

        self::assertCount(3, WpStub::$requests, 'the fourth waited for the pause the third was refused with');
        self::assertSame(429, $this->lastAnswerStatus($ids[2]));
        foreach ([$ids[2], $ids[3]] as $id) {
            self::assertSame(Meta::PENDING, WpStub::$meta[$id][Meta::STATUS]);
            self::assertArrayNotHasKey(Meta::SUBMITTED_AT, WpStub::$meta[$id]);
        }

        // The mock delivers the first two, which empties its queue; the pause then ends.
        WpStub::save($state);
        $this->worker($mock, $env);
        WpStub::load($state);
        $offset = 61;
        foreach (WpStub::$events as $event) {
            if ($event['hook'] === Submission::HOOK_RETRY && $event['at'] <= time() + $offset) {
                do_action($event['hook'], ...$event['args']);
            }
        }

        self::assertCount(4, WpStub::$requests, 'one more request for both');
        $last = json_decode(WpStub::$requests[3]['args']['body'], true)['elementy'];
        self::assertSame(['wp:' . $ids[2], 'wp:' . $ids[3]], array_column($last, 'id'));
        foreach ([$ids[2], $ids[3]] as $id) {
            self::assertNotSame('', WpStub::$meta[$id][Meta::SUBMITTED_AT] ?? '', 'the mock took it: in `przyjete`');
        }

        WpStub::save($state);
        $this->worker($mock, $env);
        WpStub::load($state);
        foreach ($ids as $id) {
            self::assertSame(['1', 'bezpieczne'], [WpStub::$comments[$id]['comment_approved'], WpStub::$meta[$id][Meta::STATUS] ?? null]);
        }
    }

    /**
     * Starts the stand-in site and the mock gateway, and hooks the plugin into this process
     * with the mock as its gateway.
     *
     * @param array<string,string> $mockEnv More variables for the mock.
     * @param callable|null        $clock   The plugin's clock in this process (`time()`).
     * @return array{0:string,1:string,2:array<string,string>} The mock's directory, the site
     *     state file and the mock's variables.
     */
    private function start(array $mockEnv, ?callable $clock = null): array
    {
        $mock = self::mockDirectory();
        $state = $this->dir . '/state.json';
        $sitePort = $this->serve(__DIR__ . '/fixtures', 'site.php', ['MINOS_SITE_STATE' => $state]);
        $env = $mockEnv + [
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
        (new Plugin(new Platform($clock)))->hook(__DIR__ . '/../../minos-moderation.php');
        return [$mock, $state, $env];
    }

    /**
     * The HTTP status the plugin logged for a comment's last failed request.
     *
     * @param int $id The comment.
     * @return int|null The status.
     */
    private function lastAnswerStatus(int $id): ?int
    {
        $status = null;
        foreach (WpStub::$options[Log::OPTION] ?? [] as $entry) {
            if (($entry['comment'] ?? null) === $id) {
                $status = $entry['http'];
            }
        }
        return $status;
    }

    /**
     * The masked comment, as a property of what was sent: published only as the sent text
     * with characters turned into `█` (the same length, nothing else changed); a masked text
     * of any other shape holds the comment, with the mask kept for the moderator. Which of
     * the two happens depends on the mock the client library bundles.
     *
     * @param int    $id       The comment.
     * @param string $original Its content.
     */
    private function assertTheMaskIsPublishedOnlyWhenItFits(int $id, string $original): void
    {
        $sent = null;
        foreach (WpStub::$requests as $request) {
            foreach (json_decode($request['args']['body'], true)['elementy'] as $item) {
                if ($item['id'] === 'wp:' . $id) {
                    $sent = $item['tekst'];
                }
            }
        }
        self::assertIsString($sent, 'the censored comment was sent');
        self::assertSame('ocenzurowane', WpStub::$meta[$id][Meta::STATUS] ?? null);
        $content = WpStub::$comments[$id]['comment_content'];
        if (WpStub::$comments[$id]['comment_approved'] === '1') {
            $published = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            self::assertSame(mb_strlen($sent, 'UTF-8'), mb_strlen($published, 'UTF-8'));
            self::assertTrue(self::onlyMasked($sent, $published), $published);
            self::assertNotSame($sent, $published, 'something was masked');
            self::assertSame($original, WpStub::$meta[$id][Meta::ORIGINAL]);
            return;
        }
        $mask = WpStub::$meta[$id][Meta::MASKED] ?? null;
        self::assertIsString($mask, 'a held censored comment keeps the mask');
        self::assertFalse(self::onlyMasked($sent, $mask), 'held although the mask fits: ' . $mask);
        self::assertSame('0', WpStub::$comments[$id]['comment_approved']);
        self::assertSame($original, $content);
    }

    /**
     * Whether a text is the sent text with some characters turned into `█`, and nothing else
     * changed (the test's own reading, independent of the plugin's).
     */
    private static function onlyMasked(string $sent, string $masked): bool
    {
        $a = preg_split('//u', $sent, -1, PREG_SPLIT_NO_EMPTY);
        $b = preg_split('//u', $masked, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($a) || !is_array($b) || count($a) !== count($b)) {
            return false;
        }
        foreach ($b as $i => $char) {
            if ($char !== $a[$i] && $char !== '█') {
                return false;
            }
        }
        return true;
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
