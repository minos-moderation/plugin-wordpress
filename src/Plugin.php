<?php

declare(strict_types=1);

namespace Minos\WordPress;

use Minos\WordPress\Admin\CommentsScreen;
use Minos\WordPress\Admin\SettingsPage;

defined('ABSPATH') || exit;

/**
 * Builds the plugin's objects and hooks them into WordPress. WordPress glue: nothing here
 * decides anything.
 */
final class Plugin
{
    /** The text domain. */
    public const TEXT_DOMAIN = 'minos-moderation';

    /**
     * Late, so the site's own rules and other plugins (spam filters) decide first; the
     * plugin holds only what they would publish or hold, never what they refused.
     */
    public const HOLD_PRIORITY = 999;

    /** Early, so the comment is marked pending before WordPress's own notifications run. */
    public const SUBMIT_PRIORITY = 5;

    /** @var Platform */
    public $wp;

    /** @var Settings */
    public $settings;

    /** @var Log */
    public $log;

    /** @var Outcome */
    public $outcome;

    /** @var Submission */
    public $submission;

    /** @var Receiver */
    public $receiver;

    /** @var Sweeper */
    public $sweeper;

    /** @var Notice */
    public $notice;

    /**
     * Builds the objects.
     *
     * @param Platform $wp The WordPress adapter (tests give it a fixed clock).
     */
    public function __construct(Platform $wp)
    {
        $this->wp = $wp;
        $this->settings = new Settings($wp);
        $this->log = new Log($wp);
        $this->outcome = new Outcome($wp, $this->settings, $this->log);
        $this->submission = new Submission($wp, $this->settings, $this->outcome, $this->log);
        $this->receiver = new Receiver($wp, $this->settings, $this->outcome);
        $this->sweeper = new Sweeper($wp, $this->submission, $this->outcome, $this->settings);
        $this->notice = new Notice($wp, $this->settings);
    }

    /**
     * Starts the plugin. Called once, when the main plugin file loads.
     *
     * @param string $file The main plugin file.
     * @return void
     */
    public static function boot(string $file): void
    {
        (new self(new Platform()))->hook($file);
    }

    /**
     * Hooks the objects into WordPress.
     *
     * @param string $file The main plugin file.
     * @return void
     */
    public function hook(string $file): void
    {
        $submission = $this->submission;
        $sweeper = $this->sweeper;
        $receiver = $this->receiver;
        $wp = $this->wp;

        add_filter('pre_comment_approved', [$submission, 'holdForAssessment'], self::HOLD_PRIORITY, 2);
        add_action('comment_post', [$submission, 'onCommentPost'], self::SUBMIT_PRIORITY, 2);
        add_action('rest_insert_comment', [$submission, 'onRestInsertComment'], self::SUBMIT_PRIORITY, 3);
        add_filter('notify_moderator', [$this->outcome, 'filterNotifyModerator'], 10, 2);
        // A person's status change or edit after a fail-open publication stands.
        add_action('transition_comment_status', [$this->outcome, 'onStatusChange'], 10, 3);
        add_action('edit_comment', [$this->outcome, 'onEdit']);
        add_action(Submission::HOOK_RETRY, [$submission, 'retry']);
        add_action(Outcome::HOOK_NOTIFY, [$this->outcome, 'notify']);
        add_filter('cron_schedules', [Sweeper::class, 'addSchedule']);
        add_action(Sweeper::HOOK, [$sweeper, 'run']);
        add_action('comment_form', [$this->notice, 'render']);
        add_action('rest_api_init', static function () use ($receiver): void {
            register_rest_route(Receiver::REST_NAMESPACE, Receiver::ROUTE, [
                'methods'             => 'POST',
                'callback'            => [$receiver, 'handle'],
                // The signature is the authentication: Receiver refuses an unsigned body.
                'permission_callback' => '__return_true',
            ]);
        });
        add_action('init', static function () use ($file, $sweeper): void {
            load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename($file)) . '/languages');
            $sweeper->ensureScheduled();
        });

        register_activation_hook($file, static function () use ($wp, $sweeper): void {
            // Created non-autoloaded, so the secrets are not loaded on every request.
            $wp->addOption(Settings::KEY_OPTION, '');
            $wp->addOption(Settings::SECRET_OPTION, '');
            $sweeper->ensureScheduled();
        });
        register_deactivation_hook($file, static function () use ($wp): void {
            // Comments still waiting stay held for manual moderation.
            foreach (self::cronHooks() as $hook) {
                $wp->unscheduleAll($hook);
            }
        });

        if (is_admin()) {
            (new SettingsPage($this->settings, $this->log, $receiver))->register();
            (new CommentsScreen($wp, $this->settings, $this->log))->register();
        }
    }

    /**
     * The WP-Cron actions the plugin schedules.
     *
     * @return array<int,string> The actions.
     */
    public static function cronHooks(): array
    {
        return [Sweeper::HOOK, Submission::HOOK_RETRY, Outcome::HOOK_NOTIFY];
    }
}
