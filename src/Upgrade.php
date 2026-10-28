<?php

declare(strict_types=1);

namespace Minos\WordPress;

defined('ABSPATH') || exit;

/**
 * Brings what an earlier version of the plugin stored up to what this one reads, once per
 * site: on every `init` it reads one autoloaded option and stops there once done.
 *
 * - 2: comments already waiting get {@see Meta::WAITING}, the key the sweep now finds them
 *   by (it used to query `_minos_status` by its value).
 */
final class Upgrade
{
    /** The option with the version of the stored data (autoloaded: read on every request). */
    public const OPTION = 'minos_moderation_schema';

    /** The version this code reads. */
    public const VERSION = 2;

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
     * Runs every step the site has not run yet.
     *
     * @return void
     */
    public function run(): void
    {
        $from = (int)$this->wp->option(self::OPTION, 1);
        if ($from >= self::VERSION) {
            return;
        }
        if ($from < 2) {
            foreach ($this->wp->commentIdsWithMeta(Meta::STATUS, Meta::PENDING) as $id) {
                $this->wp->setMeta($id, Meta::WAITING, '1');
            }
        }
        $this->wp->saveOption(self::OPTION, self::VERSION, true);
    }
}
