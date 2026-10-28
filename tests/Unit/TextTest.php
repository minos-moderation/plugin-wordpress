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

    public function testABlockBoundarySeparatesWordsAndAnInlineTagJoinsNone(): void
    {
        foreach (['blockquote', 'p', 'div', 'li', 'ul', 'ol', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            foreach (["<{$tag}>Zgoda</{$tag}>zakazane", "Zgoda<{$tag} class=\"x\">zakazane"] as $html) {
                self::assertSame(['Zgoda', 'zakazane'], preg_split('/\s+/u', Text::plain($html)), $html);
            }
        }
        foreach (['Zgoda<br>zakazane', 'Zgoda<br/>zakazane', 'Zgoda<BR class="x" />zakazane'] as $html) {
            self::assertSame("Zgoda\nzakazane", Text::plain($html), $html);
        }
        // Inline tags, and tags whose names only begin like a block tag's.
        foreach (['strong', 'b', 'em', 'i', 'span', 'code', 'del', 'big', 'bdo', 'param', 'hr', 'pa'] as $tag) {
            self::assertSame('zakazane', Text::plain("zak<{$tag}>a</{$tag}>zane"), $tag);
        }
        self::assertSame('zakazane', Text::plain('zak<a href="https://a.example/">a</a>zane'));
    }

    public function testTheOwnTextLeavesAttributeTextOut(): void
    {
        foreach (['<img src="x.png" alt="foto">', '<img alt="foto" title="wakacje" />', '<a href="https://a.example/" title="kup">&nbsp;</a>',
            "<p>\n<img alt='foto'>\n</p>", '<script>alert(1)</script><img alt="foto">'] as $html) {
            self::assertSame('', Text::ownText($html), $html);
            self::assertNotSame('', Text::plain($html), 'the attribute text alone: ' . $html);
        }
        self::assertSame('Zobacz', Text::ownText('Zobacz <img src="x.png" alt="foto">'));
        self::assertSame('ok', Text::ownText('<abbr title="ukryte słowo">ok</abbr>'));
        self::assertSame('', Text::ownText("\xC3\x28"), 'not UTF-8');
    }

    public function testTheTextOfTitleAndAltIsStillKeptInPlace(): void
    {
        self::assertSame(['ukryte', 'słowo', 'ok'], preg_split('/\s+/u', Text::plain('<abbr title="ukryte słowo">ok</abbr>')));
        self::assertSame(['tekst', 'obraz', 'alt', 'dalej'],
            preg_split('/\s+/u', Text::plain('tekst <img src="x.png" alt="obraz alt"> dalej')));
        self::assertSame(['zakazane', 'link'],
            preg_split('/\s+/u', Text::plain('<p title="zakazane">link</p>')), 'a block tag with a title keeps it');
    }

    public function testAQuotedWordIsSentApartFromTheNextOne(): void
    {
        $this->configure();
        $this->post('<blockquote>Zgoda</blockquote>zakazane');

        self::assertSame(['Zgoda', 'zakazane'], preg_split('/\s+/u', $this->sentBody()['elementy'][0]['tekst']));
    }

    public function testAMaskFitsOnlyAsTheSentTextWithCharactersTurnedIntoBlocks(): void
    {
        $sent = 'Żółw ma brzydkie słowo';
        self::assertTrue(Text::maskFits($sent, 'Żółw ma ████████ słowo'));
        self::assertTrue(Text::maskFits($sent, str_repeat('█', mb_strlen($sent, 'UTF-8'))), 'every character may be masked');
        self::assertTrue(Text::maskFits($sent, $sent), 'masking nothing changes nothing');
        self::assertFalse(Text::maskFits($sent, 'Żółw ma ███████ słowo'), 'one character short');
        self::assertFalse(Text::maskFits($sent, 'Zółw ma ████████ słowo'), 'another character');
        self::assertFalse(Text::maskFits('', ''), 'nothing was sent');
        self::assertFalse(Text::maskFits($sent, "Żółw ma \xff███████ słowo"), 'not UTF-8');
    }

    public function testOnlyPlainContentIsPlain(): void
    {
        self::assertTrue(Text::isPlain('Tom &amp; Jerry &lt;3'));
        self::assertTrue(Text::isPlain("dwa wiersze\ntekstu "));
        self::assertFalse(Text::isPlain('no to jest <em>głupi</em> pomysł'));
        self::assertFalse(Text::isPlain('<a href="https://a.example/">link</a>'));
        self::assertFalse(Text::isPlain('<abbr title="ukryty">skrót</abbr>'));
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
