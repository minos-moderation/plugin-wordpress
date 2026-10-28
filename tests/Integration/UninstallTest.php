<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Integration;

use Minos\WordPress\Plugin;
use Minos\WordPress\Retention;
use Minos\WordPress\Submission;
use Minos\WordPress\Sweeper;

/**
 * Deleting the plugin: WordPress defines `WP_UNINSTALL_PLUGIN` and includes `uninstall.php`
 * (`uninstall_plugin()`). Everything the plugin stored goes; the comments stay as they are.
 */
final class UninstallTest extends IntegrationTestCase
{
    public function testUninstallRemovesEveryOptionMetaAndEventAndLeavesTheComments(): void
    {
        global $wpdb;
        $this->startGateway();
        $waiting = $this->post('Komentarz czekający na werdykt.');
        $masked = $this->post('Komentarz z paskudny fragmentem.');
        $mask = str_replace('paskudny', '████████', 'Komentarz z paskudny fragmentem.');
        self::assertSame(200, $this->serveSigned(self::verdict($masked, 'ocenzurowane', ['ocenzurowany' => $mask])));
        $this->configure(['gateway_url' => 'http://127.0.0.1:9']);
        $retrying = $this->post('Komentarz, gdy brama nie odpowiada.');
        $before = self::row($masked)['comment_content'];
        foreach ([Sweeper::HOOK, Retention::HOOK, Submission::HOOK_RETRY] as $hook) {
            self::assertNotSame([], self::events($hook), "the plugin had scheduled {$hook}");
        }
        self::assertNotEmpty(self::pluginRows(), 'the plugin had stored data');

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'minos-moderation/minos-moderation.php');
        }
        include dirname(__DIR__, 2) . '/uninstall.php';
        wp_cache_flush();

        self::assertSame([], self::pluginRows());
        foreach (Plugin::cronHooks() as $hook) {
            self::assertSame([], self::events($hook), $hook);
        }
        self::assertSame(['0', '1', '0'], [self::row($waiting)['comment_approved'], self::row($masked)['comment_approved'],
            self::row($retrying)['comment_approved']], 'waiting comments stay held, the published one published');
        self::assertSame($before, self::row($masked)['comment_content'], 'the masked text stays masked');
        self::assertNotSame(0, (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments}"));
    }

    /**
     * What the plugin keeps in the database: its options and its comment meta.
     *
     * @return array<int,string> Option names and meta keys.
     */
    private static function pluginRows(): array
    {
        global $wpdb;
        $options = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('minos_moderation') . '%'));
        $meta = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM {$wpdb->commentmeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like('_minos_') . '%'));
        return array_merge($options, $meta);
    }
}
