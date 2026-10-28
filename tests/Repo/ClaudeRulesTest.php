<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * The rules for Claude Code sessions stay findable and cheap: the root `CLAUDE.md`, read by
 * every session, keeps to a size budget and names every scoped file, and every project
 * agent pins its model. A read-only agent lists its tools, so it cannot inherit Edit and
 * Write. A Sonnet agent that edits files works from a list of allowed paths, so its "Risk
 * list" block must name the paths it may not touch (the receiver, the submission, every
 * file that decides publish or hold, the key and secret handling, what leaves the site and
 * what the plugin keeps, CI and the rules), and its "You may change" block none of them.
 * The checker runs on the real agents and on fixtures it must reject.
 */
final class ClaudeRulesTest extends TestCase
{
    /** Bytes. Lowering is free; raising needs a sentence in the commit. */
    private const ROOT_LIMIT = 2600;

    /** Bytes, for every `CLAUDE.md` below the root. */
    private const SCOPED_LIMIT = 1600;

    /** Agents allowed to edit files. Every other agent is read-only. */
    private const WRITERS = ['programista', 'programista-prosty'];

    /** The tools that change files. */
    private const WRITING_TOOLS = ['Edit', 'Write', 'NotebookEdit'];

    /**
     * What a Sonnet agent that edits files must name in its "Risk list" block, and never in
     * its "You may change" block. The paths among them must exist, so a rename cannot leave
     * the list naming nothing.
     */
    private const SONNET_RISK_MARKERS = [
        'src/Receiver.php', 'src/Submission.php', 'src/Outcome.php', 'src/Sweeper.php',
        'src/Settings.php', 'src/Admin/SettingsPage.php', 'src/Log.php', 'src/Platform.php',
        'src/Plugin.php', 'src/Text.php', 'src/Meta.php', 'src/Retention.php', 'src/Upgrade.php',
        'src/Uninstaller.php', 'uninstall.php', '.github/', '.claude/', 'CLAUDE.md', 'composer.json',
    ];

    /** Where the agent's blocks start: a line containing the first, a line starting with the second. */
    private const ALLOWED_HEADING = 'You may change:';
    private const RISK_HEADING = 'Risk list';

    private const MODELS = ['opus', 'sonnet', 'haiku', 'fable'];

    private const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    private const ROOT = __DIR__ . '/../..';

    /** The fixtures the checker must reject, one directory of agents each. */
    private const FIXTURES = __DIR__ . '/fixtures/agents';

    public function testTheRootFileStaysWithinItsBudget(): void
    {
        $size = strlen((string)file_get_contents(self::ROOT . '/CLAUDE.md'));
        self::assertLessThanOrEqual(self::ROOT_LIMIT, $size,
            'move a rule that one directory needs into that directory\'s CLAUDE.md');
    }

    public function testEveryScopedFileStaysWithinItsBudgetAndIsNamedInTheRoot(): void
    {
        $root = (string)file_get_contents(self::ROOT . '/CLAUDE.md');
        // The plugin has no scoped file today; the search itself is guarded in scopedFiles().
        foreach (self::scopedFiles() as $path) {
            self::assertLessThanOrEqual(self::SCOPED_LIMIT,
                strlen((string)file_get_contents(self::ROOT . '/' . $path)), $path);
            self::assertStringContainsString("`{$path}`", $root, "the root CLAUDE.md must name {$path}");
        }
    }

    public function testTheProjectsAgentsKeepEveryRule(): void
    {
        $agents = self::agents(self::ROOT . '/.claude/agents');
        self::assertSame([], self::problems($agents));
        // The guards: the rules about writers and Sonnet writers checked something real.
        self::assertNotEmpty(array_intersect(self::WRITERS, array_keys($agents)), 'no implementing agent found');
        self::assertNotEmpty(array_filter($agents, static function (array $agent): bool {
            return ($agent['fields']['model'] ?? null) === 'sonnet' && self::writes($agent['fields']);
        }), 'no Sonnet agent that writes was found: the risk list check checks nothing');
        self::assertNotEmpty(array_filter(array_keys($agents), static function (string $name): bool {
            return !in_array($name, self::WRITERS, true);
        }), 'no read-only agent was found: the tools check checks nothing');
    }

    public function testEveryRiskMarkerNamesAPathThatExists(): void
    {
        foreach (self::SONNET_RISK_MARKERS as $marker) {
            self::assertFileExists(self::ROOT . '/' . rtrim($marker, '/'), "the risk marker {$marker} names nothing");
        }
    }

    public function testTheCheckerRejectsAReviewerWithoutAToolsLine(): void
    {
        $problems = self::problems(self::agents(self::FIXTURES . '/reviewer-without-tools'));

        self::assertContains('przeglad-fikstura: a read-only agent must list its tools'
            . ' (without a `tools:` line it inherits Edit and Write)', $problems);
    }

    public function testTheCheckerRejectsARiskPathInTheSonnetAgentsAllowedList(): void
    {
        $problems = self::problems(self::agents(self::FIXTURES . '/marker-in-allowed-block'));

        self::assertContains('programista-prosty: names src/Receiver.php in its "You may change" block', $problems);
    }

