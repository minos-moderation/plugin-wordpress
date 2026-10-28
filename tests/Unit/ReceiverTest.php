<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\Client\Signature;
use Minos\WordPress\Admin\CommentsScreen;
use Minos\WordPress\Log;
use Minos\WordPress\Meta;
use Minos\WordPress\Outcome;
use Minos\WordPress\Settings;
use Minos\WordPress\Text;
use WpStub;

/**
 * The webhook through the real receiver, with deliveries signed the way the gateway signs
 * them (`Signature::sign` of the bundled client).
 */
final class ReceiverTest extends PluginTestCase
{
    public function testTheRouteIsPublicBecauseTheSignatureIsTheAuthentication(): void
    {
        do_action('rest_api_init');

        self::assertCount(1, WpStub::$routes);
        $route = WpStub::$routes[0];
        self::assertSame(['minos/v1', '/webhook'], [$route['namespace'], $route['route']]);
        self::assertSame('POST', $route['args']['methods']);
        self::assertSame('__return_true', $route['args']['permission_callback']);
        self::assertSame([$this->plugin->receiver, 'handle'], $route['args']['callback']);
        self::assertSame('https://forum.example/wp-json/minos/v1/webhook', $this->plugin->receiver->url());
    }

    public function testASignedVerdictIsApplied(): void
    {
        $this->configure();
        $id = $this->post('Miły komentarz testowy.');

        self::assertSame(200, $this->deliver(self::verdict($id, 'bezpieczne')));
        self::assertSame('1', $this->field($id, 'comment_approved'));
        self::assertSame('bezpieczne', $this->field($id, Meta::STATUS));
    }

    public function testTheSignatureIsCheckedOnTheRawBytes(): void
    {
        $this->configure();
        $id = $this->post('Komentarz do sprawdzenia podpisu.');
        // The same JSON, spaced differently: any re-encoding would change the signed bytes.
        $body = '{ "id": "wp:' . $id . '", "status": "ocenione", "kwalifikacja": "bezpieczne", "kategorie": [], "wsparcie": false, "wersja": null }';

        self::assertSame(200, $this->deliver($body));
        self::assertSame('1', $this->field($id, 'comment_approved'));
    }

    /**
     * @return array<string,array{0:string|null,1:int}>
     */
    public static function badSignatures(): array
    {
        return [
            'wrong secret'           => ['inny-sekret-niz-skonfigurowany-000', 0],
            'ten minutes old'        => [null, -600],
            'six minutes early'      => [null, 360],
        ];
    }

    /**
     * @dataProvider badSignatures
     */
    public function testABadSignatureIs401AndChangesNothing(?string $secret, int $shift): void
    {
        $this->configure();
        $id = $this->post('Komentarz, którego werdykt ktoś podrabia.');

        self::assertSame(401, $this->deliver(self::verdict($id, 'bezpieczne'), $secret, $this->now + $shift));
        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertTrue($this->pending($id));
    }

    public function testAMissingOrMalformedHeaderIs401(): void
    {
        $this->configure();
        $id = $this->post('Komentarz bez podpisu.');
        $body = (string)json_encode(self::verdict($id, 'bezpieczne'));

        $request = new \WP_REST_Request('POST');
        $request->set_body($body);
        self::assertSame(401, $this->plugin->receiver->handle($request)->get_status());

        $request->set_header('X-Wergiliusz-Podpis', 'v1=' . hash_hmac('sha256', $body, self::SECRET));
        self::assertSame(401, $this->plugin->receiver->handle($request)->get_status());
        self::assertTrue($this->pending($id));
    }

    public function testWithoutAConfiguredSecretEveryDeliveryIs401(): void
    {
        $this->configure();
        $id = $this->post('Komentarz przed usunięciem sekretu.');
        WpStub::$options[Settings::SECRET_OPTION] = '';

        // Signed with the empty secret: exactly what an attacker could compute.
        self::assertSame(401, $this->deliver(self::verdict($id, 'bezpieczne'), ''));
        self::assertTrue($this->pending($id));
    }

