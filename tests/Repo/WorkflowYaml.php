<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Repo;

/**
 * Reads a GitHub Actions workflow into PHP arrays: the subset of YAML the workflows are
 * written in (block mappings and sequences, `|`/`>` block scalars, quoted and plain scalars,
 * one-line flow sequences, comments). Scalars stay strings; an empty value is null. Anything
 * outside that subset — a flow mapping, an anchor, a multi-line plain or quoted scalar, a tab —
 * throws, so a test built on it fails instead of reading a workflow wrongly. A PHP 7.4 copy
 * of the reader in minos-moderation/minos.
 */
final class WorkflowYaml
{
    /** @var list<array{indent:int,text:string,raw:string}> */
    private array $lines = [];

    private int $at = 0;

    private function __construct(string $text)
    {
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $raw) {
            if (strpos($raw, "\t") !== false && trim($raw) !== '' && ltrim($raw, ' ')[0] === "\t") {
                throw new \UnexpectedValueException('a tab indents a line');
            }
            $text = ltrim($raw, ' ');
            $this->lines[] = ['indent' => strlen($raw) - strlen($text), 'text' => rtrim($text), 'raw' => $raw];
        }
    }

    /**
     * @return array<string,mixed> The workflow's top-level mapping.
     * @throws \UnexpectedValueException When the text leaves the supported subset.
     */
    public static function parse(string $text): array
    {
        $reader = new self($text);
        $next = $reader->next();
        if ($next === null) {
            throw new \UnexpectedValueException('an empty workflow');
        }
        $value = $reader->block($next['indent']);
        if ($reader->next() !== null) {
            throw new \UnexpectedValueException('text after the top-level mapping, line ' . ($reader->at + 1));
        }
        if (!is_array($value) || ($value !== [] && array_keys($value) === range(0, count($value) - 1))) {
            throw new \UnexpectedValueException('the top level is not a mapping');
        }
        return $value;
    }

    /** @return array<string,mixed> The workflow file's top-level mapping. */
    public static function file(string $path): array
    {
        return self::parse((string)file_get_contents($path));
    }

    /** The next line that is neither blank nor a comment, without consuming it. */
    private function next(): ?array
    {
        while ($this->at < count($this->lines)) {
            $line = $this->lines[$this->at];
            if ($line['text'] !== '' && $line['text'][0] !== '#') {
                return $line;
            }
            $this->at++;
        }
        return null;
    }

    private static function isItem(string $text): bool
    {
        return $text === '-' || strncmp($text, '- ', 2) === 0;
    }

    /**
     * A mapping or a sequence whose first line is indented by exactly $indent.
     *
     * @return array<mixed>
     */
    private function block(int $indent): array
    {
        $line = $this->next();
        if ($line === null || $line['indent'] !== $indent) {
            throw new \UnexpectedValueException('a block does not start where expected, line ' . ($this->at + 1));
        }
        return self::isItem($line['text']) ? $this->sequence($indent) : $this->mapping($indent);
    }

    /** @return array<string,mixed> */
    private function mapping(int $indent): array
    {
        $map = [];
        while (($line = $this->next()) !== null && $line['indent'] === $indent && !self::isItem($line['text'])) {
            if (!preg_match('/^([A-Za-z0-9_.$-]+):(?: (.*))?$/', $line['text'], $m)) {
                throw new \UnexpectedValueException('not a "key: value" line, line ' . ($this->at + 1));
            }
            $key = $m[1];
            if (array_key_exists($key, $map)) {
                throw new \UnexpectedValueException("the key {$key} repeats, line " . ($this->at + 1));
            }
            $rest = self::withoutComment($m[2] ?? '');
            $this->at++;
            $map[$key] = $this->value($rest, $indent);
        }
        if ($line !== null && $line['indent'] > $indent) {
            throw new \UnexpectedValueException('a line is indented deeper than its mapping, line ' . ($this->at + 1));
        }
        return $map;
    }

    /** @return list<mixed> */
    private function sequence(int $indent): array
    {
        $list = [];
        while (($line = $this->next()) !== null && $line['indent'] === $indent && self::isItem($line['text'])) {
            $rest = ltrim(substr($line['text'], 1), ' ');
            if ($rest === '' || $rest[0] === '#') {
                $this->at++;
                $following = $this->next();
                $list[] = $following !== null && $following['indent'] > $indent ? $this->block($following['indent']) : null;
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_.$-]+:(?: |$)/', $rest)) {
                // An item that is a mapping: its first key sits on the dash's line. Read that
                // line again as if it were indented to the key's column.
                $column = $indent + (strlen($line['text']) - strlen($rest));
                $this->lines[$this->at] = ['indent' => $column, 'text' => $rest, 'raw' => $line['raw']];
                $list[] = $this->mapping($column);
                continue;
            }
            $this->at++;
            $list[] = $this->scalar(self::withoutComment($rest));
            $this->noDeeperLine($indent);
        }
        return $list;
    }

    /**
     * The value after "key:" on a line indented by $indent.
     *
     * @return array<mixed>|string|null
     */
    private function value(string $rest, int $indent)
    {
        if ($rest === '') {
            $following = $this->next();
            if ($following !== null && ($following['indent'] > $indent
                || ($following['indent'] === $indent && self::isItem($following['text'])))) {
                return $this->block($following['indent']);
            }
            return null;
        }
        if (preg_match('/^([|>])([-+]?)$/', $rest, $m)) {
            return $this->blockScalar($indent, $m[1], $m[2]);
        }
        $value = $this->scalar($rest);
        $this->noDeeperLine($indent);
        return $value;
    }

    private function noDeeperLine(int $indent): void
    {
        $following = $this->next();
        if ($following !== null && $following['indent'] > $indent) {
            throw new \UnexpectedValueException('a multi-line scalar is not supported, line ' . ($this->at + 1));
        }
    }

    /** A `|` or `>` scalar: the raw lines indented deeper than its key, blank lines kept. */
    private function blockScalar(int $indent, string $style, string $chomp): string
    {
        $lines = [];
        $content = null;
        while ($this->at < count($this->lines)) {
            $line = $this->lines[$this->at];
            if ($line['text'] === '') {
                $lines[] = '';
                $this->at++;
                continue;
            }
            if ($line['indent'] <= $indent) {
                break;
            }
            $content ??= $line['indent'];
            if ($line['indent'] < $content) {
                throw new \UnexpectedValueException('a block scalar line is indented less than its first, line ' . ($this->at + 1));
            }
            $lines[] = substr($line['raw'], $content);
            $this->at++;
        }
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        $text = $style === '|' ? implode("\n", $lines) : preg_replace('/(?<!\n)\n(?!\n)/', ' ', implode("\n", $lines));
        return $chomp === '-' || $text === '' ? (string)$text : $text . "\n";
    }

    /** @return list<mixed>|string|null */
    private function scalar(string $text)
    {
        if ($text === '' || $text === '~' || $text === 'null') {
            return null;
        }
        if ($text[0] === '"') {
            if (!preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $text, $m)) {
                throw new \UnexpectedValueException('an unclosed double-quoted scalar, line ' . $this->at);
            }
            return stripcslashes($m[1]);
        }
        if ($text[0] === "'") {
            if (!preg_match("/^'((?:[^']|'')*)'$/", $text, $m)) {
                throw new \UnexpectedValueException('an unclosed single-quoted scalar, line ' . $this->at);
            }
            return str_replace("''", "'", $m[1]);
        }
        if ($text[0] === '[') {
            if (!preg_match('/^\[([^\[\]{}]*)\]$/', $text, $m)) {
                throw new \UnexpectedValueException('a nested flow sequence, line ' . $this->at);
            }
            return trim($m[1]) === '' ? [] : array_map(function (string $item) {
                return $this->scalar(trim($item));
            }, explode(',', $m[1]));
        }
        if (strpbrk($text[0], '{&*!%@`') !== false) {
            throw new \UnexpectedValueException('an unsupported scalar, line ' . $this->at);
        }
        return $text;
    }

    /** The value without a trailing comment: a "#" after a space, outside quotes. */
    private static function withoutComment(string $text): string
    {
        $quote = null;
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            if ($quote !== null) {
                if ($c === '\\' && $quote === '"') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if (($c === '"' || $c === "'") && ($i === 0 || $text[$i - 1] === ' ' || $text[$i - 1] === '[' || $text[$i - 1] === ',')) {
                $quote = $c;
            } elseif ($c === '#' && ($i === 0 || $text[$i - 1] === ' ')) {
                return rtrim(substr($text, 0, $i));
            }
        }
        return trim($text);
    }
}
