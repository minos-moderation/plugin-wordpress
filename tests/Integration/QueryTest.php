<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Integration;

use Minos\WordPress\Meta;
use Minos\WordPress\Platform;
use Minos\WordPress\Retention;
use Minos\WordPress\Sweeper;

/**
 * The plugin's comment queries on the real `WP_Comment_Query`: by a meta key alone in any
 * status (the sweep), by a meta value compared as a number (the 30-day retention, the
 * settings page's last 7 days), with a date bound (the support notice), and the count of an
 * author's approved comments.
 */
final class QueryTest extends IntegrationTestCase
{
    public function testTheSweepFindsWaitingCommentsByTheirKeyWhateverTheirStatus(): void
    {
        $this->startGateway();
        $late = $this->post('Werdykt nie nadszedł na czas.');
        $recent = $this->post('Werdykt jeszcze może nadejść.');
        $spammed = $this->post('Oznaczony przez moderatora jako spam.');
        self::age($late, 21 * 60);
        self::age($spammed, 21 * 60);
        self::assertTrue(wp_spam_comment($spammed));

        do_action(Sweeper::HOOK);

        self::assertSame([Meta::UNASSESSED, '1', '0'],
            [self::stored($late, Meta::STATUS), self::stored($late, Meta::TIMED_OUT), self::row($late)['comment_approved']]);
        self::assertNull(self::stored($late, Meta::WAITING));
        self::assertSame([Meta::PENDING, '1'], [self::stored($recent, Meta::STATUS), self::stored($recent, Meta::WAITING)]);
        self::assertSame([Meta::UNASSESSED, 'spam'],
            [self::stored($spammed, Meta::STATUS), self::row($spammed)['comment_approved']], 'found in spam too, left there');
    }

    public function testRetentionComparesTheSettlementMomentAsANumber(): void
    {
        $this->startGateway();
        $old = $this->post('Rozstrzygnięty ponad 30 dni temu.');
        $young = $this->post('Rozstrzygnięty 29 dni temu.');
        $this->runMockWorker();
        foreach ($this->deliveries() as $delivery) {
            self::assertSame(200, $this->serveDelivery($delivery));
        }
        self::age($old, 31 * 86400);
        self::age($young, 29 * 86400);
        // Settled in 2001: nine digits, so a comparison as text would call it the newest.
        $ancient = self::factory()->comment->create(['comment_post_ID' => $this->postId, 'comment_approved' => '1']);
        update_comment_meta($ancient, Meta::STATUS, 'bezpieczne');
        update_comment_meta($ancient, Meta::DECIDED_AT, '999999999');

        do_action(Retention::HOOK);

        foreach ([$old, $ancient] as $id) {
            self::assertSame([], self::pluginMeta($id), "comment {$id}: its data is gone");
            self::assertNotNull(get_comment($id), 'the comment itself stays');
        }
        self::assertSame('bezpieczne', self::pluginMeta($young)[Meta::STATUS] ?? null);
    }

    public function testTheLastWeeksNumbersAndTheSupportNoticeFindTheRightComments(): void
    {
        $wp = new Platform();
        $now = time();
        $week = $now - 7 * 86400;
        $inside = self::factory()->comment->create(['comment_post_ID' => $this->postId,
            'comment_date_gmt' => gmdate('Y-m-d H:i:s', $now - 3600)]);
        $outside = self::factory()->comment->create(['comment_post_ID' => $this->postId,
            'comment_date_gmt' => gmdate('Y-m-d H:i:s', $now - 8 * 86400)]);
        update_comment_meta($inside, Meta::STATUS, 'zablokowane');
        update_comment_meta($inside, Meta::DECIDED_AT, (string)($now - 3600));
        update_comment_meta($inside, Meta::SUBMITTED_AT, (string)($now - 3700));
        update_comment_meta($outside, Meta::STATUS, 'bezpieczne');
        update_comment_meta($outside, Meta::DECIDED_AT, (string)($now - 8 * 86400));
        update_comment_meta($inside, Meta::SUPPORT, '1');
        update_comment_meta($outside, Meta::SUPPORT, '1');

        $settled = $wp->settledSince($week, 2000);
        self::assertSame([['status' => 'zablokowane', 'submitted_at' => $now - 3700, 'decided_at' => $now - 3600,
            'timed_out' => false]], $settled);
        self::assertSame(1, $wp->supportFlagsSince($week));
    }

    public function testTheFirstCommentSignalCountsTheAuthorsApprovedComments(): void
    {
        $this->startGateway();
        self::factory()->comment->create(['comment_post_ID' => $this->postId, 'comment_approved' => '1',
            'comment_author_email' => 'staly.bywalec@example.invalid',
            'comment_date_gmt' => gmdate('Y-m-d H:i:s', time() - 7200)]);
        self::factory()->comment->create(['comment_post_ID' => $this->postId, 'comment_approved' => '0',
            'comment_author_email' => 'czekajacy@example.invalid',
            'comment_date_gmt' => gmdate('Y-m-d H:i:s', time() - 7200)]);

        $this->post('Kolejny komentarz stałego bywalca.', ['email' => 'staly.bywalec@example.invalid']);
        $this->post('Drugi komentarz, pierwszy wciąż czeka.', ['email' => 'czekajacy@example.invalid']);

        self::assertSame([false, true], array_map(static function (array $body): bool {
            return $body['elementy'][0]['meta']['author_first_post'];
        }, $this->sent));
    }

    /**
     * The plugin's meta of a comment, read from the database.
     *
     * @param int $id The comment.
     * @return array<string,string> Key → value.
     */
    private static function pluginMeta(int $id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->commentmeta} WHERE comment_id = %d AND meta_key LIKE %s",
            $id, $wpdb->esc_like('_minos_') . '%'), ARRAY_A);
        return array_column($rows, 'meta_value', 'meta_key');
    }
}