    public function testASecretWrittenStraightIntoTheOptionIsCheckedAsTheSettingsCheckIt(): void
    {
        $this->configure();
        $id = $this->post('Komentarz przy zbyt krótkim sekrecie.');
        foreach (['12345678', 'sekret ze spacją 0123456789', str_repeat('x', 257), self::SECRET . "\n"] as $invalid) {
            self::assertFalse(Settings::validSecret($invalid), 'the settings page refuses it');
            WpStub::$options[Settings::SECRET_OPTION] = $invalid;

            self::assertSame(401, $this->deliver(self::verdict($id, 'bezpieczne'), $invalid), $invalid);
        }
        self::assertTrue($this->pending($id));
    }

    public function testTheGatewaysSignatureVectorWithAValidSecretIs200(): void
    {
        // The gateway's vector, copied byte for byte into the client library.
        $vector = json_decode((string)file_get_contents(__DIR__ . '/../../vendor/minos-moderation/client-php/vectors/signature.json'), true);
        self::assertIsArray($vector, 'run composer install');
        $this->configure();
        WpStub::$options[Settings::SECRET_OPTION] = $vector['secret'];
        self::assertTrue(Settings::validSecret($vector['secret']));
        $this->now = (int)$vector['timestamp'];

        $request = new \WP_REST_Request('POST');
        $request->set_body($vector['body']);
        $request->set_header('X-Wergiliusz-Podpis', $vector['header']);
        self::assertSame(200, $this->plugin->receiver->handle($request)->get_status());

        $request->set_body($vector['body'] . ' ');
        self::assertSame(401, $this->plugin->receiver->handle($request)->get_status(), 'and only the signed bytes');
    }

    public function testASignedBodyThatIsNotAPayloadIs400(): void
    {
        $this->configure();
        self::assertSame(400, $this->deliver('to nie jest JSON'));
        self::assertSame(400, $this->deliver(['status' => 'ocenione']));
    }

    public function testAnUnknownIdIs200AndChangesNothing(): void
    {
        $this->configure();
        $id = $this->post('Komentarz, którego nikt nie pytał.');
        $other = WpStub::insertComment(['comment_approved' => '0']);

        foreach (['k-1027', 'wp:999', 'wp:0', 'wp:' . $other, 'wp:' . $id . 'x'] as $unknown) {
            self::assertSame(200, $this->deliver(['id' => $unknown] + self::verdict($id, 'bezpieczne')), $unknown);
        }
        self::assertTrue($this->pending($id));
        self::assertSame('0', $this->field($other, 'comment_approved'));
        self::assertSame([], WpStub::$statusChanges);
    }

    public function testARepeatedDeliveryIs200AndChangesNothing(): void
    {
        $this->configure(['blocked_mode' => Settings::BLOCKED_SPAM]);
        $id = $this->post('Komentarz doręczony dwa razy.');

        self::assertSame(200, $this->deliver(self::verdict($id, 'bezpieczne')));
        $changes = WpStub::$statusChanges;
        // A repeat, even one that says something else, is dropped by its id.
        self::assertSame(200, $this->deliver(self::verdict($id, 'zablokowane')));

        self::assertSame($changes, WpStub::$statusChanges);
        self::assertSame('1', $this->field($id, 'comment_approved'));
        self::assertSame('bezpieczne', $this->field($id, Meta::STATUS));
    }

