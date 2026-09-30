<?php

/*
 * Hand-written stand-ins for the WordPress functions and classes the plugin calls.
 *
 * They keep WordPress's observable behaviour where the plugin depends on it — options and
 * comment meta unslash what they are given, `wp_set_comment_status` maps its words to
 * `comment_approved`, `WP_REST_Request::get_header` canonicalises the name — and record
 * every side effect (HTTP requests, scheduled events, status changes, e-mails) in
 * `WpStub`, so a test asserts on what the plugin did. `wp_remote_post` answers from a
 * queue, or sends for real in end-to-end tests.
 *
 * Global namespace, no PHP 8 syntax: CI runs the suite on PHP 7.4.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

/**
 * The state of the stubbed WordPress.
 */
final class WpStub
{
    /** @var array<string,mixed> */
    public static $options = [];

    /** @var array<string,bool> Whether each option was created autoloaded. */
    public static $autoload = [];

    /** @var array<int,array<string,string>> Comment id → the `wp_comments` row. */
    public static $comments = [];

    /** @var array<int,array<string,string>> Comment id → meta key → value. */
    public static $meta = [];

    /** @var array<int,array{url:string,args:array}> Every `wp_remote_post`. */
    public static $requests = [];

    /** @var array<int,array|WP_Error> Answers for the next `wp_remote_post` calls. */
    public static $responses = [];

    /** @var bool Whether `wp_remote_post` sends over the network (end-to-end tests). */
    public static $network = false;

    /** @var array<int,array{at:int,hook:string,args:array,recurrence:string|null}> */
    public static $events = [];

    /** @var array<int,array{0:int,1:string}> Every `wp_set_comment_status`: id, status. */
    public static $statusChanges = [];

    /** @var array<int,array{0:string,1:int}> E-mails WordPress would send: kind, comment id. */
    public static $emails = [];

    /** @var array<string,array<int,array{0:callable,1:int,2:int}>> Hook → callbacks. */
    public static $hooks = [];

    /** @var array<int,array{namespace:string,route:string,args:array}> */
    public static $routes = [];

    /** @var array<int,array{setting:string,code:string,message:string}> */
    public static $settingsErrors = [];

    /** @var array<int,array<int,string>> User id → capabilities. */
    public static $userCaps = [];

    /** @var int The current user's id (0: a visitor). */
    public static $currentUser = 0;

    /** @var bool What `is_admin()` answers. */
    public static $admin = false;

    /** @var object|null What `get_current_screen()` answers. */
    public static $screen = null;

    /** @var array<string,callable> Activation and deactivation callbacks by kind. */
    public static $lifecycle = [];

    /** @var array<string,array<int,array{id:string,callback:callable,args:array}>> Page → fields. */
    public static $fields = [];

    /** @var array<string,array> Registered settings: option → args. */
    public static $registeredSettings = [];

    /**
     * Forgets everything.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$options = [];
        self::$autoload = [];
        self::$comments = [];
        self::$meta = [];
        self::$requests = [];
        self::$responses = [];
        self::$network = false;
        self::$events = [];
        self::$statusChanges = [];
        self::$emails = [];
        self::$hooks = [];
        self::$routes = [];
        self::$settingsErrors = [];
        self::$userCaps = [];
        self::$currentUser = 0;
        self::$admin = false;
        self::$screen = null;
        self::$lifecycle = [];
        self::$fields = [];
        self::$registeredSettings = [];
    }

    /**
     * Inserts a comment row directly (what `wp_insert_comment` would store).
     *
     * @param array<string,mixed> $fields Columns over the defaults.
     * @return int The new id.
     */
    public static function insertComment(array $fields): int
    {
        $id = self::$comments === [] ? 1 : max(array_keys(self::$comments)) + 1;
        $row = $fields + [
            'comment_post_ID'      => '1',
            'comment_author'       => 'Gość testowy',
            'comment_author_email' => 'gosc@example.invalid',
            'comment_author_IP'    => '192.0.2.10',
            'comment_content'      => 'Treść testowa.',
            'comment_approved'     => '0',
            'comment_type'         => 'comment',
            'comment_date_gmt'     => gmdate('Y-m-d H:i:s'),
            'user_id'              => '0',
        ];
        $row['comment_ID'] = (string)$id;
        foreach ($row as $name => $value) {
            $row[$name] = (string)$value;
        }
        self::$comments[$id] = $row;
        return $id;
    }

