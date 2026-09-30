<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\Client\Signature;
use Minos\WordPress\Meta;
use Minos\WordPress\Outcome;
use Minos\WordPress\Settings;
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

    public function testACensoredCommentIsPublishedMaskedWithTheOriginalKept(): void
    {
        $this->configure(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $original = '<p>no to jest <em>głupi</em> pomysł</p>';
        $id = $this->post($original);

        $this->deliver(self::verdict($id, 'ocenzurowane', ['kategorie' => ['wulgaryzmy'],
            'ocenzurowany' => 'no to jest █████ pomysł']));

        self::assertSame('1', $this->field($id, 'comment_approved'));
        self::assertSame('no to jest █████ pomysł', $this->field($id, 'comment_content'));
        self::assertSame($original, $this->field($id, Meta::ORIGINAL));
        self::assertSame('ocenzurowane', $this->field($id, Meta::STATUS));
        self::assertSame('wulgaryzmy', $this->field($id, Meta::CATEGORIES));
    }

    public function testTheMaskedTextIsPublishedAsTextNeverAsMarkup(): void
    {
        $this->configure();
        $id = $this->post('uwaga &lt;script&gt; i ukośnik \\ oraz brzydkie słowo');

        $this->deliver(self::verdict($id, 'ocenzurowane',
            ['ocenzurowany' => 'uwaga <script> i ukośnik \\ oraz ███████ słowo']));

        $content = (string)$this->field($id, 'comment_content');
        self::assertStringNotContainsString('<', $content);
        self::assertSame('uwaga <script> i ukośnik \\ oraz ███████ słowo',
            html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'the backslash must survive WordPress\'s unslashing');
        self::assertSame('uwaga &lt;script&gt; i ukośnik \\ oraz brzydkie słowo', $this->field($id, Meta::ORIGINAL));
    }

    public function testTheUnassessedTailOfALongCommentIsKept(): void
    {
        $this->configure();
        $head = str_repeat('a', 2995) . ' brzydkie';
        $id = $this->post($head . ' dalszy ciąg');

        $masked = str_repeat('a', 2995) . ' ████';
        $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => $masked]));

        self::assertSame($masked . 'dkie dalszy ciąg', $this->field($id, 'comment_content'));
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

        $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => '████████ komentarz']));

        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertSame('brzydki komentarz', $this->field($id, 'comment_content'));
        self::assertNull($this->field($id, Meta::ORIGINAL));
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

    public function testTheHeldBackEmailFollowsTheFinalStatus(): void
    {
        $this->configure();
        $published = $this->post('Komentarz, który zostanie opublikowany.');
        $held = $this->post('Komentarz, który zostanie wstrzymany.');

        $this->deliver(self::verdict($published, 'bezpieczne'));
        $this->deliver(self::verdict($held, 'zablokowane'));
        self::assertSame([], WpStub::$emails, 'the webhook answers first; e-mails go to WP-Cron');

        foreach (self::events(Outcome::HOOK_NOTIFY) as $event) {
            do_action($event['hook'], ...$event['args']);
        }
        self::assertSame([['postauthor', $published], ['moderator', $held]], WpStub::$emails);
    }

    public function testSignatureHeaderNameIsTheContracts(): void
    {
        self::assertSame('X-Wergiliusz-Podpis', Signature::HEADER);
    }
}