    public function testACensoredPlainCommentIsPublishedMaskedWithTheOriginalKept(): void
    {
        $this->configure(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $original = 'no to jest głupi pomysł';
        $id = $this->post($original);
        $masked = 'no to jest █████ pomysł';

        $this->deliver(self::verdict($id, 'ocenzurowane', ['kategorie' => ['wulgaryzmy'], 'ocenzurowany' => $masked]));

        self::assertSame('1', $this->field($id, 'comment_approved'));
        self::assertSame($masked, $this->field($id, 'comment_content'));
        self::assertSame($original, $this->field($id, Meta::ORIGINAL));
        self::assertNull($this->field($id, Meta::MASKED), 'a published mask needs no copy');
        self::assertSame('ocenzurowane', $this->field($id, Meta::STATUS));
        self::assertSame('wulgaryzmy', $this->field($id, Meta::CATEGORIES));
    }

    public function testACensoredCommentWithMarkupIsHeldWithTheMaskInTheColumn(): void
    {
        $this->configure(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $original = '<p>no to jest <em>głupi</em> pomysł</p>';
        $id = $this->post($original);
        $masked = 'no to jest █████ pomysł';
        self::assertTrue(Text::maskFits(Text::plain($original), $masked), 'the mask itself fits: the markup decides');

        $this->deliver(self::verdict($id, 'ocenzurowane', ['kategorie' => ['wulgaryzmy'], 'ocenzurowany' => $masked]));

        self::assertSame('0', $this->field($id, 'comment_approved'), 'escaped text in place of markup changes more than the mask');
        self::assertSame($original, $this->field($id, 'comment_content'));
        self::assertNull($this->field($id, Meta::ORIGINAL));
        self::assertSame($masked, $this->field($id, Meta::MASKED));
        self::assertSame('ocenzurowane', $this->field($id, Meta::STATUS));
        self::assertStringContainsString($masked, $this->column($id), 'the moderator sees the masked text');
        self::assertCount(1, self::events(Outcome::HOOK_NOTIFY), 'and is told');
    }

    public function testTheMaskedTextIsPublishedAsTextNeverAsMarkup(): void
    {
        $this->configure();
        $id = $this->post('uwaga &lt;script&gt; i ukośnik \\ oraz brzydkie słowo');
        $masked = 'uwaga <script> i ukośnik \\ oraz ████████ słowo';

        $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => $masked]));

