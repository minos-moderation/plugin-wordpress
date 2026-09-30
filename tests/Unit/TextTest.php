<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Meta;
use Minos\WordPress\Settings;
use Minos\WordPress\Text;

/**
 * The plain text never goes empty for a text that has one: an empty text gets the failure
 * mode, and fail-open would publish it unassessed. Whitespace is trimmed without a regex,
 * so neither a PCRE limit nor a long run of spaces can empty it.
 */
final class TextTest extends PluginTestCase
{
    public function testTheTrimmedWhitespaceIsExactlyWhatPcreCallsWhitespace(): void
    {
        $trimmed = 0;
        for ($cp = 0; $cp <= 0x10FFFF; $cp++) {
            if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                continue;
            }
            $char = mb_chr($cp, 'UTF-8');
            $space = preg_match('/^[\s\p{Z}]$/u', $char) === 1;
            $trimmed += (int)$space;
            $expected = $space ? 'x' : 'x' . $char;
            self::assertSame($expected, Text::cut('x' . $char), sprintf('U+%04X at the end', $cp));
            self::assertSame($space ? 'x' : $char . 'x', Text::cut($char . 'x'), sprintf('U+%04X at the start', $cp));
        }
        self::assertGreaterThanOrEqual(25, $trimmed, 'the sweep must meet the whitespace');
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function spaceRuns(): array
    {
        return [
            '3000 interior spaces'     => ['INSULT' . str_repeat(' ', 3000) . 'koniec'],
            '2000 interior NBSPs'      => ['INSULT' . str_repeat("\u{A0}", 2000) . 'koniec'],
            '3000 trailing spaces'     => ['INSULT' . str_repeat(' ', 3000)],
            'mixed interior runs'      => [str_repeat("INSULT \u{3000}\t ", 400) . 'koniec'],
            // These make the other two regexes of plain() give up at the limit.
            'unclosed script tags'     => [str_repeat('<script>INSULT ', 200)],
            'a tag of 2000 attributes' => ['<abbr' . str_repeat(' t="x"', 2000) . '>INSULT</abbr>'],
        ];
    }

    /**
     * @dataProvider spaceRuns
     */
    public function testALowPcreLimitNeverEmptiesTheText(string $content): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_OPEN]);
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000');
        try {
            $id = $this->post($content);
            $plain = Text::plain($content);
        } finally {
            ini_set('pcre.backtrack_limit', (string)$limit);
        }

        self::assertStringStartsWith('INSULT', $plain);
        self::assertStringStartsWith('INSULT', $this->sentBody()['elementy'][0]['tekst']);
        self::assertTrue($this->pending($id), 'sent for assessment, not given the failure mode');
        self::assertSame('0', $this->field($id, 'comment_approved'));
    }

    public function testASixtyThousandSpaceCommentKeepsItsText(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_OPEN]);
        $interior = 'INSULT' . str_repeat(' ', 60000) . 'koniec';
        $trailing = "\u{A0}\n" . 'INSULT' . str_repeat(' ', 60000);

        self::assertSame($interior, Text::plain($interior));
        self::assertSame('INSULT', Text::plain($trailing));

        $id = $this->post($interior);
        self::assertSame('INSULT', $this->sentBody()['elementy'][0]['tekst'], 'the first 3000 characters, trimmed');
        self::assertSame('1', $this->field($id, Meta::CUT));
        self::assertTrue($this->pending($id));
    }
}
