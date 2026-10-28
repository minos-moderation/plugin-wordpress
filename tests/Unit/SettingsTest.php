<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Admin\CommentsScreen;
use Minos\WordPress\Admin\SettingsPage;
use Minos\WordPress\Log;
use Minos\WordPress\Meta;
use Minos\WordPress\Settings;
use WpStub;

/**
 * The settings: their defaults, what the form accepts, and what the admin pages show — never
 * more of the key or the secret than a prefix.
 */
final class SettingsTest extends PluginTestCase
{
    /** @var SettingsPage */
    private $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->page = new SettingsPage($this->plugin->settings, $this->plugin->log, $this->plugin->receiver);
    }

    public function testTheDefaults(): void
    {
        $settings = $this->plugin->settings->all();

        self::assertFalse($settings['enabled'], 'nothing is held before the administrator switches it on');
        self::assertSame('https://gateway.wergiliusz.app', $settings['gateway_url']);
        self::assertSame('forum_adult', $settings['profile']);
        self::assertSame(Settings::FAIL_CLOSED, $settings['failure_mode']);
        self::assertSame(20, $settings['timeout_min']);
        self::assertSame(Settings::CENSORED_PUBLISH, $settings['censored_mode']);
        self::assertSame(Settings::HOLD, $settings['blocked_mode']);
        self::assertTrue($settings['notice_enabled']);
        self::assertSame(Settings::defaultNotice(), $this->plugin->settings->noticeText());
        self::assertFalse($this->plugin->settings->isActive());
    }

    public function testTheDefaultNoticeNamesWhatIsSentAndClaimsNothingAboutTheTextNeverBeingKept(): void
    {
        $notice = Settings::defaultNotice();

        // Every kind of data the request carries (Submission::item): the text, the link
        // count, the link domains and the first-comment signal.
        foreach ([
            'the text'          => '/\btekst\w*\s+komentarz/u',
            'the links'         => '/\blink\w*/u',
            'the domains'       => '/\bdomen\w*/u',
            'the first comment' => '/\bpierwsz\w*\s+komentarz/u',
        ] as $what => $pattern) {
            self::assertMatchesRegularExpression($pattern, $notice, "the notice must name {$what}");
        }
        // What the service may hold, as the privacy policy states it.
        self::assertMatchesRegularExpression('/\b30\s+minut/u', $notice);
        self::assertMatchesRegularExpression('/\b72\s+godzin/u', $notice);

        // No claim that the text is never kept, and none that only the text is sent. Each
        // pattern must catch a sentence that makes such a claim, or it guards nothing.
        $falseClaims = [
            'Treść nie jest przechowywana po zakończeniu oceny.',
            'Usługa nie przechowuje treści komentarzy.',
            'Treść komentarza nigdy nie jest zapisywana.',
            'Przesyłamy do niej wyłącznie jego treść.',
        ];
        foreach (['/\bnie\s+(jest\s+)?(\w+\s+)?przechowywan/u', '/\bnie\s+przechowuj/u',
            '/\bnigdy\s+nie\s+(jest\s+)?(przechowywan|zapisywan)/u', '/\bwyłącznie\s+(jego\s+)?treść/u'] as $claim) {
            self::assertNotEmpty(preg_grep($claim, $falseClaims), "{$claim} catches no false claim: it is broken");
            self::assertDoesNotMatchRegularExpression($claim, $notice);
        }
    }

    public function testTheTimeoutIsNeverShorterThanTheGatewaysDeliveryWindowWithGrace(): void
    {
        foreach (['5', 19, '20', 45] as $minutes) {
            WpStub::$options[Settings::OPTION] = ['timeout_min' => $minutes];
            self::assertSame(max(20, (int)$minutes) * 60, $this->plugin->settings->timeoutS(), (string)$minutes);
        }
    }

    public function testAStoredValueNobodyChoseReadsAsTheDefault(): void
    {
        WpStub::$options[Settings::OPTION] = ['enabled' => 'yes', 'failure_mode' => 'guess', 'profile' => 'forum_kids',
            'censored_mode' => 'publish_original', 'blocked_mode' => 'delete', 'timeout_min' => 'soon',
            'gateway_url' => 'http://gateway.example'];

        self::assertSame(Settings::defaults(), $this->plugin->settings->all());
    }

    public function testTheFormIsSanitised(): void
    {
        $saved = $this->page->sanitizeSettings([
            'enabled' => '1', 'gateway_url' => 'https://brama.example/', 'profile' => 'forum_teen',
            'failure_mode' => Settings::FAIL_OPEN, 'timeout_min' => '3', 'censored_mode' => Settings::HOLD,
            'blocked_mode' => Settings::BLOCKED_SPAM, 'notice_text' => " <b>Własna</b> informacja \n",
        ]);

        self::assertSame([
            'enabled' => true, 'gateway_url' => 'https://brama.example', 'profile' => 'forum_teen',
            'failure_mode' => Settings::FAIL_OPEN, 'timeout_min' => Settings::MIN_TIMEOUT_MIN,
            'censored_mode' => Settings::HOLD, 'blocked_mode' => Settings::BLOCKED_SPAM,
            'notice_enabled' => false, 'notice_text' => 'Własna informacja',
        ], $saved);
        self::assertSame([], WpStub::$settingsErrors);
    }

    /**
     * @return array<string,array{0:string,1:bool}>
     */
    public static function gatewayUrls(): array
    {
        return [
            'production'         => ['https://gateway.wergiliusz.app', true],
            'the mock, locally'  => ['http://127.0.0.1:8100', true],
            'localhost'          => ['http://localhost:8100', true],
            'plain http'         => ['http://gateway.example', false],
            'credentials'        => ['https://user:haslo@gateway.example', false],
            'a query'            => ['https://gateway.example/?x=1', false],
            'no scheme'          => ['gateway.example', false],
            'another scheme'     => ['ftp://gateway.example', false],
        ];
    }

    /**
     * @dataProvider gatewayUrls
     */
    public function testWhichGatewayUrlsAreAccepted(string $url, bool $valid): void
    {
        self::assertSame($valid, Settings::validGatewayUrl($url));
    }

    public function testAnInvalidGatewayUrlKeepsTheStoredOneAndSaysWhy(): void
    {
        $this->configure(['gateway_url' => 'https://brama.example']);
        $saved = $this->page->sanitizeSettings(['gateway_url' => 'http://brama.example']);

        self::assertSame('https://brama.example', $saved['gateway_url']);
        self::assertCount(1, WpStub::$settingsErrors);
    }

    public function testAnEmptyKeyFieldKeepsTheStoredKeyAndAnInvalidOneIsRefused(): void
    {
        $this->configure();

        self::assertSame(self::KEY, $this->page->sanitizeKey(''));
        self::assertSame(self::KEY, $this->page->sanitizeKey('sk-nie-ten-format'));
        self::assertSame('wgb2b_nowy_klucz_1234567890', $this->page->sanitizeKey(' wgb2b_nowy_klucz_1234567890 '));
        self::assertCount(1, WpStub::$settingsErrors);
        self::assertStringNotContainsString('sk-nie-ten-format', WpStub::$settingsErrors[0]['message']);
    }

    public function testAnEmptySecretFieldKeepsTheStoredSecretAndAnInvalidOneIsRefused(): void
    {
        $this->configure();

        self::assertSame(self::SECRET, $this->page->sanitizeSecret(''));
        self::assertSame(self::SECRET, $this->page->sanitizeSecret('krótki'));
        self::assertSame(self::SECRET, $this->page->sanitizeSecret('ze spacją w środku 0123456789'));
        self::assertSame('nowy-sekret-webhooka-abcdef012345', $this->page->sanitizeSecret('nowy-sekret-webhooka-abcdef012345'));
        self::assertCount(2, WpStub::$settingsErrors);
    }

    public function testAKeyOrASecretWithATrailingNewlineIsNotValid(): void
    {
        self::assertTrue(Settings::validKey(self::KEY));
        self::assertTrue(Settings::validSecret(self::SECRET));
        foreach (["\n", "\r\n", "\0"] as $tail) {
            self::assertFalse(Settings::validKey(self::KEY . $tail), json_encode($tail));
            self::assertFalse(Settings::validSecret(self::SECRET . $tail), json_encode($tail));
        }
    }

    public function testTheSecretsAreSavedThroughTheirSanitisers(): void
    {
        $this->configure();
        $this->page->registerSettings();

        update_option(Settings::KEY_OPTION, '');
        update_option(Settings::SECRET_OPTION, 'za-krotki');

        self::assertSame(self::KEY, get_option(Settings::KEY_OPTION));
        self::assertSame(self::SECRET, get_option(Settings::SECRET_OPTION));
    }

    public function testThePageShowsTheWebhookUrlAndOnlyAPrefixOfTheSecrets(): void
    {
        $this->configure();
        WpStub::$currentUser = 1;
        WpStub::$userCaps[1] = ['manage_options'];
        WpStub::answer(401, ['blad' => ['kod' => 'brak_klucza']]);
        $this->post('Komentarz przy złym kluczu.');
        $this->page->registerSettings();

        ob_start();
        $this->page->render();
        $html = (string)ob_get_clean();

        self::assertStringContainsString('https://forum.example/wp-json/minos/v1/webhook', $html);
        self::assertStringContainsString(Settings::prefix(self::KEY, 10), $html);
        self::assertStringContainsString('brak_klucza', $html, 'the log is on the page');
        self::assertStringContainsString('name="' . Settings::KEY_OPTION . '"', $html, 'the fields were rendered');
        foreach ([self::KEY, self::SECRET, substr(self::KEY, 0, 12), substr(self::SECRET, 0, 6)] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
    }

    public function testAPrefixNeverShowsMoreThanHalfOfAShortValue(): void
    {
        self::assertSame('wgb2b_testo…', Settings::prefix(self::KEY, 11));
        self::assertSame('ab…', Settings::prefix('abcde', 10));
        self::assertSame('', Settings::prefix('', 10));
    }

    public function testTheSecretsAreCreatedNotAutoloaded(): void
    {
        call_user_func(WpStub::$lifecycle['activate']);

        self::assertFalse(WpStub::$autoload[Settings::KEY_OPTION]);
        self::assertFalse(WpStub::$autoload[Settings::SECRET_OPTION]);
    }

    public function testAConfigurationErrorIsShownToTheAdministratorWithoutTheKey(): void
    {
        $this->configure();
        WpStub::$currentUser = 1;
        WpStub::$userCaps[1] = ['manage_options'];
        WpStub::answer(403, ['blad' => ['kod' => 'brak_webhooka', 'komunikat' => self::KEY]]);
        $this->post('Komentarz bez webhooka po stronie bramy.');

        $html = $this->notices();

        self::assertStringContainsString(CommentsScreen::errorAdvice(403, 'brak_webhooka'), $html);
        self::assertStringNotContainsString(self::KEY, $html);
    }

    public function testASwitchedOnPluginWithoutAUsableSecretSaysWhyOnEveryAdminScreen(): void
    {
        $this->configure();
        WpStub::$options[Settings::SECRET_OPTION] = 'za-krotki';
        WpStub::$currentUser = 1;
        WpStub::$userCaps[1] = ['manage_options'];
        self::assertFalse($this->plugin->settings->isActive());

        foreach ([null, 'edit-comments', 'dashboard'] as $screen) {
            WpStub::$screen = $screen === null ? null : (object)['id' => $screen];
            self::assertStringContainsString(esc_html(CommentsScreen::notConfiguredMessage()), $this->notices(), (string)$screen);
        }
        $this->page->registerSettings();
        ob_start();
        $this->page->render();
        self::assertStringContainsString(esc_html(CommentsScreen::notConfiguredMessage()), (string)ob_get_clean());
        self::assertStringNotContainsString('za-krotki', $this->notices());
    }

    public function testTheCommentsScreenShowsTheVerdictAndTheSupportCue(): void
    {
        $this->configure();
        WpStub::$currentUser = 1;
        WpStub::$userCaps[1] = ['moderate_comments'];
        WpStub::$screen = (object)['id' => 'edit-comments'];
        $id = $this->post('Komentarz testowy z sygnałem wsparcia.');
        $this->deliver(self::verdict($id, 'bezpieczne', ['kategorie' => ['samookaleczenie'], 'wsparcie' => true]));

        $screen = new CommentsScreen($this->plugin->wp, $this->plugin->settings, $this->plugin->log);
        self::assertSame(['author' => 'Autor', 'minos' => 'Minos'], $screen->addColumn(['author' => 'Autor']));
        ob_start();
        $screen->renderColumn('minos', (string)$id);
        $column = (string)ob_get_clean();

        self::assertStringContainsString('Bezpieczny', $column);
        self::assertStringContainsString('samookaleczenie', $column);
        self::assertStringContainsString('116 123', $column);
        self::assertStringContainsString('samookaleczenia: 1', $this->notices());
    }

    public function testTheSupportNoticeCountsTheWeeksCuesByTheirKeyAloneNeverByAValue(): void
    {
        $this->configure();
        WpStub::$currentUser = 1;
        WpStub::$userCaps[1] = ['moderate_comments'];
        WpStub::$screen = (object)['id' => 'edit-comments'];
        $cue = ['kategorie' => ['samookaleczenie'], 'wsparcie' => true];
        $old = $this->post('Starszy komentarz testowy z sygnałem.', 1,
            ['comment_date_gmt' => gmdate('Y-m-d H:i:s', $this->now - CommentsScreen::SUPPORT_WINDOW_S - 60)]);
        $this->deliver(self::verdict($old, 'bezpieczne', $cue));
        foreach (['z sygnałem wsparcia', 'z drugim sygnałem'] as $text) {
            $id = $this->post('Komentarz testowy ' . $text . '.');
            $this->deliver(self::verdict($id, 'bezpieczne', $cue));
        }
        $plain = $this->post('Zwykły komentarz testowy.');
        $this->deliver(self::verdict($plain, 'bezpieczne'));
        WpStub::$queries = [];

        self::assertStringContainsString('samookaleczenia: 2', $this->notices(), 'the week\'s two, not the older one');

        $support = array_values(array_filter(WpStub::$queries, static function (array $query): bool {
            return ($query['meta_key'] ?? null) === Meta::SUPPORT;
        }));
        self::assertCount(1, $support, 'the notice asked for the support cues');
        self::assertArrayNotHasKey('meta_value', $support[0], 'never a query by meta value');
        self::assertArrayNotHasKey('meta_query', $support[0]);
    }

    public function testTheLogOptionHoldsOnlyTheFourFields(): void
    {
        $this->configure();
        WpStub::answer(429, ['blad' => ['kod' => 'kolejka_pelna', 'komunikat' => 'x', 'ponow_za_s' => 60, 'element' => 0]]);
        $this->post('Komentarz w czasie pełnej kolejki.');

        self::assertSame(['time', 'http', 'code', 'comment'], array_keys(WpStub::$options[Log::OPTION][0]));
        self::assertNull(WpStub::$meta[1][Meta::ERROR] ?? null);
    }

    /**
     * @return string The admin notices' HTML.
     */
    private function notices(): string
    {
        $screen = new CommentsScreen($this->plugin->wp, $this->plugin->settings, $this->plugin->log);
        ob_start();
        $screen->renderNotices();
        return (string)ob_get_clean();
    }
}
