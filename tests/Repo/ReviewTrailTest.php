<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * The review trail CI checks (`.github/review-trail/check.py`, run by
 * `.github/workflows/review-trail.yml`): a pull request that changes a path on the risk list
 * passes only with `Review: <agent> @ <head SHA> — AKCEPTACJA` for its current head, in its
 * description or a member's comment (the rule is in this repository's
 * CLAUDE.md). The script runs as the workflow runs it, on synthetic events,
 * path lists and comments, and once against a fake of GitHub's API for the comments it reads
 * itself.
 */
final class ReviewTrailTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const CHECK = self::ROOT . '/.github/review-trail/check.py';

    private const EXEMPT = self::ROOT . '/.github/review-trail/exempt-paths.txt';

    private const HEAD = '0123456789abcdef0123456789abcdef01234567';

    private const OLD_HEAD = 'fedcba9876543210fedcba9876543210fedcba98';

    private string $dir;

    /** The fake API's port, once started. */
    private int $apiPort = 0;

    protected function setUp(): void
    {
        self::assertNotSame('', trim((string)shell_exec('command -v python3')), 'python3 is needed: CI runs the check with it');
        $this->dir = sys_get_temp_dir() . '/minos-wp-trail-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dir);
    }

    /**
     * Every path a risk marker of the rules test names needs a review, and what the Sonnet
     * agent may change needs none: the exempt list and the agents' risk list are one list.
     */
    public function testTheRiskMarkersNeedAReviewAndTheSonnetScopeDoesNot(): void
    {
        $paths = [];
        foreach (ClaudeRulesTest::SONNET_RISK_MARKERS as $marker) {
            $paths[] = substr($marker, -1) === '/' ? $marker . 'x.md' : $marker;
        }
        // CLAUDE.md on every level; the files of the Polish text in __(), which hold its code;
        // the fixtures and stubs the tests prove the plugin with; what builds the zip.
        foreach (['docs/CLAUDE.md', 'src/CLAUDE.md', 'src/Admin/CommentsScreen.php', 'src/Notice.php',
            'tests/Integration/fixtures/x.txt', 'tests/EndToEnd/fixtures/x.txt', 'tests/stubs/wordpress.php',
            'tests/Repo/ClaudeRulesTest.php', 'tests/Repo/ReviewTrailTest.php', 'composer.lock', 'phpunit.xml.dist',
            'bin/build-zip.sh', 'minos-moderation.php', 'docs/other.md', 'LICENSE'] as $path) {
            $paths[] = $path;
        }
        foreach ($paths as $path) {
            [$status, $out] = $this->check([$path], [], null);
            self::assertSame(1, $status, "{$path} must need a review: {$out}");
        }
        foreach (['README.md', 'docs/development.md'] as $path) {
            [$status, $out] = $this->check([$path], [], null);
            self::assertSame(0, $status, "{$path} is the Sonnet agent's: {$out}");
            self::assertStringContainsString('No changed path is on the risk list', $out);
        }
    }

    /** @return iterable<string,array{0:string|null,1:list<array<string,mixed>>,2:int}> */
    public static function trails(): iterable
    {
        $accept = 'Review: przeglad-bezpieczenstwa @ ' . self::HEAD . ' — AKCEPTACJA';
        $member = static function (string $body, string $role = 'MEMBER'): array {
            return ['body' => $body, 'author_association' => $role, 'user' => ['login' => 'owner-login']];
        };
        yield 'no line' => [null, [], 1];
        yield 'in the description' => ["Agent: programista (Opus)\n\n" . $accept, [], 0];
        yield 'in a member\'s comment, the SHA abbreviated to 12' => [null,
            [$member('Review: przeglad-bezpieczenstwa @ ' . substr(self::HEAD, 0, 12) . ' — AKCEPTACJA')], 0];
        yield 'the SHA abbreviated to 8' => [null,
            [$member('Review: przeglad-bezpieczenstwa @ ' . substr(self::HEAD, 0, 8) . ' — AKCEPTACJA')], 1];
        yield 'the SHA abbreviated to 7' => [null,
            [$member('Review: przeglad-bezpieczenstwa @ ' . substr(self::HEAD, 0, 7) . ' — AKCEPTACJA')], 1];
        yield 'for an older head' => [null, [$member('Review: przeglad-bezpieczenstwa @ ' . self::OLD_HEAD . ' — AKCEPTACJA')], 1];
        yield 'a SHA too short to name a commit' => [null, [$member('Review: przeglad-bezpieczenstwa @ 012345 — AKCEPTACJA')], 1];
        yield 'changes asked' => [null, [$member('Review: przeglad-bezpieczenstwa @ ' . self::HEAD . ' — POPRAWKI')], 1];
        yield 'changes asked after the acceptance' => [$accept,
            [$member('Review: przeglad-bezpieczenstwa @ ' . self::HEAD . ' - POPRAWKI (1 major)')], 1];
        yield 'accepted after changes asked' => [null, [$member('Review: x @ ' . self::HEAD . ' — POPRAWKI'),
            $member("Re-check.\nReview: przeglad-bezpieczenstwa @ " . self::HEAD . ' – AKCEPTACJA')], 0];
        yield 'by someone without a role in the repository' => [null, [$member($accept, 'NONE')], 1];
        yield 'by a contributor' => [null, [$member($accept, 'CONTRIBUTOR')], 1];
        yield 'quoted inside a sentence' => [null, [$member('as in: ' . $accept)], 1];
    }

    /**
     * @dataProvider trails
     * @param list<array<string,mixed>> $comments
     */
    public function testARiskPathPassesOnlyWithAnAcceptanceOfTheCurrentHead(?string $body, array $comments, int $expected): void
    {
        [$status, $out] = $this->check(['src/Receiver.php', 'README.md'], $comments, $body);
        self::assertSame($expected, $status, $out);
        self::assertStringContainsString('src/Receiver.php', $out);
    }

    public function testADescriptionBySomeoneWithoutARoleDoesNotCount(): void
    {
        $accept = 'Review: przeglad-bezpieczenstwa @ ' . self::HEAD . ' — AKCEPTACJA';
        [$status, $out] = $this->check(['src/x.php'], [], $accept, 'CONTRIBUTOR');
        self::assertSame(1, $status, $out);
    }

    /** @return iterable<string,array{0:list<string>}> */
    public static function badInputs(): iterable
    {
        yield 'no argument' => [[]];
        yield 'a missing event' => [['--event', '{dir}/none.json', '--paths', '{dir}/paths.txt', '--exempt', self::EXEMPT,
            '--comments', '{dir}/comments.json']];
        yield 'an exempt list without a pattern' => [['--event', '{dir}/event.json', '--paths', '{dir}/paths.txt', '--exempt',
            '{dir}/empty.txt', '--comments', '{dir}/comments.json']];
        yield 'comments that are no list' => [['--event', '{dir}/event.json', '--paths', '{dir}/paths.txt', '--exempt',
            self::EXEMPT, '--comments', '{dir}/event.json']];
    }

    /**
     * @dataProvider badInputs
     * @param list<string> $arguments
     */
    public function testBadInputExitsTwo(array $arguments): void
    {
        $this->writeInputs(['src/x.php'], [], null, 'MEMBER');
        file_put_contents($this->dir . '/comments.json', '[]');
        file_put_contents($this->dir . '/empty.txt', "# only a comment\n\n");
        [$status, $out, $err] = $this->runCheck(str_replace('{dir}', $this->dir, $arguments), []);
        self::assertSame(2, $status, $out . $err);
    }

    /**
     * Without `--comments` the script reads every page of the comments from the API, with the
     * token, as in CI; the acceptance on the second page counts.
     */
    public function testTheCommentsAreReadFromTheApiPageByPage(): void
    {
        $comments = [];
        for ($i = 0; $i < 100; $i++) {
            $comments[] = ['body' => "comment {$i}", 'author_association' => 'MEMBER', 'user' => ['login' => 'a']];
        }
        $comments[] = ['body' => 'Review: przeglad-bezpieczenstwa @ ' . self::HEAD . ' — AKCEPTACJA',
            'author_association' => 'MEMBER', 'user' => ['login' => 'owner-login']];
        $server = $this->startFakeApi($comments);
        try {
            $this->writeInputs(['src/x.php'], null, null, 'MEMBER');
            $env = $this->apiEnv();
            [$status, $out, $err] = $this->runCheck(['--event', $this->dir . '/event.json', '--paths', $this->dir . '/paths.txt',
                '--exempt', self::EXEMPT], $env);
            self::assertSame(0, $status, $out . $err);
            self::assertStringContainsString('a comment by owner-login', $out);
            $requests = file($this->dir . '/api.log', FILE_IGNORE_NEW_LINES) ?: [];
            self::assertCount(2, $requests, 'two pages, then it stops');
            self::assertStringEndsWith('Bearer test-token', $requests[0]);
            self::assertStringNotContainsString('test-token', $out . $err, 'the token is never printed');

            unset($env['GITHUB_TOKEN']);
            [$status, , $err] = $this->runCheck(['--event', $this->dir . '/event.json', '--paths', $this->dir . '/paths.txt',
                '--exempt', self::EXEMPT], $env);
            self::assertSame(2, $status, 'without a token it cannot tell');
            self::assertStringContainsString('GITHUB_TOKEN', $err);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    /**
     * The workflow runs the check on every change of a pull request, its description's
     * included, as the base branch has it (`pull_request_target`), with nothing but read
     * permissions, the whole history, and a checkout of the base that keeps no token. No step
     * but that checkout uses an action.
     */
    public function testTheWorkflowRunsTheBasesCheckOnEveryChangeOfAPullRequest(): void
    {
        $workflow = WorkflowYaml::file(self::ROOT . '/.github/workflows/review-trail.yml');
        $on = $workflow['on'] ?? [];
        self::assertSame(['pull_request_target'], array_keys($on));
        foreach (['opened', 'synchronize', 'reopened', 'edited', 'ready_for_review'] as $type) {
            self::assertContains($type, $on['pull_request_target']['types'] ?? [], $type);
        }
        self::assertSame(['contents' => 'read', 'pull-requests' => 'read'], $workflow['permissions'] ?? null);
        self::assertSame(['trail'], array_keys($workflow['jobs'] ?? []));
        self::assertArrayNotHasKey('permissions', $workflow['jobs']['trail'], 'the job widens the permissions');
        $steps = $workflow['jobs']['trail']['steps'] ?? [];
        self::assertCount(2, $steps);
        self::assertStringStartsWith('actions/checkout@', (string)($steps[0]['uses'] ?? ''));
        // No ref: pull_request_target checks out the base branch.
        self::assertSame(['fetch-depth', 'persist-credentials'], array_keys($steps[0]['with'] ?? []));
        self::assertSame('0', (string)$steps[0]['with']['fetch-depth'], 'the diff needs the merge base');
        self::assertSame('false', (string)$steps[0]['with']['persist-credentials']);
        self::assertArrayNotHasKey('uses', $steps[1]);
    }

    /**
     * The workflow's own step, run as CI runs it: in a checkout of the base branch alone, the
     * pull request's head fetched by the step from a stand-in for GitHub. A file moved out of
     * a risk path (onto an exempt one) is still on it; a pull request that loosens the check or
     * its exempt list is judged by the base's; a base without the check fails rather than
     * passes; a change to the exempt docs alone passes.
     */
    public function testTheWorkflowStepJudgesByTheBaseAndSeesAMoveOutOfARiskPath(): void
    {
        $server = $this->startFakeApi([]);
        try {
            // A rename out of a risk path onto an exempt one, with no review line.
            $repo = $this->repository(true);
            $this->git($repo, ['mv', 'src/Receiver.php', 'README.md']);
            [$status, $out] = $this->runStep($repo, 'move a risk file onto an exempt path');
            self::assertSame(1, $status, $out);
            self::assertStringContainsString('src/Receiver.php', $out);

            // A check that passes everything, an exempt list that frees src/: the base's judge.
            $repo = $this->repository(true);
            file_put_contents("{$repo}/.github/review-trail/check.py", "import sys\nsys.exit(0)\n");
            file_put_contents("{$repo}/.github/review-trail/exempt-paths.txt", "src/\n.github/\n", FILE_APPEND);
            file_put_contents("{$repo}/src/Receiver.php", "<?php\n// changed\n");
            [$status, $out] = $this->runStep($repo, 'loosen the check');
            self::assertSame(1, $status, $out);
            self::assertStringContainsString('.github/review-trail/check.py', $out);

            // No check in the base: the head's own is never taken instead.
            $repo = $this->repository(false);
            mkdir("{$repo}/.github/review-trail", 0700, true);
            file_put_contents("{$repo}/.github/review-trail/check.py", "import sys\nsys.exit(0)\n");
            copy(self::EXEMPT, "{$repo}/.github/review-trail/exempt-paths.txt");
            [$status, $out] = $this->runStep($repo, 'introduce a lenient check');
            self::assertNotSame(0, $status, 'a base without the check let a pull request pass: ' . $out);

            // The exempt docs alone.
            $repo = $this->repository(true);
            file_put_contents("{$repo}/docs/development.md", "# A, amended\n");
            [$status, $out] = $this->runStep($repo, 'docs');
            self::assertSame(0, $status, $out);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    /**
     * A name outside ASCII, or with a quote, reaches the check as it is (git quotes it in
     * `--name-only` unless told otherwise): on a risk path it is named so, and in an exempt
     * folder it needs no review (a base whose list exempts a folder: this repository's own
     * exempts none).
     */
    public function testTheWorkflowStepJudgesNamesOutsideAsciiAsTheyAre(): void
    {
        $server = $this->startFakeApi([]);
        try {
            $repo = $this->repository(true);
            file_put_contents("{$repo}/src/zażółć.php", "<?php\n");
            [$status, $out] = $this->runStep($repo, 'a file of a Polish name');
            self::assertSame(1, $status, $out);
            self::assertStringContainsString('src/zażółć.php', $out, 'the risk path is not named as it is');

            $repo = $this->repository(true, "README.md\ndocs/\n");
            file_put_contents("{$repo}/docs/źródło \"cytat\".md", "# Ź\n");
            [$status, $out] = $this->runStep($repo, 'a doc of a Polish name');
            self::assertSame(0, $status, 'an exempt doc of a non-ASCII name was judged a risk path: ' . $out);
            // The guard: the check saw the one changed path, not an empty list.
            self::assertStringContainsString('(1 changed)', $out);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    /**
     * What a pull request names reaches the job's log, where a line that starts with `::`
     * (leading whitespace aside) or holds `##[` is a command to the runner: a path with a line
     * break, a carriage return or a leading `::`, or a commenter's login, never makes one, and
     * cannot forge the check's own verdict. The check runs on the names as the workflow gives
     * them (`-z`).
     */
    public function testWhatAPullRequestNamesNeverBecomesARunnerCommand(): void
    {
        $forged = '::notice title=Review trail::Reviewed: przeglad-bezpieczenstwa @ ' . self::HEAD . ' — AKCEPTACJA';
        $paths = ["src/x\n{$forged}", '::warning::x', '##[error]y', "src/z\r::error::w", "  ::add-mask::v"];
        file_put_contents($this->dir . '/event.json', json_encode(['pull_request' => ['number' => 7, 'body' => null,
            'author_association' => 'MEMBER', 'head' => ['sha' => self::HEAD]]]));
        file_put_contents($this->dir . '/paths.txt', implode("\0", $paths) . "\0");
        file_put_contents($this->dir . '/comments.json', json_encode([['body' => 'Review: x @ ' . self::HEAD . ' — POPRAWKI',
            'author_association' => 'MEMBER', 'user' => ['login' => "a\n::error::forged"]]]));
        [$status, $out, $err] = $this->runCheck(['--event', $this->dir . '/event.json', '--paths', $this->dir . '/paths.txt',
            '-z', '--exempt', self::EXEMPT, '--comments', $this->dir . '/comments.json'], []);

        self::assertSame(1, $status, 'a forged line passed the check: ' . $out . $err);
        self::assertSame([], self::commandLines($out . "\n" . $err), 'the check printed a runner command');
        // The guards: every name and the login were reported, not dropped.
        self::assertStringContainsString(count($paths) . ' changed path(s) on the risk list', $out);
        self::assertStringContainsString(substr($forged, 2), $out, 'the forged name is not reported');
        self::assertStringContainsString('a comment by a', $out);
    }

    /**
     * The workflow's step stops the runner's commands, with a token nobody knows in advance,
     * before the check prints the pull request's names, and resumes them after it, failed or
     * not: the only commands in its log are its own, and the check's verdict stands.
     */
    public function testTheWorkflowStepStopsTheRunnersCommandsAroundTheCheck(): void
    {
        $server = $this->startFakeApi([]);
        try {
            $tokens = [];
            foreach (["src/x\n::notice title=Review trail::Reviewed: przeglad-bezpieczenstwa — AKCEPTACJA", '::warning::x'] as $name) {
                $repo = $this->repository(true);
                file_put_contents("{$repo}/{$name}", "x\n");
                [$status, $out] = $this->runStep($repo, 'a name that holds a command');
                self::assertSame(1, $status, $out);
                $commands = self::commandLines($out);
                self::assertCount(3, $commands, 'commands other than the step\'s own: ' . implode(' | ', $commands));
                self::assertStringStartsWith('::add-mask::', $commands[0]);
                self::assertSame(1, preg_match('/^::stop-commands::([0-9a-f]{32})$/', $commands[1], $stop), $commands[1]);
                self::assertSame("::{$stop[1]}::", $commands[2], 'the commands are not resumed with the token');
                // The check's report lies between the stop and the resumption.
                $report = strpos($out, 'changed path(s) on the risk list');
                self::assertNotFalse($report, 'the check reported nothing: ' . $out);
                self::assertGreaterThan(strpos($out, $commands[1]), $report);
                self::assertLessThan(strpos($out, $commands[2]), $report);
                $tokens[] = $stop[1];
            }
            self::assertNotSame($tokens[0], $tokens[1], 'the token is the same on every run');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    /**
     * The lines of a log that the runner reads as commands: those that start with `::` once
     * the leading whitespace is trimmed, and those that hold the legacy `##[`.
     *
     * @return list<string>
     */
    private static function commandLines(string $log): array
    {
        $commands = [];
        foreach (preg_split('/\r\n|\r|\n/', $log) ?: [] as $line) {
            $trimmed = preg_replace('/^[\s\p{Z}]+/u', '', $line);
            self::assertIsString($trimmed, 'the log is not UTF-8');
            if (strncmp($trimmed, '::', 2) === 0 || strpos($line, '##[') !== false) {
                $commands[] = $trimmed;
            }
        }
        return $commands;
    }

    /**
     * Nothing of the head runs or lands in the work tree: its check, made to leave a mark when
     * run, leaves none; the checkout stays on the base, clean; the token the step fetches with
     * is kept nowhere.
     */
    public function testTheWorkflowStepNeverChecksOutOrRunsTheHead(): void
    {
        $server = $this->startFakeApi([]);
        try {
            $mark = $this->dir . '/the-head-ran';
            $repo = $this->repository(true);
            file_put_contents("{$repo}/.github/review-trail/check.py",
                "import pathlib, sys\npathlib.Path(" . json_encode($mark) . ").write_text('ran')\nsys.exit(0)\n");
            file_put_contents("{$repo}/src/run-me.sh", "#!/bin/sh\ntouch " . escapeshellarg($mark) . "\n");
            [$status, $out, $runner] = $this->runStep($repo, 'a head that would run');

            self::assertSame(1, $status, $out);
            self::assertFileDoesNotExist($mark, 'a file of the head ran');
            self::assertSame($this->git($repo, ['rev-parse', 'main']), $this->git($runner, ['rev-parse', 'HEAD']), 'the work tree left the base');
            self::assertSame('', $this->git($runner, ['status', '--porcelain']), 'a file of the head reached the work tree');
            self::assertFileDoesNotExist("{$runner}/src/run-me.sh");
            // The guard: the head was fetched, so the step did see it.
            self::assertSame('commit', $this->git($runner, ['cat-file', '-t', $this->git($repo, ['rev-parse', 'HEAD'])]));
            $config = (string)file_get_contents("{$runner}/.git/config");
            self::assertStringNotContainsStringIgnoringCase('extraheader', $config);
            self::assertStringNotContainsString('test-token', $config);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    /**
     * A repository on its base commit (with the review trail's files or without), then a
     * branch for the pull request's change: the stand-in for GitHub the step fetches from.
     *
     * @param string|null $exempt The base's exempt list; null: this repository's.
     */
    private function repository(bool $withTrail, ?string $exempt = null): string
    {
        $repo = $this->dir . '/repo-' . bin2hex(random_bytes(4));
        mkdir("{$repo}/src", 0700, true);
        mkdir("{$repo}/docs", 0700, true);
        file_put_contents("{$repo}/src/Receiver.php", "<?php\n");
        file_put_contents("{$repo}/docs/development.md", "# A\n");
        $this->git($repo, ['init', '-q', '-b', 'main']);
        $paths = ['src/Receiver.php', 'docs/development.md'];
        if ($withTrail) {
            mkdir("{$repo}/.github/review-trail", 0700, true);
            copy(self::CHECK, "{$repo}/.github/review-trail/check.py");
            if ($exempt === null) {
                copy(self::EXEMPT, "{$repo}/.github/review-trail/exempt-paths.txt");
            } else {
                file_put_contents("{$repo}/.github/review-trail/exempt-paths.txt", $exempt);
            }
            $paths = array_merge($paths, ['.github/review-trail/check.py', '.github/review-trail/exempt-paths.txt']);
        }
        $this->git($repo, array_merge(['add', '--'], $paths));
        $this->git($repo, ['commit', '-q', '-m', 'base']);
        $this->git($repo, ['checkout', '-q', '-b', 'pr']);
        return $repo;
    }

    /**
     * Commits the work tree's changes as the pull request's head, makes the runner's checkout
     * — the base branch alone, as actions/checkout fetches it for `pull_request_target` — and
     * runs the workflow's step there.
     *
     * @return array{0:int,1:string,2:string} The exit status, the output, the checkout.
     */
    private function runStep(string $repo, string $message): array
    {
        $this->git($repo, ['add', '--', '.']);
        $this->git($repo, ['commit', '-q', '-m', $message]);
        $base = $this->git($repo, ['rev-parse', 'main']);
        $head = $this->git($repo, ['rev-parse', 'HEAD']);
        $runner = "{$repo}.checkout";
        mkdir($runner, 0700);
        $this->git($runner, ['init', '-q', '-b', 'main']);
        $this->git($runner, ['remote', 'add', 'origin', $repo]);
        $this->git($runner, ['fetch', '-q', '--no-tags', 'origin', '+refs/heads/main:refs/remotes/origin/main']);
        $this->git($runner, ['checkout', '-q', '-B', 'main', 'origin/main']);
        file_put_contents("{$repo}.event.json", json_encode(['pull_request' => ['number' => 7, 'body' => null,
            'author_association' => 'MEMBER', 'head' => ['sha' => $head], 'base' => ['sha' => $base]]]));
        $temp = "{$repo}.runner";
        mkdir($temp, 0700);
        $steps = WorkflowYaml::file(self::ROOT . '/.github/workflows/review-trail.yml')['jobs']['trail']['steps'];
        $run = (string)$steps[1]['run'];
        self::assertNotSame('', $run, 'the step has no script');
        $env = $this->apiEnv() + ['BASE_SHA' => $base, 'HEAD_SHA' => $head, 'GITHUB_EVENT_PATH' => "{$repo}.event.json",
            'RUNNER_TEMP' => $temp, 'HOME' => $this->dir, 'GIT_CONFIG_NOSYSTEM' => '1'];
        $process = proc_open(['bash', '-e', '-c', $run], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $runner, $env);
        self::assertIsResource($process);
        $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out, $runner];
    }

    /**
     * @param list<string> $arguments
     * @return string Its standard output, trimmed.
     */
    private function git(string $repo, array $arguments): string
    {
        $process = proc_open(array_merge(['git', '-c', 'user.name=test', '-c', 'user.email=test@example.invalid',
            '-c', 'commit.gpgsign=false', '-c', 'init.defaultBranch=main'], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo, ['HOME' => $this->dir, 'PATH' => (string)getenv('PATH'),
                'GIT_CONFIG_NOSYSTEM' => '1']);
        self::assertIsResource($process);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), 'git ' . implode(' ', $arguments) . ': ' . $err);
        return trim($out);
    }

    /** @return array<string,string> What the check needs to read the fake API. */
    private function apiEnv(): array
    {
        return ['GITHUB_API_URL' => 'http://127.0.0.1:' . $this->apiPort, 'GITHUB_REPOSITORY' => 'minos-moderation/plugin-wordpress',
            'GITHUB_TOKEN' => 'test-token', 'PATH' => (string)getenv('PATH')];
    }

    /**
     * A fake of GitHub's API that lists the comments of pull request 7, page by page, to the
     * test token only, and logs every request.
     *
     * @param list<array<string,mixed>> $comments
     * @return resource The server's process.
     */
    private function startFakeApi(array $comments)
    {
        file_put_contents($this->dir . '/api.json', json_encode($comments));
        file_put_contents($this->dir . '/router.php', <<<'PHP'
            <?php
            $log = getenv('FAKE_API_LOG');
            file_put_contents($log, ($_SERVER['REQUEST_URI'] ?? '') . ' ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? '') . "\n", FILE_APPEND);
            if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer test-token') { http_response_code(401); echo '{}'; return true; }
            if (!preg_match('#^/repos/minos-moderation/plugin-wordpress/issues/7/comments\?per_page=100&page=(\d+)$#', $_SERVER['REQUEST_URI'] ?? '', $m)) {
                http_response_code(404); echo '{}'; return true;
            }
            $all = json_decode((string)file_get_contents(getenv('FAKE_API_DATA')), true);
            header('Content-Type: application/json');
            echo json_encode(array_slice($all, ((int)$m[1] - 1) * 100, 100));
            return true;
            PHP);
        $port = self::freePort();
        $server = proc_open([PHP_BINARY, '-q', '-S', "127.0.0.1:{$port}", $this->dir . '/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes,
            null, ['FAKE_API_LOG' => $this->dir . '/api.log', 'FAKE_API_DATA' => $this->dir . '/api.json']);
        self::assertIsResource($server);
        $this->apiPort = $port;
        self::waitFor($port);
        return $server;
    }

    /**
     * Runs the check on a pull request event for HEAD.
     *
     * @param list<string>                    $paths
     * @param list<array<string,mixed>>|null  $comments Null: the script reads the API.
     * @return array{0:int,1:string,2:string}
     */
    private function check(array $paths, ?array $comments, ?string $body, string $author = 'MEMBER'): array
    {
        $this->writeInputs($paths, $comments ?? [], $body, $author);
        return $this->runCheck(['--event', $this->dir . '/event.json', '--paths', $this->dir . '/paths.txt',
            '--exempt', self::EXEMPT, '--comments', $this->dir . '/comments.json'], []);
    }

    /**
     * @param list<string>                   $paths
     * @param list<array<string,mixed>>|null $comments
     */
    private function writeInputs(array $paths, ?array $comments, ?string $body, string $author): void
    {
        file_put_contents($this->dir . '/event.json', json_encode(['action' => 'edited', 'pull_request' => [
            'number' => 7, 'body' => $body, 'author_association' => $author,
            'head' => ['sha' => self::HEAD], 'base' => ['sha' => self::OLD_HEAD],
        ]]));
        file_put_contents($this->dir . '/paths.txt', implode("\n", $paths) . "\n");
        if ($comments !== null) {
            file_put_contents($this->dir . '/comments.json', json_encode($comments, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * @param list<string>          $arguments
     * @param array<string,string>  $env An empty array: the test's own environment.
     * @return array{0:int,1:string,2:string}
     */
    private function runCheck(array $arguments, array $env): array
    {
        $process = proc_open(array_merge(['python3', '-I', self::CHECK], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env === [] ? null : $env);
        self::assertIsResource($process);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string)stream_socket_get_name($socket, false);
        fclose($socket);
        return (int)substr($name, strrpos($name, ':') + 1);
    }

    private static function waitFor(int $port): void
    {
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            usleep(50000);
        }
        self::fail("the fake API did not start on port {$port}");
    }
}
