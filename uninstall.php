<?php
/**
 * Uninstall handler for "Offload Video to Cloudflare Stream".
 *
 * Removes data this plugin stored in WordPress. It does NOT delete any videos
 * from Cloudflare Stream — those live in your Cloudflare account and are not
 * touched here. To remove videos from Cloudflare, use the Cloudflare dashboard
 * or API.
 *
 * @package OffloadVideoToCloudflareStream
 */

// Exit if this file is called directly or not during a real uninstall.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Remove plugin data for the current site in a multisite-safe way.
 */
function ovcs_uninstall_site_cleanup(): void {
    global $wpdb;

    delete_option('ovcs_options');
    wp_clear_scheduled_hook('ovcs_status_cron');

    // Per-user transients (notices + connection-test results).
    $like_notice  = $wpdb->esc_like('_transient_ovcs_notice_') . '%';
    $like_notice2 = $wpdb->esc_like('_transient_timeout_ovcs_notice_') . '%';
    $like_test    = $wpdb->esc_like('_transient_ovcs_connection_test_') . '%';
    $like_test2   = $wpdb->esc_like('_transient_timeout_ovcs_connection_test_') . '%';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup on uninstall.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_notice));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_notice2));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_test));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_test2));
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

    // Per-attachment meta this plugin created (local Stream metadata only).
    $meta_keys = [
        '_ovcs_uid',
        '_ovcs_state',
        '_ovcs_ready',
        '_ovcs_pct_complete',
        '_ovcs_thumbnail',
        '_ovcs_preview',
        '_ovcs_hls',
        '_ovcs_dash',
        '_ovcs_last_error',
        '_ovcs_uploaded_at',
        '_ovcs_checked_at',
        '_ovcs_result_json',
        '_ovcs_upload_method',
    ];

    foreach ($meta_keys as $meta_key) {
        delete_post_meta_by_key($meta_key);
    }
}

if (is_multisite()) {
    $site_ids = get_sites([
        'fields' => 'ids',
        'number' => 0,
    ]);

    foreach ((array) $site_ids as $site_id) {
        switch_to_blog((int) $site_id);
        ovcs_uninstall_site_cleanup();
        restore_current_blog();
    }
} else {
    ovcs_uninstall_site_cleanup();
}
