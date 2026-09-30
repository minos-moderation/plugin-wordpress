<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Meta;
use Minos\WordPress\Outcome;
use Minos\WordPress\Settings;
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
        $this->deliver(self::verdict($refused, 'ocenzurowane', ['ocenzurowany' => str_repeat("'", 11000)]));
        self::assertSame('0', $this->field($refused, 'comment_approved'), 'a masked text that cannot be stored unpublishes');
        self::assertSame('no to jest głupi pomysł', $this->field($refused, 'comment_content'));
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
        $edited = $this->autoPublished('Moderator poprawił brzydki komentarz.');
        wp_update_comment(['comment_ID' => $edited, 'comment_content' => 'Moderator poprawił komentarz.']);
        $changes = WpStub::$statusChanges;

        $this->deliver(self::verdict($unapproved, 'zablokowane'));
        $this->deliver(self::verdict($trashed, 'zablokowane'));
        $this->deliver(self::verdict($edited, 'ocenzurowane', ['ocenzurowany' => 'Moderator poprawił ███████ komentarz.']));

        self::assertSame($changes, WpStub::$statusChanges);
        self::assertSame('1', $this->field($unapproved, 'comment_approved'));
        self::assertSame('trash', $this->field($trashed, 'comment_approved'));
        self::assertSame(['1', 'Moderator poprawił komentarz.'],
            [$this->field($edited, 'comment_approved'), $this->field($edited, 'comment_content')]);
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
}
