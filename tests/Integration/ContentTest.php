<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Integration;

use Minos\WordPress\Meta;
use Minos\WordPress\Text;

/**
 * The comment's content as WordPress really stores it: kses on the way in (for a visitor
 * when the comment is posted, for the webhook's anonymous request when the plugin writes a
 * masked text), the content filters of `wp_update_comment`, and the database column, which
 * refuses more than 65,535 bytes.
 */
final class ContentTest extends IntegrationTestCase
{
    public function testWhatIsSentIsTheTextOfTheContentKsesLeft(): void
    {
        $this->startGateway();

        $id = $this->post('<p>Akapit</p><script>alert("x")</script><b>pogrubiony</b> & znak <unknown>obcy</unknown>');

        $stored = self::row($id)['comment_content'];
        self::assertStringNotContainsString('<script', $stored, 'kses removed the tag');
        self::assertStringContainsString('&amp;', $stored, 'kses encoded the ampersand');
        $text = $this->sent[0]['elementy'][0]['tekst'] ?? null;
        self::assertIsString($text);
        foreach (['Akapit', 'alert("x")', 'pogrubiony', '& znak', 'obcy'] as $visible) {
            self::assertStringContainsString($visible, $text, 'a reader sees it in the stored comment');
        }
        self::assertStringNotContainsString('<', $text);
        self::assertStringNotContainsString('&amp;', $text);
    }

    public function testAMaskedTextWrittenThroughTheContentFiltersIsPublishedExactly(): void
    {
        $this->startGateway();
        $id = $this->post('Ala\'s "kot" & pies: paskudny tekst.');
        $original = self::row($id)['comment_content'];
        $sent = $this->sent[0]['elementy'][0]['tekst'];
        self::assertSame('Ala\'s "kot" & pies: paskudny tekst.', $sent);
        $mask = str_replace('paskudny', '████████', $sent);

        self::assertSame(200, $this->serveSigned(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => $mask])));

        $row = self::row($id);
        self::assertSame('1', $row['comment_approved'], 'published: the write came back exactly as written');
        self::assertSame($mask, html_entity_decode($row['comment_content'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        self::assertSame($original, self::stored($id, Meta::ORIGINAL));
        self::assertNull(self::stored($id, Meta::ERROR));
    }

    public function testAMaskedWriteTheDatabaseRefusesLeavesTheOriginalHeld(): void
    {
        $this->startGateway();
        $id = $this->post('Krótki paskudny komentarz.');
        $original = self::row($id)['comment_content'];
        // Another plugin's filter makes the value longer than the column (65,535 bytes).
        add_filter('wp_update_comment_data', static function ($data) {
            $data['comment_content'] .= str_repeat('x', 70000);
            return $data;
        });

        $mask = str_replace('paskudny', '████████', 'Krótki paskudny komentarz.');
        self::assertSame(200, $this->serveSigned(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => $mask])));

        $row = self::row($id);
        self::assertSame([$original, '0'], [$row['comment_content'], $row['comment_approved']]);
        self::assertSame(Meta::ERROR_MASKED_WRITE, self::stored($id, Meta::ERROR));
        self::assertSame($mask, self::stored($id, Meta::MASKED), 'kept for the moderator');
        self::assertNull(self::stored($id, Meta::ORIGINAL), 'the content is the original');
    }

    /**
     * @return array<string,array{0:string}> Content a visitor posts: an image or a blank link,
     *     with attribute text, beside one character a reader does not see.
     */
    public static function invisibleOnly(): array
    {
        $cases = [];
        foreach (['&#8203;', '&shy;', '&#8288;', '&#65279;'] as $character) {
            $cases["a blank link with a title, {$character}"] = ['<a href="https://kasyno.example/bonus" title="kup teraz">'
                . $character . '</a>'];
            $cases["an image with alt, {$character}"] = ['<img src="https://obrazek.example/a.png" alt="ładny kot" />' . $character];
        }
        return $cases;
    }

    /**
     * Shared rules D9.5 and F7 on the real kses, which keeps these entities: a comment whose
     * only text is attribute text and an invisible character is never sent.
     *
     * @dataProvider invisibleOnly
     */
    public function testAnInvisibleCharacterBesideAttributeTextIsNoTextToAssess(string $content): void
    {
        $this->startGateway();
        // A site that lets visitors post images in comments.
        add_filter('wp_kses_allowed_html', static function ($tags, $context) {
            if ($context === 'pre_comment_content' && is_array($tags)) {
                $tags['img'] = ['src' => true, 'alt' => true, 'title' => true];
            }
            return $tags;
        }, 10, 2);

        $id = $this->post($content);

        $stored = self::row($id)['comment_content'];
        self::assertMatchesRegularExpression('/(title|alt)="/', $stored, 'kses kept the attribute');
        self::assertNotSame('', Text::ownText($stored), 'more than whitespace is left beside it');
        self::assertSame([], $this->sent, 'nothing to assess: no request');
        self::assertSame(['0', Meta::UNASSESSED, Meta::ERROR_NO_TEXT],
            [self::row($id)['comment_approved'], self::stored($id, Meta::STATUS), self::stored($id, Meta::ERROR)]);
    }
}
