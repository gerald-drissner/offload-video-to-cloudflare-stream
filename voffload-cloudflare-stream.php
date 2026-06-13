<?php
/**
 * Plugin Name:       Voffload for Cloudflare Stream
 * Plugin URI:        https://wordpress.org/plugins/voffload-cloudflare-stream/
 * Description:       Upload Media Library videos to Cloudflare Stream and serve ready videos through the Stream player.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       voffload-cloudflare-stream
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

final class VoffloadCFS_Plugin {
    const VERSION = '1.0.0';
    const OPTION  = 'voffloadcfs_options';
    const CRON_HOOK = 'voffloadcfs_status_cron';

    // Cloudflare basic (single-request multipart) upload hard limit.
    const DIRECT_UPLOAD_MAX_BYTES = 200 * 1024 * 1024; // 200 MB

    const META_UID          = '_voffloadcfs_uid';
    const META_STATE        = '_voffloadcfs_state';
    const META_READY        = '_voffloadcfs_ready';
    const META_PCT          = '_voffloadcfs_pct_complete';
    const META_THUMBNAIL    = '_voffloadcfs_thumbnail';
    const META_PREVIEW      = '_voffloadcfs_preview';
    const META_HLS          = '_voffloadcfs_hls';
    const META_DASH         = '_voffloadcfs_dash';
    const META_ERROR        = '_voffloadcfs_last_error';
    const META_UPLOADED_AT  = '_voffloadcfs_uploaded_at';
    const META_CHECKED_AT   = '_voffloadcfs_checked_at';
    const META_RESULT_JSON  = '_voffloadcfs_result_json';
    const META_METHOD       = '_voffloadcfs_upload_method';

    public static function init(): void {
        // cron_schedules is registered at file scope (see bottom) so it is
        // available during activation regardless of load order.
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_filter('option_page_capability_voffloadcfs_settings', [__CLASS__, 'settings_capability']);
        add_action('admin_init', [__CLASS__, 'handle_admin_actions']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);

        add_action(self::CRON_HOOK, [__CLASS__, 'cron_status_check']);

        add_filter('render_block', [__CLASS__, 'replace_core_video_block'], 20, 2);
        add_filter('wp_video_shortcode_override', [__CLASS__, 'replace_video_shortcode'], 20, 4);
        add_filter('the_content', [__CLASS__, 'replace_local_video_content'], 99);
        add_shortcode('voffloadcfs_video', [__CLASS__, 'shortcode']);

        add_filter('manage_media_columns', [__CLASS__, 'add_media_column']);
        add_action('manage_media_custom_column', [__CLASS__, 'render_media_column'], 10, 2);
    }


    public static function activate(): void {
        // Do not force a recurring poll on activation. The cron is scheduled
        // on demand when the first video is queued, and unschedules itself
        // when nothing is left to poll. This avoids a perpetual 10-min wake
        // when no videos are being processed.
        if (self::has_pending_videos()) {
            self::ensure_cron_scheduled();
        }
    }

    public static function deactivate(): void {
        self::clear_all_cron();
    }

    private static function clear_all_cron(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function ensure_cron_scheduled(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'voffloadcfs_ten_minutes', self::CRON_HOOK);
        }
    }

    public static function maybe_unschedule_cron(): void {
        if (!self::has_pending_videos()) {
            self::clear_all_cron();
        }
    }

    /**
     * True if at least one attachment has a Stream UID but is not yet ready
     * and is not in a terminal error state. These are the only rows worth
     * polling.
     */
    private static function has_pending_videos(): bool {
        $query = new WP_Query(self::pending_query_args(1));
        return !empty($query->posts);
    }

    private static function pending_query_args(int $limit): array {
        return [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'video',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'cache_results'  => false,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => self::META_UID,
                    'compare' => 'EXISTS',
                ],
                [
                    'key'     => self::META_READY,
                    'value'   => '1',
                    'compare' => '!=',
                ],
                [
                    'key'     => self::META_STATE,
                    'value'   => 'error',
                    'compare' => '!=',
                ],
            ],
        ];
    }

    public static function cron_schedules(array $schedules): array {
        if (!isset($schedules['voffloadcfs_ten_minutes'])) {
            $schedules['voffloadcfs_ten_minutes'] = [
                'interval' => 10 * MINUTE_IN_SECONDS,
                'display'  => __('Every 10 minutes', 'voffload-cloudflare-stream'),
            ];
        }
        return $schedules;
    }

    public static function defaults(): array {
        return [
            'account_id'          => '',
            'api_token'           => '',
            'customer_code'       => '',
            'allowed_origins'     => '',
            'auto_replace_blocks' => 1,
            'auto_replace_shortcode' => 1,
            'auto_replace_html' => 1,
        ];
    }

    public static function get_options(): array {
        $options = get_option(self::OPTION, []);
        if (!is_array($options)) {
            $options = [];
        }
        $options = wp_parse_args($options, self::defaults());
        $options['customer_code'] = self::sanitize_customer_code((string) $options['customer_code']);

        // Allow the API token to be defined in wp-config.php so the secret
        // never has to live in the database (and in DB backups).
        if (defined('VOFFLOADCFS_API_TOKEN') && VOFFLOADCFS_API_TOKEN !== '') {
            $options['api_token'] = (string) VOFFLOADCFS_API_TOKEN;
        }

        return $options;
    }

    private static function token_is_constant(): bool {
        return defined('VOFFLOADCFS_API_TOKEN') && VOFFLOADCFS_API_TOKEN !== '';
    }

    public static function register_settings(): void {
        register_setting('voffloadcfs_settings', self::OPTION, [
            'sanitize_callback' => [__CLASS__, 'sanitize_options'],
            'type'              => 'array',
        ]);
    }

    public static function sanitize_options($input): array {
        $old = self::get_options();
        $input = is_array($input) ? $input : [];

        $api_token = isset($input['api_token']) ? trim((string) $input['api_token']) : '';
        if ($api_token === '' && !empty($old['api_token'])) {
            // Keep the previously stored DB token, but only if we are not
            // running off a wp-config constant (in which case the DB stays empty).
            $stored = get_option(self::OPTION, []);
            $api_token = is_array($stored) && !empty($stored['api_token']) ? (string) $stored['api_token'] : '';
        }
        if (self::token_is_constant()) {
            $api_token = ''; // never persist a token to the DB when a constant defines it
        }

        return [
            'account_id'          => isset($input['account_id']) ? sanitize_text_field((string) $input['account_id']) : '',
            'api_token'           => sanitize_text_field($api_token),
            'customer_code'       => isset($input['customer_code']) ? self::sanitize_customer_code((string) $input['customer_code']) : '',
            'allowed_origins'     => isset($input['allowed_origins']) ? self::sanitize_allowed_origins((string) $input['allowed_origins']) : '',
            'auto_replace_blocks' => !empty($input['auto_replace_blocks']) ? 1 : 0,
            'auto_replace_shortcode' => !empty($input['auto_replace_shortcode']) ? 1 : 0,
            'auto_replace_html' => !empty($input['auto_replace_html']) ? 1 : 0,
        ];
    }

    private static function sanitize_customer_code(string $raw): string {
        $raw = html_entity_decode(trim((string) $raw), ENT_QUOTES, 'UTF-8');
        if ($raw === '') {
            return '';
        }

        // Accept the bare code, customer-CODE.cloudflarestream.com, a full
        // iframe URL, or a pasted iframe snippet from the Cloudflare dashboard.
        if (preg_match('/customer-([a-z0-9-]+)\.cloudflarestream\.com/i', $raw, $m)) {
            return sanitize_key($m[1]);
        }

        $raw = trim(wp_strip_all_tags($raw));
        return sanitize_key(str_replace(['customer-', '.cloudflarestream.com'], '', $raw));
    }

    private static function sanitize_allowed_origins(string $raw): string {
        $parts = preg_split('/[\r\n,]+/', $raw);
        $clean = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $host = parse_url($part, PHP_URL_HOST);
            if (!$host) {
                $host = $part;
            }
            $host = strtolower(trim((string) $host));
            $host = preg_replace('/[^a-z0-9.*\-]/', '', $host);
            if ($host !== '' && preg_match('/^(?:\*\.)?[a-z0-9][a-z0-9.-]*\.[a-z0-9-]{2,}$/', $host)) {
                $clean[] = $host;
            }
        }

        $clean = array_values(array_unique($clean));
        return implode("\n", $clean);
    }

    private static function allowed_origins_array(): array {
        $options = self::get_options();
        $raw = trim((string) $options['allowed_origins']);
        if ($raw === '') {
            return [];
        }
        $items = preg_split('/[\r\n,]+/', $raw);
        return array_values(array_filter(array_map('trim', $items)));
    }

    /**
     * Capability required to manage Cloudflare Stream uploads and settings.
     *
     * This defaults to administrators because the configured token can incur
     * Cloudflare Stream usage and billing. Sites that need a different policy
     * can adjust it with the voffloadcfs_manage_capability filter.
     */
    private static function capability(): string {
        $capability = apply_filters('voffloadcfs_manage_capability', 'manage_options');
        return is_string($capability) && $capability !== '' ? $capability : 'manage_options';
    }

    public static function settings_capability(): string {
        return self::capability();
    }

    public static function admin_menu(): void {
        add_media_page(
            __('Cloudflare Stream', 'voffload-cloudflare-stream'),
            __('Cloudflare Stream', 'voffload-cloudflare-stream'),
            self::capability(),
            'voffload-cloudflare-stream',
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function admin_assets(string $hook): void {
        if ($hook !== 'media_page_voffload-cloudflare-stream') {
            return;
        }

        wp_register_style('voffloadcfs-admin', false, [], self::VERSION);
        wp_enqueue_style('voffloadcfs-admin');
        wp_add_inline_style('voffloadcfs-admin', self::admin_css());

        wp_register_script('voffloadcfs-admin', false, [], self::VERSION, true);
        wp_enqueue_script('voffloadcfs-admin');
        wp_add_inline_script('voffloadcfs-admin', self::admin_js());
    }

    private static function admin_js(): string {
        return implode("\n", [
            "document.addEventListener('click', function (e) {",
            "    var t = e.target;",
            "    if (t && t.classList && t.classList.contains('voffloadcfs-check-all')) {",
            "        var boxes = document.querySelectorAll('.voffloadcfs-table input[type=\"checkbox\"][name=\"attachment_ids[]\"]');",
            "        boxes.forEach(function (cb) { cb.checked = t.checked; });",
            "    }",
            "});",
        ]);
    }

    private static function admin_css(): string {
        return implode("\n", [
            '.voffloadcfs-wrap { max-width: 1320px; }',
            '.voffloadcfs-wrap .description { max-width: 880px; }',
            '.voffloadcfs-dashboard-footer { color:#646970; margin:18px 0 0; padding-top:12px; border-top:1px solid #dcdcde; font-size:12px; line-height:1.5; text-align:right; }',
            '@media (max-width: 782px) { .voffloadcfs-dashboard-footer { text-align:left; } }',
            '.voffloadcfs-hero { background:#fff; border:1px solid #dcdcde; border-left:4px solid #2271b1; border-radius:10px; padding:18px 20px; margin:16px 0; box-shadow:0 1px 2px rgba(0,0,0,.03); }',
            '.voffloadcfs-hero h2 { margin:4px 0 8px; font-size:20px; line-height:1.3; max-width:980px; }',
            '.voffloadcfs-hero p { margin:0; color:#50575e; font-size:14px; line-height:1.55; max-width:1060px; }',
            '.voffloadcfs-kicker { text-transform:uppercase; letter-spacing:.08em; color:#646970 !important; font-size:11px !important; font-weight:700; }',
            '.voffloadcfs-workflow-line { margin:10px 0 0 !important; color:#646970 !important; font-size:13px !important; }',
            '.voffloadcfs-status-strip { display:flex; flex-wrap:wrap; gap:8px; margin:12px 0 0; }',
            '.voffloadcfs-status-pill { display:inline-flex; gap:6px; align-items:center; border:1px solid #dcdcde; background:#fff; border-radius:999px; padding:5px 9px; font-size:12px; color:#3c434a; }',
            '.voffloadcfs-status-dot { width:8px; height:8px; border-radius:999px; background:#dba617; flex:0 0 8px; }',
            '.voffloadcfs-status-dot.ok { background:#00a32a; }',
            '.voffloadcfs-status-dot.warning { background:#dba617; }',
            '.voffloadcfs-status-dot.error { background:#d63638; }',
            '.voffloadcfs-card { background:#fff; border:1px solid #dcdcde; border-radius:12px; padding:18px 20px; margin:16px 0; box-shadow:0 1px 2px rgba(0,0,0,.03); }',
            '.voffloadcfs-card h2 { margin-top:0; }',
            '.voffloadcfs-grid { display:grid; grid-template-columns:minmax(0,1fr) minmax(330px,430px); gap:18px; align-items:start; }',
            '.voffloadcfs-help { color:#646970; margin-top:4px; line-height:1.5; }',
            '.voffloadcfs-setup-help { background:#f6f7f7; border:1px solid #dcdcde; border-left:4px solid #2271b1; border-radius:8px; padding:12px 14px; margin:10px 0 18px; }',
            '.voffloadcfs-setup-help h3 { margin-top:0; }',
            '.voffloadcfs-setup-help summary { cursor:pointer; font-weight:600; font-size:14px; }',
            '.voffloadcfs-setup-help[open] summary { margin-bottom:8px; }',
            '.voffloadcfs-setup-help ol { margin-left:20px; }',
            '.voffloadcfs-field { margin:0 0 16px; padding-bottom:14px; border-bottom:1px solid #f0f0f1; }',
            '.voffloadcfs-field:last-of-type { border-bottom:0; padding-bottom:0; }',
            '.voffloadcfs-field label { display:block; font-weight:600; margin-bottom:5px; }',
            '.voffloadcfs-field input[type="text"], .voffloadcfs-field input[type="password"], .voffloadcfs-field textarea { width:100%; max-width:620px; border-radius:6px; }',
            '.voffloadcfs-token-line { display:block; width:100%; max-width:620px; box-sizing:border-box; margin:8px 0 6px; padding:9px 10px; white-space:normal; line-height:1.45; background:#f6f7f7; border:1px solid #dcdcde; border-radius:6px; }',
            '.voffloadcfs-badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; line-height:1.5; font-weight:600; background:#f0f0f1; color:#3c434a; }',
            '.voffloadcfs-badge.ready { background:#d1e7dd; color:#0f5132; }',
            '.voffloadcfs-badge.inprogress { background:#fff3cd; color:#664d03; }',
            '.voffloadcfs-badge.error { background:#f8d7da; color:#842029; }',
            '.voffloadcfs-badge.not-uploaded { background:#f0f0f1; color:#50575e; }',
            '.voffloadcfs-table { border-radius:8px; overflow:hidden; }',
            '.voffloadcfs-table td, .voffloadcfs-table th { vertical-align:middle; }',
            '.voffloadcfs-title { font-weight:600; }',
            '.voffloadcfs-small { color:#646970; font-size:12px; line-height:1.45; }',
            '.voffloadcfs-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:12px 0; }',
            '.voffloadcfs-action-groups { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin:12px 0 16px; }',
            '.voffloadcfs-action-note { background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:10px 12px; }',
            '.voffloadcfs-action-note strong { display:block; margin-bottom:3px; }',
            '.voffloadcfs-action-note span { color:#646970; font-size:12px; line-height:1.45; }',
            '.voffloadcfs-row-actions { margin-top:6px; }',
            '.voffloadcfs-sync-box { border:1px solid #c3c4c7; background:#f6f7f7; border-radius:10px; padding:14px; margin:12px 0 16px; }',
            '.voffloadcfs-sync-box p { margin:0 0 8px; }',
            '.voffloadcfs-sync-box .button { margin-top:4px; }',
            '.voffloadcfs-code { font-family:Consolas, Monaco, monospace; background:#f6f7f7; border:1px solid #dcdcde; padding:2px 5px; border-radius:4px; }',
            '.voffloadcfs-thumb { width:96px; max-height:54px; object-fit:cover; background:#f0f0f1; border-radius:6px; }',
            '.voffloadcfs-diagnostics { margin-top:18px; }',
            '.voffloadcfs-test-row { display:flex; align-items:flex-start; gap:9px; padding:8px 0; border-top:1px solid #f0f0f1; }',
            '.voffloadcfs-test-row:first-child { border-top:0; }',
            '.voffloadcfs-light { width:12px; height:12px; border-radius:999px; margin-top:4px; flex:0 0 12px; background:#8c8f94; box-shadow:0 0 0 2px rgba(0,0,0,.04); }',
            '.voffloadcfs-light.ok { background:#00a32a; }',
            '.voffloadcfs-light.warning { background:#dba617; }',
            '.voffloadcfs-light.error { background:#d63638; }',
            '.voffloadcfs-test-title { font-weight:600; }',
            '.voffloadcfs-test-message { color:#50575e; margin-top:2px; }',
            '.voffloadcfs-overall { display:inline-flex; align-items:center; gap:8px; font-weight:600; margin:4px 0 12px; }',
            '.voffloadcfs-overall .voffloadcfs-light { margin-top:0; }',
            '.voffloadcfs-danger { color:#b32d2e !important; border-color:#d63638 !important; }',
            '@media (max-width: 1100px) { .voffloadcfs-grid { grid-template-columns:1fr; } .voffloadcfs-action-groups { grid-template-columns:1fr; } }',
        ]);
    }

    public static function handle_admin_actions(): void {
        if (!is_admin() || !isset($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== 'voffload-cloudflare-stream') {
            return;
        }

        if (empty($_POST['voffloadcfs_action'])) {
            return;
        }

        if (!current_user_can(self::capability())) {
            wp_die(esc_html__('You are not allowed to manage Cloudflare Stream settings.', 'voffload-cloudflare-stream'));
        }

        check_admin_referer('voffloadcfs_bulk_action', 'voffloadcfs_nonce');

        $action = sanitize_key(wp_unslash($_POST['voffloadcfs_action']));
        $single_delete_id = isset($_POST['voffloadcfs_single_delete']) ? absint(wp_unslash($_POST['voffloadcfs_single_delete'])) : 0;
        if ($single_delete_id > 0) {
            $action = 'delete_cloudflare';
        }

        if ($action === 'connection_test') {
            $connection_result = self::run_connection_test();
            self::set_connection_test($connection_result);
            if (isset($connection_result['overall']) && $connection_result['overall'] === 'ok') {
                update_user_meta(get_current_user_id(), 'voffloadcfs_setup_help_collapsed', '1');
            } else {
                delete_user_meta(get_current_user_id(), 'voffloadcfs_setup_help_collapsed');
            }
            self::safe_redirect();
        }

        if ($action === 'sync_cloudflare') {
            self::set_notice([self::message_from_sync_result(self::sync_existing_stream_videos())]);
            self::safe_redirect();
        }

        $ids = isset($_POST['attachment_ids']) && is_array($_POST['attachment_ids'])
            ? array_values(array_unique(array_map('absint', wp_unslash($_POST['attachment_ids']))))
            : [];
        if ($single_delete_id > 0) {
            $ids = [$single_delete_id];
        }
        $manual_uid = isset($_POST['voffloadcfs_manual_uid']) ? sanitize_text_field((string) wp_unslash($_POST['voffloadcfs_manual_uid'])) : '';

        $messages = [];

        if ($action === 'attach_uid' && count($ids) !== 1) {
            $messages[] = ['type' => 'warning', 'text' => __('Select exactly one WordPress video attachment before attaching a manual Cloudflare Stream UID.', 'voffload-cloudflare-stream')];
            self::set_notice($messages);
            self::safe_redirect();
        }

        if (!$ids) {
            $messages[] = ['type' => 'warning', 'text' => __('No videos were selected.', 'voffload-cloudflare-stream')];
            self::set_notice($messages);
            self::safe_redirect();
        }

        foreach ($ids as $attachment_id) {
            if (!self::is_video_attachment($attachment_id)) {
                continue;
            }

            if ($action === 'upload') {
                $result = self::copy_to_stream($attachment_id, false);
                $messages[] = self::message_from_result($attachment_id, $result, __('queued for Cloudflare Stream upload', 'voffload-cloudflare-stream'));
            } elseif ($action === 'force_upload') {
                $result = self::copy_to_stream($attachment_id, true);
                $messages[] = self::message_from_result($attachment_id, $result, __('re-queued for Cloudflare Stream upload', 'voffload-cloudflare-stream'));
            } elseif ($action === 'refresh') {
                $result = self::refresh_stream_status($attachment_id);
                $messages[] = self::message_from_result($attachment_id, $result, __('status refreshed', 'voffload-cloudflare-stream'));
            } elseif ($action === 'reconnect') {
                $result = self::reconnect_attachment_from_cloudflare($attachment_id);
                $messages[] = self::message_from_result($attachment_id, $result, __('reconnected to an existing Cloudflare Stream video', 'voffload-cloudflare-stream'));
            } elseif ($action === 'attach_uid') {
                $result = self::attach_existing_stream_uid($attachment_id, $manual_uid);
                $messages[] = self::message_from_result($attachment_id, $result, __('attached to an existing Cloudflare Stream UID', 'voffload-cloudflare-stream'));
            } elseif ($action === 'delete_cloudflare') {
                $result = self::delete_stream_video($attachment_id);
                $messages[] = self::message_from_result($attachment_id, $result, __('deleted from Cloudflare Stream; local Stream metadata cleared', 'voffload-cloudflare-stream'));
            } elseif ($action === 'clear') {
                self::clear_stream_meta($attachment_id);
                $messages[] = ['type' => 'success', 'text' => sprintf(__('Attachment %d: local Cloudflare Stream metadata cleared. The video was not deleted from Cloudflare.', 'voffload-cloudflare-stream'), $attachment_id)];
            }
        }

        self::set_notice($messages);
        self::safe_redirect();
    }

    private static function set_notice(array $messages): void {
        set_transient('voffloadcfs_notice_' . get_current_user_id(), $messages, 60);
    }

    private static function set_connection_test(array $result): void {
        set_transient('voffloadcfs_connection_test_' . get_current_user_id(), $result, 30 * MINUTE_IN_SECONDS);
    }

    private static function get_connection_test(): ?array {
        $result = get_transient('voffloadcfs_connection_test_' . get_current_user_id());
        return is_array($result) ? $result : null;
    }

    private static function safe_redirect(): void {
        $url = remove_query_arg(['voffloadcfs_notice'], wp_get_referer() ?: admin_url('upload.php?page=voffload-cloudflare-stream'));
        wp_safe_redirect($url);
        exit;
    }

    private static function message_from_result(int $attachment_id, array $result, string $success_text): array {
        if (!empty($result['ok'])) {
            $text = !empty($result['message']) ? (string) $result['message'] : $success_text;
            return [
                'type' => 'success',
                'text' => sprintf(__('Attachment %1$d: %2$s.', 'voffload-cloudflare-stream'), $attachment_id, $text),
            ];
        }

        return [
            'type' => 'error',
            'text' => sprintf(__('Attachment %1$d: %2$s', 'voffload-cloudflare-stream'), $attachment_id, $result['message'] ?? __('Unknown error.', 'voffload-cloudflare-stream')),
        ];
    }

    private static function message_from_sync_result(array $result): array {
        if (empty($result['ok'])) {
            return [
                'type' => 'error',
                'text' => (string) ($result['message'] ?? __('Cloudflare Stream sync failed.', 'voffload-cloudflare-stream')),
            ];
        }

        return [
            'type' => 'success',
            'text' => sprintf(
                /* translators: 1: imported count, 2: refreshed count, 3: skipped count, 4: scanned count */
                __('Cloudflare Stream sync complete: %1$d imported, %2$d refreshed, %3$d skipped, %4$d scanned.', 'voffload-cloudflare-stream'),
                (int) ($result['imported'] ?? 0),
                (int) ($result['refreshed'] ?? 0),
                (int) ($result['skipped'] ?? 0),
                (int) ($result['scanned'] ?? 0)
            ),
        ];
    }

    public static function render_admin_page(): void {
        if (!current_user_can(self::capability())) {
            return;
        }

        $options = self::get_options();
        $paged = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
        $status_filter = isset($_GET['voffloadcfs_status']) ? sanitize_key(wp_unslash($_GET['voffloadcfs_status'])) : '';

        $query_args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'video',
            'posts_per_page' => 30,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if ($status_filter === 'ready') {
            $query_args['meta_query'] = [[
                'key'   => self::META_READY,
                'value' => '1',
            ]];
        } elseif ($status_filter === 'uploaded') {
            $query_args['meta_query'] = [[
                'key'     => self::META_UID,
                'compare' => 'EXISTS',
            ]];
        } elseif ($status_filter === 'not_uploaded') {
            $query_args['meta_query'] = [[
                'key'     => self::META_UID,
                'compare' => 'NOT EXISTS',
            ]];
        } elseif ($status_filter === 'error') {
            $query_args['meta_query'] = [[
                'key'   => self::META_STATE,
                'value' => 'error',
            ]];
        }

        $videos = new WP_Query($query_args);
        $notice = get_transient('voffloadcfs_notice_' . get_current_user_id());
        delete_transient('voffloadcfs_notice_' . get_current_user_id());

        $account_label = !empty($options['account_id']) ? __('Account ID set', 'voffload-cloudflare-stream') : __('Account ID missing', 'voffload-cloudflare-stream');
        $account_class = !empty($options['account_id']) ? 'ok' : 'error';
        $token_label = self::token_is_constant()
            ? __('Token set in wp-config.php', 'voffload-cloudflare-stream')
            : (!empty($options['api_token']) ? __('Token saved in settings', 'voffload-cloudflare-stream') : __('API token missing', 'voffload-cloudflare-stream'));
        $token_class = !empty($options['api_token']) ? 'ok' : 'error';
        $customer_label = !empty($options['customer_code']) ? __('Customer Code set', 'voffload-cloudflare-stream') : __('Customer Code missing', 'voffload-cloudflare-stream');
        $customer_class = !empty($options['customer_code']) ? 'ok' : 'warning';

        echo '<div class="wrap voffloadcfs-wrap">';
        echo '<h1>' . esc_html__('Cloudflare Stream Offloader', 'voffload-cloudflare-stream') . '</h1>';
        echo '<p class="description">' . esc_html__('Upload selected WordPress video attachments to Cloudflare Stream, reconnect existing Cloudflare Stream assets, and serve ready videos through the Cloudflare Stream player.', 'voffload-cloudflare-stream') . '</p>';

        echo '<section class="voffloadcfs-hero" aria-label="' . esc_attr__('Cloudflare Stream workflow overview', 'voffload-cloudflare-stream') . '">';
        echo '<p class="voffloadcfs-kicker">' . esc_html__('Media offload workflow', 'voffload-cloudflare-stream') . '</p>';
        echo '<h2>' . esc_html__('Move heavy video delivery away from WordPress without losing your local Media Library.', 'voffload-cloudflare-stream') . '</h2>';
        echo '<p>' . esc_html__('Use this screen to upload local WordPress video attachments, reconnect videos that already exist in Cloudflare Stream, refresh processing status, and remove remote Stream copies when you no longer need them. Your original WordPress media attachments are kept unless you delete them yourself in the Media Library.', 'voffload-cloudflare-stream') . '</p>';
        echo '<div class="voffloadcfs-status-strip">';
        echo '<span class="voffloadcfs-status-pill"><span class="voffloadcfs-status-dot ' . esc_attr($account_class) . '"></span>' . esc_html($account_label) . '</span>';
        echo '<span class="voffloadcfs-status-pill"><span class="voffloadcfs-status-dot ' . esc_attr($token_class) . '"></span>' . esc_html($token_label) . '</span>';
        echo '<span class="voffloadcfs-status-pill"><span class="voffloadcfs-status-dot ' . esc_attr($customer_class) . '"></span>' . esc_html($customer_label) . '</span>';
        echo '</div>';
        echo '<p class="voffloadcfs-workflow-line">' . esc_html__('Typical workflow: configure Cloudflare, upload or reconnect videos, then refresh status or delete remote Stream copies when needed.', 'voffload-cloudflare-stream') . '</p>';
        echo '</section>';

        if (is_array($notice)) {
            foreach ($notice as $item) {
                $type = isset($item['type']) ? sanitize_html_class((string) $item['type']) : 'info';
                $text = isset($item['text']) ? (string) $item['text'] : '';
                echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
            }
        }

        echo '<div class="voffloadcfs-grid">';
        echo '<div class="voffloadcfs-card">';
        self::render_video_table($videos, $status_filter);
        echo '</div>';

        echo '<div class="voffloadcfs-card">';
        self::render_settings_box($options);
        echo '</div>';
        echo '</div>';
        echo '<footer class="voffloadcfs-dashboard-footer" aria-label="' . esc_attr__('Plugin information', 'voffload-cloudflare-stream') . '">';
        echo esc_html(sprintf(__('Version %s · Copyright © 2026 Gerald Drißner · Licensed under GPLv2 or later.', 'voffload-cloudflare-stream'), self::VERSION));
        echo '</footer>';
        echo '</div>';
    }

    private static function render_settings_box(array $options): void {
        $token_saved = !empty($options['api_token']);
        $token_docs_url = 'https://developers.cloudflare.com/fundamentals/api/get-started/create-token/';
        $permissions_docs_url = 'https://developers.cloudflare.com/fundamentals/api/reference/permissions/';
        $player_docs_url = 'https://developers.cloudflare.com/stream/viewing-videos/using-the-stream-player/';

        $setup_collapsed = get_user_meta(get_current_user_id(), 'voffloadcfs_setup_help_collapsed', true) === '1';
        $setup_summary = $setup_collapsed
            ? __('Cloudflare setup checklist — connection successful; click to show instructions', 'voffload-cloudflare-stream')
            : __('Cloudflare setup checklist', 'voffload-cloudflare-stream');

        echo '<h2>' . esc_html__('Settings', 'voffload-cloudflare-stream') . '</h2>';
        echo '<details class="voffloadcfs-setup-help"' . ($setup_collapsed ? '' : ' open') . '>';
        echo '<summary>' . esc_html($setup_summary) . '</summary>';
        echo '<ol>';
        echo '<li>' . esc_html__('Open Cloudflare Dashboard → Manage Account → API Tokens and create a custom token.', 'voffload-cloudflare-stream') . '</li>';
        echo '<li>' . esc_html__('Set the token permission to Account → Stream → Edit. In some Cloudflare screens this is shown as Account → Stream → Write. Upload, sync, status refresh, and remote deletion use this scoped Stream permission. Scope the token to the one Cloudflare account used for Stream.', 'voffload-cloudflare-stream') . '</li>';
        echo '<li>' . esc_html__('Copy the token immediately after creation. Cloudflare only shows the token secret once.', 'voffload-cloudflare-stream') . '</li>';
        echo '<li>' . esc_html__('For playback, open Cloudflare Stream → Videos, copy any video embed code, and paste either the full iframe or the customer-CODE.cloudflarestream.com value into the Customer Code field below.', 'voffload-cloudflare-stream') . '</li>';
        echo '</ol>';
        echo '<p class="voffloadcfs-help">' . esc_html__('The plugin does not need DNS, Zone, Workers, Cache Purge, or Global API Key permissions.', 'voffload-cloudflare-stream') . '</p>';
        echo '<p class="voffloadcfs-help"><a href="' . esc_url($token_docs_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Cloudflare token creation guide', 'voffload-cloudflare-stream') . '</a> &nbsp;|&nbsp; <a href="' . esc_url($permissions_docs_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Cloudflare token permission reference', 'voffload-cloudflare-stream') . '</a> &nbsp;|&nbsp; <a href="' . esc_url($player_docs_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Cloudflare Stream player and Customer Code guide', 'voffload-cloudflare-stream') . '</a></p>';
        echo '</details>';

        echo '<form method="post" action="options.php">';
        settings_fields('voffloadcfs_settings');

        echo '<div class="voffloadcfs-field"><label for="voffloadcfs_account_id">' . esc_html__('Cloudflare Account ID', 'voffload-cloudflare-stream') . '</label>';
        echo '<input id="voffloadcfs_account_id" type="text" name="' . esc_attr(self::OPTION) . '[account_id]" value="' . esc_attr($options['account_id']) . '" autocomplete="off">';
        echo '<p class="voffloadcfs-help">' . esc_html__('Use the Account ID of the Cloudflare account where Stream is enabled. It is usually a 32-character value shown in the dashboard URL and account overview.', 'voffload-cloudflare-stream') . '</p></div>';

        echo '<div class="voffloadcfs-field"><label for="voffloadcfs_api_token">' . esc_html__('Cloudflare API Token', 'voffload-cloudflare-stream') . '</label>';
        if (self::token_is_constant()) {
            echo '<input id="voffloadcfs_api_token" type="password" value="" autocomplete="new-password" placeholder="' . esc_attr__('Defined in wp-config.php via VOFFLOADCFS_API_TOKEN', 'voffload-cloudflare-stream') . '" disabled>';
            echo '<p class="voffloadcfs-help">' . esc_html__('The API token is set by the VOFFLOADCFS_API_TOKEN constant in wp-config.php and is not stored in the database.', 'voffload-cloudflare-stream') . '</p></div>';
        } else {
            echo '<input id="voffloadcfs_api_token" type="password" name="' . esc_attr(self::OPTION) . '[api_token]" value="" autocomplete="new-password" placeholder="' . esc_attr($token_saved ? __('Token saved — leave blank to keep it', 'voffload-cloudflare-stream') : __('Paste a token with Account → Stream → Edit/Write', 'voffload-cloudflare-stream')) . '">';
            echo '<p class="voffloadcfs-help"><strong>' . esc_html__('Required permission:', 'voffload-cloudflare-stream') . '</strong> ' . esc_html__('Account → Stream → Edit. If your Cloudflare dashboard uses the newer label, choose Account → Stream → Write. Restrict Account Resources to the selected account.', 'voffload-cloudflare-stream') . '</p>';
            echo '<p class="voffloadcfs-help">' . esc_html__('For better security, add the full line below to wp-config.php and replace xxx with your token. When this constant exists, the plugin does not store the token in the WordPress database.', 'voffload-cloudflare-stream') . '</p>';
            echo '<code class="voffloadcfs-token-line">' . esc_html("define( 'VOFFLOADCFS_API_TOKEN', 'xxx' );") . '</code></div>';
        }

        echo '<div class="voffloadcfs-field"><label for="voffloadcfs_customer_code">' . esc_html__('Cloudflare Stream Customer Code', 'voffload-cloudflare-stream') . '</label>';
        echo '<input id="voffloadcfs_customer_code" type="text" name="' . esc_attr(self::OPTION) . '[customer_code]" value="' . esc_attr($options['customer_code']) . '" autocomplete="off" placeholder="' . esc_attr__('Example: qa5278df5riidxii', 'voffload-cloudflare-stream') . '">';
        echo '<p class="voffloadcfs-help"><strong>' . esc_html__('Where to find it:', 'voffload-cloudflare-stream') . '</strong> ' . esc_html__('Cloudflare Dashboard → Stream → Videos → open any video → copy the embed code. The Customer Code is the CODE in customer-CODE.cloudflarestream.com.', 'voffload-cloudflare-stream') . '</p>';
        echo '<p class="voffloadcfs-help">' . esc_html__('You may paste the bare code, the customer subdomain, the iframe URL, or the full iframe snippet. The plugin extracts the code on save.', 'voffload-cloudflare-stream') . '</p></div>';

        echo '<div class="voffloadcfs-field"><label for="voffloadcfs_allowed_origins">' . esc_html__('Allowed origins', 'voffload-cloudflare-stream') . '</label>';
        echo '<textarea id="voffloadcfs_allowed_origins" name="' . esc_attr(self::OPTION) . '[allowed_origins]" rows="4" placeholder="example.com&#10;example.org&#10;media.example.net">' . esc_textarea($options['allowed_origins']) . '</textarea>';
        echo '<p class="voffloadcfs-help">' . esc_html__('Optional. One hostname per line. Leave empty if you do not want Cloudflare origin restrictions for uploaded videos.', 'voffload-cloudflare-stream') . '</p></div>';

        echo '<div class="voffloadcfs-field"><label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[auto_replace_blocks]" value="1" ' . checked(1, (int) $options['auto_replace_blocks'], false) . '> ' . esc_html__('Automatically replace Gutenberg video blocks when a Stream UID exists', 'voffload-cloudflare-stream') . '</label></div>';
        echo '<div class="voffloadcfs-field"><label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[auto_replace_shortcode]" value="1" ' . checked(1, (int) $options['auto_replace_shortcode'], false) . '> ' . esc_html__('Automatically replace WordPress [video] shortcodes when a Stream UID exists', 'voffload-cloudflare-stream') . '</label></div>';
        echo '<div class="voffloadcfs-field"><label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[auto_replace_html]" value="1" ' . checked(1, (int) $options['auto_replace_html'], false) . '> ' . esc_html__('Automatically replace local video HTML and video-file links when a Stream UID exists', 'voffload-cloudflare-stream') . '</label>';
        echo '<p class="voffloadcfs-help">' . esc_html__('This catches classic-editor video tags, core/video blocks without an attachment ID, and direct links to local video files. It only replaces media that already has a saved Cloudflare Stream UID.', 'voffload-cloudflare-stream') . '</p></div>';

        submit_button(__('Save settings', 'voffload-cloudflare-stream'));
        echo '</form>';

        self::render_connection_test_box();

        echo '<hr>';
        echo '<h3>' . esc_html__('Shortcode', 'voffload-cloudflare-stream') . '</h3>';
        echo '<p><code>[voffloadcfs_video id="123"]</code> &nbsp; ' . esc_html__('or', 'voffload-cloudflare-stream') . ' &nbsp; <code>[voffloadcfs_video uid="VIDEO_UID"]</code></p>';
        echo '<p class="voffloadcfs-help">' . esc_html__('Use the WordPress attachment ID from the Media Library table, or use a Cloudflare Stream UID. The shortcode requires the Cloudflare Stream Customer Code setting to generate the official iframe URL.', 'voffload-cloudflare-stream') . '</p>';
    }

    private static function render_connection_test_box(): void {
        $result = self::get_connection_test();

        echo '<div class="voffloadcfs-diagnostics">';
        echo '<hr>';
        echo '<h3>' . esc_html__('Connection test', 'voffload-cloudflare-stream') . '</h3>';
        echo '<p class="voffloadcfs-help">' . esc_html__('Runs non-destructive Cloudflare API checks: token verification, account Stream access, playback Customer Code, and allowed-origin sanity checks. It does not upload a test video.', 'voffload-cloudflare-stream') . '</p>';

        if (is_array($result)) {
            $overall = isset($result['overall']) ? (string) $result['overall'] : 'warning';
            $label = isset($result['label']) ? (string) $result['label'] : __('Connection test completed', 'voffload-cloudflare-stream');
            echo '<div class="voffloadcfs-overall"><span class="voffloadcfs-light ' . esc_attr(sanitize_html_class($overall)) . '"></span><span>' . esc_html($label) . '</span></div>';

            if (!empty($result['checked_at'])) {
                echo '<p class="voffloadcfs-small">' . esc_html(sprintf(__('Last checked: %s', 'voffload-cloudflare-stream'), (string) $result['checked_at'])) . '</p>';
            }

            if (!empty($result['items']) && is_array($result['items'])) {
                foreach ($result['items'] as $item) {
                    $status = isset($item['status']) ? sanitize_html_class((string) $item['status']) : 'warning';
                    $title = isset($item['title']) ? (string) $item['title'] : '';
                    $message = isset($item['message']) ? (string) $item['message'] : '';
                    echo '<div class="voffloadcfs-test-row"><span class="voffloadcfs-light ' . esc_attr($status) . '"></span><div><div class="voffloadcfs-test-title">' . esc_html($title) . '</div>';
                    if ($message !== '') {
                        echo '<div class="voffloadcfs-test-message">' . esc_html($message) . '</div>';
                    }
                    echo '</div></div>';
                }
            }
        } else {
            echo '<div class="voffloadcfs-overall"><span class="voffloadcfs-light"></span><span>' . esc_html__('Not tested yet', 'voffload-cloudflare-stream') . '</span></div>';
        }

        echo '<form method="post">';
        wp_nonce_field('voffloadcfs_bulk_action', 'voffloadcfs_nonce');
        echo '<input type="hidden" name="voffloadcfs_action" value="connection_test">';
        submit_button(__('Test Cloudflare connection', 'voffload-cloudflare-stream'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';
    }

    private static function run_connection_test(): array {
        $options = self::get_options();
        $items = [];
        $has_error = false;
        $has_warning = false;

        $add = static function (string $status, string $title, string $message = '') use (&$items, &$has_error, &$has_warning): void {
            $items[] = ['status' => $status, 'title' => $title, 'message' => $message];
            if ($status === 'error') {
                $has_error = true;
            } elseif ($status === 'warning') {
                $has_warning = true;
            }
        };

        $account_id = trim((string) $options['account_id']);
        $api_token = trim((string) $options['api_token']);
        $customer_code = trim((string) $options['customer_code']);

        if ($account_id === '') {
            $add('error', __('Account ID', 'voffload-cloudflare-stream'), __('Missing Cloudflare Account ID.', 'voffload-cloudflare-stream'));
        } elseif (!preg_match('/^[a-f0-9]{32}$/i', $account_id)) {
            $add('warning', __('Account ID', 'voffload-cloudflare-stream'), __('Configured, but it does not look like the usual 32-character Cloudflare account identifier. The API test below is authoritative.', 'voffload-cloudflare-stream'));
        } else {
            $add('ok', __('Account ID', 'voffload-cloudflare-stream'), __('Looks like a valid Cloudflare account identifier.', 'voffload-cloudflare-stream'));
        }

        if ($api_token === '') {
            $add('error', __('API token', 'voffload-cloudflare-stream'), __('Missing API token. Add it in the settings or define VOFFLOADCFS_API_TOKEN in wp-config.php.', 'voffload-cloudflare-stream'));
        } else {
            $verify = self::api_request_absolute('GET', 'https://api.cloudflare.com/client/v4/user/tokens/verify');
            if (!empty($verify['ok'])) {
                $status = '';
                if (isset($verify['result']) && is_array($verify['result']) && !empty($verify['result']['status'])) {
                    $status = (string) $verify['result']['status'];
                }
                $add('ok', __('API token', 'voffload-cloudflare-stream'), $status ? sprintf(__('Token verified by Cloudflare. Status: %s.', 'voffload-cloudflare-stream'), $status) : __('Token verified by Cloudflare.', 'voffload-cloudflare-stream'));
            } else {
                $add('error', __('API token', 'voffload-cloudflare-stream'), (string) ($verify['message'] ?? __('Cloudflare token verification failed.', 'voffload-cloudflare-stream')));
            }
        }

        if ($account_id !== '' && $api_token !== '') {
            $stream = self::api_request('GET', '/stream?limit=1');
            if (!empty($stream['ok'])) {
                $count = '';
                if (isset($stream['result']) && is_array($stream['result'])) {
                    $count = sprintf(__('Response contains %d item(s) in this sample.', 'voffload-cloudflare-stream'), count($stream['result']));
                }
                $add('ok', __('Stream API access', 'voffload-cloudflare-stream'), $count ? sprintf(__('The token can access Cloudflare Stream for this account. %s', 'voffload-cloudflare-stream'), $count) : __('The token can access Cloudflare Stream for this account.', 'voffload-cloudflare-stream'));
            } else {
                $add('error', __('Stream API access', 'voffload-cloudflare-stream'), (string) ($stream['message'] ?? __('Could not list Stream videos for this account.', 'voffload-cloudflare-stream')));
            }
        } else {
            $add('warning', __('Stream API access', 'voffload-cloudflare-stream'), __('Skipped because Account ID or API token is missing.', 'voffload-cloudflare-stream'));
        }

        if ($customer_code === '') {
            $add('error', __('Customer Code / playback', 'voffload-cloudflare-stream'), __('Missing Cloudflare Stream Customer Code. Uploads can work without it, but shortcodes and frontend playback cannot generate iframe URLs.', 'voffload-cloudflare-stream'));
        } elseif (!preg_match('/^[a-z0-9-]+$/i', $customer_code)) {
            $add('warning', __('Customer Code / playback', 'voffload-cloudflare-stream'), __('Configured, but it contains unusual characters. It must be the CODE part from customer-CODE.cloudflarestream.com.', 'voffload-cloudflare-stream'));
        } else {
            $add('ok', __('Customer Code / playback', 'voffload-cloudflare-stream'), __('Configured. The plugin can build Cloudflare Stream iframe URLs.', 'voffload-cloudflare-stream'));
        }

        $allowed = self::allowed_origins_array();
        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $home_host = is_string($home_host) ? strtolower($home_host) : '';
        if (!$allowed) {
            $add('ok', __('Allowed origins', 'voffload-cloudflare-stream'), __('No origin restriction configured for newly uploaded videos.', 'voffload-cloudflare-stream'));
        } elseif ($home_host !== '' && in_array($home_host, array_map('strtolower', $allowed), true)) {
            $add('ok', __('Allowed origins', 'voffload-cloudflare-stream'), sprintf(__('The current site host %s is included.', 'voffload-cloudflare-stream'), $home_host));
        } else {
            $add('warning', __('Allowed origins', 'voffload-cloudflare-stream'), $home_host ? sprintf(__('The current site host %s is not listed. Playback may be blocked on this domain or on a www/non-www variant.', 'voffload-cloudflare-stream'), $home_host) : __('Could not determine the current site host.', 'voffload-cloudflare-stream'));
        }

        $upload_note = __('This test is intentionally non-destructive. It verifies token validity and Stream account access. It does not create a throwaway video, so the final Stream Write check still happens when you upload or re-upload a real video.', 'voffload-cloudflare-stream');
        $add($has_error ? 'warning' : 'ok', __('Upload permission note', 'voffload-cloudflare-stream'), $upload_note);

        $overall = $has_error ? 'error' : ($has_warning ? 'warning' : 'ok');
        $label = $overall === 'ok'
            ? __('Connection looks good', 'voffload-cloudflare-stream')
            : ($overall === 'warning' ? __('Connection works, but check warnings', 'voffload-cloudflare-stream') : __('Connection has errors', 'voffload-cloudflare-stream'));

        return [
            'overall'    => $overall,
            'label'      => $label,
            'checked_at' => current_time('mysql'),
            'items'      => $items,
        ];
    }

    private static function render_video_table(WP_Query $videos, string $status_filter): void {
        $base_url = admin_url('upload.php?page=voffload-cloudflare-stream');
        $filters = [
            ''             => __('All videos', 'voffload-cloudflare-stream'),
            'not_uploaded' => __('Not uploaded', 'voffload-cloudflare-stream'),
            'uploaded'     => __('Uploaded', 'voffload-cloudflare-stream'),
            'ready'        => __('Ready', 'voffload-cloudflare-stream'),
            'error'        => __('Errors', 'voffload-cloudflare-stream'),
        ];

        echo '<h2>' . esc_html__('Video Library', 'voffload-cloudflare-stream') . '</h2>';
        echo '<p class="voffloadcfs-help">' . esc_html__('This table shows video attachments from the WordPress Media Library. Select rows to upload them to Cloudflare Stream, reconnect already uploaded Stream videos, refresh processing status, or delete only the remote Stream copy.', 'voffload-cloudflare-stream') . '</p>';
        echo '<div class="voffloadcfs-sync-box">';
        echo '<p><strong>' . esc_html__('Already uploaded videos in Cloudflare Stream?', 'voffload-cloudflare-stream') . '</strong></p>';
        echo '<p class="voffloadcfs-help">' . esc_html__('This non-destructive sync lists videos in your Cloudflare Stream account and reconnects matching WordPress video attachments. It first uses the WordPress attachment ID stored in Stream metadata, then falls back to exact unique title/filename matches. It does not upload, delete, or overwrite existing local mappings.', 'voffload-cloudflare-stream') . '</p>';
        echo '<form method="post">';
        wp_nonce_field('voffloadcfs_bulk_action', 'voffloadcfs_nonce');
        echo '<button class="button" name="voffloadcfs_action" value="sync_cloudflare" onclick="return confirm(\'' . esc_js(__('This will import matching Cloudflare Stream metadata into WordPress. It will not delete or re-upload videos. Continue?', 'voffload-cloudflare-stream')) . '\');">' . esc_html__('Sync existing Cloudflare Stream videos', 'voffload-cloudflare-stream') . '</button>';
        echo '</form>';
        echo '</div>';

        echo '<div class="voffloadcfs-sync-box">';
        echo '<p><strong>' . esc_html__('Manual fallback', 'voffload-cloudflare-stream') . '</strong></p>';
        echo '<p class="voffloadcfs-help">' . esc_html__('If automatic reconnect cannot identify a unique match, copy the Stream UID from Cloudflare, select exactly one WordPress video attachment below, paste the UID here, and attach it. The plugin verifies the UID with Cloudflare before saving it.', 'voffload-cloudflare-stream') . '</p>';
        echo '<input type="text" form="voffloadcfs-video-actions" name="voffloadcfs_manual_uid" placeholder="' . esc_attr__('Cloudflare Stream UID', 'voffload-cloudflare-stream') . '" style="width:100%;max-width:360px;"> ';
        echo '<button class="button" form="voffloadcfs-video-actions" name="voffloadcfs_action" value="attach_uid">' . esc_html__('Attach UID to selected video', 'voffload-cloudflare-stream') . '</button>';
        echo '</div>';

        echo '<div class="voffloadcfs-action-groups">';
        echo '<div class="voffloadcfs-action-note"><strong>' . esc_html__('Upload selected', 'voffload-cloudflare-stream') . '</strong><span>' . esc_html__('Creates a Cloudflare Stream copy for selected local WordPress videos. The local attachment remains in WordPress.', 'voffload-cloudflare-stream') . '</span></div>';
        echo '<div class="voffloadcfs-action-note"><strong>' . esc_html__('Refresh / reconnect', 'voffload-cloudflare-stream') . '</strong><span>' . esc_html__('Refresh reads the current Stream processing state. Reconnect finds an existing Stream video when the local UID is missing.', 'voffload-cloudflare-stream') . '</span></div>';
        echo '<div class="voffloadcfs-action-note"><strong>' . esc_html__('Delete from Cloudflare', 'voffload-cloudflare-stream') . '</strong><span>' . esc_html__('Deletes the remote Stream asset after confirmation and clears local Stream metadata. It does not delete the WordPress attachment.', 'voffload-cloudflare-stream') . '</span></div>';
        echo '</div>';

        echo '<p class="subsubsub">';
        $links = [];
        foreach ($filters as $key => $label) {
            $url = $key === '' ? $base_url : add_query_arg('voffloadcfs_status', $key, $base_url);
            $class = $key === $status_filter ? ' class="current"' : '';
            $links[] = '<a href="' . esc_url($url) . '"' . $class . '>' . esc_html($label) . '</a>';
        }
        echo implode(' | ', $links);
        echo '</p><div style="clear:both"></div>';

        echo '<form method="post" id="voffloadcfs-video-actions">';
        wp_nonce_field('voffloadcfs_bulk_action', 'voffloadcfs_nonce');
        echo '<div class="voffloadcfs-actions">';
        echo '<button class="button button-primary" name="voffloadcfs_action" value="upload">' . esc_html__('Upload selected', 'voffload-cloudflare-stream') . '</button>';
        echo '<button class="button" name="voffloadcfs_action" value="refresh">' . esc_html__('Refresh selected status', 'voffload-cloudflare-stream') . '</button>';
        echo '<button class="button" name="voffloadcfs_action" value="reconnect">' . esc_html__('Reconnect selected from Cloudflare', 'voffload-cloudflare-stream') . '</button>';
        echo '<button class="button" name="voffloadcfs_action" value="force_upload" onclick="return confirm(\'' . esc_js(__('This will create a new Cloudflare Stream copy for selected videos even if they already have a UID. Continue?', 'voffload-cloudflare-stream')) . '\');">' . esc_html__('Force re-upload selected', 'voffload-cloudflare-stream') . '</button>';
        echo '<button class="button voffloadcfs-danger" name="voffloadcfs_action" value="delete_cloudflare" data-voffloadcfs-confirm="' . esc_attr__('This permanently deletes the selected videos from Cloudflare Stream and clears only the local Stream metadata in WordPress. The original WordPress media attachments remain. Continue?', 'voffload-cloudflare-stream') . '" onclick="return confirm(this.dataset.voffloadcfsConfirm);">' . esc_html__('Delete from Cloudflare', 'voffload-cloudflare-stream') . '</button>';
        echo '<button class="button" name="voffloadcfs_action" value="clear" onclick="return confirm(\'' . esc_js(__('This only clears local WordPress Stream metadata. It does not delete videos from Cloudflare. Continue?', 'voffload-cloudflare-stream')) . '\');">' . esc_html__('Clear local Stream metadata', 'voffload-cloudflare-stream') . '</button>';
        echo '</div>';

        echo '<table class="widefat striped voffloadcfs-table">';
        echo '<thead><tr>';
        echo '<td class="manage-column column-cb check-column"><input type="checkbox" class="voffloadcfs-check-all"></td>';
        echo '<th>' . esc_html__('Video', 'voffload-cloudflare-stream') . '</th>';
        echo '<th>' . esc_html__('Local file', 'voffload-cloudflare-stream') . '</th>';
        echo '<th>' . esc_html__('Cloudflare Stream', 'voffload-cloudflare-stream') . '</th>';
        echo '<th>' . esc_html__('Shortcode', 'voffload-cloudflare-stream') . '</th>';
        echo '</tr></thead><tbody>';

        if ($videos->have_posts()) {
            foreach ($videos->posts as $post) {
                self::render_video_row($post);
            }
        } else {
            echo '<tr><td colspan="5">' . esc_html__('No video attachments found for this filter.', 'voffload-cloudflare-stream') . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '<div class="voffloadcfs-actions">';
        echo '<button class="button button-primary" name="voffloadcfs_action" value="upload">' . esc_html__('Upload selected', 'voffload-cloudflare-stream') . '</button>';
        echo '<button class="button" name="voffloadcfs_action" value="refresh">' . esc_html__('Refresh selected status', 'voffload-cloudflare-stream') . '</button>';
        echo '<button class="button" name="voffloadcfs_action" value="reconnect">' . esc_html__('Reconnect selected from Cloudflare', 'voffload-cloudflare-stream') . '</button>';
        echo '<button class="button voffloadcfs-danger" name="voffloadcfs_action" value="delete_cloudflare" data-voffloadcfs-confirm="' . esc_attr__('This permanently deletes the selected videos from Cloudflare Stream and clears only the local Stream metadata in WordPress. The original WordPress media attachments remain. Continue?', 'voffload-cloudflare-stream') . '" onclick="return confirm(this.dataset.voffloadcfsConfirm);">' . esc_html__('Delete from Cloudflare', 'voffload-cloudflare-stream') . '</button>';
        echo '</div>';
        echo '</form>';

        $total_pages = max(1, (int) $videos->max_num_pages);
        if ($total_pages > 1) {
            $pagination = paginate_links([
                'base'      => add_query_arg('paged', '%#%', $base_url . ($status_filter ? '&voffloadcfs_status=' . rawurlencode($status_filter) : '')),
                'format'    => '',
                'current'   => isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1,
                'total'     => $total_pages,
                'type'      => 'list',
            ]);
            if ($pagination) {
                echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post($pagination) . '</div></div>';
            }
        }
    }

    private static function render_video_row(WP_Post $post): void {
        $id = (int) $post->ID;
        $url = wp_get_attachment_url($id);
        $file = get_attached_file($id);
        $uid = (string) get_post_meta($id, self::META_UID, true);
        $thumbnail = (string) get_post_meta($id, self::META_THUMBNAIL, true);
        $edit_link = get_edit_post_link($id);
        $status_html = self::status_badge($id);
        $size = ($file && file_exists($file)) ? size_format((int) filesize($file)) : '';

        echo '<tr>';
        echo '<th scope="row" class="check-column"><input type="checkbox" name="attachment_ids[]" value="' . esc_attr((string) $id) . '"></th>';
        echo '<td>';
        if ($thumbnail) {
            echo '<img class="voffloadcfs-thumb" src="' . esc_url($thumbnail) . '" alt=""> ';
        }
        echo '<div class="voffloadcfs-title"><a href="' . esc_url($edit_link ?: '#') . '">' . esc_html(get_the_title($id) ?: basename((string) $url)) . '</a></div>';
        echo '<div class="voffloadcfs-small">ID ' . esc_html((string) $id) . ' · ' . esc_html(get_post_mime_type($id) ?: '') . '</div>';
        echo '</td>';
        echo '<td>';
        if ($url) {
            echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html__('Open local file', 'voffload-cloudflare-stream') . '</a>';
        }
        if ($size) {
            echo '<div class="voffloadcfs-small">' . esc_html($size) . '</div>';
        }
        echo '</td>';
        echo '<td>' . wp_kses_post($status_html);
        if ($uid) {
            echo '<div class="voffloadcfs-small">UID: <span class="voffloadcfs-code">' . esc_html($uid) . '</span></div>';
            echo '<div class="voffloadcfs-row-actions"><button type="submit" class="button button-small voffloadcfs-danger" name="voffloadcfs_single_delete" value="' . esc_attr((string) $id) . '" data-voffloadcfs-confirm="' . esc_attr__('This permanently deletes this video from Cloudflare Stream and clears only the local Stream metadata in WordPress. The original WordPress media attachment remains. Continue?', 'voffload-cloudflare-stream') . '" onclick="return confirm(this.dataset.voffloadcfsConfirm);">' . esc_html__('Delete from Cloudflare', 'voffload-cloudflare-stream') . '</button></div>';
        }
        $error = (string) get_post_meta($id, self::META_ERROR, true);
        if ($error) {
            echo '<div class="voffloadcfs-small">' . esc_html($error) . '</div>';
        }
        echo '</td>';
        echo '<td><code>[voffloadcfs_video id=&quot;' . esc_html((string) $id) . '&quot;]</code>';
        if ($uid) {
            echo '<div class="voffloadcfs-small"><code>[voffloadcfs_video uid=&quot;' . esc_html($uid) . '&quot;]</code></div>';
        }
        echo '</td>';
        echo '</tr>';
    }

    private static function status_badge(int $attachment_id): string {
        $uid = (string) get_post_meta($attachment_id, self::META_UID, true);
        if ($uid === '') {
            return '<span class="voffloadcfs-badge not-uploaded">' . esc_html__('Not uploaded', 'voffload-cloudflare-stream') . '</span>';
        }

        $ready = (string) get_post_meta($attachment_id, self::META_READY, true) === '1';
        $state = (string) get_post_meta($attachment_id, self::META_STATE, true);
        $pct = (string) get_post_meta($attachment_id, self::META_PCT, true);

        if ($ready) {
            return '<span class="voffloadcfs-badge ready">' . esc_html__('Ready', 'voffload-cloudflare-stream') . '</span>';
        }
        if ($state === 'error') {
            return '<span class="voffloadcfs-badge error">' . esc_html__('Error', 'voffload-cloudflare-stream') . '</span>';
        }

        $label = $state ? ucfirst($state) : __('Processing', 'voffload-cloudflare-stream');
        if ($pct !== '') {
            $label .= ' ' . $pct . '%';
        }
        return '<span class="voffloadcfs-badge inprogress">' . esc_html($label) . '</span>';
    }

    private static function is_configured(): bool {
        $options = self::get_options();
        return !empty($options['account_id']) && !empty($options['api_token']);
    }

    private static function api_request_absolute(string $method, string $url, ?array $body = null): array {
        $options = self::get_options();
        $api_token = trim((string) $options['api_token']);

        if ($api_token === '') {
            return ['ok' => false, 'message' => __('Cloudflare API token is missing.', 'voffload-cloudflare-stream')];
        }

        $args = [
            'method'  => strtoupper($method),
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'User-Agent'    => 'Voffload for Cloudflare Stream/' . self::VERSION . '; ' . home_url('/'),
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        if (!is_array($json)) {
            return ['ok' => false, 'message' => sprintf(__('Unexpected Cloudflare response. HTTP %d.', 'voffload-cloudflare-stream'), $code), 'raw' => $raw, 'code' => $code];
        }

        if ($code < 200 || $code >= 300 || empty($json['success'])) {
            return ['ok' => false, 'message' => self::cloudflare_error_message($json, $code), 'response' => $json, 'code' => $code];
        }

        return ['ok' => true, 'result' => $json['result'] ?? null, 'response' => $json, 'code' => $code];
    }

    private static function api_request(string $method, string $path, ?array $body = null): array {
        $options = self::get_options();
        $account_id = trim((string) $options['account_id']);
        $api_token = trim((string) $options['api_token']);

        if ($account_id === '' || $api_token === '') {
            return ['ok' => false, 'message' => __('Cloudflare Account ID or API token is missing.', 'voffload-cloudflare-stream')];
        }

        $url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($account_id) . $path;
        $args = [
            'method'  => strtoupper($method),
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'User-Agent'    => 'Voffload for Cloudflare Stream/' . self::VERSION . '; ' . home_url('/'),
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        if (!is_array($json)) {
            return ['ok' => false, 'message' => sprintf(__('Unexpected Cloudflare response. HTTP %d.', 'voffload-cloudflare-stream'), $code), 'raw' => $raw, 'code' => $code];
        }

        if ($code < 200 || $code >= 300 || empty($json['success'])) {
            $message = self::cloudflare_error_message($json, $code);
            return ['ok' => false, 'message' => $message, 'response' => $json, 'code' => $code];
        }

        return ['ok' => true, 'result' => $json['result'] ?? null, 'response' => $json, 'code' => $code];
    }

    private static function cloudflare_error_message(array $json, int $code): string {
        $parts = [];
        if (!empty($json['errors']) && is_array($json['errors'])) {
            foreach ($json['errors'] as $error) {
                if (is_array($error)) {
                    $err_code = isset($error['code']) ? (string) $error['code'] . ': ' : '';
                    $parts[] = $err_code . (string) ($error['message'] ?? __('Unknown Cloudflare error', 'voffload-cloudflare-stream'));
                }
            }
        }
        if (!$parts) {
            $parts[] = sprintf(__('Cloudflare API request failed. HTTP %d.', 'voffload-cloudflare-stream'), $code);
        }
        return implode(' | ', $parts);
    }

    public static function copy_to_stream(int $attachment_id, bool $force = false): array {
        if (!self::is_video_attachment($attachment_id)) {
            return ['ok' => false, 'message' => __('Selected attachment is not a video.', 'voffload-cloudflare-stream')];
        }

        if (!self::is_configured()) {
            return ['ok' => false, 'message' => __('Cloudflare settings are incomplete.', 'voffload-cloudflare-stream')];
        }

        $existing_uid = (string) get_post_meta($attachment_id, self::META_UID, true);
        if ($existing_uid !== '' && !$force) {
            return ['ok' => true, 'message' => __('Already has a Stream UID. Skipped.', 'voffload-cloudflare-stream')];
        }

        $url = wp_get_attachment_url($attachment_id);
        if (!$url || !wp_http_validate_url($url)) {
            return ['ok' => false, 'message' => __('Attachment URL is missing or invalid.', 'voffload-cloudflare-stream')];
        }

        $title = get_the_title($attachment_id);
        if ($title === '') {
            $title = basename((string) parse_url($url, PHP_URL_PATH));
        }

        $meta = [
            'name' => $title,
            'wordpress_attachment_id' => (string) $attachment_id,
            'wordpress_site' => home_url('/'),
        ];
        $allowed = self::allowed_origins_array();

        // 1) Always try Cloudflare URL-copy first. A local HEAD/GET preflight
        //    is useful as a diagnostic, but it must not gate the real copy
        //    request: some servers reject HEAD or local loopback checks while
        //    still allowing Cloudflare to fetch the media URL normally. This is
        //    especially important for large files, where direct upload is not a
        //    safe fallback inside a synchronous WordPress admin request.
        $preflight_ok = self::media_url_reachable($url);

        $payload = ['url' => $url, 'meta' => $meta];
        if ($allowed) {
            $payload['allowedOrigins'] = $allowed;
        }

        $api = self::api_request('POST', '/stream/copy', $payload);
        if (!empty($api['ok'])) {
            return self::finalize_upload($attachment_id, $api['result'] ?? [], 'url_copy');
        }

        // Fall through to direct upload only after the real /stream/copy call failed.
        $copy_error = (string) ($api['message'] ?? __('URL copy failed.', 'voffload-cloudflare-stream'));
        if (!$preflight_ok) {
            $copy_error .= ' ' . __('Local preflight also reported that the public media URL is not reachable.', 'voffload-cloudflare-stream');
        }

        // 2) Fallback: server-side direct upload (multipart, <200 MB).
        $direct = self::direct_upload($attachment_id, $meta, $allowed);
        if (!empty($direct['ok'])) {
            return self::finalize_upload($attachment_id, $direct['result'] ?? [], 'direct');
        }

        $message = sprintf(
            /* translators: 1: URL-copy error, 2: direct-upload error */
            __('URL copy failed (%1$s) and direct upload failed (%2$s).', 'voffload-cloudflare-stream'),
            $copy_error,
            (string) ($direct['message'] ?? __('unknown', 'voffload-cloudflare-stream'))
        );
        update_post_meta($attachment_id, self::META_STATE, 'error');
        update_post_meta($attachment_id, self::META_ERROR, $message);
        return ['ok' => false, 'message' => $message];
    }

    /**
     * Persist a successful upload result and (re)schedule the poll cron.
     */
    private static function finalize_upload(int $attachment_id, $result, string $method): array {
        $result = is_array($result) ? $result : [];
        $uid = isset($result['uid']) ? sanitize_text_field((string) $result['uid']) : '';
        if ($uid === '') {
            return ['ok' => false, 'message' => __('Cloudflare response did not contain a video UID.', 'voffload-cloudflare-stream')];
        }

        update_post_meta($attachment_id, self::META_UID, $uid);
        update_post_meta($attachment_id, self::META_METHOD, $method);
        update_post_meta($attachment_id, self::META_STATE, self::extract_state($result));
        update_post_meta($attachment_id, self::META_READY, !empty($result['readyToStream']) ? '1' : '0');
        update_post_meta($attachment_id, self::META_ERROR, '');
        update_post_meta($attachment_id, self::META_UPLOADED_AT, current_time('mysql'));
        self::store_result_meta($attachment_id, $result);

        // A fresh upload is "pending" until ready — make sure the poll cron runs.
        if (empty($result['readyToStream'])) {
            self::ensure_cron_scheduled();
        }

        return ['ok' => true, 'uid' => $uid, 'result' => $result, 'method' => $method];
    }

    /**
     * Cheap diagnostic reachability heuristic for the URL-copy path. This does
     * not decide whether URL-copy is attempted; the real Cloudflare copy call
     * is always attempted first.
     */
    private static function media_url_reachable(string $url): bool {
        $resp = wp_remote_head($url, [
            'timeout'     => 8,
            'redirection' => 2,
            'sslverify'   => true,
            'headers'     => ['User-Agent' => 'Cloudflare-Stream-Copy-Preflight'],
        ]);
        if (is_wp_error($resp)) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        return $code >= 200 && $code < 300;
    }

    /**
     * Server-side direct (multipart) upload, used when Cloudflare cannot
     * fetch the public URL. Limited by Cloudflare to <200 MB single request;
     * larger files require the tus protocol, which is out of scope here.
     */
    private static function direct_upload(int $attachment_id, array $meta, array $allowed): array {
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file) || !is_readable($file)) {
            return ['ok' => false, 'message' => __('Local file is not available for direct upload.', 'voffload-cloudflare-stream')];
        }

        $size = (int) filesize($file);
        if ($size <= 0) {
            return ['ok' => false, 'message' => __('Local file is empty.', 'voffload-cloudflare-stream')];
        }
        if ($size >= self::DIRECT_UPLOAD_MAX_BYTES) {
            return ['ok' => false, 'message' => sprintf(
                /* translators: %s: file size */
                __('File is %s; direct upload supports under 200 MB. Make the media URL publicly reachable so URL copy can be used, or upload this file to Cloudflare with a tus client.', 'voffload-cloudflare-stream'),
                size_format($size)
            )];
        }

        $memory_limit = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
        if ($memory_limit > 0) {
            $available = $memory_limit - memory_get_usage(true) - (8 * MB_IN_BYTES);
            if ($available > 0 && $size > $available) {
                return ['ok' => false, 'message' => sprintf(
                    /* translators: 1: file size, 2: PHP memory limit */
                    __('File is %1$s, but PHP memory_limit is %2$s. Make the media URL publicly reachable so URL copy can be used, or raise PHP memory for direct upload fallback.', 'voffload-cloudflare-stream'),
                    size_format($size),
                    size_format($memory_limit)
                )];
            }
        }

        $options = self::get_options();
        $account_id = trim((string) $options['account_id']);
        $api_token  = trim((string) $options['api_token']);
        if ($account_id === '' || $api_token === '') {
            return ['ok' => false, 'message' => __('Cloudflare Account ID or API token is missing.', 'voffload-cloudflare-stream')];
        }

        $body = file_get_contents($file);
        if ($body === false) {
            return ['ok' => false, 'message' => __('Could not read local file for upload.', 'voffload-cloudflare-stream')];
        }

        $boundary = 'voffloadcfs' . wp_generate_password(20, false);
        $eol = "\r\n";
        $filename = str_replace(["\r", "\n", '"'], '_', basename($file));
        $mime = preg_replace('/[^a-z0-9+_.\/-]/i', '', (string) (get_post_mime_type($attachment_id) ?: 'application/octet-stream'));
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        $parts = '--' . $boundary . $eol;
        $parts .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . $eol;
        $parts .= 'Content-Type: ' . $mime . $eol . $eol;
        $parts .= $body . $eol;

        // Pass metadata + allowed origins as documented form fields.
        $form_fields = ['meta' => wp_json_encode($meta)];
        if ($allowed) {
            $form_fields['allowedOrigins'] = implode(',', $allowed);
        }
        foreach ($form_fields as $name => $value) {
            $parts .= '--' . $boundary . $eol;
            $parts .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
            $parts .= $value . $eol;
        }
        $parts .= '--' . $boundary . '--' . $eol;

        $url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($account_id) . '/stream';
        $response = wp_remote_post($url, [
            'method'  => 'POST',
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
                'Accept'        => 'application/json',
                'User-Agent'    => 'Voffload for Cloudflare Stream/' . self::VERSION . '; ' . home_url('/'),
            ],
            'body'    => $parts,
        ]);
        unset($parts, $body);

        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($json)) {
            return ['ok' => false, 'message' => sprintf(__('Unexpected Cloudflare response. HTTP %d.', 'voffload-cloudflare-stream'), $code)];
        }
        if ($code < 200 || $code >= 300 || empty($json['success'])) {
            return ['ok' => false, 'message' => self::cloudflare_error_message($json, $code)];
        }

        return ['ok' => true, 'result' => $json['result'] ?? []];
    }

    private static function sync_existing_stream_videos(): array {
        if (!self::is_configured()) {
            return ['ok' => false, 'message' => __('Cloudflare settings are incomplete.', 'voffload-cloudflare-stream')];
        }

        $scanned = 0;
        $imported = 0;
        $refreshed = 0;
        $skipped = 0;
        $before = '';
        $seen_uids = [];
        $local_index = self::local_video_index_for_sync();

        // Cloudflare returns up to 1000 Stream videos per list request. Use the
        // created timestamp of the last item as a cursor for older videos and
        // cap admin-request work to avoid timeouts on very large libraries.
        for ($page = 0; $page < 10; $page++) {
            $path = '/stream?limit=1000';
            if ($before !== '') {
                $path .= '&before=' . rawurlencode($before);
            }

            $api = self::api_request('GET', $path);
            if (empty($api['ok'])) {
                return [
                    'ok'       => false,
                    'message'  => (string) ($api['message'] ?? __('Could not list Cloudflare Stream videos.', 'voffload-cloudflare-stream')),
                    'scanned'  => $scanned,
                    'imported' => $imported,
                    'refreshed'=> $refreshed,
                    'skipped'  => $skipped,
                ];
            }

            $items = is_array($api['result'] ?? null) ? $api['result'] : [];
            if (!$items) {
                break;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    $skipped++;
                    continue;
                }

                $scanned++;
                $uid = isset($item['uid']) ? sanitize_text_field((string) $item['uid']) : '';
                if ($uid === '' || isset($seen_uids[$uid])) {
                    $skipped++;
                    continue;
                }
                $seen_uids[$uid] = true;

                $attachment_id = self::attachment_id_from_stream_result($item);
                if ($attachment_id <= 0 || !self::is_video_attachment($attachment_id)) {
                    $attachment_id = self::attachment_id_from_local_index($item, $local_index);
                }
                if ($attachment_id <= 0 || !self::is_video_attachment($attachment_id)) {
                    $skipped++;
                    continue;
                }

                if (!self::stream_result_matches_current_site($item)) {
                    $skipped++;
                    continue;
                }

                $existing_uid = (string) get_post_meta($attachment_id, self::META_UID, true);
                $import = self::import_stream_result_for_attachment($attachment_id, $item, 'imported');
                if (empty($import['ok'])) {
                    $skipped++;
                    continue;
                }

                if ($existing_uid === $uid) {
                    $refreshed++;
                } else {
                    $imported++;
                }
            }

            if (count($items) < 1000) {
                break;
            }

            $last = end($items);
            $next_before = is_array($last) && !empty($last['created']) ? (string) $last['created'] : '';
            if ($next_before === '' || $next_before === $before) {
                break;
            }
            $before = $next_before;
        }

        return [
            'ok'       => true,
            'scanned'  => $scanned,
            'imported' => $imported,
            'refreshed'=> $refreshed,
            'skipped'  => $skipped,
        ];
    }

    private static function local_video_index_for_sync(): array {
        $query = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'video',
            'posts_per_page' => 5000,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'cache_results'  => false,
        ]);

        $index = [];
        foreach ($query->posts as $attachment_id) {
            $attachment_id = absint($attachment_id);
            if ($attachment_id <= 0) {
                continue;
            }
            foreach (self::local_video_match_keys($attachment_id) as $key) {
                if (!isset($index[$key])) {
                    $index[$key] = [];
                }
                $index[$key][$attachment_id] = true;
            }
        }

        return $index;
    }

    private static function attachment_id_from_local_index(array $result, array $local_index): int {
        $candidates = [];
        foreach (self::stream_video_match_keys($result) as $key) {
            if (empty($local_index[$key]) || !is_array($local_index[$key])) {
                continue;
            }
            foreach ($local_index[$key] as $attachment_id => $_unused) {
                $attachment_id = absint($attachment_id);
                if ($attachment_id > 0) {
                    $candidates[$attachment_id] = true;
                }
            }
        }

        if (count($candidates) !== 1) {
            return 0;
        }

        return (int) array_key_first($candidates);
    }

    private static function attachment_id_from_stream_result(array $result): int {
        $meta = isset($result['meta']) && is_array($result['meta']) ? $result['meta'] : [];
        if (empty($meta['wordpress_attachment_id'])) {
            return 0;
        }

        return absint($meta['wordpress_attachment_id']);
    }

    private static function stream_result_matches_current_site(array $result): bool {
        $meta = isset($result['meta']) && is_array($result['meta']) ? $result['meta'] : [];
        if (empty($meta['wordpress_site'])) {
            return true;
        }

        $video_host = wp_parse_url((string) $meta['wordpress_site'], PHP_URL_HOST);
        $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (!is_string($video_host) || !is_string($site_host) || $video_host === '' || $site_host === '') {
            return true;
        }

        return strtolower($video_host) === strtolower($site_host);
    }

    private static function local_video_match_keys(int $attachment_id): array {
        $items = [];
        $title = get_the_title($attachment_id);
        if (is_string($title) && $title !== '') {
            $items[] = $title;
        }

        $url = wp_get_attachment_url($attachment_id);
        if (is_string($url) && $url !== '') {
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ($path !== '') {
                $items[] = wp_basename(rawurldecode($path));
            }
        }

        $file = get_attached_file($attachment_id);
        if (is_string($file) && $file !== '') {
            $items[] = wp_basename($file);
        }

        return self::normalize_match_keys($items);
    }

    private static function stream_video_match_keys(array $result): array {
        $items = [];

        if (!empty($result['meta']) && is_array($result['meta'])) {
            foreach (['name', 'filename', 'file_name', 'title', 'wordpress_attachment_title'] as $key) {
                if (!empty($result['meta'][$key])) {
                    $items[] = (string) $result['meta'][$key];
                }
            }
        }

        foreach (['name', 'filename', 'fileName', 'title'] as $key) {
            if (!empty($result[$key])) {
                $items[] = (string) $result[$key];
            }
        }

        if (!empty($result['input']) && is_array($result['input'])) {
            foreach (['name', 'filename', 'fileName'] as $key) {
                if (!empty($result['input'][$key])) {
                    $items[] = (string) $result['input'][$key];
                }
            }
        }

        return self::normalize_match_keys($items);
    }

    private static function normalize_match_keys(array $items): array {
        $keys = [];
        foreach ($items as $item) {
            $raw = trim((string) $item);
            if ($raw === '') {
                continue;
            }

            $path = (string) wp_parse_url($raw, PHP_URL_PATH);
            if ($path !== '') {
                $raw = wp_basename(rawurldecode($path));
            }

            $raw = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
            $raw = remove_accents($raw);
            $raw = strtolower($raw);
            $raw = preg_replace('/\?.*$/', '', $raw);
            $raw = trim((string) $raw);

            if ($raw !== '') {
                $keys[$raw] = true;
            }

            $without_ext = preg_replace('/\.[a-z0-9]{1,8}$/', '', $raw);
            $without_ext = trim((string) $without_ext);
            if ($without_ext !== '' && $without_ext !== $raw) {
                $keys[$without_ext] = true;
            }

            $slug = preg_replace('/[^a-z0-9]+/', ' ', $without_ext ?: $raw);
            $slug = trim((string) preg_replace('/\s+/', ' ', (string) $slug));
            if ($slug !== '') {
                $keys[$slug] = true;
            }
        }

        return array_keys($keys);
    }

    private static function stream_result_matches_attachment(array $result, int $attachment_id): bool {
        $remote_attachment_id = self::attachment_id_from_stream_result($result);
        if ($remote_attachment_id > 0) {
            return $remote_attachment_id === $attachment_id;
        }

        $local = self::local_video_match_keys($attachment_id);
        $remote = self::stream_video_match_keys($result);
        if (!$local || !$remote) {
            return false;
        }

        return (bool) array_intersect($local, $remote);
    }

    private static function import_stream_result_for_attachment(int $attachment_id, array $item, string $method = 'imported'): array {
        $uid = isset($item['uid']) ? sanitize_text_field((string) $item['uid']) : '';
        if ($uid === '') {
            return ['ok' => false, 'message' => __('Cloudflare Stream result did not contain a UID.', 'voffload-cloudflare-stream')];
        }

        if (!self::stream_result_matches_current_site($item)) {
            return ['ok' => false, 'message' => __('A possible Cloudflare Stream match belongs to a different WordPress site.', 'voffload-cloudflare-stream')];
        }

        $existing_uid = (string) get_post_meta($attachment_id, self::META_UID, true);
        if ($existing_uid !== '' && $existing_uid !== $uid) {
            return ['ok' => false, 'message' => __('This attachment already has a different Stream UID. Clear local Stream metadata first if you want to reconnect it.', 'voffload-cloudflare-stream')];
        }

        update_post_meta($attachment_id, self::META_UID, $uid);
        update_post_meta($attachment_id, self::META_METHOD, $method);
        self::store_result_meta($attachment_id, $item);
        update_post_meta($attachment_id, self::META_CHECKED_AT, current_time('mysql'));
        if ($existing_uid === '' && !empty($item['created'])) {
            update_post_meta($attachment_id, self::META_UPLOADED_AT, sanitize_text_field((string) $item['created']));
        }

        return ['ok' => true, 'uid' => $uid, 'result' => $item];
    }

    private static function search_stream_videos_by_name(string $name): array {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => true, 'result' => []];
        }

        return self::api_request('GET', '/stream?limit=1000&search=' . rawurlencode($name));
    }

    public static function reconnect_attachment_from_cloudflare(int $attachment_id): array {
        if (!self::is_video_attachment($attachment_id)) {
            return ['ok' => false, 'message' => __('Selected attachment is not a video.', 'voffload-cloudflare-stream')];
        }

        if (!self::is_configured()) {
            return ['ok' => false, 'message' => __('Cloudflare settings are incomplete.', 'voffload-cloudflare-stream')];
        }

        $existing_uid = (string) get_post_meta($attachment_id, self::META_UID, true);
        if ($existing_uid !== '') {
            return self::refresh_stream_status($attachment_id);
        }

        $queries = [];
        $title = get_the_title($attachment_id);
        if (is_string($title) && $title !== '') {
            $queries[] = $title;
        }
        $url = wp_get_attachment_url($attachment_id);
        if (is_string($url) && $url !== '') {
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ($path !== '') {
                $queries[] = wp_basename(rawurldecode($path));
            }
        }
        $file = get_attached_file($attachment_id);
        if (is_string($file) && $file !== '') {
            $queries[] = wp_basename($file);
        }
        $queries = array_values(array_unique(array_filter(array_map('trim', $queries))));

        if (!$queries) {
            return ['ok' => false, 'message' => __('No local title or filename is available for Cloudflare Stream search.', 'voffload-cloudflare-stream')];
        }

        $matches = [];
        $seen = [];
        foreach ($queries as $query) {
            $api = self::search_stream_videos_by_name($query);
            if (empty($api['ok'])) {
                return $api;
            }

            $items = is_array($api['result'] ?? null) ? $api['result'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $uid = isset($item['uid']) ? sanitize_text_field((string) $item['uid']) : '';
                if ($uid === '' || isset($seen[$uid])) {
                    continue;
                }
                $seen[$uid] = true;

                if (!self::stream_result_matches_current_site($item)) {
                    continue;
                }
                if (!self::stream_result_matches_attachment($item, $attachment_id)) {
                    continue;
                }
                $matches[$uid] = $item;
            }
        }

        if (!$matches) {
            return ['ok' => false, 'message' => __('No unique matching Cloudflare Stream video was found by attachment metadata, title, or filename.', 'voffload-cloudflare-stream')];
        }

        if (count($matches) > 1) {
            return ['ok' => false, 'message' => sprintf(__('More than one possible Cloudflare Stream match was found (%d). Reconnection was skipped to avoid linking the wrong video.', 'voffload-cloudflare-stream'), count($matches))];
        }

        $item = reset($matches);
        if (!is_array($item)) {
            return ['ok' => false, 'message' => __('Cloudflare Stream match was invalid.', 'voffload-cloudflare-stream')];
        }

        return self::import_stream_result_for_attachment($attachment_id, $item, 'reconnected');
    }

    public static function attach_existing_stream_uid(int $attachment_id, string $uid): array {
        if (!self::is_video_attachment($attachment_id)) {
            return ['ok' => false, 'message' => __('Selected attachment is not a video.', 'voffload-cloudflare-stream')];
        }

        $uid = sanitize_text_field(trim($uid));
        if ($uid === '' || !preg_match('/^[a-zA-Z0-9_-]{6,128}$/', $uid)) {
            return ['ok' => false, 'message' => __('Enter a valid Cloudflare Stream UID.', 'voffload-cloudflare-stream')];
        }

        if (!self::is_configured()) {
            return ['ok' => false, 'message' => __('Cloudflare settings are incomplete.', 'voffload-cloudflare-stream')];
        }

        $existing_uid = (string) get_post_meta($attachment_id, self::META_UID, true);
        if ($existing_uid !== '' && $existing_uid !== $uid) {
            return ['ok' => false, 'message' => __('This attachment already has a different Stream UID. Clear local Stream metadata first if you want to replace it.', 'voffload-cloudflare-stream')];
        }

        $api = self::api_request('GET', '/stream/' . rawurlencode($uid));
        if (empty($api['ok'])) {
            return $api;
        }

        $result = is_array($api['result'] ?? null) ? $api['result'] : [];
        if (!$result) {
            return ['ok' => false, 'message' => __('Cloudflare did not return video details for this UID.', 'voffload-cloudflare-stream')];
        }
        $result['uid'] = $uid;

        return self::import_stream_result_for_attachment($attachment_id, $result, 'manual_uid');
    }

    public static function delete_stream_video(int $attachment_id): array {
        $uid = (string) get_post_meta($attachment_id, self::META_UID, true);
        if ($uid === '') {
            return ['ok' => false, 'message' => __('No Cloudflare Stream UID is saved for this attachment.', 'voffload-cloudflare-stream')];
        }

        if (!self::is_configured()) {
            return ['ok' => false, 'message' => __('Cloudflare settings are incomplete.', 'voffload-cloudflare-stream')];
        }

        $api = self::api_request('DELETE', '/stream/' . rawurlencode($uid));
        if (empty($api['ok'])) {
            update_post_meta($attachment_id, self::META_ERROR, (string) ($api['message'] ?? __('Delete failed.', 'voffload-cloudflare-stream')));
            return $api;
        }

        self::clear_stream_meta($attachment_id);

        return ['ok' => true, 'message' => __('Deleted from Cloudflare Stream. The original WordPress media attachment was not deleted.', 'voffload-cloudflare-stream')];
    }

    public static function refresh_stream_status(int $attachment_id): array {
        $uid = (string) get_post_meta($attachment_id, self::META_UID, true);
        if ($uid === '') {
            $reconnect = self::reconnect_attachment_from_cloudflare($attachment_id);
            if (empty($reconnect['ok'])) {
                return ['ok' => false, 'message' => sprintf(__('No Cloudflare Stream UID is saved for this attachment, and automatic reconnection failed: %s', 'voffload-cloudflare-stream'), (string) ($reconnect['message'] ?? __('unknown error', 'voffload-cloudflare-stream')))];
            }
            $uid = (string) get_post_meta($attachment_id, self::META_UID, true);
            if ($uid === '') {
                return ['ok' => false, 'message' => __('Automatic reconnection completed, but no Stream UID was saved.', 'voffload-cloudflare-stream')];
            }
        }

        if (!self::is_configured()) {
            return ['ok' => false, 'message' => __('Cloudflare settings are incomplete.', 'voffload-cloudflare-stream')];
        }

        $api = self::api_request('GET', '/stream/' . rawurlencode($uid));
        if (empty($api['ok'])) {
            update_post_meta($attachment_id, self::META_ERROR, (string) ($api['message'] ?? __('Status check failed.', 'voffload-cloudflare-stream')));
            return $api;
        }

        $result = is_array($api['result']) ? $api['result'] : [];
        self::store_result_meta($attachment_id, $result);
        update_post_meta($attachment_id, self::META_CHECKED_AT, current_time('mysql'));

        return ['ok' => true, 'result' => $result];
    }

    private static function store_result_meta(int $attachment_id, array $result): void {
        $state = self::extract_state($result);
        $ready = !empty($result['readyToStream']) || $state === 'ready';

        update_post_meta($attachment_id, self::META_STATE, $state);
        update_post_meta($attachment_id, self::META_READY, $ready ? '1' : '0');
        update_post_meta($attachment_id, self::META_RESULT_JSON, wp_json_encode($result));

        if (isset($result['thumbnail'])) {
            update_post_meta($attachment_id, self::META_THUMBNAIL, esc_url_raw((string) $result['thumbnail']));
        }
        if (isset($result['preview'])) {
            update_post_meta($attachment_id, self::META_PREVIEW, esc_url_raw((string) $result['preview']));
        }
        if (isset($result['playback']) && is_array($result['playback'])) {
            if (!empty($result['playback']['hls'])) {
                update_post_meta($attachment_id, self::META_HLS, esc_url_raw((string) $result['playback']['hls']));
            }
            if (!empty($result['playback']['dash'])) {
                update_post_meta($attachment_id, self::META_DASH, esc_url_raw((string) $result['playback']['dash']));
            }
        }
        if (isset($result['status']) && is_array($result['status']) && isset($result['status']['pctComplete'])) {
            update_post_meta($attachment_id, self::META_PCT, (string) absint($result['status']['pctComplete']));
        }

        $error = '';
        if (isset($result['status']) && is_array($result['status']) && !empty($result['status']['errorReasonText'])) {
            $error = (string) $result['status']['errorReasonText'];
        }
        update_post_meta($attachment_id, self::META_ERROR, sanitize_text_field($error));
    }

    private static function extract_state(array $result): string {
        if (!empty($result['readyToStream'])) {
            return 'ready';
        }
        if (isset($result['status']) && is_array($result['status']) && !empty($result['status']['state'])) {
            return sanitize_key((string) $result['status']['state']);
        }
        return 'processing';
    }

    public static function clear_stream_meta(int $attachment_id): void {
        foreach ([
            self::META_UID,
            self::META_STATE,
            self::META_READY,
            self::META_PCT,
            self::META_THUMBNAIL,
            self::META_PREVIEW,
            self::META_HLS,
            self::META_DASH,
            self::META_ERROR,
            self::META_UPLOADED_AT,
            self::META_CHECKED_AT,
            self::META_RESULT_JSON,
            self::META_METHOD,
        ] as $key) {
            delete_post_meta($attachment_id, $key);
        }
    }

    public static function cron_status_check(): void {
        $query = new WP_Query(self::pending_query_args(20));

        foreach ($query->posts as $attachment_id) {
            self::refresh_stream_status((int) $attachment_id);
        }

        // Nothing left worth polling -> stop the recurring wake-up.
        self::maybe_unschedule_cron();
    }

    public static function replace_core_video_block(string $block_content, array $block): string {
        $options = self::get_options();
        if (empty($options['auto_replace_blocks'])) {
            return $block_content;
        }

        if (($block['blockName'] ?? '') !== 'core/video') {
            return $block_content;
        }

        $attachment_id = isset($block['attrs']['id']) ? absint($block['attrs']['id']) : 0;
        if (!$attachment_id) {
            return $block_content;
        }

        $iframe = self::stream_iframe_for_attachment($attachment_id, ['require_ready' => true]);
        return $iframe ?: $block_content;
    }

    public static function replace_video_shortcode($override, $attr, $content, $instance) {
        $options = self::get_options();
        if (empty($options['auto_replace_shortcode'])) {
            return $override;
        }

        $attachment_id = 0;
        if (is_array($attr)) {
            if (!empty($attr['id'])) {
                $attachment_id = absint($attr['id']);
            } elseif (!empty($attr['src'])) {
                $attachment_id = attachment_url_to_postid((string) $attr['src']);
            }
        }

        if (!$attachment_id) {
            return $override;
        }

        $iframe = self::stream_iframe_for_attachment($attachment_id, ['require_ready' => true]);
        return $iframe ?: $override;
    }

    public static function replace_local_video_content(string $content): string {
        $options = self::get_options();
        if (empty($options['auto_replace_html'])) {
            return $content;
        }

        if (is_admin() || is_feed() || trim($content) === '') {
            return $content;
        }

        // Cheap short-circuit: avoid regex work on normal posts.
        if (stripos($content, '<video') === false && stripos($content, 'wp-video') === false && !preg_match('/\.(?:mp4|m4v|mov|webm|ogv|avi|mkv|mpeg|mpg|3gp)(?:[?#][^\s"\']*)?/i', $content)) {
            return $content;
        }

        // Replace the full WordPress classic-player wrapper first, if present.
        $content = preg_replace_callback('/<div\b[^>]*class=(?:"[^"]*\bwp-video\b[^"]*"|\'[^\']*\bwp-video\b[^\']*\')[^>]*>.*?<\/div>/is', function ($matches) {
            return self::replace_video_html_fragment($matches[0]);
        }, $content) ?? $content;

        // Replace bare <video>...</video> fragments, including <source> children.
        $content = preg_replace_callback('/<video\b[^>]*>.*?<\/video>/is', function ($matches) {
            return self::replace_video_html_fragment($matches[0]);
        }, $content) ?? $content;

        // Replace direct links to local uploaded video files, e.g. core/file or classic-editor links.
        $content = preg_replace_callback('/<a\b[^>]*href=("|\')([^"\']+\.(?:mp4|m4v|mov|webm|ogv|avi|mkv|mpeg|mpg|3gp)(?:[?#][^"\']*)?)\1[^>]*>.*?<\/a>/is', function ($matches) {
            $attachment_id = self::attachment_id_from_media_url((string) $matches[2]);
            if (!$attachment_id) {
                return $matches[0];
            }

            $iframe = self::stream_iframe_for_attachment($attachment_id, ['require_ready' => true]);
            return $iframe ?: $matches[0];
        }, $content) ?? $content;

        return $content;
    }

    private static function replace_video_html_fragment(string $html): string {
        // Never touch Cloudflare Stream embeds or a player we already generated.
        if (stripos($html, 'cloudflarestream.com') !== false || stripos($html, 'voffloadcfs-player') !== false) {
            return $html;
        }

        $attachment_id = self::attachment_id_from_video_html($html);
        if (!$attachment_id) {
            return $html;
        }

        $iframe = self::stream_iframe_for_attachment($attachment_id, ['require_ready' => true]);
        return $iframe ?: $html;
    }

    private static function attachment_id_from_video_html(string $html): int {
        if (!preg_match_all('/\s(?:src|href)=("|\')([^"\']+)\1/i', $html, $matches)) {
            return 0;
        }

        foreach ($matches[2] as $url) {
            $attachment_id = self::attachment_id_from_media_url((string) $url);
            if ($attachment_id) {
                return $attachment_id;
            }
        }

        return 0;
    }

    private static function attachment_id_from_media_url(string $url): int {
        $url = trim(html_entity_decode($url, ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8'));
        if ($url === '' || stripos($url, 'cloudflarestream.com') !== false) {
            return 0;
        }

        if (strpos($url, '//') === 0) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        } elseif (strpos($url, '/') === 0) {
            $url = home_url($url);
        }

        $parts = wp_parse_url($url);
        if (empty($parts['path'])) {
            return 0;
        }

        $clean_url = (isset($parts['scheme'], $parts['host']) ? $parts['scheme'] . '://' . $parts['host'] : '') . $parts['path'];
        $clean_url = esc_url_raw($clean_url);

        if ($clean_url) {
            $attachment_id = attachment_url_to_postid($clean_url);
            if ($attachment_id && self::is_video_attachment((int) $attachment_id)) {
                return (int) $attachment_id;
            }
        }

        // Fallback for CDN/domain/protocol differences: match the uploads-relative path
        // against _wp_attached_file, e.g. 2026/06/example.mp4.
        $uploads = wp_get_upload_dir();
        $base_path = isset($uploads['baseurl']) ? (string) wp_parse_url((string) $uploads['baseurl'], PHP_URL_PATH) : '';
        $path = rawurldecode((string) $parts['path']);
        $relative = '';

        if ($base_path !== '' && strpos($path, $base_path . '/') === 0) {
            $relative = ltrim(substr($path, strlen($base_path)), '/');
        } elseif (preg_match('#/(\d{4}/\d{2}/[^/?#]+)$#', $path, $m)) {
            $relative = $m[1];
        }

        if ($relative === '') {
            return 0;
        }

        $query = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'video',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [[
                'key'   => '_wp_attached_file',
                'value' => $relative,
            ]],
        ]);

        $found = !empty($query->posts[0]) ? (int) $query->posts[0] : 0;
        return ($found && self::is_video_attachment($found)) ? $found : 0;
    }

    public static function shortcode($atts): string {
        $raw_atts = (array) $atts;
        $atts = shortcode_atts([
            'id'            => '',
            'attachment_id' => '',
            'attachment'    => '',
            'uid'           => '',
            'video_uid'     => '',
            'video'         => '',
            'title'         => '',
            'aspect'        => '16/9',
        ], $raw_atts, 'voffloadcfs_video');

        // Support shorthand such as [voffloadcfs_video 123] if WordPress
        // passes the first unnamed value as $atts[0].
        $unnamed = isset($raw_atts[0]) && is_scalar($raw_atts[0]) ? trim((string) $raw_atts[0]) : '';

        $id_candidate = trim((string) ($atts['attachment_id'] ?: $atts['attachment'] ?: $atts['id'] ?: $unnamed));
        $uid_candidate = trim((string) ($atts['uid'] ?: $atts['video_uid'] ?: $atts['video']));

        if ($id_candidate !== '') {
            if (ctype_digit($id_candidate)) {
                $html = self::stream_iframe_for_attachment(absint($id_candidate), [
                    'title'      => (string) $atts['title'],
                    'aspect'     => (string) $atts['aspect'],
                    'diagnostic' => true,
                ]);
                return $html ?: '';
            }

            // Common mistake: users paste the Cloudflare UID into id="...".
            // Treat a non-numeric id as a Stream UID instead of rendering blank.
            $uid_candidate = $id_candidate;
        }

        $uid = sanitize_text_field($uid_candidate);
        if ($uid === '') {
            return self::frontend_notice(__('Cloudflare Stream shortcode is missing an attachment ID or Stream UID.', 'voffload-cloudflare-stream'), ['diagnostic' => true]);
        }

        return self::stream_iframe($uid, [
            'title'      => (string) $atts['title'],
            'aspect'     => (string) $atts['aspect'],
            'diagnostic' => true,
        ]);
    }

    private static function stream_iframe_for_attachment(int $attachment_id, array $args = []): string {
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            return self::frontend_notice(__('Cloudflare Stream shortcode points to an invalid WordPress attachment ID.', 'voffload-cloudflare-stream'), $args);
        }

        $uid = (string) get_post_meta($attachment_id, self::META_UID, true);
        if ($uid === '') {
            return self::frontend_notice(__('This attachment does not have a saved Cloudflare Stream UID yet. Upload it first or refresh its Stream status.', 'voffload-cloudflare-stream'), $args);
        }

        if (!empty($args['require_ready']) && (string) get_post_meta($attachment_id, self::META_READY, true) !== '1') {
            return self::frontend_notice(__('This Cloudflare Stream video is not ready yet, so the local video fallback is still being used.', 'voffload-cloudflare-stream'), $args);
        }

        $title = isset($args['title']) && $args['title'] !== '' ? (string) $args['title'] : get_the_title($attachment_id);
        $args['title'] = $title ?: __('Cloudflare Stream video', 'voffload-cloudflare-stream');

        return self::stream_iframe($uid, $args);
    }

    private static function stream_iframe(string $uid, array $args = []): string {
        $options = self::get_options();
        $customer_code = trim((string) $options['customer_code']);
        if ($customer_code === '') {
            return self::frontend_notice(__('Cloudflare Stream Customer Code is missing in Media → Cloudflare Stream settings. Upload can work without it, but playback iframe generation cannot.', 'voffload-cloudflare-stream'), $args);
        }

        $uid = sanitize_text_field($uid);
        if ($uid === '') {
            return self::frontend_notice(__('Cloudflare Stream UID is empty.', 'voffload-cloudflare-stream'), $args);
        }

        $title = isset($args['title']) && $args['title'] !== '' ? (string) $args['title'] : __('Cloudflare Stream video', 'voffload-cloudflare-stream');
        $padding_top = self::aspect_to_padding_top(isset($args['aspect']) ? (string) $args['aspect'] : '16/9');

        $src = 'https://customer-' . rawurlencode($customer_code) . '.cloudflarestream.com/' . rawurlencode($uid) . '/iframe';

        return sprintf(
            '<div class="voffloadcfs-player" style="position:relative;width:100%%;padding-top:%1$s;"><iframe src="%2$s" title="%3$s" loading="lazy" style="border:0;position:absolute;top:0;left:0;width:100%%;height:100%%;" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen></iframe></div>',
            esc_attr($padding_top),
            esc_url($src),
            esc_attr($title)
        );
    }

    private static function aspect_to_padding_top(string $aspect): string {
        $aspect = trim($aspect);
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)$/', $aspect, $m)) {
            return '56.25%';
        }

        $w = (float) $m[1];
        $h = (float) $m[2];
        if ($w <= 0 || $h <= 0) {
            return '56.25%';
        }

        return rtrim(rtrim(number_format(($h / $w) * 100, 4, '.', ''), '0'), '.') . '%';
    }

    private static function frontend_notice(string $message, array $args = []): string {
        if (empty($args['diagnostic']) || !current_user_can(self::capability())) {
            return '';
        }

        return '<div class="voffloadcfs-player voffloadcfs-player-notice" style="border:1px solid #d63638;padding:12px;margin:12px 0;background:#fff8f8;color:#1d2327;font-size:14px;line-height:1.45;">' . esc_html($message) . '</div>';
    }

    public static function is_video_attachment(int $attachment_id): bool {
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            return false;
        }
        $mime = get_post_mime_type($attachment_id);
        return is_string($mime) && strpos($mime, 'video/') === 0;
    }

    public static function add_media_column(array $columns): array {
        $columns['voffloadcfs_status'] = __('Cloudflare Stream', 'voffload-cloudflare-stream');
        return $columns;
    }

    /**
     * Add a "Settings" link on the Plugins list row.
     */
    public static function plugin_action_links(array $links): array {
        $settings = '<a href="' . esc_url(admin_url('upload.php?page=voffload-cloudflare-stream')) . '">' . esc_html__('Settings', 'voffload-cloudflare-stream') . '</a>';
        array_unshift($links, $settings);
        return $links;
    }

    public static function render_media_column(string $column_name, int $post_id): void {
        if ($column_name !== 'voffloadcfs_status') {
            return;
        }
        if (!self::is_video_attachment($post_id)) {
            echo '&mdash;';
            return;
        }
        echo wp_kses_post(self::status_badge($post_id));
    }
}

VoffloadCFS_Plugin::init();
// Registered at file scope (not inside init()) so the custom interval exists
// during activation regardless of hook timing.
add_filter('cron_schedules', ['VoffloadCFS_Plugin', 'cron_schedules']);
add_filter('plugin_action_links_' . plugin_basename(__FILE__), ['VoffloadCFS_Plugin', 'plugin_action_links']);
register_activation_hook(__FILE__, ['VoffloadCFS_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['VoffloadCFS_Plugin', 'deactivate']);
