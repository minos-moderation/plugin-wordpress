<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Meta;
use Minos\WordPress\Plugin;
use Minos\WordPress\Retention;
use Minos\WordPress\Settings;
use Minos\WordPress\Upgrade;
use WpStub;

/**
 * Shared rule D9.11: what the plugin keeps about a settled comment — the original of a
 * masked one, the masked text a moderator was shown, the verdict and its categories — goes
 * 30 days after the comment was settled. The comment itself stays. Invented comments only.
 */
final class RetentionTest extends PluginTestCase
{
    /**
     * Every `_minos_*` key a comment still has.
     *
     * @param int $id The comment.
     * @return array<int,string> The keys.
     */
    private function minosKeys(int $id): array
    {
        return array_values(array_filter(array_keys(WpStub::$meta[$id] ?? []), static function (string $key): bool {
            return strpos($key, '_minos_') === 0;
        }));
    }

    /**
     * Three settled comments that keep comment text or a verdict in the plugin's meta.
     *
     * @return array{masked:int,held:int,support:int}
     */
    private function settledComments(): array
    {
        $this->configure(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $masked = $this->post('no to jest głupi pomysł');
        $this->deliver(self::verdict($masked, 'ocenzurowane', ['ocenzurowany' => 'no to jest █████ pomysł']));
        $held = $this->post('<em>głupi</em> pomysł');
        $this->deliver(self::verdict($held, 'ocenzurowane', ['ocenzurowany' => '█████ pomysł']));
        $support = $this->post('Komentarz testowy z sygnałem wsparcia.');
        $this->deliver(self::verdict($support, 'bezpieczne', ['kategorie' => ['samookaleczenie'], 'wsparcie' => true]));
        self::assertSame('no to jest głupi pomysł', $this->field($masked, Meta::ORIGINAL));
        self::assertSame('█████ pomysł', $this->field($held, Meta::MASKED));
        self::assertSame('samookaleczenie', $this->field($support, Meta::CATEGORIES));
        return ['masked' => $masked, 'held' => $held, 'support' => $support];
    }

    public function testTheDataOfASettledCommentGoesThirtyDaysAfterItWasSettled(): void
    {
        $ids = $this->settledComments();
        $settledAt = $this->now;
        foreach ($ids as $id) {
            self::assertSame((string)$settledAt, $this->field($id, Meta::DECIDED_AT));
        }

        $this->now = $settledAt + Retention::DAYS * 86400 - 1;
        do_action(Retention::HOOK);
        foreach ($ids as $name => $id) {
            self::assertContains(Meta::STATUS, $this->minosKeys($id), "{$name}: kept for 30 days");
        }

        $this->now = $settledAt + Retention::DAYS * 86400 + 1;
        do_action(Retention::HOOK);
        foreach ($ids as $name => $id) {
            self::assertSame([], $this->minosKeys($id), "{$name}: nothing of the plugin's is left");
        }
        self::assertSame('no to jest █████ pomysł', $this->field($ids['masked'], 'comment_content'), 'the comment stays');
        self::assertSame('1', $this->field($ids['masked'], 'comment_approved'));
        self::assertSame('<em>głupi</em> pomysł', $this->field($ids['held'], 'comment_content'));
    }

    public function testACommentStillWaitingIsNeverCleaned(): void
    {
        $this->configure();
        $waiting = $this->post('Komentarz czekający, gdy wtyczka była wyłączona.');
        $this->configure(['enabled' => false]);
        $this->now += 90 * 86400;

        do_action(Retention::HOOK);

        self::assertTrue($this->pending($waiting));
        self::assertSame('1', $this->field($waiting, Meta::WAITING));
    }

    public function testItRunsWhileModerationIsOffAndAtMostABatchARun(): void
    {
        $this->configure();
        $ids = [];
        for ($i = 0; $i < Retention::BATCH + 5; $i++) {
            $ids[] = $id = $this->post('Komentarz numer ' . $i . '.');
            $this->deliver(self::verdict($id, 'bezpieczne'));
        }
        $this->configure(['enabled' => false]);
        $this->now += 31 * 86400;

        do_action(Retention::HOOK);
        $cleaned = count(array_filter($ids, function (int $id): bool {
            return $this->minosKeys($id) === [];
        }));
        self::assertSame(Retention::BATCH, $cleaned);

        do_action(Retention::HOOK);
        foreach ($ids as $id) {
            self::assertSame([], $this->minosKeys($id));
        }
    }

    public function testItIsScheduledHourlyAndStopsWithThePlugin(): void
    {
        do_action('init');
        $runs = self::events(Retention::HOOK);
        self::assertCount(1, $runs);
        self::assertSame('hourly', $runs[0]['recurrence']);
        self::assertContains(Retention::HOOK, Plugin::cronHooks(), 'deactivation and uninstall remove it');
    }

    public function testAnUpdateDatesTheCommentsSettledBeforeItFromWhenTheyWereHeld(): void
    {
        $ids = $this->settledComments();
        $heldAt = $this->now;
        foreach ($ids as $id) {
            unset(WpStub::$meta[$id][Meta::DECIDED_AT]);
        }
        $waiting = $this->post('Komentarz czekający w chwili aktualizacji.');
        WpStub::$options[Upgrade::OPTION] = 2;
        $this->now += 86400;

        do_action('init');

        foreach ($ids as $id) {
            self::assertSame((string)$heldAt, $this->field($id, Meta::DECIDED_AT));
        }
        self::assertNull($this->field($waiting, Meta::DECIDED_AT), 'a waiting comment is not settled');
        $this->now = $heldAt + Retention::DAYS * 86400 + 1;
        do_action(Retention::HOOK);
        self::assertSame([], $this->minosKeys($ids['masked']));
    }

    public function testANewRevisionIsNotSettledUntilItsVerdict(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_CLOSED]);
        $id = $this->post('Komentarz bez werdyktu w czasie.');
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;
        do_action('minos_moderation_sweep');
        self::assertNotNull($this->field($id, Meta::DECIDED_AT));

        wp_update_comment(['comment_ID' => $id, 'comment_content' => 'Komentarz poprawiony po czasie.']);

        self::assertNull($this->field($id, Meta::DECIDED_AT));
        $this->now += 40 * 86400;
        do_action(Retention::HOOK);
        self::assertTrue($this->pending($id));
    }
}
