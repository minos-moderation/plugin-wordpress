<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Log;
use Minos\WordPress\Meta;
use Minos\WordPress\Settings;
use Minos\WordPress\Submission;
use Minos\WordPress\Text;
use WpStub;

/**
 * The submission through the real hooks: which comments are held, what the request carries
 * and what it never carries, and what each answer of the gateway does.
 */
final class SubmissionTest extends PluginTestCase
{
    public function testANewCommentIsHeldAndSentToTheGatewayOnce(): void
    {
        $this->configure();
        $id = $this->post('Pierwszy testowy komentarz.');

        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertTrue($this->pending($id));
        self::assertSame((string)$this->now, $this->field($id, Meta::HELD_AT));
        self::assertSame((string)$this->now, $this->field($id, Meta::SUBMITTED_AT));
        self::assertSame('1', $this->field($id, Meta::WP_APPROVED));
        self::assertCount(1, WpStub::$requests);
        self::assertSame([], self::events(Submission::HOOK_RETRY));
    }

    public function testTheRequestHasTheContractsShape(): void
    {
        $this->configure(['profile' => 'forum_teen', 'gateway_url' => 'https://brama.example/']);
        $id = $this->post('Zobacz <a href="https://a.example/x">https://a.example/x</a> i https://b.example.');

        $request = WpStub::$requests[0];
        self::assertSame('https://brama.example/api/v1/b2b/oceny', $request['url']);
        self::assertSame(['Content-Type' => 'application/json', 'X-Gateway-Key' => self::KEY],
            $request['args']['headers']);
        self::assertSame(10, $request['args']['timeout']);
        self::assertSame(0, $request['args']['redirection'], 'a redirect could carry the key to another host');

        $body = $this->sentBody();
        self::assertSame(['elementy'], array_keys($body));
        self::assertCount(1, $body['elementy']);
        $item = $body['elementy'][0];
        self::assertSame(['id', 'tekst', 'profil', 'meta'], array_keys($item));
        self::assertSame('wp:' . $id, $item['id']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{1,64}$/', $item['id'], 'the contract\'s id rule');
        self::assertSame('Zobacz https://a.example/x i https://b.example.', $item['tekst']);
        self::assertSame('forum_teen', $item['profil']);
        self::assertSame(['links' => 2, 'link_domains' => ['a.example', 'b.example'], 'author_first_post' => true],
            $item['meta']);
    }

