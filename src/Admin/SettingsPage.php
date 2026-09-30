<?php

declare(strict_types=1);

namespace Minos\WordPress\Admin;

use Minos\WordPress\Log;
use Minos\WordPress\Receiver;
use Minos\WordPress\Settings;

defined('ABSPATH') || exit;

/**
 * Ustawienia → Minos: the settings, the webhook URL to register, the state and the log.
 *
 * WordPress glue (Settings API). The key and the secret are password fields that start
 * empty: a page never shows more of them than a prefix, and an empty field keeps what is
 * stored.
 */
final class SettingsPage
{
    /** The page's slug. */
    public const SLUG = 'minos-moderation';

    /** The Settings API group. */
    public const GROUP = 'minos_moderation';

    /** @var Settings */
    private $settings;

    /** @var Log */
    private $log;

    /** @var Receiver */
    private $receiver;

    /**
     * @param Settings $settings The settings.
     * @param Log      $log      The administrator's log.
     * @param Receiver $receiver The webhook, for its URL.
     */
    public function __construct(Settings $settings, Log $log, Receiver $receiver)
    {
        $this->settings = $settings;
        $this->log = $log;
        $this->receiver = $receiver;
    }

    /**
     * Hooks the page into the admin.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    /**
     * `admin_menu` action.
     *
     * @return void
     */
    public function addPage(): void
    {
        add_options_page(__('Minos — moderacja komentarzy', 'minos-moderation'), 'Minos',
            'manage_options', self::SLUG, [$this, 'render']);
    }

