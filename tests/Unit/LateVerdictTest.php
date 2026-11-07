<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Admin\CommentsScreen;
use Minos\WordPress\Meta;
use Minos\WordPress\Outcome;
use Minos\WordPress\Settings;
use Minos\WordPress\Submission;
use Minos\WordPress\Sweeper;
use WpStub;

/**
 * A verdict that arrives after the receive timeout, for a comment the plugin published
 * under fail-open meanwhile: applied, unless a person decided in between.
 */
final class LateVerdictTest extends PluginTestCase
{
    /**
     * Posts a comment and lets the timeout publish it under fail-open.
     *
     * @param string              $text     The comment.
     * @param array<string,mixed> $settings Settings over fail-open.
     * @return int The comment id.
     */
    private function autoPublished(string $text, array $settings = []): int
    {
        $this->configure($settings + ['failure_mode' => Settings::FAIL_OPEN]);
        $id = $this->post($text);
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;
        do_action(Sweeper::HOOK);
        self::assertSame('1', $this->field($id, 'comment_approved'));
        self::assertSame('1', $this->field($id, Meta::AUTO_PUBLISHED));
        WpStub::$events = [];
        return $id;
    }

    public function testALateBlockedVerdictHoldsTheComment(): void
    {
        // Held, not spam: the decision for a late `zablokowane`, whatever the setting.
        $id = $this->autoPublished('Komentarz, który okaże się zablokowany.', ['blocked_mode' => Settings::BLOCKED_SPAM]);

        self::assertSame(200, $this->deliver(self::verdict($id, 'zablokowane', ['kategorie' => ['nekanie']])));

        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertSame('zablokowane', $this->field($id, Meta::STATUS));
        self::assertSame('nekanie', $this->field($id, Meta::CATEGORIES));
        self::assertNull($this->field($id, Meta::AUTO_PUBLISHED));
        self::assertSame([[$id]], array_column(self::events(Outcome::HOOK_NOTIFY), 'args'), 'the moderator is told');

        $changes = WpStub::$statusChanges;
        $this->deliver(self::verdict($id, 'bezpieczne'));
        self::assertSame($changes, WpStub::$statusChanges, 'a repeat or a second verdict changes nothing');
    }

    public function testALateCensoredVerdictFollowsTheSetting(): void
    {
        $masked = $this->autoPublished('no to jest głupi pomysł', ['censored_mode' => Settings::CENSORED_PUBLISH]);
        $this->deliver(self::verdict($masked, 'ocenzurowane', ['ocenzurowany' => 'no to jest █████ pomysł']));
        self::assertSame('1', $this->field($masked, 'comment_approved'));
        self::assertSame('no to jest █████ pomysł', $this->field($masked, 'comment_content'));
        self::assertSame('no to jest głupi pomysł', $this->field($masked, Meta::ORIGINAL));

        $held = $this->autoPublished('no to jest głupi pomysł', ['censored_mode' => Settings::HOLD]);
        $this->deliver(self::verdict($held, 'ocenzurowane', ['ocenzurowany' => 'no to jest █████ pomysł']));
        self::assertSame('0', $this->field($held, 'comment_approved'));
        self::assertSame('no to jest głupi pomysł', $this->field($held, 'comment_content'));

        $refused = $this->autoPublished('no to jest głupi pomysł', ['censored_mode' => Settings::CENSORED_PUBLISH]);
        $tooLong = str_repeat("'", 11000) . '█';
        $this->deliver(self::verdict($refused, 'ocenzurowane', ['ocenzurowany' => $tooLong]));
        self::assertSame('0', $this->field($refused, 'comment_approved'), 'a masked text that does not fit unpublishes');
        self::assertSame('no to jest głupi pomysł', $this->field($refused, 'comment_content'));
        self::assertSame($tooLong, $this->field($refused, Meta::MASKED), 'kept for the moderator');

        $short = $this->autoPublished('no to jest głupi pomysł', ['censored_mode' => Settings::CENSORED_PUBLISH]);
        $this->deliver(self::verdict($short, 'ocenzurowane', ['ocenzurowany' => 'no to jest ████ pomysł']));
        self::assertSame('0', $this->field($short, 'comment_approved'), 'a mask one character short unpublishes');
        self::assertSame('no to jest ████ pomysł', $this->field($short, Meta::MASKED));
    }