    /**
     * Everything wrong with a set of agents, one line each; empty when they keep the rules.
     *
     * @param array<string,array{fields:array<string,string>,body:string}> $agents By file name.
     * @return array<int,string> The problems.
     */
    private static function problems(array $agents): array
    {
        $problems = [];
        foreach ($agents as $name => $agent) {
            $fields = $agent['fields'];
            if (($fields['name'] ?? null) !== $name) {
                $problems[] = "{$name}: the name must match the file";
            }
            if (!in_array($fields['model'] ?? null, self::MODELS, true)) {
                $problems[] = "{$name}: no pinned model";
            }
            if (!in_array($fields['effort'] ?? null, self::EFFORTS, true)) {
                $problems[] = "{$name}: no pinned effort";
            }
            if (($fields['description'] ?? '') === '') {
                $problems[] = "{$name}: no description";
            }
            if (in_array($name, self::WRITERS, true)) {
                if (($fields['model'] ?? null) === 'fable') {
                    $problems[] = "{$name}: Fable does not implement";
                }
            } elseif (($fields['tools'] ?? '') === '') {
                $problems[] = "{$name}: a read-only agent must list its tools"
                    . ' (without a `tools:` line it inherits Edit and Write)';
            } elseif (self::writes($fields)) {
                $problems[] = "{$name}: a read-only agent may not list "
                    . implode(', ', self::WRITING_TOOLS);
            }
            if (($fields['model'] ?? null) === 'sonnet' && self::writes($fields)) {
                $problems = array_merge($problems, self::riskListProblems($name, $agent['body']));
            }
        }
        return $problems;
    }

    /**
     * What a Sonnet agent that writes gets wrong about its risk list.
     *
     * @param string $name The agent.
     * @param string $body Its instructions.
     * @return array<int,string> The problems.
     */
    private static function riskListProblems(string $name, string $body): array
    {
        $allowed = self::block($body, self::ALLOWED_HEADING, false);
        $risk = self::block($body, self::RISK_HEADING, true);
        if ($allowed === null || $risk === null) {
            return ["{$name}: needs a \"" . self::ALLOWED_HEADING . '" block and a "' . self::RISK_HEADING . '" block'];
        }
        $problems = [];
        foreach (self::SONNET_RISK_MARKERS as $marker) {
            if (strpos($risk, $marker) === false) {
                $problems[] = "{$name}: must name {$marker} in its \"" . self::RISK_HEADING . '" block';
            }
            if (strpos($allowed, $marker) !== false) {
                $problems[] = "{$name}: names {$marker} in its \"You may change\" block";
            }
        }
        return $problems;
    }

    /**
     * One block of an agent's instructions: the line with the heading and the list after
     * it (lines starting with `- `, or indented continuations), up to the first other line.
     *
     * @param string $body      The instructions.
     * @param string $heading   The heading.
     * @param bool   $atStart   Whether the heading starts its line (or is anywhere in it).
     * @return string|null The block, or null when no line has the heading.
     */
    private static function block(string $body, string $heading, bool $atStart): ?string
    {
        $lines = explode("\n", $body);
        foreach ($lines as $i => $line) {
            $at = strpos($line, $heading);
            if ($at === false || ($atStart && $at !== 0)) {
                continue;
            }
            $block = [$line];
            for ($j = $i + 1; $j < count($lines) && preg_match('/^(- |  )/', $lines[$j]) === 1; $j++) {
                $block[] = $lines[$j];
            }
            return implode("\n", $block);
        }
        return null;
    }

    /** @param array<string,string> $fields */
    private static function writes(array $fields): bool
    {
        $tools = array_map('trim', explode(',', $fields['tools'] ?? ''));
        return array_intersect(self::WRITING_TOOLS, $tools) !== [];
    }

    /**
     * Every `CLAUDE.md` below the root, as paths relative to it.
     *
     * @return array<int,string>
     */
    private static function scopedFiles(): array
    {
        $root = realpath(self::ROOT);
        $skip = ['.git', 'vendor', 'node_modules', 'build'];
        $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator((string)$root, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $file) use ($skip): bool {
                return !($file->isDir() && in_array($file->getFilename(), $skip, true));
            }
        ));
        $found = [];
        $sawRoot = false;
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen((string)$root) + 1);
            if ($file->getFilename() !== 'CLAUDE.md') {
                continue;
            }
            if ($path === 'CLAUDE.md') {
                $sawRoot = true;
            } else {
                $found[] = $path;
            }
        }
        // The guard: a search that cannot find the root file would find no scoped one either.
        self::assertTrue($sawRoot, 'the search did not find the root CLAUDE.md: it is broken');
        sort($found);
        return $found;
    }

    /**
     * Every agent of a directory by file name: its front matter and its body.
     *
     * @param string $dir The directory (`.claude/agents`, or a fixture).
     * @return array<string,array{fields:array<string,string>,body:string}>
     */
    private static function agents(string $dir): array
    {
        $agents = [];
        foreach (glob($dir . '/*.md') ?: [] as $file) {
            $text = (string)file_get_contents($file);
            $fields = [];
            $body = $text;
            if (preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $text, $m)) {
                $body = $m[2];
                foreach (explode("\n", $m[1]) as $line) {
                    $pair = explode(':', $line, 2);
                    if (count($pair) === 2) {
                        $fields[trim($pair[0])] = trim($pair[1]);
                    }
                }
            }
            $agents[basename($file, '.md')] = ['fields' => $fields, 'body' => $body];
        }
        self::assertNotEmpty($agents, "no agents found in {$dir}");
        return $agents;
    }
}
