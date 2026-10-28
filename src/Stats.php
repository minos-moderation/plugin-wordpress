<?php

declare(strict_types=1);

namespace Minos\WordPress;

defined('ABSPATH') || exit;

/**
 * The last seven days in numbers, for the administrator: how many comments got each
 * outcome, and how long the gateway took from accepting a comment (`202`) to its verdict
 * (the median and the 95th percentile).
 *
 * Computed from what the plugin already keeps about each comment ({@see Meta::STATUS},
 * {@see Meta::SUBMITTED_AT}, {@see Meta::DECIDED_AT}, {@see Meta::TIMED_OUT}) when the
 * settings page is shown: nothing is stored for it, no text is read, and the gateway is
 * never asked. The time is measured for the comments the gateway assessed (`bezpieczne`,
 * `ocenzurowane`, `zablokowane`), a late verdict after a fail-open publication included;
 * `nieocenione` is counted, with how many of those the receive timeout ended.
 */
final class Stats
{
    /** The window, in seconds. */
    public const WINDOW_S = 7 * 86400;

    /** The most settled comments one computation reads, the newest first. */
    public const LIMIT = 2000;

    /** The outcomes counted, in the order they are shown. */
    public const OUTCOMES = ['bezpieczne', 'ocenzurowane', 'zablokowane', Meta::UNASSESSED];

    /** @var Platform */
    private $wp;

    /**
     * @param Platform $wp The WordPress adapter.
     */
    public function __construct(Platform $wp)
    {
        $this->wp = $wp;
    }

    /**
     * The numbers of the last {@see WINDOW_S}.
     *
     * @return array{counts:array<string,int>,timed_out:int,measured:int,p50:int|null,p95:int|null,limited:bool}
     *     Comments per outcome; how many `nieocenione` the receive timeout ended; how many
     *     verdicts were timed, and their median and 95th percentile in seconds (null when
     *     none); whether {@see LIMIT} cut the reading short.
     */
    public function lastWeek(): array
    {
        $rows = $this->wp->settledSince($this->wp->now() - self::WINDOW_S, self::LIMIT);
        $counts = array_fill_keys(self::OUTCOMES, 0);
        $timedOut = 0;
        $times = [];
        foreach ($rows as $row) {
            if (!isset($counts[$row['status']])) {
                continue;
            }
            $counts[$row['status']]++;
            if ($row['status'] === Meta::UNASSESSED) {
                $timedOut += $row['timed_out'] ? 1 : 0;
                continue;
            }
            if ($row['submitted_at'] > 0 && $row['decided_at'] >= $row['submitted_at']) {
                $times[] = $row['decided_at'] - $row['submitted_at'];
            }
        }
        sort($times);
        return [
            'counts'    => $counts,
            'timed_out' => $timedOut,
            'measured'  => count($times),
            'p50'       => self::percentile($times, 50),
            'p95'       => self::percentile($times, 95),
            'limited'   => count($rows) >= self::LIMIT,
        ];
    }

    /**
     * A percentile by the nearest rank: the smallest value at or below which at least
     * that share of the values lies.
     *
     * @param array<int,int> $sorted Values, ascending.
     * @param int            $p      1-100.
     * @return int|null The value, or null when there is none.
     */
    public static function percentile(array $sorted, int $p): ?int
    {
        if ($sorted === []) {
            return null;
        }
        return $sorted[max(0, (int)ceil($p / 100 * count($sorted)) - 1)];
    }
}