    /**
     * Shared rule A7: a fail-open publication with the support cue is never taken off the
     * page by the plugin. A late verdict that would hold it leaves it published until a
     * person decides; the verdict, the cue and an unpublished mask are recorded for the
     * moderator.
     */
    public function testALateVerdictWithTheSupportCueLeavesThePublicationInPlace(): void
    {
        $blocked = $this->autoPublished('Komentarz testowy z sygnałem wsparcia, zablokowany później.',
            ['blocked_mode' => Settings::BLOCKED_SPAM]);
        $changes = WpStub::$statusChanges;

        self::assertSame(200, $this->deliver(self::verdict($blocked, 'zablokowane',
            ['kategorie' => ['samookaleczenie'], 'wsparcie' => true])));

        self::assertSame(['1', 'zablokowane', '1'], [$this->field($blocked, 'comment_approved'),
            $this->field($blocked, Meta::STATUS), $this->field($blocked, Meta::SUPPORT)]);
        self::assertSame($changes, WpStub::$statusChanges, 'neither held nor marked as spam');
        self::assertNull($this->field($blocked, Meta::AUTO_PUBLISHED), 'settled: a repeat changes nothing');
        self::assertSame([], self::events(Outcome::HOOK_NOTIFY));

        $censored = $this->autoPublished('no to jest głupi pomysł', ['censored_mode' => Settings::HOLD]);
        $changes = WpStub::$statusChanges;
        $this->deliver(self::verdict($censored, 'ocenzurowane',
            ['ocenzurowany' => 'no to jest █████ pomysł', 'wsparcie' => true]));
        self::assertSame(['1', 'no to jest głupi pomysł', 'no to jest █████ pomysł'], [$this->field($censored, 'comment_approved'),
            $this->field($censored, 'comment_content'), $this->field($censored, Meta::MASKED)]);
        self::assertSame($changes, WpStub::$statusChanges);
    }

    public function testALateSafeOrUnassessedVerdictLeavesItPublished(): void
    {
        $safe = $this->autoPublished('Komentarz, który okaże się bezpieczny.');
        $this->deliver(self::verdict($safe, 'bezpieczne'));
        self::assertSame('1', $this->field($safe, 'comment_approved'));
        self::assertSame('bezpieczne', $this->field($safe, Meta::STATUS));
        self::assertNull($this->field($safe, Meta::AUTO_PUBLISHED));

        $unassessed = $this->autoPublished('Komentarz, którego brama nie oceni.');
        $this->deliver(['id' => 'wp:' . $unassessed, 'status' => 'nieocenione']);
        self::assertSame('1', $this->field($unassessed, 'comment_approved'));
        self::assertSame(Meta::UNASSESSED, $this->field($unassessed, Meta::STATUS));
    }

    public function testAPersonsDecisionAfterThePublicationStands(): void
    {
        $unapproved = $this->autoPublished('Moderator cofnął publikację i zatwierdził ponownie.');
        wp_set_comment_status($unapproved, 'hold');
        wp_set_comment_status($unapproved, 'approve');
        $trashed = $this->autoPublished('Moderator przeniósł do kosza.');
        wp_set_comment_status($trashed, 'trash');
        $changes = WpStub::$statusChanges;

        $this->deliver(self::verdict($unapproved, 'zablokowane'));
        $this->deliver(self::verdict($trashed, 'zablokowane'));

        self::assertSame($changes, WpStub::$statusChanges);
        self::assertSame('1', $this->field($unapproved, 'comment_approved'));
        self::assertSame('trash', $this->field($trashed, 'comment_approved'));
        // An edit is not a decision on a comment that was never assessed: RevisionTest.
    }

