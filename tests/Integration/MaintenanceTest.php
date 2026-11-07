<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Integration;

use Minos\Client\Signature;

/**
 * Shared rule F10: a page the platform serves in place of the webhook's answer must be a
 * non-`2xx`, or the gateway counts the verdict as delivered and drops it.
 *
 * WordPress's maintenance mode (a recent `.maintenance` file while core, a plugin or a theme
 * updates) answers every request before any plugin loads: `wp-settings.php` calls
 * `wp_maintenance()`, so the receiver never runs. The test runs in one PHP process with
 * WordPress loaded, so it cannot send a request through `wp-settings.php`; it proves the
 * two halves instead: in the `wp-settings.php` of the WordPress under test that call comes
 * before the must-use and the active plugins are loaded, and `wp_maintenance()` itself,
 * asked as a gateway delivery asks (a JSON `POST` to the route), answers `503`. A site's own
 * maintenance page (`wp-content/maintenance.php`) answers with whatever code it sends, so
 * the README tells the administrator it must answer `503`.
 */
final class MaintenanceTest extends IntegrationTestCase
{
    public function testMaintenanceModeIsDecidedBeforeAnyPluginLoads(): void
    {
        $settings = (string)file_get_contents(ABSPATH . 'wp-settings.php');
        $maintenance = strpos($settings, "\nwp_maintenance();");
        $mustUse = strpos($settings, 'foreach ( wp_get_mu_plugins() as');
        $plugins = strpos($settings, 'foreach ( wp_get_active_and_valid_plugins() as');
        self::assertIsInt($maintenance, 'the call must be found, or the order proves nothing');
        self::assertIsInt($mustUse, 'the must-use plugins\' loop must be found');
        self::assertIsInt($plugins, 'the plugins\' loop must be found');
        self::assertLessThan($mustUse, $maintenance);
        self::assertLessThan($plugins, $maintenance);
    }

    public function testMaintenanceModeAnswersAGatewayDelivery503(): void
    {
        $flag = ABSPATH . '.maintenance';
        self::assertFileDoesNotExist($flag, 'the test owns the maintenance flag');
        self::assertFileDoesNotExist(WP_CONTENT_DIR . '/maintenance.php', 'core\'s page, not a drop-in');
        file_put_contents($flag, '<?php $upgrading = ' . time() . ';');

        $body = (string)json_encode(self::verdict(1, 'bezpieczne'));
        $server = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/wp-json/minos/v1/webhook';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER[self::serverName(Signature::HEADER)] = Signature::sign(self::SECRET, $body, time());

        // A JSON request dies through the JSON handler: captured here, as the test case
        // captures the default one, so the code core gives `wp_die()` is the answer read.
        $handlers = [];
        $capture = function () use (&$handlers) {
            return function ($message, $title, $args) use (&$handlers): void {
                $handlers[] = 'json';
                $this->wp_die_handler($message, $title, $args);
            };
        };
        add_filter('wp_die_json_handler', $capture);
        // Core sends `Retry-After` first; the CLI has written output already, so PHP warns
        // that headers were sent. Only that warning is ignored.
        $previous = set_error_handler(static function (int $no, string $text) use (&$previous): bool {
            if ($no === E_WARNING && strpos($text, 'Cannot modify header information') === 0) {
                return true;
            }
            return is_callable($previous) ? (bool)$previous(...func_get_args()) : false;
        });
        try {
            self::assertTrue(wp_is_maintenance_mode(), 'the flag puts WordPress in maintenance mode');
            wp_maintenance();
            self::fail('maintenance mode let the request through');
        } catch (\WPDieException $e) {
            self::assertSame(['json'], $handlers, 'the delivery got the JSON answer, as over HTTP');
            self::assertSame(503, $e->getCode(), 'a non-2xx, which the gateway retries');
        } finally {
            restore_error_handler();
            remove_filter('wp_die_json_handler', $capture);
            $_SERVER = $server;
            unlink($flag);
        }
    }
}
