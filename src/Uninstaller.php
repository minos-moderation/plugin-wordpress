<?php

declare(strict_types=1);

namespace Minos\WordPress;

defined('ABSPATH') || exit;

/**
 * Removes everything the plugin stored, when it is deleted (`uninstall.php`).
 *
 * The comments stay as they are: a comment published in its masked form stays masked (its
 * original goes with the plugin's meta), and a comment still waiting stays held for manual
 * moderation.
 */
final class Uninstaller
{
    /** Every option the plugin writes. */
    public const OPTIONS = [
        Settings::OPTION, Settings::KEY_OPTION, Settings::SECRET_OPTION, Log::OPTION,
        Log::CONFIG_ERROR_OPTION,
    ];

    /**
     * Deletes the options, the comment meta and the scheduled events.
     *
     * @param Platform $wp The WordPress adapter.
     * @return void
     */
    public static function run(Platform $wp): void
    {
        foreach (self::OPTIONS as $option) {
            $wp->deleteOption($option);
        }
        foreach (Meta::ALL as $key) {
            $wp->deleteMetaEverywhere($key);
        }
        foreach (Plugin::cronHooks() as $hook) {
            $wp->unscheduleAll($hook);
        }
    }
}