        $content = (string)$this->field($id, 'comment_content');
        self::assertSame('1', $this->field($id, 'comment_approved'));
        self::assertStringNotContainsString('<', $content);
        self::assertSame($masked, html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'the backslash must survive WordPress\'s unslashing');
        self::assertSame('uwaga &lt;script&gt; i ukośnik \\ oraz brzydkie słowo', $this->field($id, Meta::ORIGINAL));
    }

    /**
     * Masked texts that are not the sent text with some characters turned into `█`.
     *
     * @return array<string,array{0:string}>
     */
    public static function masksThatDoNotFit(): array
    {
        // The sent text is 'Tekst z brzydkim słowem testowym.' ("brzydkim": 8 characters).
        return [
            'one character short'  => ['Tekst z ███████ słowem testowym.'],
            'one character long'   => ['Tekst z █████████ słowem testowym.'],
            'a foreign character'  => ['Tekst z ████████ słowem testowym!'],
            'a link in its place'  => ['Tekst z https://a.ex słowem testowym.'],
            'another text'         => ['Zupełnie inny tekst, dłuższy, z linkiem https://obcy.example ███'],
        ];
    }

    /**
     * @dataProvider masksThatDoNotFit
     */
    public function testAMaskThatDoesNotFitTheSentTextIsHeldWithTheMaskKept(string $masked): void
    {
        $this->configure(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $original = 'Tekst z brzydkim słowem testowym.';
        $id = $this->post($original);
        self::assertFalse(Text::maskFits($this->sentBody()['elementy'][0]['tekst'], $masked));

        self::assertSame(200, $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => $masked])));

        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertSame($original, $this->field($id, 'comment_content'));
        self::assertNull($this->field($id, Meta::ORIGINAL));
        self::assertSame($masked, $this->field($id, Meta::MASKED));
        self::assertNotContains([$id, 'approve'], WpStub::$statusChanges);
    }

    public function testAFittingMaskOfEveryLetterIsPublished(): void
    {
        $this->configure(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $id = $this->post('Tekst z brzydkim słowem testowym.');

        $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => 'Tekst z ████████ słowem testowym.']));

        self::assertSame('1', $this->field($id, 'comment_approved'));
        $content = (string)$this->field($id, 'comment_content');
        $sent = $this->sentBody()['elementy'][0]['tekst'];
        self::assertSame(mb_strlen($sent, 'UTF-8'), mb_strlen($content, 'UTF-8'));
        self::assertTrue(Text::maskFits($sent, $content));
    }

    public function testAMaskForACommentWordPressItselfHoldsLeavesItsContent(): void
    {
        $this->configure(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $original = 'Tekst z brzydkim słowem testowym.';
        $id = $this->post($original, 0);
        $masked = 'Tekst z ████████ słowem testowym.';

        $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => $masked]));

        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertSame($original, $this->field($id, 'comment_content'), 'the content is the moderator\'s to change');
        self::assertNull($this->field($id, Meta::ORIGINAL));
        self::assertSame($masked, $this->field($id, Meta::MASKED));
    }

    public function testAHeldCensoredCommentKeepsItsMaskForTheModerator(): void
    {
        $this->configure(['censored_mode' => Settings::HOLD]);
        $original = 'Tekst z brzydkim słowem testowym.';
        $id = $this->post($original);
        $masked = 'Tekst z ████████ słowem testowym.';

        $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => $masked]));

        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertSame($original, $this->field($id, 'comment_content'));
        self::assertSame($masked, $this->field($id, Meta::MASKED));
        self::assertStringContainsString($masked, $this->column($id));
    }

    public function testACensoredCutCommentIsHeldWithItsOriginal(): void
    {
        $this->configure(['censored_mode' => Settings::CENSORED_PUBLISH, 'failure_mode' => Settings::FAIL_OPEN]);
        $original = str_repeat('a', 2995) . ' brzydkie dalszy ciąg';
        $id = $this->post($original);
        self::assertSame('1', $this->field($id, Meta::CUT));

        $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => str_repeat('a', 2995) . ' ████']));

        self::assertSame('0', $this->field($id, 'comment_approved'), 'the unassessed rest must not go public');
        self::assertSame($original, $this->field($id, 'comment_content'));
        self::assertNull($this->field($id, Meta::ORIGINAL));
        self::assertSame(str_repeat('a', 2995) . ' ████', $this->field($id, Meta::MASKED));
        self::assertSame('ocenzurowane', $this->field($id, Meta::STATUS));
    }

    public function testASafeCutCommentGetsTheFailureMode(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_CLOSED]);
        $closed = $this->post(str_repeat('b', 3001));
        $this->deliver(self::verdict($closed, 'bezpieczne'));
        self::assertSame('0', $this->field($closed, 'comment_approved'));
        self::assertSame('bezpieczne', $this->field($closed, Meta::STATUS), 'the verdict on the beginning is kept');
        ob_start();
        (new CommentsScreen($this->plugin->wp, $this->plugin->settings, $this->plugin->log))
            ->renderColumn(CommentsScreen::COLUMN, $closed);
        self::assertStringContainsString('wpis dłuższy niż 3000 znaków — oceniono początek', (string)ob_get_clean());

        $this->configure(['failure_mode' => Settings::FAIL_OPEN]);
        $open = $this->post(str_repeat('c', 3001));
        $this->deliver(self::verdict($open, 'bezpieczne'));
        self::assertSame('1', $this->field($open, 'comment_approved'));
        self::assertSame('1', $this->field($open, Meta::AUTO_PUBLISHED));

        // Exactly 3000 characters were assessed whole: an ordinary verdict.
        $whole = $this->post(str_repeat('d', 3000));
        self::assertNull($this->field($whole, Meta::CUT));
        $this->configure(['failure_mode' => Settings::FAIL_CLOSED]);
        $this->deliver(self::verdict($whole, 'bezpieczne'));
        self::assertSame('1', $this->field($whole, 'comment_approved'));
    }

    public function testABlockedCutCommentIsBlocked(): void
    {
        $this->configure(['blocked_mode' => Settings::BLOCKED_SPAM, 'failure_mode' => Settings::FAIL_OPEN]);
        $id = $this->post(str_repeat('e', 3500));

        $this->deliver(self::verdict($id, 'zablokowane'));

        self::assertSame('spam', $this->field($id, 'comment_approved'));
    }

    public function testElevenThousandApostrophesAreNeverPublishedInTheOriginal(): void
    {
        // Escaped, 11,000 apostrophes are 66,000 bytes: more than wpdb stores in the column.
        $this->configure(['censored_mode' => Settings::CENSORED_PUBLISH, 'failure_mode' => Settings::FAIL_OPEN]);
        $original = str_repeat("'", 11000);
        $id = $this->post($original);

        $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => str_repeat('█', 3000)]));

        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertSame($original, $this->field($id, 'comment_content'));
        self::assertNull($this->field($id, Meta::ORIGINAL));
        self::assertNotContains([$id, 'approve'], WpStub::$statusChanges);
    }

    public function testAMaskTooLongToStoreIsNeverWritten(): void
    {
        $this->configure(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $original = 'krótki brzydki komentarz';
        $id = $this->post($original);

        // Escaped, it would be more than wpdb stores; it does not fit the sent text either.
        $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => str_repeat("'", 11000)]));

        self::assertSame('0', $this->field($id, 'comment_approved'), 'never published with the original');
        self::assertSame($original, $this->field($id, 'comment_content'));
        self::assertNull($this->field($id, Meta::ORIGINAL), 'nothing claims a masked version');
        self::assertCount(1, self::events(Outcome::HOOK_NOTIFY), 'the moderator is told');
    }

    public function testAnUpdateAFilterRefusesKeepsTheCommentHeld(): void
    {
        $this->configure(['censored_mode' => Settings::CENSORED_PUBLISH]);
        add_filter('wp_update_comment_data', static function () {
            return new \WP_Error('zablokowane_przez_wtyczke', 'Inna wtyczka nie pozwala.');
        });
        $id = $this->post('pierwotna brzydka treść');

        $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => 'pierwotna ███████ treść']));

        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertSame('pierwotna brzydka treść', $this->field($id, 'comment_content'));
        self::assertNull($this->field($id, Meta::ORIGINAL));
        self::assertSame(Meta::ERROR_MASKED_WRITE, $this->field($id, Meta::ERROR));
        self::assertSame('pierwotna ███████ treść', $this->field($id, Meta::MASKED));
        $entry = $this->plugin->log->entries()[0];
        self::assertSame([null, Meta::ERROR_MASKED_WRITE, $id], [$entry['http'], $entry['code'], $entry['comment']]);
        self::assertStringNotContainsString('brzydka', (string)json_encode(WpStub::$options[Log::OPTION]));
        self::assertCount(1, self::events(Outcome::HOOK_NOTIFY), 'the moderator is told');
    }

    public function testAMaskedTextStoredDifferentlyIsNotPublishedAndTheOriginalComesBack(): void
    {
        $this->configure(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $id = $this->post('pierwotna brzydka treść');
        $append = static function ($content) {
            return is_string($content) && strpos($content, '███') !== false ? $content . ' [dopisek]' : $content;
        };
        add_filter('comment_save_pre', $append);

        $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => 'pierwotna ███████ treść']));

        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertSame('pierwotna brzydka treść', $this->field($id, 'comment_content'), 'the original is put back');
        self::assertNull($this->field($id, Meta::ORIGINAL));
        self::assertSame(Meta::ERROR_MASKED_WRITE, $this->field($id, Meta::ERROR));
    }

    public function testTheOriginalIsKeptWhenItCannotBePutBack(): void
    {
        $this->configure(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $id = $this->post('pierwotna brzydka treść');
        add_filter('comment_save_pre', static function ($content) {
            return $content . ' [zawsze dopisane]';
        });

        $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => 'pierwotna ███████ treść']));

        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertSame('pierwotna brzydka treść', $this->field($id, Meta::ORIGINAL), 'then it is the only copy');
    }

    public function testACensoredCommentWithoutTheMaskedTextIsHeld(): void
    {
        $this->configure(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $id = $this->post('Komentarz ocenzurowany bez wersji zamaskowanej.');

        $this->deliver(self::verdict($id, 'ocenzurowane'));

        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertSame('Komentarz ocenzurowany bez wersji zamaskowanej.', $this->field($id, 'comment_content'));
        self::assertSame('ocenzurowane', $this->field($id, Meta::STATUS));
    }

    public function testACensoredCommentIsHeldWhenTheSettingSaysSo(): void
    {
        $this->configure(['censored_mode' => Settings::HOLD]);
        $id = $this->post('brzydki komentarz');

        $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => '███████ komentarz']));

        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertSame('brzydki komentarz', $this->field($id, 'comment_content'));
        self::assertNull($this->field($id, Meta::ORIGINAL));
        self::assertSame('███████ komentarz', $this->field($id, Meta::MASKED));
    }

    public function testAMaskedTextForAnEditedCommentIsNotPublished(): void
    {
        $this->configure();
        $longer = $this->post('pierwotna brzydka treść');
        WpStub::$comments[$longer]['comment_content'] = 'treść poprawiona przez moderatora';
        // An edit of the same length: only the hash of the sent text tells it apart.
        $same = $this->post('pierwotna brzydka treść');
        WpStub::$comments[$same]['comment_content'] = 'pierwotna ładniej treść';

        foreach ([$longer, $same] as $id) {
            $edited = $this->field($id, 'comment_content');
            $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => 'pierwotna ███████ treść']));
            self::assertSame($edited, $this->field($id, 'comment_content'));
            self::assertSame('0', $this->field($id, 'comment_approved'));
        }
    }

    public function testABlockedCommentIsHeldOrMarkedAsSpam(): void
    {
        $this->configure(['blocked_mode' => Settings::HOLD]);
        $held = $this->post('Komentarz do zablokowania.');
        $this->deliver(self::verdict($held, 'zablokowane', ['kategorie' => ['nekanie']]));
        self::assertSame('0', $this->field($held, 'comment_approved'));
        self::assertSame('zablokowane', $this->field($held, Meta::STATUS));

        $this->configure(['blocked_mode' => Settings::BLOCKED_SPAM]);
        $spam = $this->post('Komentarz do oznaczenia jako spam.');
        $this->deliver(self::verdict($spam, 'zablokowane', ['kategorie' => ['spam']]));
        self::assertSame('spam', $this->field($spam, 'comment_approved'));
    }

    public function testAnUnassessedCommentFollowsTheFailureMode(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_OPEN]);
        $open = $this->post('Nieoceniony przy fail-open.');
        self::assertSame(200, $this->deliver(['id' => 'wp:' . $open, 'status' => 'nieocenione']));
        self::assertSame('1', $this->field($open, 'comment_approved'));
        self::assertSame(Meta::UNASSESSED, $this->field($open, Meta::STATUS));

        $this->configure(['failure_mode' => Settings::FAIL_CLOSED]);
        $closed = $this->post('Nieoceniony przy fail-closed.');
        self::assertSame(200, $this->deliver(['id' => 'wp:' . $closed, 'status' => 'nieocenione']));
        self::assertSame('0', $this->field($closed, 'comment_approved'));
        self::assertSame(Meta::UNASSESSED, $this->field($closed, Meta::STATUS));
    }

    public function testAnUnknownQualificationIsNeverReadAsAVerdict(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_CLOSED]);
        $id = $this->post('Komentarz z nieznanym werdyktem.');

        $this->deliver(self::verdict($id, 'dozwolone'));

        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertSame(Meta::UNASSESSED, $this->field($id, Meta::STATUS));
    }

    public function testASupportCueIsRecordedWhateverTheVerdict(): void
    {
        $this->configure();
        $id = $this->post('Komentarz testowy z sygnałem wsparcia.');

        $this->deliver(self::verdict($id, 'bezpieczne', ['kategorie' => ['samookaleczenie'], 'wsparcie' => true]));

        self::assertSame('1', $this->field($id, Meta::SUPPORT));
        self::assertSame('1', $this->field($id, 'comment_approved'), 'support is a cue, not a punishment');
        self::assertSame('samookaleczenie', $this->field($id, Meta::CATEGORIES));
    }

    public function testNoVerdictPublishesACommentWordPressItselfHeld(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_OPEN]);
        $safe = $this->post('Bezpieczny, ale wstrzymany przez WordPressa.', 0);
        $unassessed = $this->post('Nieoceniony i wstrzymany przez WordPressa.', 0);

        $this->deliver(self::verdict($safe, 'bezpieczne'));
        $this->deliver(['id' => 'wp:' . $unassessed, 'status' => 'nieocenione']);

        self::assertSame('0', $this->field($safe, 'comment_approved'));
        self::assertSame('bezpieczne', $this->field($safe, Meta::STATUS));
        self::assertSame('0', $this->field($unassessed, 'comment_approved'));
    }

    public function testAPersonsDecisionInTheMeantimeStands(): void
    {
        $this->configure(['blocked_mode' => Settings::BLOCKED_SPAM]);
        $approved = $this->post('Zatwierdzony ręcznie przed werdyktem.');
        wp_set_comment_status($approved, 'approve');
        $trashed = $this->post('Usunięty ręcznie przed werdyktem.');
        wp_set_comment_status($trashed, 'trash');
        $changes = WpStub::$statusChanges;

        $this->deliver(self::verdict($approved, 'zablokowane'));
        $this->deliver(self::verdict($trashed, 'bezpieczne'));

        self::assertSame($changes, WpStub::$statusChanges);
        self::assertSame('zablokowane', $this->field($approved, Meta::STATUS), 'the verdict is still recorded');
        self::assertSame('trash', $this->field($trashed, 'comment_approved'));
    }

    public function testEachEmailGoesOutOnce(): void
    {
        $this->configure();
        $published = $this->post('Komentarz, który zostanie opublikowany.');
        $held = $this->post('Komentarz, który zostanie wstrzymany.');

        $this->deliver(self::verdict($published, 'bezpieczne'));
        $this->deliver(self::verdict($held, 'zablokowane'));
        // Core mails the post's author when a comment is approved.
        self::assertSame([['postauthor', $published]], WpStub::$emails, 'the moderator\'s e-mail goes to WP-Cron');
        self::assertSame([[$held]], array_column(self::events(Outcome::HOOK_NOTIFY), 'args'));

        foreach (self::events(Outcome::HOOK_NOTIFY) as $event) {
            do_action($event['hook'], ...$event['args']);
        }
        self::assertSame([['postauthor', $published], ['moderator', $held]], WpStub::$emails);
    }

    public function testAHeldCommentApprovedBeforeTheCronRunsMailsTheAuthorOnce(): void
    {
        $this->configure();
        $id = $this->post('Wstrzymany, a potem zatwierdzony przez moderatora.');
        $this->deliver(self::verdict($id, 'zablokowane'));

        wp_set_comment_status($id, 'approve');
        foreach (self::events(Outcome::HOOK_NOTIFY) as $event) {
            do_action($event['hook'], ...$event['args']);
        }

        self::assertSame([['postauthor', $id]], WpStub::$emails);
    }

    public function testReplacingContentReportsWhatWordPressDid(): void
    {
        $id = WpStub::insertComment(['comment_content' => 'Treść.']);
        $wp = $this->plugin->wp;

        self::assertTrue($wp->replaceContent($id, 'Nowa treść.'));
        self::assertFalse($wp->replaceContent($id, str_repeat('x', 65536)), 'wpdb refuses it');
        self::assertFalse($wp->replaceContent(999, 'Brak komentarza.'));
        add_filter('wp_update_comment_data', static function () {
            return new \WP_Error('odmowa', 'Nie.');
        });
        self::assertFalse($wp->replaceContent($id, 'Odrzucona treść.'));
        self::assertSame('Nowa treść.', $this->field($id, 'comment_content'));
    }

    /**
     * What the "Minos" column shows for a comment.
     *
     * @param int $id The comment.
     * @return string The column's HTML.
     */
    private function column(int $id): string
    {
        ob_start();
        (new CommentsScreen($this->plugin->wp, $this->plugin->settings, $this->plugin->log))
            ->renderColumn(CommentsScreen::COLUMN, $id);
        return (string)ob_get_clean();
    }

    public function testSignatureHeaderNameIsTheContracts(): void
    {
        self::assertSame('X-Wergiliusz-Podpis', Signature::HEADER);
    }
}