    /**
     * Posts a comment the way `wp_new_comment` does: `pre_comment_approved` on WordPress's
     * own decision, the insert, then `comment_post`.
     *
     * @param array<string,mixed> $data     The comment's fields.
     * @param int|string          $decision What WordPress's own rules decided.
     * @return int The comment id.
     */
    public static function postComment(array $data, $decision = 1): int
    {
        $data += ['comment_type' => 'comment', 'user_id' => 0];
        $approved = apply_filters('pre_comment_approved', $decision, $data);
        $id = self::insertComment(['comment_approved' => (string)$approved] + $data);
        do_action('comment_post', $id, $approved, $data);
        return $id;
    }

    /**
     * The answer `wp_remote_post` gives next.
     *
     * @param int                 $status The HTTP status.
     * @param array<string,mixed> $body   The JSON body.
     * @return void
     */
    public static function answer(int $status, array $body = []): void
    {
        self::$responses[] = ['response' => ['code' => $status], 'body' => (string)json_encode($body)];
    }

    /**
     * Makes the next `wp_remote_post` fail without an answer.
     *
     * @return void
     */
    public static function networkFailure(): void
    {
        self::$responses[] = new WP_Error('http_request_failed', 'cURL error 28: timeout');
    }

    /**
     * Writes the data part of the state to a file, for another process (end-to-end).
     *
     * @param string $path The file.
     * @return void
     */
    public static function save(string $path): void
    {
        file_put_contents($path, json_encode([
            'options'       => self::$options,
            'comments'      => self::$comments,
            'meta'          => self::$meta,
            'events'        => self::$events,
            'statusChanges' => self::$statusChanges,
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * Reads the data part of the state from a file.
     *
     * @param string $path The file.
     * @return void
     */
    public static function load(string $path): void
    {
        $data = json_decode((string)file_get_contents($path), true);
        self::$options = $data['options'] ?? [];
        self::$comments = [];
        foreach ($data['comments'] ?? [] as $id => $row) {
            self::$comments[(int)$id] = $row;
        }
        self::$meta = [];
        foreach ($data['meta'] ?? [] as $id => $values) {
            self::$meta[(int)$id] = $values;
        }
        self::$events = $data['events'] ?? [];
        self::$statusChanges = $data['statusChanges'] ?? [];
    }

    /**
     * A comment row as an object, like `WP_Comment`.
     *
     * @param int $id The comment id.
     * @return object|null The comment.
     */
    public static function commentObject(int $id): ?object
    {
        return isset(self::$comments[$id]) ? (object)self::$comments[$id] : null;
    }
}

/** A `WP_Error`. */
class WP_Error
{
    /** @var string */
    public $code;

    /** @var string */
    public $message;

    public function __construct(string $code = '', string $message = '')
    {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

/** A `WP_REST_Request`, as the REST server builds it: the body is the raw input. */
class WP_REST_Request
{
    /** @var string */
    private $body = '';

    /** @var array<string,string> Canonical name → value. */
    private $headers = [];

    public function __construct(string $method = 'GET', string $route = '')
    {
    }

    public function set_body(string $body): void
    {
        $this->body = $body;
    }

    public function get_body(): string
    {
        return $this->body;
    }

    public function set_header(string $name, string $value): void
    {
        $this->headers[strtolower(str_replace('-', '_', $name))] = $value;
    }

    /** @return string|null */
    public function get_header(string $name)
    {
        return $this->headers[strtolower(str_replace('-', '_', $name))] ?? null;
    }
}

/** A `WP_REST_Response`. */
class WP_REST_Response
{
    /** @var mixed */
    public $data;

    /** @var int */
    public $status;

    /** @param mixed $data */
    public function __construct($data = null, int $status = 200)
    {
        $this->data = $data;
        $this->status = $status;
    }

    public function get_status(): int
    {
        return $this->status;
    }
}

// --- Options -------------------------------------------------------------------------

function get_option($name, $default = false)
{
    return array_key_exists($name, WpStub::$options) ? WpStub::$options[$name] : $default;
}

function update_option($name, $value, $autoload = null)
{
    $value = apply_filters('sanitize_option_' . $name, $value, $name, $value);
    if (!array_key_exists($name, WpStub::$options)) {
        WpStub::$autoload[$name] = $autoload === null ? true : (bool)$autoload;
    }
    WpStub::$options[$name] = $value;
    return true;
}

function add_option($name, $value = '', $deprecated = '', $autoload = null)
{
    if (array_key_exists($name, WpStub::$options)) {
        return false;
    }
    WpStub::$options[$name] = $value;
    WpStub::$autoload[$name] = !($autoload === false || $autoload === 'no');
    return true;
}

function delete_option($name)
{
    unset(WpStub::$options[$name], WpStub::$autoload[$name]);
    return true;
}

// --- Comments and their meta ---------------------------------------------------------

function get_comment($comment = null, $output = OBJECT)
{
    return WpStub::commentObject((int)$comment);
}

/**
 * The subset of `WP_Comment_Query` the plugin uses: status, one meta pair, user or e-mail,
 * a `comment_date_gmt` lower bound, ids or a count, a limit.
 */
function get_comments($args = [])
{
    $statuses = ['approve' => ['1'], 'hold' => ['0'], 'all' => ['0', '1'], 'spam' => ['spam'], 'trash' => ['trash']];
    $status = $args['status'] ?? 'all';
    $found = [];
    foreach (WpStub::$comments as $id => $row) {
        if ($status !== 'any' && !in_array($row['comment_approved'], $statuses[$status] ?? [], true)) {
            continue;
        }
        if (isset($args['meta_key'])) {
            $meta = WpStub::$meta[$id][$args['meta_key']] ?? null;
            if ($meta === null || (isset($args['meta_value']) && $meta !== $args['meta_value'])) {
                continue;
            }
        }
        if (isset($args['user_id']) && (int)$row['user_id'] !== (int)$args['user_id']) {
            continue;
        }
        if (isset($args['author_email']) && $row['comment_author_email'] !== $args['author_email']) {
            continue;
        }
        foreach ($args['date_query'] ?? [] as $clause) {
            if (isset($clause['after']) && $row['comment_date_gmt'] <= $clause['after']) {
                continue 2;
            }
        }
        $found[] = $id;
    }
    sort($found);
    if (!empty($args['count'])) {
        return count($found);
    }
    if (!empty($args['number'])) {
        $found = array_slice($found, 0, (int)$args['number']);
    }
    if (($args['fields'] ?? '') === 'ids') {
        return $found;
    }
    return array_map([WpStub::class, 'commentObject'], $found);
}

function get_comment_meta($id, $key = '', $single = false)
{
    $value = WpStub::$meta[(int)$id][$key] ?? null;
    if ($single) {
        return $value ?? '';
    }
    return $value === null ? [] : [$value];
}

function update_comment_meta($id, $key, $value, $prev = '')
{
    WpStub::$meta[(int)$id][wp_unslash($key)] = (string)wp_unslash($value);
    return true;
}

function delete_comment_meta($id, $key, $value = '')
{
    unset(WpStub::$meta[(int)$id][$key]);
    return true;
}

function delete_metadata($type, $objectId, $key, $value = '', $deleteAll = false)
{
    foreach (WpStub::$meta as $id => $values) {
        if ($deleteAll || (int)$id === (int)$objectId) {
            unset(WpStub::$meta[$id][$key]);
        }
    }
    return true;
}

/**
 * As core: false when the status does not change (wpdb reports no row updated); approving
 * mails the post's author (core hooks `wp_new_comment_notify_postauthor` onto
 * `wp_set_comment_status`); then `transition_comment_status`.
 */
function wp_set_comment_status($id, $status, $wpError = false)
{
    $map = ['approve' => '1', '1' => '1', 'hold' => '0', '0' => '0', 'spam' => 'spam', 'trash' => 'trash'];
    $id = (int)$id;
    if (!isset($map[$status], WpStub::$comments[$id])) {
        return false;
    }
    $old = WpStub::$comments[$id]['comment_approved'];
    if ($old === $map[$status]) {
        return $wpError ? new WP_Error('db_update_error', 'Could not update comment status.') : false;
    }
    WpStub::$comments[$id]['comment_approved'] = $map[$status];
    WpStub::$statusChanges[] = [$id, (string)$status];
    if ($map[$status] === '1') {
        wp_new_comment_notify_postauthor($id);
    }
    wpstub_transition_comment_status($map[$status], $old, $id);
    return true;
}

/**
 * As core: the content filters run on the way in (`pre_comment_content` on slashed data,
 * `comment_save_pre` on unslashed), `wp_update_comment_data` may refuse with a `WP_Error`,
 * wpdb refuses a `comment_content` over the `text` column's 65,535 bytes, and a done
 * update fires `edit_comment`.
 */
function wp_update_comment($commentarr, $wpError = false)
{
    $id = (int)($commentarr['comment_ID'] ?? 0);
    if (!isset(WpStub::$comments[$id])) {
        return $wpError ? new WP_Error('invalid_comment_id', 'Invalid comment ID.') : false;
    }
    $old = WpStub::$comments[$id];
    $merged = array_merge(wp_slash($old), $commentarr);
    $merged['comment_content'] = apply_filters('pre_comment_content', $merged['comment_content']);
    $data = wp_unslash($merged);
    $data['comment_content'] = apply_filters('comment_save_pre', $data['comment_content']);
    $data = apply_filters('wp_update_comment_data', $data, $old, $commentarr);
    if (is_wp_error($data)) {
        return $wpError ? $data : false;
    }
    if (strlen((string)$data['comment_content']) > 65535) {
        return $wpError ? new WP_Error('db_update_error', 'Could not update comment in the database.') : false;
    }
    foreach ($data as $name => $value) {
        WpStub::$comments[$id][$name] = (string)$value;
    }
    do_action('edit_comment', $id, $data);
    if (WpStub::$comments[$id]['comment_approved'] !== $old['comment_approved']) {
        wpstub_transition_comment_status(WpStub::$comments[$id]['comment_approved'], $old['comment_approved'], $id);
    }
    return 1;
}

/** Core's `wp_transition_comment_status`, as far as `transition_comment_status` goes. */
function wpstub_transition_comment_status(string $new, string $old, int $id): void
{
    $names = ['0' => 'unapproved', '1' => 'approved'];
    $new = $names[$new] ?? $new;
    $old = $names[$old] ?? $old;
    if ($new !== $old) {
        do_action('transition_comment_status', $new, $old, get_comment($id));
    }
}

function wp_new_comment_notify_moderator($id)
{
    $comment = get_comment($id);
    if ($comment !== null && $comment->comment_approved === '0'
        && apply_filters('notify_moderator', true, $id)) {
        WpStub::$emails[] = ['moderator', (int)$id];
    }
    return true;
}

function wp_new_comment_notify_postauthor($id)
{
    $comment = get_comment($id);
    if ($comment !== null && $comment->comment_approved === '1') {
        WpStub::$emails[] = ['postauthor', (int)$id];
    }
    return true;
}

function wp_slash($value)
{
    return is_array($value) ? array_map('wp_slash', $value) : (is_string($value) ? addslashes($value) : $value);
}

function wp_unslash($value)
{
    return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value);
}

// --- Users ---------------------------------------------------------------------------

function user_can($user, $capability, ...$args)
{
    return in_array($capability, WpStub::$userCaps[(int)$user] ?? [], true);
}

function current_user_can($capability, ...$args)
{
    return user_can(WpStub::$currentUser, $capability);
}

// --- HTTP ----------------------------------------------------------------------------

function wp_remote_post($url, $args = [])
{
    WpStub::$requests[] = ['url' => $url, 'args' => $args];
    if (WpStub::$network) {
        $headers = [];
        foreach ($args['headers'] ?? [] as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $args['body'] ?? '',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY          => '',
            CURLOPT_TIMEOUT        => (int)($args['timeout'] ?? 5),
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($body === false || $status === 0) {
            return new WP_Error('http_request_failed', 'no answer');
        }
        return ['response' => ['code' => $status], 'body' => (string)$body];
    }
    if (WpStub::$responses === []) {
        return ['response' => ['code' => 202], 'body' => '{"przyjete":[]}'];
    }
    return array_shift(WpStub::$responses);
}

function wp_remote_retrieve_response_code($response)
{
    return is_array($response) ? $response['response']['code'] : '';
}

function wp_remote_retrieve_body($response)
{
    return is_array($response) ? $response['body'] : '';
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

// --- WP-Cron -------------------------------------------------------------------------

function wp_schedule_single_event($timestamp, $hook, $args = [], $wpError = false)
{
    WpStub::$events[] = ['at' => (int)$timestamp, 'hook' => $hook, 'args' => $args, 'recurrence' => null];
    return true;
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = [], $wpError = false)
{
    WpStub::$events[] = ['at' => (int)$timestamp, 'hook' => $hook, 'args' => $args, 'recurrence' => $recurrence];
    return true;
}

function wp_next_scheduled($hook, $args = [])
{
    foreach (WpStub::$events as $event) {
        if ($event['hook'] === $hook && $event['args'] === $args) {
            return $event['at'];
        }
    }
    return false;
}

function wp_unschedule_hook($hook, $wpError = false)
{
    $before = count(WpStub::$events);
    WpStub::$events = array_values(array_filter(WpStub::$events, static function (array $event) use ($hook): bool {
        return $event['hook'] !== $hook;
    }));
    return $before - count(WpStub::$events);
}

// --- Hooks ---------------------------------------------------------------------------

function add_filter($hook, $callback, $priority = 10, $acceptedArgs = 1)
{
    WpStub::$hooks[$hook][] = [$callback, (int)$priority, (int)$acceptedArgs];
    return true;
}

function add_action($hook, $callback, $priority = 10, $acceptedArgs = 1)
{
    return add_filter($hook, $callback, $priority, $acceptedArgs);
}

/** @return array<int,array{0:callable,1:int,2:int}> The callbacks of a hook by priority. */
function wpstub_callbacks(string $hook): array
{
    $callbacks = WpStub::$hooks[$hook] ?? [];
    usort($callbacks, static function (array $a, array $b): int {
        return $a[1] <=> $b[1];
    });
    return $callbacks;
}

function apply_filters($hook, $value, ...$args)
{
    foreach (wpstub_callbacks($hook) as [$callback, , $accepted]) {
        $value = call_user_func_array($callback, array_slice(array_merge([$value], $args), 0, $accepted));
    }
    return $value;
}

function do_action($hook, ...$args)
{
    foreach (wpstub_callbacks($hook) as [$callback, , $accepted]) {
        call_user_func_array($callback, array_slice($args, 0, $accepted));
    }
}

function register_activation_hook($file, $callback)
{
    WpStub::$lifecycle['activate'] = $callback;
}

function register_deactivation_hook($file, $callback)
{
    WpStub::$lifecycle['deactivate'] = $callback;
}

function register_rest_route($namespace, $route, $args = [], $override = false)
{
    WpStub::$routes[] = ['namespace' => $namespace, 'route' => $route, 'args' => $args];
    return true;
}

function rest_url($path = '', $scheme = 'rest')
{
    return 'https://forum.example/wp-json/' . ltrim($path, '/');
}

function is_admin()
{
    return WpStub::$admin;
}

function load_plugin_textdomain($domain, $deprecated = false, $path = false)
{
    return true;
}

function plugin_basename($file)
{
    return basename(dirname($file)) . '/' . basename($file);
}

function __return_true()
{
    return true;
}

function __return_false()
{
    return false;
}

// --- Translation and escaping --------------------------------------------------------

function __($text, $domain = 'default')
{
    return $text;
}

function esc_html__($text, $domain = 'default')
{
    return esc_html($text);
}

function esc_html($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function esc_attr($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function esc_textarea($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function sanitize_textarea_field($text)
{
    return trim(strip_tags((string)$text));
}

function checked($checked, $current = true, $display = true)
{
    return (string)$checked === (string)$current ? " checked='checked'" : '';
}

function selected($selected, $current = true, $display = true)
{
    return (string)$selected === (string)$current ? " selected='selected'" : '';
}

function wp_date($format, $timestamp = null, $timezone = null)
{
    return gmdate($format, (int)$timestamp);
}

// --- Admin: the Settings API ---------------------------------------------------------

function add_options_page($pageTitle, $menuTitle, $capability, $slug, $callback = '', $position = null)
{
    return 'settings_page_' . $slug;
}

function register_setting($group, $option, $args = [])
{
    WpStub::$registeredSettings[$option] = $args;
    if (isset($args['sanitize_callback'])) {
        add_filter('sanitize_option_' . $option, $args['sanitize_callback']);
    }
}

function add_settings_section($id, $title, $callback, $page, $args = [])
{
}

function add_settings_field($id, $title, $callback, $page, $section = 'default', $args = [])
{
    WpStub::$fields[$page][] = ['id' => $id, 'callback' => $callback, 'args' => $args];
}

function add_settings_error($setting, $code, $message, $type = 'error')
{
    WpStub::$settingsErrors[] = ['setting' => $setting, 'code' => $code, 'message' => $message];
}

function settings_fields($group)
{
    echo '<input type="hidden" name="option_page" value="' . esc_attr($group) . '" />';
}

function do_settings_sections($page)
{
    foreach (WpStub::$fields[$page] ?? [] as $field) {
        call_user_func($field['callback'], $field['args']);
    }
}

function submit_button($text = '', $type = 'primary', $name = 'submit', $wrap = true, $other = '')
{
    echo '<input type="submit" />';
}

function get_current_screen()
{
    return WpStub::$screen;
}