    public function testLinkDomainsAreRegistrableDomainsFromTheContentOnly(): void
    {
        $this->configure();
        $links = ['https://www.Sklep.Example.com.pl/oferta', 'http://forum.example.co.uk/', 'https://sub.a.example/x',
            'https://a.example/y', 'https://192.168.0.1/', 'https://[2001:db8::1]/', 'https://user:haslo@b.example/',
            'https://localhost/'];
        for ($i = 0; $i < 12; $i++) {
            $links[] = 'https://d' . $i . '.example/';
        }
        $this->post('Linki: ' . implode(' ', $links), 1, ['comment_author_url' => 'https://autorka.example']);

        $domains = $this->sentBody()['elementy'][0]['meta']['link_domains'];
        self::assertSame(['example.com.pl', 'example.co.uk', 'a.example', 'b.example', 'd0.example', 'd1.example',
            'd2.example', 'd3.example', 'd4.example', 'd5.example'], $domains);
        self::assertCount(Text::MAX_LINK_DOMAINS, $domains);
        foreach ($domains as $domain) {
            // The gateway's own rule for a bare domain.
            self::assertMatchesRegularExpression('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain);
        }
        self::assertStringNotContainsString('autorka', WpStub::$requests[0]['args']['body'], 'never the author\'s website');

        $this->post('Bez linków.');
        self::assertArrayNotHasKey('link_domains', $this->sentBody()['elementy'][0]['meta']);
    }

    public function testTheTextOfTitleAndAltAttributesIsAssessed(): void
    {
        $this->configure();
        $this->post('<abbr title="brzydkie słowo">B.S.</abbr> i <img alt=\'obraźliwy opis\' src="x.png" />'
            . ' <a title=podpis href="https://a.example/">link</a> wul<b>gar</b>ny <b data-title="ukryte">x</b>'
            . ' <abbr title="a &lt;b&gt; c<d">t</abbr>');

        self::assertSame('brzydkie słowo B.S. i  obraźliwy opis   podpis link wulgarny x  a <b> c<d t',
            $this->sentBody()['elementy'][0]['tekst']);
    }

    public function testALongCommentIsMarkedAsCut(): void
    {
        $this->configure();
        $long = $this->post(str_repeat('x', Text::MAX_CHARS + 1));
        $exact = $this->post(str_repeat('y', Text::MAX_CHARS));
        // What counts is the plain text: markup around 3000 characters does not cut it.
        $marked = $this->post('<p><b>' . str_repeat('z', Text::MAX_CHARS) . '</b></p>');

        self::assertSame('1', $this->field($long, Meta::CUT));
        self::assertNull($this->field($exact, Meta::CUT));
        self::assertNull($this->field($marked, Meta::CUT));
    }

    public function testNothingAboutTheAuthorTravels(): void
    {
        $this->configure();
        $this->post('Komentarz bez danych autora.', 1, [
            'comment_author'       => 'Autorka Testowa',
            'comment_author_email' => 'autorka@example.invalid',
            'comment_author_IP'    => '198.51.100.23',
            'comment_author_url'   => 'https://autorka.example',
            'user_id'              => 42,
        ]);

        $raw = WpStub::$requests[0]['args']['body'];
        foreach (['Autorka Testowa', 'autorka@example.invalid', '198.51.100.23', 'autorka.example', '"42"', ':42'] as $personal) {
            self::assertStringNotContainsString($personal, $raw);
        }
        self::assertSame(['links', 'author_first_post'], array_keys($this->sentBody()['elementy'][0]['meta']));
    }

    public function testTheFirstPostSignalCountsOnlyApprovedEarlierComments(): void
    {
        $this->configure();
        WpStub::insertComment(['comment_author_email' => 'staly@example.invalid', 'comment_approved' => '1']);
        WpStub::insertComment(['comment_author_email' => 'nowy@example.invalid', 'comment_approved' => 'spam']);
        WpStub::insertComment(['user_id' => '7', 'comment_approved' => '1']);

        $this->post('Stały bywalec.', 1, ['comment_author_email' => 'staly@example.invalid']);
        $this->post('Nowy gość.', 1, ['comment_author_email' => 'nowy@example.invalid']);
        $this->post('Zalogowany z historią.', 1, ['user_id' => 7, 'comment_author_email' => 'inny@example.invalid']);

        self::assertFalse($this->sentBody(0)['elementy'][0]['meta']['author_first_post']);
        self::assertTrue($this->sentBody(1)['elementy'][0]['meta']['author_first_post']);
        self::assertFalse($this->sentBody(2)['elementy'][0]['meta']['author_first_post']);
    }

    public function testTheTextIsPlainTrimmedAndCutAtThreeThousandCharacters(): void
    {
        $this->configure();
        // Multi-byte characters: the cut counts characters, not bytes.
        $long = str_repeat('ż', 2990) . ' <b>' . str_repeat('ź', 20) . '</b>';
        $this->post("  \n <p>" . $long . "</p> &amp; koniec \n ");

        $text = $this->sentBody()['elementy'][0]['tekst'];
        self::assertSame('1', $this->field(1, Meta::CUT));
        self::assertSame(Text::MAX_CHARS, mb_strlen($text, 'UTF-8'));
        self::assertSame(str_repeat('ż', 2990) . ' ' . str_repeat('ź', 9), $text);
        self::assertStringNotContainsString('<', $text);

        $this->post('<script>alert(1)</script>Tom &amp; Jerry &lt;3&nbsp;');
        self::assertSame('Tom & Jerry <3', $this->sentBody()['elementy'][0]['tekst']);
    }

    public function testCommentsThePluginDoesNotModerateAreLeftAsWordPressDecided(): void
    {
        $this->configure();
        WpStub::$userCaps[5] = ['moderate_comments'];

        $moderator = $this->post('Odpowiedź moderatora.', 1, ['user_id' => 5]);
        $spam = $this->post('Spam wykryty przez inną wtyczkę.', 'spam');
        $trash = $this->post('Treść z czarnej listy.', 'trash');
        $pingback = $this->post('Pingback z innej strony.', 1, ['comment_type' => 'pingback']);

        self::assertSame('1', $this->field($moderator, 'comment_approved'));
        self::assertSame('spam', $this->field($spam, 'comment_approved'));
        self::assertSame('trash', $this->field($trash, 'comment_approved'));
        self::assertSame('1', $this->field($pingback, 'comment_approved'));
        self::assertSame([], WpStub::$requests);
        foreach ([$moderator, $spam, $trash, $pingback] as $id) {
            self::assertNull($this->field($id, Meta::STATUS));
        }
    }

    public function testAnErrorFromAnEarlierFilterIsPassedThrough(): void
    {
        $this->configure();
        $error = new \WP_Error('comment_flood', 'Za szybko.');
        self::assertSame($error, apply_filters('pre_comment_approved', $error, ['comment_type' => 'comment']));
    }

    public function testNothingIsHeldWhileThePluginIsOffOrHasNoSecret(): void
    {
        $this->configure(['enabled' => false]);
        $off = $this->post('Wtyczka wyłączona.');

        $this->configure();
        WpStub::$options[Settings::SECRET_OPTION] = '';
        $noSecret = $this->post('Brak sekretu.');

        self::assertSame('1', $this->field($off, 'comment_approved'));
        self::assertSame('1', $this->field($noSecret, 'comment_approved'));
        self::assertSame([], WpStub::$requests);
    }

    public function testACommentWordPressItselfHeldIsRecordedAsSuch(): void
    {
        $this->configure();
        $id = $this->post('Komentarz wstrzymany przez reguły WordPressa.', 0);

        self::assertSame('0', $this->field($id, Meta::WP_APPROVED));
        self::assertTrue($this->pending($id));
    }

    public function testAStatusChangedByALaterFilterIsNotTheirs(): void
    {
        $this->configure();
        add_filter('pre_comment_approved', static function () {
            return 'spam';
        }, 1000);
        $id = $this->post('Inna wtyczka zmieniła zdanie po nas.');

        self::assertNull($this->field($id, Meta::STATUS));
        self::assertSame([], WpStub::$requests);
    }

    public function testACommentPostedOverTheRestApiIsSentToo(): void
    {
        $this->configure();
        $data = ['comment_content' => 'Komentarz z aplikacji.', 'comment_type' => 'comment', 'user_id' => 0];
        $approved = apply_filters('pre_comment_approved', 1, $data);
        $id = WpStub::insertComment(['comment_approved' => (string)$approved] + $data);
        do_action('rest_insert_comment', WpStub::commentObject($id), new \WP_REST_Request('POST'), true);

        self::assertTrue($this->pending($id));
        self::assertSame('wp:' . $id, $this->sentBody()['elementy'][0]['id']);
    }

    public function testAFullQueueRetriesAfterTheGatewaysPause(): void
    {
        $this->configure();
        WpStub::answer(429, ['blad' => ['kod' => 'kolejka_pelna', 'komunikat' => 'Kolejka ocen jest pełna.', 'ponow_za_s' => 45]]);
        $id = $this->post('Komentarz w czasie pełnej kolejki.');

        self::assertTrue($this->pending($id));
        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertNull($this->field($id, Meta::SUBMITTED_AT));
        self::assertSame((string)($this->now + 45), $this->field($id, Meta::RETRY_AT));
        self::assertSame([['at' => $this->now + 45, 'hook' => Submission::HOOK_RETRY, 'args' => [$id], 'recurrence' => null]],
            self::events(Submission::HOOK_RETRY));
        self::assertSame('kolejka_pelna', $this->plugin->log->entries()[0]['code']);
        self::assertNull($this->plugin->log->lastConfigError(), 'a full queue is not a configuration error');
    }

    public function testWithoutAPauseTheBackoffDoublesFromAMinute(): void
    {
        $this->configure();
        WpStub::answer(503, ['blad' => ['kod' => 'kolejka_niedostepna', 'komunikat' => 'Kolejka ocen jest chwilowo niedostępna.']]);
        $id = $this->post('Komentarz w czasie awarii kolejki.');
        self::assertSame((string)($this->now + 60), $this->field($id, Meta::RETRY_AT));

        WpStub::networkFailure();
        $this->now += 60;
        do_action(Submission::HOOK_RETRY, $id);
        self::assertSame((string)($this->now + 120), $this->field($id, Meta::RETRY_AT));
        self::assertCount(2, WpStub::$requests);

        WpStub::answer(502);
        $this->now += 120;
        do_action(Submission::HOOK_RETRY, $id);
        self::assertSame((string)($this->now + 240), $this->field($id, Meta::RETRY_AT));
        self::assertSame('3', $this->field($id, Meta::ATTEMPTS));
    }

    public function testARetryThatSucceedsEndsTheBackoff(): void
    {
        $this->configure();
        WpStub::networkFailure();
        $id = $this->post('Komentarz, który przejdzie za drugim razem.');

        $this->now += 60;
        do_action(Submission::HOOK_RETRY, $id);

        self::assertTrue($this->pending($id));
        self::assertSame((string)$this->now, $this->field($id, Meta::SUBMITTED_AT));
        self::assertNull($this->field($id, Meta::RETRY_AT));
        self::assertNull($this->field($id, Meta::ATTEMPTS));
    }

    public function testARetryThatIsNotDueOrNotPendingSendsNothing(): void
    {
        $this->configure();
        WpStub::networkFailure();
        $id = $this->post('Komentarz czekający na ponowienie.');

        do_action(Submission::HOOK_RETRY, $id);
        self::assertCount(1, WpStub::$requests, 'not due yet');

        $accepted = $this->post('Komentarz już przyjęty.');
        do_action(Submission::HOOK_RETRY, $accepted);
        self::assertCount(2, WpStub::$requests, 'accepted already');
    }

    /**
     * @return array<string,array{0:int,1:string|null,2:string}>
     */
    public static function configurationErrors(): array
    {
        return [
            'unknown key'         => [401, 'brak_klucza', 'brak_klucza'],
            'no webhook'          => [403, 'brak_webhooka', 'brak_webhooka'],
            'profile not allowed' => [403, 'profil_niedozwolony', 'profil_niedozwolony'],
            'B2B off'             => [404, 'nie_znaleziono', 'nie_znaleziono'],
            'not a gateway'       => [200, null, 'http_200'],
        ];
    }

    /**
     * @dataProvider configurationErrors
     */
    public function testAConfigurationErrorAppliesTheFailureModeAndTellsTheAdministrator(int $status, ?string $code, string $recorded): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_CLOSED]);
        WpStub::answer($status, $code === null ? [] : ['blad' => ['kod' => $code, 'komunikat' => 'Odmowa.']]);
        $id = $this->post('Komentarz odrzucony przez bramę.');

        self::assertSame(Meta::UNASSESSED, $this->field($id, Meta::STATUS));
        self::assertSame($recorded, $this->field($id, Meta::ERROR));
        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertSame([], self::events(Submission::HOOK_RETRY), 'retrying will not help');
        self::assertSame(['time' => $this->now, 'http' => $status, 'code' => $code], $this->plugin->log->lastConfigError());
    }

    public function testAConfigurationErrorPublishesUnderFailOpen(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_OPEN]);
        WpStub::answer(401, ['blad' => ['kod' => 'brak_klucza', 'komunikat' => 'Ta trasa wymaga klucza B2B.']]);
        $id = $this->post('Komentarz przy złym kluczu.');

        self::assertSame('1', $this->field($id, 'comment_approved'));
        self::assertSame(Meta::UNASSESSED, $this->field($id, Meta::STATUS));
    }

    public function testAnAcceptedCommentClearsTheConfigurationError(): void
    {
        $this->configure();
        WpStub::answer(401, ['blad' => ['kod' => 'brak_klucza', 'komunikat' => 'Ta trasa wymaga klucza B2B.']]);
        $this->post('Przed poprawką klucza.');
        self::assertNotNull($this->plugin->log->lastConfigError());

        $this->post('Po poprawce klucza.');
        self::assertNull($this->plugin->log->lastConfigError());
    }

    public function testNeitherTheKeyNorTheSecretNorTheContentReachesTheLog(): void
    {
        $this->configure();
        WpStub::answer(403, ['blad' => ['kod' => 'brak_webhooka', 'komunikat' => self::KEY . ' Poufny komentarz']]);
        $this->post('Poufny komentarz testowy.');
        WpStub::answer(429, ['blad' => ['kod' => '<script>' . self::SECRET, 'ponow_za_s' => 5]]);
        $this->post('Drugi poufny komentarz.');

        $stored = json_encode([WpStub::$options[Log::OPTION], WpStub::$options[Log::CONFIG_ERROR_OPTION]]);
        self::assertStringContainsString('brak_webhooka', $stored, 'the log must hold the code');
        foreach ([self::KEY, self::SECRET, 'Poufny', 'script'] as $secret) {
            self::assertStringNotContainsString($secret, $stored);
        }
        self::assertFalse(WpStub::$autoload[Log::OPTION], 'the log is not loaded on every request');
    }

    public function testTheLogKeepsItsLastEntriesOnly(): void
    {
        $this->configure();
        for ($i = 0; $i < Log::MAX_ENTRIES + 5; $i++) {
            WpStub::answer(429, ['blad' => ['kod' => 'limit_minutowy_klucza', 'ponow_za_s' => 30]]);
            $this->post('Komentarz numer ' . $i . '.');
        }
        self::assertCount(Log::MAX_ENTRIES, $this->plugin->log->entries());
    }

    public function testACommentWithNoTextGetsTheFailureModeWithoutARequest(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_CLOSED]);
        $id = $this->post('<img src="https://obrazek.example/a.png" />');

        self::assertSame([], WpStub::$requests);
        self::assertSame(Meta::UNASSESSED, $this->field($id, Meta::STATUS));
        self::assertSame('0', $this->field($id, 'comment_approved'));
    }

    public function testTheModeratorsEmailWaitsForTheVerdict(): void
    {
        $this->configure();
        $id = $this->post('Komentarz czekający na ocenę.');
        wp_new_comment_notify_moderator($id);

        self::assertSame([], WpStub::$emails, 'no "awaiting moderation" e-mail while the verdict is on its way');
    }
}
