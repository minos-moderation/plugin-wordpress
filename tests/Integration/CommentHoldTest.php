<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Integration;

use Minos\WordPress\Meta;
use Minos\WordPress\Plugin;

/**
 * `pre_comment_approved` on the real `wp_new_comment`: WordPress 6.7 and later run it twice
 * for one comment (again after `wp_filter_comment`), older versions once. The plugin holds
 * every comment it moderates and keeps the decision of the LAST run as WordPress's own.
 */
final class CommentHoldTest extends IntegrationTestCase
{
    public function testTheDecisionOfTheLastRunIsWordPressesOwnAndAVerdictNeverPublishesPastIt(): void
    {
        $this->startGateway();
        // Another plugin's rule that holds a comment only once its data is filtered: on
        // WordPress 6.7+ the second run says "hold", on older versions there is none.
        $runs = 0;
        add_filter('pre_comment_approved', static function ($approved) use (&$runs) {
            $runs++;
            return $runs >= 2 ? 0 : $approved;
        }, 10);
        $seen = [];
        add_filter('pre_comment_approved', static function ($approved) use (&$seen) {
            $seen[] = $approved;
            return $approved;
        }, Plugin::HOLD_PRIORITY - 1);

        $id = $this->post('Komentarz sprawdzany dwa razy.');

        self::assertCount(version_compare($GLOBALS['wp_version'], '6.7', '>=') ? 2 : 1, $seen,
            'how often this WordPress runs the filter for one comment');
        self::assertSame('0', self::row($id)['comment_approved'], 'held for the verdict');
        self::assertSame(Meta::PENDING, self::stored($id, Meta::STATUS));
        self::assertSame((string)(int)end($seen), self::stored($id, Meta::WP_APPROVED), 'the last run decides');
        self::assertSame([['wp:' . $id]], array_map(static function (array $body): array {
            return array_column($body['elementy'], 'id');
        }, $this->sent), 'sent once');

        $this->runMockWorker();
        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries);
        self::assertSame(200, $this->serveDelivery($deliveries[0]));

        self::assertSame('bezpieczne', self::stored($id, Meta::STATUS));
        self::assertSame(self::stored($id, Meta::WP_APPROVED), self::row($id)['comment_approved'],
            'published only when WordPress itself would have published it');
    }

    public function testManualApprovalKeepsACommentHeldAfterASafeVerdict(): void
    {
        $this->startGateway();
        update_option('comment_moderation', '1');

        $id = $this->post('Komentarz na stronie z ręcznym zatwierdzaniem.');
        self::assertSame(['0', Meta::PENDING, '0'],
            [self::row($id)['comment_approved'], self::stored($id, Meta::STATUS), self::stored($id, Meta::WP_APPROVED)]);

        $this->runMockWorker();
        foreach ($this->deliveries() as $delivery) {
            self::assertSame(200, $this->serveDelivery($delivery));
        }
        self::assertSame(['0', 'bezpieczne'], [self::row($id)['comment_approved'], self::stored($id, Meta::STATUS)]);
    }

    public function testWhatWordPressRefusesOrMarksAsSpamIsLeftAloneAndNeverSent(): void
    {
        $this->startGateway();
        update_option('disallowed_keys', 'zakazanesłowo');

        $id = $this->post('Komentarz z zakazanesłowo w treści.');

        self::assertContains(self::row($id)['comment_approved'], ['spam', 'trash']);
        self::assertNull(self::stored($id, Meta::STATUS), 'not moderated by the plugin');
        self::assertSame([], $this->sent);
    }

    public function testAModeratorsCommentIsNotHeldOrSent(): void
    {
        $this->startGateway();
        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);

        $id = $this->post('Komentarz redaktora.');

        self::assertSame('1', self::row($id)['comment_approved']);
        self::assertNull(self::stored($id, Meta::STATUS));
        self::assertSame([], $this->sent);
    }

    public function testACommentOverTheRestApiIsHeldAndSentToo(): void
    {
        $this->startGateway();
        // Anonymous comments over the REST API are off by default.
        add_filter('rest_allow_anonymous_comments', '__return_true');
        $request = new \WP_REST_Request('POST', '/wp/v2/comments');
        $request->set_body_params([
            'post'         => $this->postId,
            'author_name'  => 'Gość przez REST',
            'author_email' => 'gosc.rest@example.invalid',
            'content'      => 'Komentarz wysłany przez REST API.',
        ]);
        $_SERVER['REMOTE_ADDR'] = '198.18.255.254';

        $response = rest_get_server()->dispatch($request);

        self::assertSame(201, $response->get_status(), (string)wp_json_encode($response->get_data()));
        $id = (int)$response->get_data()['id'];
        self::assertSame(['0', Meta::PENDING], [self::row($id)['comment_approved'], self::stored($id, Meta::STATUS)]);
        self::assertSame(['wp:' . $id], array_column($this->sent[0]['elementy'] ?? [], 'id'));
    }
}
