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

    public function testASwitchedOnPluginWithoutASecretSaysSo(): void
    {
        $this->configure();
        WpStub::$options[Settings::SECRET_OPTION] = '';
        WpStub::$currentUser = 1;
        WpStub::$userCaps[1] = ['manage_options'];

        self::assertStringContainsString('brakuje klucza API lub sekretu', $this->notices());
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
