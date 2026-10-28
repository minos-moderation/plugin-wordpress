<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Integration;

use Minos\WordPress\Meta;

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
}