    /**
     * `admin_init` action: the options, the sections and the fields.
     *
     * @return void
     */
    public function registerSettings(): void
    {
        register_setting(self::GROUP, Settings::OPTION, ['type' => 'array',
            'sanitize_callback' => [$this, 'sanitizeSettings'], 'default' => Settings::defaults()]);
        register_setting(self::GROUP, Settings::KEY_OPTION, ['type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeKey'], 'default' => '']);
        register_setting(self::GROUP, Settings::SECRET_OPTION, ['type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeSecret'], 'default' => '']);

        add_settings_section('minos_connection', __('Połączenie z bramą', 'minos-moderation'), '__return_false', self::SLUG);
        add_settings_section('minos_decisions', __('Co zrobić z komentarzem', 'minos-moderation'), '__return_false', self::SLUG);
        add_settings_section('minos_notice', __('Informacja pod formularzem komentarza', 'minos-moderation'), '__return_false', self::SLUG);

        $fields = [
            'enabled'        => ['minos_connection', __('Moderacja włączona', 'minos-moderation')],
            'gateway_url'    => ['minos_connection', __('Adres bramy', 'minos-moderation')],
            'api_key'        => ['minos_connection', __('Klucz API', 'minos-moderation')],
            'webhook_secret' => ['minos_connection', __('Sekret webhooka', 'minos-moderation')],
            'profile'        => ['minos_connection', __('Profil oceny', 'minos-moderation')],
            'failure_mode'   => ['minos_decisions', __('Gdy brak werdyktu', 'minos-moderation')],
            'timeout_min'    => ['minos_decisions', __('Czas oczekiwania na werdykt', 'minos-moderation')],
            'censored_mode'  => ['minos_decisions', __('Komentarz ocenzurowany', 'minos-moderation')],
            'blocked_mode'   => ['minos_decisions', __('Komentarz zablokowany', 'minos-moderation')],
            'notice_enabled' => ['minos_notice', __('Pokazuj informację', 'minos-moderation')],
            'notice_text'    => ['minos_notice', __('Treść informacji', 'minos-moderation')],
        ];
        foreach ($fields as $name => [$section, $title]) {
            add_settings_field('minos_' . $name, $title, [$this, 'field'], self::SLUG, $section,
                ['name' => $name, 'label_for' => 'minos_' . $name]);
        }
    }

    /**
     * Sanitises the settings form.
     *
     * @param mixed $input The submitted values.
     * @return array<string,mixed> Known values; an invalid gateway URL keeps the stored one.
     */
    public function sanitizeSettings($input): array
    {
        $current = $this->settings->all();
        if (!is_array($input)) {
            return $current;
        }
        $url = trim(is_string($input['gateway_url'] ?? null) ? $input['gateway_url'] : '');
        if ($url === '') {
            $url = Settings::DEFAULT_GATEWAY;
        } elseif (!Settings::validGatewayUrl($url)) {
            add_settings_error(Settings::OPTION, 'minos_gateway_url', __('Adres bramy musi zaczynać się od https:// (http:// jest dozwolone tylko dla localhost). Zachowano poprzedni adres.', 'minos-moderation'));
            $url = $current['gateway_url'];
        }
        return Settings::normalise([
            'enabled'        => !empty($input['enabled']),
            'gateway_url'    => $url,
            'profile'        => $input['profile'] ?? null,
            'failure_mode'   => $input['failure_mode'] ?? null,
            'timeout_min'    => $input['timeout_min'] ?? null,
            'censored_mode'  => $input['censored_mode'] ?? null,
            'blocked_mode'   => $input['blocked_mode'] ?? null,
            'notice_enabled' => !empty($input['notice_enabled']),
            'notice_text'    => sanitize_textarea_field(is_string($input['notice_text'] ?? null) ? $input['notice_text'] : ''),
        ]);
    }

    /**
     * Sanitises the key field: empty keeps the stored key, an invalid one is refused.
     *
     * @param mixed $input The submitted value.
     * @return string The key to store.
     */
    public function sanitizeKey($input): string
    {
        $value = is_string($input) ? trim($input) : '';
        if ($value === '' || $value === $this->settings->apiKey()) {
            return $this->settings->apiKey();
        }
        if (!Settings::validKey($value)) {
            add_settings_error(Settings::KEY_OPTION, 'minos_api_key', __('Klucz API ma postać wgb2b_… (litery, cyfry, „_” i „-”). Zachowano poprzedni klucz.', 'minos-moderation'));
            return $this->settings->apiKey();
        }
        return $value;
    }

    /**
     * Sanitises the secret field: empty keeps the stored secret, an invalid one is refused.
     *
     * @param mixed $input The submitted value.
     * @return string The secret to store.
     */
    public function sanitizeSecret($input): string
    {
        $value = is_string($input) ? trim($input) : '';
        if ($value === '' || $value === $this->settings->webhookSecret()) {
            return $this->settings->webhookSecret();
        }
        if (!Settings::validSecret($value)) {
            add_settings_error(Settings::SECRET_OPTION, 'minos_webhook_secret', __('Sekret webhooka to 16–256 widocznych znaków ASCII, bez spacji. Zachowano poprzedni sekret.', 'minos-moderation'));
            return $this->settings->webhookSecret();
        }
        return $value;
    }

    /**
     * Prints one field.
     *
     * @param array{name:string} $args The field's name.
     * @return void
     */
    public function field(array $args): void
    {
        $values = $this->settings->all();
        $name = $args['name'];
        $id = 'minos_' . $name;
        $input = Settings::OPTION . '[' . $name . ']';
        switch ($name) {
            case 'enabled':
            case 'notice_enabled':
                printf('<input type="checkbox" id="%s" name="%s" value="1" %s />', esc_attr($id),
                    esc_attr($input), checked($values[$name], true, false));
                if ($name === 'enabled') {
                    self::help(__('Po włączeniu każdy nowy komentarz (poza komentarzami moderatorów) jest wstrzymywany do czasu otrzymania werdyktu. Wtyczka działa dopiero, gdy zapisano klucz API i sekret webhooka.', 'minos-moderation'));
                }
                break;
            case 'gateway_url':
                printf('<input type="url" class="regular-text code" id="%s" name="%s" value="%s" />',
                    esc_attr($id), esc_attr($input), esc_attr($values['gateway_url']));
                self::help(sprintf(__('Domyślnie %s. Zmieniaj tylko na polecenie operatora lub do testów z atrapą bramy.', 'minos-moderation'), Settings::DEFAULT_GATEWAY));
                break;
            case 'api_key':
                $this->secretField($id, Settings::KEY_OPTION, Settings::prefix($this->settings->apiKey(), 10),
                    __('Klucz wydany przez operatora (wgb2b_…). Wysyłany wyłącznie do bramy, w nagłówku X-Gateway-Key.', 'minos-moderation'));
                break;
            case 'webhook_secret':
                $this->secretField($id, Settings::SECRET_OPTION, Settings::prefix($this->settings->webhookSecret(), 4),
                    __('Sekret wydrukowany jednorazowo przy wydaniu klucza. Służy do sprawdzania podpisu werdyktów i nigdy nie jest wysyłany.', 'minos-moderation'));
                break;
            case 'profile':
                $labels = ['forum_adult' => __('forum_adult — forum dla dorosłych', 'minos-moderation'),
                    'forum_teen' => __('forum_teen — forum z udziałem nastolatków', 'minos-moderation')];
                $this->select($id, $input, $values['profile'], $labels);
                break;
            case 'failure_mode':
                $this->radios($input, $values['failure_mode'], [
                    Settings::FAIL_OPEN   => __('fail-open — opublikuj komentarz', 'minos-moderation'),
                    Settings::FAIL_CLOSED => __('fail-closed — zostaw komentarz do ręcznej moderacji', 'minos-moderation'),
                ]);
                self::help(__('Dotyczy komentarza, którego brama nie oceniła (status „nieocenione”), na który werdykt nie nadszedł w czasie oczekiwania, albo którego brama nie przyjęła z powodu błędu konfiguracji. Wtyczka nigdy nie zgaduje werdyktu.', 'minos-moderation'));
                break;
            case 'timeout_min':
                printf('<input type="number" class="small-text" id="%s" name="%s" value="%d" min="%d" max="%d" /> %s',
                    esc_attr($id), esc_attr($input), (int)$values['timeout_min'], Settings::MIN_TIMEOUT_MIN,
                    Settings::MAX_TIMEOUT_MIN, esc_html__('min', 'minos-moderation'));
                self::help(__('Domyślnie 20 minut: brama próbuje dostarczyć werdykt przez 15 minut, do tego zapas. Krótszy czas może sprawić, że spóźniony werdykt zostanie pominięty.', 'minos-moderation'));
                break;
            case 'censored_mode':
                $this->radios($input, $values['censored_mode'], [
                    Settings::CENSORED_PUBLISH => __('opublikuj wersję z zamaskowanymi fragmentami (█)', 'minos-moderation'),
                    Settings::HOLD             => __('zostaw do ręcznej moderacji', 'minos-moderation'),
                ]);
                self::help(__('Oryginalna treść zostaje zachowana w danych komentarza. Gdy brama nie odesłała wersji zamaskowanej, komentarz zostaje do ręcznej moderacji.', 'minos-moderation'));
                break;
            case 'blocked_mode':
                $this->radios($input, $values['blocked_mode'], [
                    Settings::HOLD         => __('zostaw do ręcznej moderacji', 'minos-moderation'),
                    Settings::BLOCKED_SPAM => __('oznacz jako spam', 'minos-moderation'),
                ]);
                break;
            case 'notice_text':
                printf('<textarea class="large-text" rows="4" id="%s" name="%s" placeholder="%s">%s</textarea>',
                    esc_attr($id), esc_attr($input), esc_attr(Settings::defaultNotice()),
                    esc_textarea((string)$values['notice_text']));
                self::help(__('Zwykły tekst. Puste pole oznacza tekst domyślny (widoczny jako podpowiedź).', 'minos-moderation'));
                break;
        }
    }

    /**
     * Prints the page.
     *
     * @return void
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $values = $this->settings->all();
        $url = $this->receiver->url();
        echo '<div class="wrap"><h1>' . esc_html__('Minos — moderacja komentarzy', 'minos-moderation') . '</h1>';

        if ($this->settings->isActive()) {
            $state = __('Moderacja działa: nowe komentarze czekają na werdykt bramy.', 'minos-moderation');
        } elseif ($values['enabled']) {
            $state = __('Moderacja jest włączona, ale brakuje klucza API lub sekretu webhooka — komentarze nie są wysyłane do oceny.', 'minos-moderation');
        } else {
            $state = __('Moderacja jest wyłączona — komentarze nie są wysyłane do oceny.', 'minos-moderation');
        }
        echo '<p><strong>' . esc_html($state) . '</strong></p>';

        echo '<h2>' . esc_html__('Adres webhooka', 'minos-moderation') . '</h2>';
        echo '<p><code>' . esc_html($url) . '</code></p>';
        self::help(__('Przekaż ten adres operatorowi razem z kluczem: brama wysyła werdykty wyłącznie na adres zapisany przy kluczu.', 'minos-moderation'));
        if (strpos($url, 'https://') !== 0) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Ten adres nie zaczyna się od https://. Brama dostarcza werdykty wyłącznie przez HTTPS, na nazwę domeny o publicznym adresie — bez tego werdykty nie dotrą.', 'minos-moderation') . '</p></div>';
        }

        echo '<form action="options.php" method="post">';
        settings_fields(self::GROUP);
        do_settings_sections(self::SLUG);
        submit_button();
        echo '</form>';

        $this->renderLog();
        echo '</div>';
    }

    /**
     * Prints the last entries of the log: codes and statuses only.
     *
     * @return void
     */
    private function renderLog(): void
    {
        echo '<h2>' . esc_html__('Dziennik błędów', 'minos-moderation') . '</h2>';
        $entries = array_reverse(array_slice($this->log->entries(), -20));
        if ($entries === []) {
            echo '<p>' . esc_html__('Brak wpisów.', 'minos-moderation') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Czas', 'minos-moderation')
            . '</th><th>HTTP</th><th>' . esc_html__('Kod', 'minos-moderation') . '</th><th>'
            . esc_html__('Komentarz', 'minos-moderation') . '</th></tr></thead><tbody>';
        foreach ($entries as $entry) {
            printf('<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>#%d</td></tr>',
                esc_html(wp_date('Y-m-d H:i:s', (int)($entry['time'] ?? 0))),
                esc_html(isset($entry['http']) ? (string)$entry['http'] : __('brak odpowiedzi', 'minos-moderation')),
                esc_html((string)($entry['code'] ?? '—')), (int)($entry['comment'] ?? 0));
        }
        echo '</tbody></table>';
    }

    /**
     * A password field that starts empty and shows only the stored value's prefix.
     *
     * @param string $id     The field's id.
     * @param string $option The option it saves to.
     * @param string $prefix The stored value's prefix, or ''.
     * @param string $help   The help text.
     * @return void
     */
    private function secretField(string $id, string $option, string $prefix, string $help): void
    {
        printf('<input type="password" class="regular-text code" id="%s" name="%s" value="" autocomplete="off" />',
            esc_attr($id), esc_attr($option));
        if ($prefix !== '') {
            echo ' <span class="description">' . esc_html(sprintf(__('Zapisano: %s — zostaw pole puste, aby go nie zmieniać.', 'minos-moderation'), $prefix)) . '</span>';
        }
        self::help($help);
    }

    /**
     * A drop-down list.
     *
     * @param string               $id      The field's id.
     * @param string               $name    The input's name.
     * @param string               $current The stored value.
     * @param array<string,string> $labels  Value → label.
     * @return void
     */
    private function select(string $id, string $name, string $current, array $labels): void
    {
        printf('<select id="%s" name="%s">', esc_attr($id), esc_attr($name));
        foreach ($labels as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($current, $value, false), esc_html($label));
        }
        echo '</select>';
    }

    /**
     * A set of radio buttons.
     *
     * @param string               $name    The input's name.
     * @param string               $current The stored value.
     * @param array<string,string> $labels  Value → label.
     * @return void
     */
    private function radios(string $name, string $current, array $labels): void
    {
        foreach ($labels as $value => $label) {
            printf('<label><input type="radio" name="%s" value="%s" %s /> %s</label><br />', esc_attr($name),
                esc_attr($value), checked($current, $value, false), esc_html($label));
        }
    }

    /**
     * A help line under a field.
     *
     * @param string $text The text.
     * @return void
     */
    private static function help(string $text): void
    {
        echo '<p class="description">' . esc_html($text) . '</p>';
    }
}
