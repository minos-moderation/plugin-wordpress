<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Integration;

use Minos\WordPress\Admin\CommentsScreen;
use Minos\WordPress\Meta;
use Minos\WordPress\Outcome;
use Minos\WordPress\Settings;
use Minos\WordPress\Sweeper;

/**
 * WordPress's own status changes and e-mails around a verdict: `wp_set_comment_status`
 * (approving mails the post's author from core, inside the webhook's request),
 * `transition_comment_status`, `wp_update_comment` with `edit_comment`, and the moderator's
 * e-mail the plugin holds back for WP-Cron.
 */
final class StatusAndMailTest extends IntegrationTestCase
{
    public function testASafeVerdictPublishesAndCoreMailsThePostsAuthorInsideTheWebhookRequest(): void
    {
        $this->startGateway();
        $id = $this->post('Uprzejmy komentarz pod wpisem.');
        self::assertSame([], self::mails(), 'no "awaiting moderation" e-mail while it waits for the verdict');

        $this->runMockWorker();
        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries);
        self::assertSame(200, $this->serveDelivery($deliveries[0]));

        self::assertSame('1', self::row($id)['comment_approved']);
        $mails = self::mails();
        self::assertCount(1, $mails, 'one e-mail, sent while the webhook request ran');
        self::assertSame(self::AUTHOR_EMAIL, $mails[0][0]);
        self::assertSame([], self::events(Outcome::HOOK_NOTIFY), 'nothing for the moderator');
    }

    public function testAHeldVerdictMailsTheModeratorFromWpCronNotFromTheRequest(): void
    {
        $this->startGateway();
        $id = $this->post('Komentarz testowy [minos:blokuj] [minos:kategoria=nekanie]');

        $this->runMockWorker();
        foreach ($this->deliveries() as $delivery) {
            self::assertSame(200, $this->serveDelivery($delivery));
        }
        self::assertSame(['0', 'zablokowane'], [self::row($id)['comment_approved'], self::stored($id, Meta::STATUS)]);
        self::assertSame([], self::mails(), 'the webhook answers without sending e-mail');
        $events = self::events(Outcome::HOOK_NOTIFY);
        self::assertSame([[$id]], array_column($events, 'args'));

        do_action(Outcome::HOOK_NOTIFY, $id);

        $recipients = array_column(self::mails(), 0);
        self::assertContains(get_option('admin_email'), $recipients, 'the moderator is told now');
    }

    /**
     * Shared rule A7 on the real WordPress: under "mark as spam" a blocked comment with the
     * support cue stays held for a person, and the moderator's e-mail WordPress composes
     * names the cue; the comment's author gets nothing. Without the cue the setting applies.
     */
    public function testABlockedCommentWithTheSupportCueStaysHeldAndTheModeratorsEmailNamesIt(): void
    {
        $this->startGateway(['blocked_mode' => Settings::BLOCKED_SPAM]);
        $support = $this->post('Komentarz testowy z sygnałem wsparcia.');
        $plain = $this->post('Komentarz testowy bez sygnału.');

        self::assertSame(200, $this->serveSigned(self::verdict($support, 'zablokowane',
            ['kategorie' => ['samookaleczenie'], 'wsparcie' => true])));
        self::assertSame(200, $this->serveSigned(self::verdict($plain, 'zablokowane', ['kategorie' => ['nekanie']])));

        self::assertSame(['0', 'zablokowane', '1'], [self::row($support)['comment_approved'],
            self::stored($support, Meta::STATUS), self::stored($support, Meta::SUPPORT)]);
        self::assertSame('spam', self::row($plain)['comment_approved'], 'without the cue the setting applies');
        self::assertSame([[$support]], array_column(self::events(Outcome::HOOK_NOTIFY), 'args'));
        self::assertSame([], self::mails(), 'the webhook answers without sending e-mail');

        do_action(Outcome::HOOK_NOTIFY, $support);

        $mails = self::mails();
        self::assertNotSame([], $mails, 'the moderator is told');
        foreach ($mails as [$to, , $text]) {
            self::assertStringNotContainsString(self::row($support)['comment_author_email'], $to, 'never its author');
            self::assertStringContainsString(CommentsScreen::supportAdvice(), $text);
        }
        self::assertContains(get_option('admin_email'), array_column($mails, 0));
    }

    /**
     * Shared rule A7: a late `zablokowane` with the support cue leaves a fail-open
     * publication published until a person decides.
     */
    public function testALateBlockedVerdictWithTheSupportCueLeavesAFailOpenPublicationPublished(): void
    {
        $this->startGateway(['failure_mode' => Settings::FAIL_OPEN, 'blocked_mode' => Settings::BLOCKED_SPAM]);
        $id = $this->post('Komentarz testowy opublikowany bez werdyktu.');
        self::age($id, 21 * 60);
        do_action(Sweeper::HOOK);
        self::assertSame('1', self::row($id)['comment_approved']);

        self::assertSame(200, $this->serveSigned(self::verdict($id, 'zablokowane',
            ['kategorie' => ['samookaleczenie'], 'wsparcie' => true])));

        self::assertSame(['1', 'zablokowane', '1', null], [self::row($id)['comment_approved'],
            self::stored($id, Meta::STATUS), self::stored($id, Meta::SUPPORT), self::stored($id, Meta::AUTO_PUBLISHED)]);
    }

    public function testAPersonsDecisionAfterAFailOpenPublicationStandsAgainstALateVerdict(): void
    {
        $this->startGateway(['failure_mode' => Settings::FAIL_OPEN]);
        $id = $this->post('Komentarz bez werdyktu na czas.');
        self::age($id, 21 * 60);
        do_action(Sweeper::HOOK);
        self::assertSame(['1', '1'], [self::row($id)['comment_approved'], self::stored($id, Meta::AUTO_PUBLISHED)]);

        // A moderator looks at it, holds it, then approves it again.
        self::assertTrue(wp_set_comment_status($id, 'hold'));
        self::assertTrue(wp_set_comment_status($id, 'approve'));
        self::assertNull(self::stored($id, Meta::AUTO_PUBLISHED), 'transition_comment_status cleared the flag');

        self::assertSame(200, $this->serveSigned(self::verdict($id, 'zablokowane')));
        self::assertSame('1', self::row($id)['comment_approved'], 'the moderator\'s approval stands');
    }

    public function testALateVerdictHoldsAFailOpenPublicationNobodyTouched(): void
    {
        $this->startGateway(['failure_mode' => Settings::FAIL_OPEN]);
        $id = $this->post('Komentarz opublikowany bez werdyktu.');
        self::age($id, 21 * 60);
        do_action(Sweeper::HOOK);
        self::assertSame('1', self::row($id)['comment_approved']);

        self::assertSame(200, $this->serveSigned(self::verdict($id, 'zablokowane')));
        self::assertSame(['0', 'zablokowane'], [self::row($id)['comment_approved'], self::stored($id, Meta::STATUS)]);
    }

    public function testAnEditWithWpUpdateCommentSendsTheNextRevisionAndTheOldVerdictChangesNothing(): void
    {
        $this->startGateway();
        $id = $this->post('Pierwsza wersja [minos:blokuj]');

        $result = wp_update_comment(['comment_ID' => $id, 'comment_content' => 'Druga, poprawiona wersja.']);

        self::assertSame(1, $result);
        self::assertSame(['wp:' . $id, 'wp:' . $id . '.1'], array_map(static function (array $body): string {
            return $body['elementy'][0]['id'];
        }, $this->sent), 'edit_comment sent the next revision');
        self::assertSame('Druga, poprawiona wersja.', $this->sent[1]['elementy'][0]['tekst']);

        $this->runMockWorker();
        $byItem = [];
        foreach ($this->deliveries() as $delivery) {
            $byItem[json_decode($delivery['body'], true)['id']] = $delivery;
        }
        $items = array_keys($byItem);
        sort($items, SORT_STRING);
        self::assertSame(['wp:' . $id, 'wp:' . $id . '.1'], $items);

        // The verdict on the first text comes first: it changes nothing.
        self::assertSame(200, $this->serveDelivery($byItem['wp:' . $id]));
        self::assertSame(['0', Meta::PENDING, '1', null],
            [self::row($id)['comment_approved'], self::stored($id, Meta::STATUS), self::stored($id, Meta::REVISION),
                self::stored($id, Meta::RETRY_AT)], 'the stale verdict is not applied and sends nothing again');

        self::assertSame(200, $this->serveDelivery($byItem['wp:' . $id . '.1']));
        self::assertSame(['1', 'bezpieczne', '1'],
            [self::row($id)['comment_approved'], self::stored($id, Meta::STATUS), self::stored($id, Meta::REVISION)],
            'the verdict on the first text blocked nothing; the one on the edited text applied');
    }
}
