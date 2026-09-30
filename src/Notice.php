<?php

declare(strict_types=1);

namespace Minos\WordPress;

defined('ABSPATH') || exit;

/**
 * The privacy (RODO) notice under the comment form: it tells a commenter that the comment's
 * text is sent for automatic assessment. Shown only when the plugin is active, the notice
 * is switched on, and the visitor's comments are moderated at all (not to moderators).
 */
final class Notice
{
    /** @var Platform */
    private $wp;

    /** @var Settings */
    private $settings;

    /**
     * @param Platform $wp       The WordPress adapter.
     * @param Settings $settings The settings.
     */
    public function __construct(Platform $wp, Settings $settings)
    {
        $this->wp = $wp;
        $this->settings = $settings;
    }

    /**
     * `comment_form` action: prints the notice at the bottom of the form.
     *
     * @return void
     */
    public function render(): void
    {
        if (!$this->settings->isActive() || !$this->settings->all()['notice_enabled']
            || $this->wp->currentUserCan('moderate_comments')) {
            return;
        }
        echo '<p class="minos-moderation-notice">' . esc_html($this->settings->noticeText()) . '</p>';
    }
}
