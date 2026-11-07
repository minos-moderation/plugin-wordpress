<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * The review trail CI checks (`.github/review-trail/check.py`, run by
 * `.github/workflows/review-trail.yml`): a pull request that changes a path on the risk list
 * passes only with `Review: <agent> @ <head SHA> — AKCEPTACJA` for its current head, in its
 * description or a comment by someone who may push (the rule is in this repository's
 * CLAUDE.md).
 * The script runs as the workflow runs it, on synthetic events, pull requests, path lists,
 * comments and permissions, and against a fake of GitHub's API for what it reads itself:
 * the pull request, its comments and the permission of who wrote them, never the event's
 * `author_association`, which GitHub computes for the app that receives the event.
 */
final class ReviewTrailTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const CHECK = self::ROOT . '/.github/review-trail/check.py';

    private const EXEMPT = self::ROOT . '/.github/review-trail/exempt-paths.txt';

    private const HEAD = '0123456789abcdef0123456789abcdef01234567';

    private const OLD_HEAD = 'fedcba9876543210fedcba9876543210fedcba98';

    /**
     * The repository permission of each login, as the collaborators API gives it; a login
     * not here has none (the API answers 404).
     */
    private const PERMISSIONS = ['owner-login' => 'admin', 'maintainer' => 'maintain', 'writer' => 'write',
        'triager' => 'triage', 'reader' => 'read'];

    /** A path on the risk list, for the tests that need any one. */
    private const RISK_PATH = 'src/x.php';

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
        // A comment by a login; the event's association is what GitHub's payload said of it.
        $member = static function (string $body, string $login = 'owner-login', string $association = 'MEMBER'): array {
            return ['body' => $body, 'author_association' => $association, 'user' => ['login' => $login]];
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
        yield 'by someone without a role in the repository' => [null, [$member($accept, 'stranger', 'NONE')], 1];
        yield 'by a reader the payload calls a member' => [null, [$member($accept, 'reader', 'MEMBER')], 1];
        yield 'by a triager the payload calls a collaborator' => [null, [$member($accept, 'triager', 'COLLABORATOR')], 1];
        yield 'by an admin the payload calls a contributor' => [null, [$member($accept, 'owner-login', 'CONTRIBUTOR')], 0];
        yield 'by a maintainer the payload calls nobody' => [null, [$member($accept, 'maintainer', 'NONE')], 0];
        yield 'by a writer' => [null, [$member($accept, 'writer', 'NONE')], 0];
        yield 'a reader\'s acceptance after a writer\'s changes asked' => [null,
            [$member('Review: x @ ' . self::HEAD . ' — POPRAWKI', 'writer'), $member($accept, 'reader')], 1];
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

    /** @return iterable<string,array{0:string,1:string,2:int}> */
    public static function descriptionAuthors(): iterable
    {
        // The payload's association is what failed the owner's own lines: it decides nothing.
        yield 'an admin the payload calls a contributor' => ['owner-login', 'CONTRIBUTOR', 0];
        yield 'an admin the payload calls nobody' => ['owner-login', 'NONE', 0];
        yield 'a writer' => ['writer', 'NONE', 0];
        yield 'a reader the payload calls a member' => ['reader', 'MEMBER', 1];
        yield 'a reader the payload calls the owner' => ['reader', 'OWNER', 1];
        yield 'a triager' => ['triager', 'COLLABORATOR', 1];
        yield 'someone without a role' => ['stranger', 'COLLABORATOR', 1];
    }

    /**
     * The description counts when its author may push to the repository, whatever the
     * event's `author_association` says.
     *
     * @dataProvider descriptionAuthors
     */
    public function testTheDescriptionCountsByItsAuthorsPermission(string $author, string $association, int $expected): void
    {
        $accept = 'Review: przeglad-bezpieczenstwa @ ' . self::HEAD . ' — AKCEPTACJA';
        [$status, $out] = $this->check([self::RISK_PATH], [], $accept, $author, $association);
        self::assertSame($expected, $status, $out);
        self::assertStringContainsString(self::PERMISSIONS[$author] ?? 'none', $out);
    }

    /**
     * The description is the pull request's as it is now, not the event's copy: a line only
     * in the event does not count, a line written since does.
     */
    public function testTheDescriptionIsTheLiveOne(): void
    {
        $accept = 'Review: przeglad-bezpieczenstwa @ ' . self::HEAD . ' — AKCEPTACJA';
        [$status, $out] = $this->check([self::RISK_PATH], [], null, 'owner-login', 'MEMBER', $accept);
        self::assertSame(1, $status, 'a line in the event alone counted: ' . $out);
        [$status, $out] = $this->check([self::RISK_PATH], [], $accept, 'owner-login', 'MEMBER', null);
        self::assertSame(0, $status, $out);
        self::assertStringContainsString('in the description', $out);
    }

    /**
     * One line tells who wrote the description, their permission, the event's association
     * and the description's length; the description itself is never printed.
     */
    public function testTheLogNamesTheDescriptionsAuthorAndLengthButNotItsText(): void
    {
        $body = "Notatka-ktorej-nie-ma-w-logu zażółć\n\nReview: przeglad-bezpieczenstwa @ " . self::HEAD . ' — AKCEPTACJA';
        [$status, $out] = $this->check([self::RISK_PATH], [], $body, 'owner-login', 'CONTRIBUTOR');
        self::assertSame(0, $status, $out);
        $line = preg_grep('/^The description: /', explode("\n", $out)) ?: [];
        self::assertCount(1, $line, 'no single diagnostic line: ' . $out);
        $line = (string)reset($line);
        self::assertStringContainsString(preg_match_all('/./su', $body) . ' character(s)', $line);
        self::assertStringContainsString('by owner-login', $line);
        self::assertStringContainsString('permission admin', $line);
        self::assertStringContainsString('CONTRIBUTOR', $line);
        self::assertStringNotContainsString('Notatka-ktorej-nie-ma-w-logu', $out, 'the description reached the log');
    }

    /**
     * A run for a head the pull request has left fails: the run for the new head decides, and
     * an old run re-run later cannot turn green on a description written for the new one.
     */
    public function testARunForAHeadThePullRequestHasLeftFails(): void
    {
        $accept = 'Review: przeglad-bezpieczenstwa @ ' . self::HEAD . ' — AKCEPTACJA';
        [$status, $out] = $this->check([self::RISK_PATH], [], $accept, 'owner-login', 'MEMBER', null, self::OLD_HEAD);
        self::assertSame(1, $status, $out);
        self::assertStringContainsString('head is now ' . self::OLD_HEAD, $out);
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
        yield 'a pull request that is no object' => [['--event', '{dir}/event.json', '--paths', '{dir}/paths.txt', '--exempt',
            self::EXEMPT, '--pull', '{dir}/comments.json', '--comments', '{dir}/comments.json', '--permissions', '{dir}/event.json']];
        yield 'permissions that are no object' => [['--event', '{dir}/event.json', '--paths', '{dir}/paths.txt', '--exempt',
            self::EXEMPT, '--pull', '{dir}/pull.json', '--comments', '{dir}/comments.json', '--permissions', '{dir}/comments.json']];
        yield 'an event without the pull request\'s number' => [['--event', '{dir}/no-number.json', '--paths', '{dir}/paths.txt',
            '--exempt', self::EXEMPT, '--pull', '{dir}/pull.json', '--comments', '{dir}/comments.json', '--permissions',
            '{dir}/permissions.json']];
    }

    /**
     * @dataProvider badInputs
     * @param list<string> $arguments
     */
    public function testBadInputExitsTwo(array $arguments): void
    {
        $this->writeInputs([self::RISK_PATH], [], null);
        file_put_contents($this->dir . '/comments.json', '[]');
        file_put_contents($this->dir . '/empty.txt', "# only a comment\n\n");
        file_put_contents($this->dir . '/no-number.json', json_encode(['pull_request' => ['head' => ['sha' => self::HEAD]]]));
        [$status, $out, $err] = $this->runCheck(str_replace('{dir}', $this->dir, $arguments), []);
        self::assertSame(2, $status, $out . $err);
    }

    /**
     * Without `--pull`, `--comments` and `--permissions` the script reads them from the API,
     * with the token, as in CI: the pull request once, every page of its comments, and the
     * permission of each author of a review line once. The owner's acceptance counts though
     * the payload calls them a contributor; a login the API does not know (404) has none.
     */
    public function testThePullRequestItsCommentsAndThePermissionsAreReadFromTheApi(): void
    {
        $accept = 'Review: przeglad-bezpieczenstwa @ ' . self::HEAD . ' — AKCEPTACJA';
        $comments = [];
        for ($i = 0; $i < 100; $i++) {
            $comments[] = ['body' => "comment {$i}", 'author_association' => 'MEMBER', 'user' => ['login' => 'a']];
        }
        $comments[] = ['body' => 'Review: x @ ' . self::HEAD . ' — POPRAWKI', 'author_association' => 'MEMBER',
            'user' => ['login' => 'reader']];
        $comments[] = ['body' => $accept, 'author_association' => 'MEMBER', 'user' => ['login' => 'reader']];
        $comments[] = ['body' => $accept, 'author_association' => 'NONE', 'user' => ['login' => 'ghost']];
        $comments[] = ['body' => $accept, 'author_association' => 'CONTRIBUTOR', 'user' => ['login' => 'owner-login']];
        $server = $this->startFakeApi($comments);
        try {
            $this->writeInputs([self::RISK_PATH], null, null, 'owner-login', 'CONTRIBUTOR');
            $env = $this->apiEnv();
            [$status, $out, $err] = $this->runCheck(['--event', $this->dir . '/event.json', '--paths', $this->dir . '/paths.txt',
                '--exempt', self::EXEMPT], $env);
            self::assertSame(0, $status, $out . $err);
            self::assertStringContainsString('a comment by owner-login', $out);
            self::assertStringContainsString('ghost (none)', $out, 'a login the API does not know has a permission');
            self::assertStringContainsString('reader (read)', $out);
            $requests = file($this->dir . '/api.log', FILE_IGNORE_NEW_LINES) ?: [];
            $paths = array_map(static function (string $request): string {
                return (string)strtok($request, ' ');
            }, $requests);
            $repository = '/repos/' . $env['GITHUB_REPOSITORY'];
            self::assertSame([
                "{$repository}/pulls/7",
                "{$repository}/collaborators/owner-login/permission",
                "{$repository}/issues/7/comments?per_page=100&page=1",
                "{$repository}/issues/7/comments?per_page=100&page=2",
                "{$repository}/collaborators/reader/permission",
                "{$repository}/collaborators/ghost/permission",
            ], $paths, 'two pages, then it stops; one permission read per login');
            foreach ($requests as $request) {
                self::assertStringEndsWith('Bearer test-token', $request);
            }
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
     * A permission the API refuses to give (as a token without `metadata: read` would get)
     * fails the check as bad input, naming the status; it never reads as "no permission".
     */
    public function testAPermissionTheApiRefusesFailsTheCheck(): void
    {
        $server = $this->startFakeApi([]);
        try {
            $this->writeInputs([self::RISK_PATH], null, null, 'refused');
            [$status, $out, $err] = $this->runCheck(['--event', $this->dir . '/event.json', '--paths', $this->dir . '/paths.txt',
                '--exempt', self::EXEMPT], $this->apiEnv());
            self::assertSame(2, $status, $out . $err);
            self::assertStringContainsString('HTTP 403', $err);
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
     * break, a carriage return or a leading `::`, or a login (the description's author's, a
     * commenter's, counted or not), never makes one, and cannot forge the check's own verdict.
     * The check runs on the names as the workflow gives them (`-z`).
     */
    public function testWhatAPullRequestNamesNeverBecomesARunnerCommand(): void
    {
        $forged = '::notice title=Review trail::Reviewed: przeglad-bezpieczenstwa @ ' . self::HEAD . ' — AKCEPTACJA';
        $author = "b\u{2028}::warning::forged";
        $commenter = "a\n::error::forged";
        file_put_contents($this->dir . '/pull.json', json_encode(['number' => 7, 'body' => null,
            'user' => ['login' => $author], 'head' => ['sha' => self::HEAD]]));
        file_put_contents($this->dir . '/permissions.json', json_encode([$author => 'admin', $commenter => 'admin']));
        $paths = ["src/x\n{$forged}", '::warning::x', '##[error]y', "src/z\r::error::w", "  ::add-mask::v"];
        file_put_contents($this->dir . '/event.json', json_encode(['pull_request' => ['number' => 7, 'body' => null,
            'author_association' => 'MEMBER', 'head' => ['sha' => self::HEAD]]]));
        file_put_contents($this->dir . '/paths.txt', implode("\0", $paths) . "\0");
        file_put_contents($this->dir . '/comments.json', json_encode([['body' => 'Review: x @ ' . self::HEAD . ' — POPRAWKI',
            'author_association' => 'MEMBER', 'user' => ['login' => $commenter]],
            ['body' => 'Review: y @ ' . self::HEAD . ' — AKCEPTACJA', 'author_association' => 'MEMBER',
            'user' => ['login' => "c\r::error::forged"]]]));
        [$status, $out, $err] = $this->runCheck(['--event', $this->dir . '/event.json', '--paths', $this->dir . '/paths.txt',
            '-z', '--exempt', self::EXEMPT, '--pull', $this->dir . '/pull.json', '--comments', $this->dir . '/comments.json',
            '--permissions', $this->dir . '/permissions.json'], []);

        self::assertSame(1, $status, 'a forged line passed the check: ' . $out . $err);
        self::assertSame([], self::commandLines($out . "\n" . $err), 'the check printed a runner command');
        // The guards: every name and the login were reported, not dropped.
        self::assertStringContainsString(count($paths) . ' changed path(s) on the risk list', $out);
        self::assertStringContainsString(substr($forged, 2), $out, 'the forged name is not reported');
        self::assertStringContainsString('a comment by a', $out);
        self::assertStringContainsString('by b%u2028::warning::forged', $out);
        self::assertStringContainsString('c%0D::error::forged (none)', $out);
    }

    /**
     * The log's escaping (`shown`): a C0 or C1 control, DEL, U+2028 and U+2029 are printed as
     * `%XX` or `%uXXXX`, and a `%` of the name as `%25`, so an escape in a name reads as the
     * name's own text, not as a character the runner would decode.
     */
    public function testTheLogEscapesControlsSeparatorsAndPercentSigns(): void
    {
        $name = "tools/c0\x01\x1b-del\x7f-c1\u{85}\u{9b}-sep\u{2028}\u{2029}-pct%0A%25.php";
        $this->writeInputs([], [], null);
        file_put_contents($this->dir . '/paths.txt', $name . "\0");
        [$status, $out, $err] = $this->runCheck(['--event', $this->dir . '/event.json', '--paths', $this->dir . '/paths.txt',
            '-z', '--exempt', self::EXEMPT, '--pull', $this->dir . '/pull.json', '--comments', $this->dir . '/comments.json',
            '--permissions', $this->dir . '/permissions.json'], []);

        self::assertSame(1, $status, $out . $err);
        self::assertStringContainsString('tools/c0%01%1B-del%7F-c1%85%9B-sep%u2028%u2029-pct%250A%2525.php', $out);
        self::assertSame(0, preg_match('/[\x00-\x09\x0b-\x1f\x7f]|\x{85}|\x{9b}|\x{2028}|\x{2029}/u', $out),
            'a control or separator character reached the log: ' . $out);
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
        // What the fake API gives as the pull request now: the same head, by the owner.
        file_put_contents($this->dir . '/pull.json', json_encode(['number' => 7, 'body' => null,
            'user' => ['login' => 'owner-login'], 'head' => ['sha' => $head]]));
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
     * A fake of GitHub's API, to the test token only, that logs every request: pull request 7
     * as `pull.json` has it, its comments page by page, and the permission of each login of
     * `PERMISSIONS` (`maintain` as `write` and `triage` as `read`, as the API's `permission`
     * gives them); another login is unknown (404), and `refused` is refused (403).
     *
     * @param list<array<string,mixed>> $comments
     * @return resource The server's process.
     */
    private function startFakeApi(array $comments)
    {
        file_put_contents($this->dir . '/api.json', json_encode(['comments' => $comments, 'permissions' => self::PERMISSIONS]));
        file_put_contents($this->dir . '/router.php', <<<'PHP'
            <?php
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            file_put_contents(getenv('FAKE_API_LOG'), $uri . ' ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? '') . "\n", FILE_APPEND);
            header('Content-Type: application/json');
            if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer test-token') { http_response_code(401); echo '{}'; return true; }
            $repository = preg_quote('/repos/' . getenv('FAKE_API_REPOSITORY'), '#');
            $data = json_decode((string)file_get_contents(getenv('FAKE_API_DATA')), true);
            if (preg_match("#^{$repository}/pulls/7$#", $uri)) {
                echo (string)file_get_contents(getenv('FAKE_API_PULL'));
                return true;
            }
            if (preg_match("#^{$repository}/issues/7/comments\\?per_page=100&page=(\\d+)$#", $uri, $m)) {
                echo json_encode(array_slice($data['comments'], ((int)$m[1] - 1) * 100, 100));
                return true;
            }
            if (preg_match("#^{$repository}/collaborators/([^/?]+)/permission$#", $uri, $m)) {
                $login = rawurldecode($m[1]);
                if ($login === 'refused') { http_response_code(403); echo '{}'; return true; }
                if (isset($data['permissions'][$login])) {
                    $role = $data['permissions'][$login];
                    $legacy = ['maintain' => 'write', 'triage' => 'read'];
                    echo json_encode(['permission' => $legacy[$role] ?? $role, 'role_name' => $role, 'user' => ['login' => $login]]);
                    return true;
                }
            }
            http_response_code(404);
            echo '{"message":"Not Found"}';
            return true;
            PHP);
        $port = self::freePort();
        $server = proc_open([PHP_BINARY, '-q', '-S', "127.0.0.1:{$port}", $this->dir . '/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes,
            null, ['FAKE_API_LOG' => $this->dir . '/api.log', 'FAKE_API_DATA' => $this->dir . '/api.json',
                'FAKE_API_PULL' => $this->dir . '/pull.json', 'FAKE_API_REPOSITORY' => $this->apiEnv()['GITHUB_REPOSITORY']]);
        self::assertIsResource($server);
        $this->apiPort = $port;
        self::waitFor($port);
        return $server;
    }

    /**
     * Runs the check on a pull request event for HEAD, the pull request, its comments and the
     * permissions given as files.
     *
     * @param list<string>              $paths
     * @param list<array<string,mixed>> $comments
     * @return array{0:int,1:string,2:string}
     */
    private function check(array $paths, array $comments, ?string $body, string $author = 'owner-login',
        string $association = 'MEMBER', ?string $eventBody = null, string $liveHead = self::HEAD): array
    {
        $this->writeInputs($paths, $comments, $body, $author, $association, $eventBody, $liveHead);
        return $this->runCheck(['--event', $this->dir . '/event.json', '--paths', $this->dir . '/paths.txt',
            '--exempt', self::EXEMPT, '--pull', $this->dir . '/pull.json', '--comments', $this->dir . '/comments.json',
            '--permissions', $this->dir . '/permissions.json'], []);
    }

    /**
     * Writes the event (for HEAD, with the payload's association and its copy of the
     * description), the pull request as it is now (`$body` by `$author`, at `$liveHead`), the
     * changed paths, the permissions and, unless null, the comments.
     *
     * @param list<string>                   $paths
     * @param list<array<string,mixed>>|null $comments
     */
    private function writeInputs(array $paths, ?array $comments, ?string $body, string $author = 'owner-login',
        string $association = 'MEMBER', ?string $eventBody = null, string $liveHead = self::HEAD): void
    {
        file_put_contents($this->dir . '/event.json', json_encode(['action' => 'edited', 'pull_request' => [
            'number' => 7, 'body' => $eventBody, 'author_association' => $association,
            'head' => ['sha' => self::HEAD], 'base' => ['sha' => self::OLD_HEAD],
        ]]));
        file_put_contents($this->dir . '/pull.json', json_encode(['number' => 7, 'body' => $body,
            'user' => ['login' => $author], 'head' => ['sha' => $liveHead]], JSON_UNESCAPED_UNICODE));
        file_put_contents($this->dir . '/permissions.json', json_encode(self::PERMISSIONS));
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