    public function testAFailClosedTimeoutIsNotReopenedByALateVerdict(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_CLOSED]);
        $id = $this->post('Werdykt nie nadszedł przy fail-closed.');
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;
        do_action(Sweeper::HOOK);

        $this->deliver(self::verdict($id, 'bezpieczne'));

        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertNull($this->field($id, Meta::AUTO_PUBLISHED));
    }

    /**
     * Posts a comment and lets the timeout give it the failure mode under fail-closed:
     * held, settled as `nieocenione`.
     *
     * @param string              $text     The comment.
     * @param array<string,mixed> $settings Settings over fail-closed.
     * @return int The comment id.
     */
    private function timedOutHeld(string $text, array $settings = []): int
    {
        $this->configure($settings + ['failure_mode' => Settings::FAIL_CLOSED]);
        $id = $this->post($text);
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;
        do_action(Sweeper::HOOK);
        self::assertSame(['0', Meta::UNASSESSED], [$this->field($id, 'comment_approved'), $this->field($id, Meta::STATUS)]);
        WpStub::$events = [];
        return $id;
    }

    /**
     * Shared rule A7, decided by the owner (2026-11-01): a late verdict with `wsparcie` for a
     * comment a moderator already approved is still a signal for the moderators, with the
     * note that it came after the decision; the comment's status and everything else of
     * the verdict stay as they were. A repeated delivery adds nothing; a late verdict
     * without `wsparcie` signals nothing.
     */
    public function testALateSupportCueAfterAModeratorsApprovalIsASignalAndNothingElse(): void
    {
        $cue = ['kategorie' => ['samookaleczenie'], 'wsparcie' => true];
        $support = $this->timedOutHeld('Komentarz testowy zatwierdzony przed werdyktem.', ['blocked_mode' => Settings::BLOCKED_SPAM]);
        $plain = $this->post('Drugi komentarz testowy zatwierdzony przed werdyktem.');
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;
        do_action(Sweeper::HOOK);
        WpStub::$events = [];
        self::assertTrue(wp_set_comment_status($support, 'approve'));
        self::assertTrue(wp_set_comment_status($plain, 'approve'));
        $changes = WpStub::$statusChanges;

        self::assertSame(200, $this->deliver(self::verdict($support, 'zablokowane', $cue)));
        self::assertSame(200, $this->deliver(self::verdict($plain, 'zablokowane', ['kategorie' => ['nekanie']])));

        self::assertSame($changes, WpStub::$statusChanges, 'neither held nor marked as spam: the approval stands');
        self::assertSame(['1', Meta::UNASSESSED, null], [$this->field($support, 'comment_approved'),
            $this->field($support, Meta::STATUS), $this->field($support, Meta::CATEGORIES)], 'the rest of the verdict is not applied');
        self::assertSame(['1', '1'], [$this->field($support, Meta::SUPPORT), $this->field($support, Meta::SUPPORT_AFTER_DECISION)]);
        $column = $this->column($support);
        self::assertStringContainsString(CommentsScreen::supportAdvice(), $column);
        self::assertStringContainsString(CommentsScreen::supportAfterDecisionNote(), $column);
        self::assertSame(1, $this->plugin->wp->supportFlagsSince($this->now - CommentsScreen::SUPPORT_WINDOW_S),
            'one comment in the comments screen\'s notice');
        self::assertSame([], self::events(Outcome::HOOK_NOTIFY), 'WordPress mails the moderators only about a held comment');

        self::assertSame([null, null], [$this->field($plain, Meta::SUPPORT), $this->field($plain, Meta::SUPPORT_AFTER_DECISION)],
            'without wsparcie no signal');
        self::assertStringNotContainsString(CommentsScreen::supportAdvice(), $this->column($plain));

        // The gateway delivers again, later in its window.
        $this->now += 11 * 60;
        self::assertSame(200, $this->deliver(self::verdict($support, 'zablokowane', $cue)));
        self::assertSame($changes, WpStub::$statusChanges);
        self::assertSame(1, $this->plugin->wp->supportFlagsSince($this->now - CommentsScreen::SUPPORT_WINDOW_S));
        self::assertSame([], self::events(Outcome::HOOK_NOTIFY));
    }

    /**
     * Shared rule A7 for a comment the failure mode held after the timeout, nobody touching
     * it: the late cue is a signal, and as the comment is held the moderator's e-mail goes
     * out, once. The comment stays held, its status `nieocenione`.
     */
    public function testALateSupportCueForAHeldCommentMailsTheModeratorOnce(): void
    {
        $id = $this->timedOutHeld('Komentarz testowy wstrzymany po czasie oczekiwania.');
        $changes = WpStub::$statusChanges;

        self::assertSame(200, $this->deliver(self::verdict($id, 'bezpieczne', ['wsparcie' => true])));

        self::assertSame(['0', Meta::UNASSESSED, '1', '1'], [$this->field($id, 'comment_approved'), $this->field($id, Meta::STATUS),
            $this->field($id, Meta::SUPPORT), $this->field($id, Meta::SUPPORT_AFTER_DECISION)], 'a late safe verdict publishes nothing');
        self::assertSame($changes, WpStub::$statusChanges);
        self::assertSame([[$id]], array_column(self::events(Outcome::HOOK_NOTIFY), 'args'), 'the moderator is told');

        // WP-Cron ran the e-mail; the gateway delivers the same verdict again.
        WpStub::$events = [];
        $this->now += 11 * 60;
        self::assertSame(200, $this->deliver(self::verdict($id, 'bezpieczne', ['wsparcie' => true])));
        self::assertSame([], self::events(Outcome::HOOK_NOTIFY), 'one signal, not two');
    }

    /**
     * Shared rule A7 after the other decisions a person makes: a moderator who trashed a
     * fail-open publication, and one who approved a comment while it still waited for its
     * verdict. Each keeps its status and gets the cue with the note.
     */
    public function testALateSupportCueAfterOtherDecisionsIsASignal(): void
    {
        $cue = ['kategorie' => ['samookaleczenie'], 'wsparcie' => true];
        $trashed = $this->autoPublished('Komentarz testowy przeniesiony do kosza.', ['blocked_mode' => Settings::BLOCKED_SPAM]);
        self::assertTrue(wp_set_comment_status($trashed, 'trash'));
        $this->configure(['failure_mode' => Settings::FAIL_CLOSED]);
        $approved = $this->post('Komentarz testowy zatwierdzony, gdy czekał na werdykt.');
        self::assertTrue(wp_set_comment_status($approved, 'approve'));
        $changes = WpStub::$statusChanges;

        self::assertSame(200, $this->deliver(self::verdict($trashed, 'zablokowane', $cue)));
        self::assertSame(200, $this->deliver(self::verdict($approved, 'zablokowane', $cue)));

        self::assertSame($changes, WpStub::$statusChanges);
        self::assertSame(['trash', '1'], [$this->field($trashed, 'comment_approved'), $this->field($approved, 'comment_approved')]);
        foreach ([$trashed, $approved] as $id) {
            self::assertSame(['1', '1'], [$this->field($id, Meta::SUPPORT), $this->field($id, Meta::SUPPORT_AFTER_DECISION)]);
        }
        self::assertSame('zablokowane', $this->field($approved, Meta::STATUS), 'in time: the verdict is recorded, as before');
        self::assertSame([], self::events(Outcome::HOOK_NOTIFY));
    }

    /**
     * Shared rule A7 for a superseded text: the author edited the comment while it waited,
     * so the verdict on the first text applies nothing, but its cue is recorded. The next
     * revision's verdict then treats the comment as one with the cue: `zablokowane` holds
     * it under "mark as spam" too.
     */
    public function testTheSupportCueOfASupersededTextIsRecordedAndKeptForTheNextVerdict(): void
    {
        $this->configure(['blocked_mode' => Settings::BLOCKED_SPAM]);
        $id = $this->post('Pierwsza wersja komentarza testowego.');
        wp_update_comment(['comment_ID' => $id, 'comment_content' => 'Druga wersja komentarza testowego.']);
        self::assertSame(['1', Meta::PENDING], [$this->field($id, Meta::REVISION), $this->field($id, Meta::STATUS)]);
        $changes = WpStub::$statusChanges;
        WpStub::$events = [];

        self::assertSame(200, $this->deliver(self::verdict($id, 'zablokowane', ['wsparcie' => true])));

        self::assertSame($changes, WpStub::$statusChanges);
        self::assertSame([Meta::PENDING, '1', '1'], [$this->field($id, Meta::STATUS), $this->field($id, Meta::SUPPORT),
            $this->field($id, Meta::SUPPORT_AFTER_DECISION)]);
        self::assertSame([], self::events(Outcome::HOOK_NOTIFY), 'the e-mail follows the next verdict');

        $next = self::verdict($id, 'zablokowane');
        $next['id'] = Submission::itemId($id, 1);
        self::assertSame(200, $this->deliver($next));
        self::assertSame(['0', 'zablokowane'], [$this->field($id, 'comment_approved'), $this->field($id, Meta::STATUS)],
            'held for a person, never marked as spam');
        self::assertSame([[$id]], array_column(self::events(Outcome::HOOK_NOTIFY), 'args'));
    }

    /**
     * The same for a text changed without `edit_comment` (another plugin's direct write):
     * the receiver sends the next revision instead of applying the verdict, and records
     * its cue. A comment a person approved meanwhile keeps its status too.
     */
    public function testTheSupportCueOfATextChangedOnTheQuietIsRecorded(): void
    {
        $this->configure();
        $waiting = $this->post('Komentarz testowy podmieniony przez inną wtyczkę.');
        $approved = $this->post('Komentarz testowy podmieniony i zatwierdzony.');
        WpStub::$comments[$waiting]['comment_content'] = 'Podmieniona treść testowa.';
        WpStub::$comments[$approved]['comment_content'] = 'Druga podmieniona treść testowa.';
        self::assertTrue(wp_set_comment_status($approved, 'approve'));
        $changes = WpStub::$statusChanges;

        self::assertSame(200, $this->deliver(self::verdict($waiting, 'zablokowane', ['wsparcie' => true])));
        self::assertSame(200, $this->deliver(self::verdict($approved, 'zablokowane', ['wsparcie' => true])));

        self::assertSame($changes, WpStub::$statusChanges);
        self::assertSame([Meta::PENDING, '1'], [$this->field($waiting, Meta::STATUS), $this->field($waiting, Meta::REVISION)],
            'the next revision waits');
        self::assertSame([Meta::UNASSESSED, '1'], [$this->field($approved, Meta::STATUS), $this->field($approved, 'comment_approved')]);
        foreach ([$waiting, $approved] as $id) {
            self::assertSame(['1', '1'], [$this->field($id, Meta::SUPPORT), $this->field($id, Meta::SUPPORT_AFTER_DECISION)]);
        }
    }

    /**
     * A verdict for a revision the comment never reached, and one for a comment the plugin
     * never sent, record nothing.
     */
    public function testALateSupportCueForNoRevisionOrNoCommentRecordsNothing(): void
    {
        $id = $this->timedOutHeld('Komentarz testowy bez kolejnych wersji.');
        $ahead = self::verdict($id, 'zablokowane', ['wsparcie' => true]);
        $ahead['id'] = Submission::itemId($id, 3);
        self::assertSame(200, $this->deliver($ahead));
        self::assertSame(200, $this->deliver(self::verdict($id + 1000, 'zablokowane', ['wsparcie' => true])));

        self::assertSame([null, null], [$this->field($id, Meta::SUPPORT), $this->field($id, Meta::SUPPORT_AFTER_DECISION)]);
        self::assertSame([], WpStub::$meta[$id + 1000] ?? [], 'nothing about a comment that does not exist');
        self::assertSame([], self::events(Outcome::HOOK_NOTIFY));
    }

    /**
     * What the "Minos" column shows for a comment.
     *
     * @param int $id The comment.
     * @return string The column's HTML.
     */
    private function column(int $id): string
    {
        ob_start();
        (new CommentsScreen($this->plugin->wp, $this->plugin->settings, $this->plugin->log))
            ->renderColumn(CommentsScreen::COLUMN, $id);
        return (string)ob_get_clean();
    }
}
