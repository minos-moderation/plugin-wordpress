<?php

/**
 * Plugin Name:       Minos — moderacja komentarzy
 * Plugin URI:        https://github.com/minos-moderation/plugin-wordpress
 * Description:       Wysyła nowe komentarze do automatycznej oceny w usłudze Minos (brama Wergiliusz) i stosuje odesłany werdykt: publikuje komentarz, publikuje go z zamaskowanymi fragmentami albo zostawia do moderacji.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Minos
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       minos-moderation
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!is_readable(__DIR__ . '/vendor/autoload.php')) {
    // A copy of the repository without `composer install`: install the release zip instead.
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Minos: brak katalogu vendor/. Zainstaluj wtyczkę z paczki zip z wydania albo uruchom composer install.', 'minos-moderation')
            . '</p></div>';
    });
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

\Minos\WordPress\Plugin::boot(__FILE__);
