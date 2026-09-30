<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Settings;
use WpStub;

/**
 * The privacy notice under the comment form (`comment_form` action).
 */
final class NoticeTest extends PluginTestCase
{
    public function testTheDefaultNoticeIsShownToACommenter(): void
    {
        $this->configure();

        $html = $this->form();

        self::assertStringContainsString('class="minos-moderation-notice"', $html);
        self::assertStringContainsString('Minos', $html);
        self::assertStringContainsString('bez imienia, adresu e-mail i adresu IP', $html);
    }

    public function testTheAdministratorsTextIsShownAsText(): void
    {
        $this->configure(['notice_text' => 'Sprawdzamy komentarze <script>alert(1)</script> automatycznie.']);

        $html = $this->form();

        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testNoNoticeWhenItIsOffThePluginIsInactiveOrTheVisitorModerates(): void
    {
        $this->configure(['notice_enabled' => false]);
        self::assertSame('', $this->form());

        $this->configure(['enabled' => false]);
        self::assertSame('', $this->form());

        $this->configure();
        WpStub::$options[Settings::KEY_OPTION] = '';
        self::assertSame('', $this->form());

        $this->configure();
        WpStub::$currentUser = 3;
        WpStub::$userCaps[3] = ['moderate_comments'];
        self::assertSame('', $this->form(), 'a moderator\'s comments are not sent');
    }

    /**
     * @return string What the plugin prints into the comment form.
     */
    private function form(): string
    {
        ob_start();
        do_action('comment_form', 1);
        return (string)ob_get_clean();
    }
}
