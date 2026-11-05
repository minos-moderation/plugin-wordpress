<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * The PreToolUse hook that refuses staging the whole work tree (`.claude/hooks/no-add-all.sh`
 * and `no_add_all.py`), run through the command `.claude/settings.json` registers, as Claude
 * Code runs it: what it refuses, what it lets through (a quoted message, a here-document, an
 * explicit path), and that unreadable input never stops a session.
 */
final class AddAllHookTest extends TestCase
{
    private string $command = '';

    protected function setUp(): void
    {
        self::assertNotSame('', trim((string)shell_exec('command -v python3')), 'python3 is needed: the hook parses with it');
        $settings = json_decode((string)file_get_contents(__DIR__ . '/../../.claude/settings.json'), true);
        foreach ($settings['hooks']['PreToolUse'] ?? [] as $entry) {
            foreach ($entry['hooks'] ?? [] as $hook) {
                if (strpos((string)($hook['command'] ?? ''), 'no-add-all.sh') !== false) {
                    $this->command = (string)$hook['command'];
                }
            }
        }
        self::assertNotSame('', $this->command, 'the hook is not registered in .claude/settings.json');
    }

    /** @return iterable<string,array{0:string}> */
    public static function refused(): iterable
    {
        foreach ([
            'git add -A', 'git add --all', 'git add .', 'git add -Av', 'git add --al', 'git add --no-ignore-removal',
            'git stage -A', 'git add -- .', 'git add *', 'git add :/', 'git -C /tmp/w add .', 'git --no-pager add -A',
            'cd /tmp/w && git add -A', "git status\ngit add .", 'GIT_DIR=.git git add -A', 'sudo -u me git add -A',
            "bash -c 'git add -A'", 'sh -c "cd x && git add ."', 'eval git add -A', '(git add -A)', 'git add . 2>&1',
            "git add \\\n  -A", 'git add -u', 'git add --update', 'git add -uv', 'git stage --up',
            'git commit -a -m x', 'git commit -am "x"', "git commit -qam 'x'", 'git commit --all -m x',
            'git commit -C HEAD -a', 'git -C /tmp/w commit --al',
        ] as $command) {
            yield $command => [$command];
        }
    }

    /** @dataProvider refused */
    public function testStagingTheWholeTreeIsRefused(string $command): void
    {
        [$status, $err] = ClaudeRulesTest::runHook($this->command, $command);
        self::assertSame(2, $status, $err);
        self::assertStringContainsString('Stage explicit paths', $err);
    }

    /** @return iterable<string,array{0:string}> */
    public static function letThrough(): iterable
    {
        foreach ([
            'git add README.md', 'git add src/Receiver.php tests/Unit/', 'git add -p', 'git add *.php',
            'git log --all', 'echo git add -A', "grep -n 'git add .' CLAUDE.md", "git commit -m 'never git add -A'",
            "git commit -q -F - <<'EOF'\nSubject\n\nNever git add -A, never git add .\nEOF",
            'git add docs/x.md # not git add -A', "git add 'unbalanced", 'git diff --name-only origin/main...HEAD',
            'git add -u src/', 'git add -u -- tools/x.php', 'git add --update --pathspec-from-file=list.txt',
            'git commit -m "add -a"', 'git commit -m -a', 'git commit -uall -m x', 'git commit -q -F msg.txt',
            'git commit --amend --no-edit', 'git commit -- tools/x.php',
        ] as $command) {
            yield $command => [$command];
        }
    }

    /** @dataProvider letThrough */
    public function testAnythingElseRuns(string $command): void
    {
        [$status, $err] = ClaudeRulesTest::runHook($this->command, $command);
        self::assertSame(0, $status, $err);
        self::assertSame('', $err);
    }

    public function testInputItCannotReadNeverStopsTheSession(): void
    {
        foreach (['', 'not json', '{"tool_input": {"command": 7}}', '{"tool_name": "Bash"}'] as $input) {
            $process = proc_open(['bash', '-c', $this->command], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes, null, ['CLAUDE_PROJECT_DIR' => (string)realpath(__DIR__ . '/../..'), 'PATH' => (string)getenv('PATH')]);
            self::assertIsResource($process);
            fwrite($pipes[0], $input);
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), "input: {$input}");
        }
    }
}
