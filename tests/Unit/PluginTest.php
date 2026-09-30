<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Platform;
use Minos\WordPress\Plugin;
use WpStub;

/**
 * The wiring: which WordPress hooks the plugin uses, in which order and with how many
 * arguments — the parts of the design that live in priorities.
 */
final class PluginTest extends PluginTestCase
{
    public function testTheHoldRunsAfterTheSitesOwnFiltersAndSeesTheCommentData(): void
    {
        [$priority, $args] = self::registration('pre_comment_approved', 'holdForAssessment');
        self::assertGreaterThan(10, $priority, 'spam filters at the default priority decide first');
        self::assertSame(2, $args);
    }

    public function testTheSubmissionRunsBeforeWordPresssNotifications(): void
    {
        // wp_new_comment_notify_moderator runs on comment_post at the default priority 10.
        [$priority, $args] = self::registration('comment_post', 'onCommentPost');
        self::assertLessThan(10, $priority);
        self::assertSame(2, $args);
        [$priority, $args] = self::registration('rest_insert_comment', 'onRestInsertComment');
        self::assertLessThan(10, $priority);
        self::assertSame(3, $args);
        [, $args] = self::registration('notify_moderator', 'filterNotifyModerator');
        self::assertSame(2, $args);
    }

    public function testTheAdminScreensAreHookedOnlyInTheAdmin(): void
    {
        self::assertArrayNotHasKey('admin_menu', WpStub::$hooks);

        WpStub::reset();
        WpStub::$admin = true;
        (new Plugin(new Platform()))->hook(__DIR__ . '/../../minos-moderation.php');

        foreach (['admin_menu', 'admin_init', 'admin_notices', 'manage_edit-comments_columns',
            'manage_comments_custom_column'] as $hook) {
            self::assertArrayHasKey($hook, WpStub::$hooks, $hook);
        }
    }

    /**
     * The priority and the accepted arguments of the plugin's callback on a hook.
     *
     * @return array{0:int,1:int}
     */
    private static function registration(string $hook, string $method): array
    {
        foreach (WpStub::$hooks[$hook] ?? [] as [$callback, $priority, $args]) {
            if (is_array($callback) && $callback[1] === $method) {
                return [$priority, $args];
            }
        }
        self::fail("{$method} is not hooked to {$hook}");
    }
}
