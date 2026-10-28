<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Admin\SettingsPage;
use Minos\WordPress\Meta;
use Minos\WordPress\Settings;
use Minos\WordPress\Stats;
use Minos\WordPress\Sweeper;
use WpStub;

/**
 * The last seven days on the settings page, from what the plugin already records: comments
 * per outcome, and the median and 95th percentile of the time from the gateway's `202` to
 * the verdict. Comments go through the real hooks, verdicts through the signed webhook.
 * Invented comments only.
 */
final class StatsTest extends PluginTestCase
{
    public function testThePageShowsTheWeeksOutcomesAndTheTimeToTheVerdict(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_CLOSED]);
        // Eight days ago: outside the window.
        $old = $this->post('Komentarz sprzed ośmiu dni.');
        $this->deliver(self::verdict($old, 'zablokowane'));
        $this->now += 8 * 86400;

        // Twenty verdicts, 1 to 20 seconds after the gateway took each comment.
        $verdicts = ['bezpieczne' => 12, 'ocenzurowane' => 3, 'zablokowane' => 5];
        $ids = [];
        foreach ($verdicts as $qualification => $count) {
            for ($i = 0; $i < $count; $i++) {
                $ids[] = [$this->post('Komentarz testowy ' . $qualification . ' ' . $i . '.'), $qualification];
            }
        }
        foreach ($ids as [$id, $qualification]) {
            $this->now += 1;
            $this->deliver(self::verdict($id, $qualification, $qualification === 'ocenzurowane'
                ? ['ocenzurowany' => str_repeat('█', mb_strlen($this->field($id, 'comment_content') ?? '', 'UTF-8'))] : []));
        }
        // One the gateway could not assess, one whose verdict never came, one still waiting.
        $unassessed = $this->post('Komentarz bez oceny.');
        $this->deliver(['status' => 'nieocenione', 'kwalifikacja' => null] + self::verdict($unassessed, 'bezpieczne'));
        $this->post('Komentarz, na który werdykt nie nadejdzie.');
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;
        do_action(Sweeper::HOOK);
        $this->post('Komentarz czekający na werdykt.');

        $requests = count(WpStub::$requests);
        $meta = WpStub::$meta;
        WpStub::$optionWrites = [];
        $html = $this->settingsPage();

        $stats = $this->plugin->stats->lastWeek();
        self::assertSame(['bezpieczne' => 12, 'ocenzurowane' => 3, 'zablokowane' => 5, Meta::UNASSESSED => 2], $stats['counts']);
        self::assertSame(1, $stats['timed_out']);
        self::assertSame([20, 10, 19], [$stats['measured'], $stats['p50'], $stats['p95']], 'nearest rank over 1..20 s');
        foreach (['<td>12</td>', '<td>3</td>', '<td>5</td>', '<td>2</td>', '<td>1</td>', '10 s', '19 s'] as $shown) {
            self::assertStringContainsString($shown, $html);
        }
        foreach (WpStub::$comments as $comment) {
            self::assertStringNotContainsString($comment['comment_content'], $html, 'no comment text on the page');
        }
        self::assertCount($requests, WpStub::$requests, 'the gateway is never asked');
        self::assertSame($meta, WpStub::$meta, 'nothing stored about any comment');
        self::assertSame([], WpStub::$optionWrites, 'nothing stored at all');
    }

    public function testALateVerdictCountsItsWholeWaitAndAWeekWithoutVerdictsShowsNoTime(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_OPEN]);
        self::assertSame([0, null, null], array_values(array_intersect_key($this->plugin->stats->lastWeek(),
            ['measured' => 0, 'p50' => 0, 'p95' => 0])));
        self::assertStringContainsString('brak ocenionych komentarzy', $this->settingsPage());

        $id = $this->post('Komentarz opublikowany przy fail-open.');
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;
        do_action(Sweeper::HOOK);
        $this->now += 5 * 60;
        $this->deliver(self::verdict($id, 'zablokowane'));

        $stats = $this->plugin->stats->lastWeek();
        self::assertSame(['zablokowane', 0], [array_search(1, $stats['counts'], true), $stats['timed_out']]);
        self::assertSame((Settings::DEFAULT_TIMEOUT_MIN + 5) * 60, $stats['p95'], 'from its 202 to the late verdict');
        self::assertStringContainsString('25 min 0 s', $this->settingsPage());
    }

    public function testTheNewestSettledCommentsAreReadInOneQueryByTheirKey(): void
    {
        $this->configure();
        $id = $this->post('Komentarz do statystyki.');
        $this->deliver(self::verdict($id, 'bezpieczne'));
        WpStub::$queries = [];

        $this->plugin->stats->lastWeek();

        self::assertCount(1, WpStub::$queries);
        $query = WpStub::$queries[0];
        self::assertSame([Meta::DECIDED_AT, '>=', 'DESC', Stats::LIMIT, true],
            [$query['meta_key'] ?? null, $query['meta_compare'] ?? null, $query['order'] ?? null,
                $query['number'] ?? null, $query['update_comment_meta_cache'] ?? null]);
        self::assertArrayNotHasKey('fields', $query, 'whole comments, so core primes their meta at once');
    }

    public function testPercentilesByTheNearestRank(): void
    {
        self::assertNull(Stats::percentile([], 50));
        self::assertSame(7, Stats::percentile([7], 95));
        self::assertSame([2, 4], [Stats::percentile([1, 2, 3, 4], 50), Stats::percentile([1, 2, 3, 4], 95)]);
    }

    /**
     * @return string Settings → Minos, as an administrator sees it.
     */
    private function settingsPage(): string
    {
        WpStub::$currentUser = 1;
        WpStub::$userCaps[1] = ['manage_options'];
        ob_start();
        (new SettingsPage($this->plugin->settings, $this->plugin->log, $this->plugin->receiver, $this->plugin->stats))->render();
        return (string)ob_get_clean();
    }
}
