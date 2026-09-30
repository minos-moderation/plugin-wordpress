<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * The rules for Claude Code sessions stay findable and cheap: the root `CLAUDE.md`, read by
 * every session, keeps to a size budget and names every scoped file, and every project
 * agent pins its model. A Sonnet agent that edits files works from a list of allowed paths,
 * so its file must name the paths it may not touch: the receiver, the submission, every
 * file that decides publish or hold, the key and secret handling, CI and the rules.
 */
final class ClaudeRulesTest extends TestCase
{
    /** Bytes. Lowering is free; raising needs a sentence in the commit. */
    private const ROOT_LIMIT = 2600;

    /** Bytes, for every `CLAUDE.md` below the root. */
    private const SCOPED_LIMIT = 1600;

    /** Agents allowed to edit files. Every other agent is read-only. */
    private const WRITERS = ['programista', 'programista-prosty'];

    /**
     * What a Sonnet agent that edits files must name as off limits. The paths among them
     * must exist, so a rename cannot leave the list naming nothing.
     */
    private const SONNET_RISK_MARKERS = [
        'src/Receiver.php', 'src/Submission.php', 'src/Outcome.php', 'src/Sweeper.php',
        'src/Settings.php', 'src/Admin/SettingsPage.php', 'src/Log.php', 'src/Platform.php',
        'src/Plugin.php', 'uninstall.php', '.github/', '.claude/', 'CLAUDE.md', 'composer.json',
    ];

    private const MODELS = ['opus', 'sonnet', 'haiku', 'fable'];

    private const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    private const ROOT = __DIR__ . '/../..';

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

    public function testEveryAgentPinsAModelAndAnEffort(): void
    {
        foreach (self::agents() as $name => $agent) {
            $fields = $agent['fields'];
            self::assertSame($name, $fields['name'] ?? null, "{$name}: the name must match the file");
            self::assertContains($fields['model'] ?? null, self::MODELS, "{$name}: no pinned model");
            self::assertContains($fields['effort'] ?? null, self::EFFORTS, "{$name}: no pinned effort");
            self::assertNotEmpty($fields['description'] ?? null, "{$name}: no description");
        }
    }

    public function testOnlyAnImplementingAgentMayWriteAndItNeverRunsOnFable(): void
    {
        foreach (self::agents() as $name => $agent) {
            if (in_array($name, self::WRITERS, true)) {
                self::assertNotSame('fable', $agent['fields']['model'] ?? null, "{$name}: Fable does not implement");
            } else {
                self::assertFalse(self::writes($agent['fields']), "{$name} is read-only and may not list Edit or Write");
            }
        }
    }

    public function testASonnetAgentThatWritesNamesTheRiskList(): void
    {
        $checked = 0;
        foreach (self::agents() as $name => $agent) {
            if (($agent['fields']['model'] ?? null) !== 'sonnet' || !self::writes($agent['fields'])) {
                continue;
            }
            $checked++;
            foreach (self::SONNET_RISK_MARKERS as $marker) {
                self::assertStringContainsString($marker, $agent['body'], "{$name} must name {$marker} as off limits");
                self::assertFileExists(self::ROOT . '/' . rtrim($marker, '/'), "the risk marker {$marker} names nothing");
            }
        }
        self::assertGreaterThan(0, $checked, 'no Sonnet agent that writes was found: the check checks nothing');
    }

    /** @param array<string,string> $fields */
    private static function writes(array $fields): bool
    {
        $tools = array_map('trim', explode(',', $fields['tools'] ?? ''));
        return in_array('Edit', $tools, true) || in_array('Write', $tools, true);
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
     * Every project agent by file name: its front matter and its body.
     *
     * @return array<string,array{fields:array<string,string>,body:string}>
     */
    private static function agents(): array
    {
        $agents = [];
        foreach (glob(self::ROOT . '/.claude/agents/*.md') ?: [] as $file) {
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
        self::assertNotEmpty($agents, 'no agents found in .claude/agents/');
        return $agents;
    }
}
