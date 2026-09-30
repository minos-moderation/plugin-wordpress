<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Meta;
use Minos\WordPress\Plugin;
use Minos\WordPress\Settings;
use Minos\WordPress\Submission;
use Minos\WordPress\Sweeper;
use Minos\WordPress\Uninstaller;
use WpStub;

/**
 * Removal: `uninstall.php` deletes every option, every `_minos_*` meta and every scheduled
 * event, and leaves the comments and everyone else's data alone. Deactivation only stops
 * the scheduled work.
 */
final class UninstallTest extends PluginTestCase
{
    public function testUninstallRemovesEverythingThePluginStored(): void
    {
        $this->configure();
        do_action('init');
        WpStub::answer(401, ['blad' => ['kod' => 'brak_klucza']]);
        $refused = $this->post('Komentarz odrzucony.');
        WpStub::answer(429, ['blad' => ['kod' => 'kolejka_pelna', 'ponow_za_s' => 60]]);
        $retrying = $this->post('Komentarz czekający na ponowienie.');
        $censored = $this->post('brzydki komentarz');
        $this->deliver(self::verdict($censored, 'ocenzurowane', ['ocenzurowany' => '████████ komentarz',
            'kategorie' => ['wulgaryzmy'], 'wsparcie' => true]));
        WpStub::$options['inna_wtyczka'] = 'zostaje';
        WpStub::$meta[$censored]['_inna_wtyczka'] = 'zostaje';
        WpStub::$events[] = ['at' => $this->now, 'hook' => 'inna_wtyczka_cron', 'args' => [], 'recurrence' => null];
        self::assertNotEmpty(self::events(Sweeper::HOOK));
        self::assertNotEmpty(self::events(Submission::HOOK_RETRY));

        $this->uninstall();

        self::assertSame(['inna_wtyczka' => 'zostaje'], WpStub::$options);
        self::assertSame([$censored => ['_inna_wtyczka' => 'zostaje']], array_filter(WpStub::$meta));
        self::assertSame(['inna_wtyczka_cron'], array_column(WpStub::$events, 'hook'));
        self::assertSame('████████ komentarz', $this->field($censored, 'comment_content'), 'a masked comment stays masked');
        self::assertSame('0', $this->field($retrying, 'comment_approved'), 'a waiting comment stays held');
        self::assertSame('0', $this->field($refused, 'comment_approved'));
    }

    public function testUninstallRunsOnlyWhenWordPressUninstalls(): void
    {
        $this->configure();
        $this->assertFileExists(self::uninstallFile());

        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::uninstallFile()) . ' 2>&1', $output, $exit);

        self::assertSame(0, $exit, implode("\n", $output));
        self::assertSame([], $output, 'without WP_UNINSTALL_PLUGIN the file exits before doing anything');
    }

    public function testEveryOptionThePluginDeclaresIsOnTheUninstallList(): void
    {
        $options = self::literalsIn("/const [A-Z_]*OPTION = '([a-z_]+)'/");
        self::assertGreaterThanOrEqual(5, count($options), 'the scan must find the options: it is broken');
        foreach ($options as $option) {
            self::assertContains($option, Uninstaller::OPTIONS, $option);
        }
    }

    public function testEveryMetaKeyInTheCodeIsOnTheUninstallList(): void
    {
        $keys = self::literalsIn('/[\'"](_minos_[a-z_]+)[\'"]/');
        self::assertGreaterThanOrEqual(10, count($keys), 'the scan must find the meta keys: it is broken');
        foreach ($keys as $key) {
            self::assertContains($key, Meta::ALL, $key);
        }
        self::assertSame(count(Meta::ALL), count(array_unique(Meta::ALL)));
    }

    public function testDeactivationStopsTheScheduledWorkAndLeavesTheData(): void
    {
        $this->configure();
        do_action('init');
        WpStub::networkFailure();
        $id = $this->post('Komentarz przed dezaktywacją.');

        call_user_func(WpStub::$lifecycle['deactivate']);

        foreach (Plugin::cronHooks() as $hook) {
            self::assertSame([], self::events($hook), $hook);
        }
        self::assertTrue($this->pending($id));
        self::assertSame(self::KEY, WpStub::$options[Settings::KEY_OPTION]);
    }

    /**
     * Runs the uninstaller the way `uninstall.php` does.
     */
    private function uninstall(): void
    {
        Uninstaller::run($this->plugin->wp);
    }

    private static function uninstallFile(): string
    {
        return __DIR__ . '/../../uninstall.php';
    }

    /**
     * The distinct first groups of a pattern over every PHP file in `src/`.
     *
     * @return array<int,string>
     */
    private static function literalsIn(string $pattern): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src',
            \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                preg_match_all($pattern, (string)file_get_contents($file->getPathname()), $matches);
                foreach ($matches[1] as $literal) {
                    $found[$literal] = true;
                }
            }
        }
        return array_keys($found);
    }
}
