<?php
/**
 * Uninstall handler for "Offload Video to Cloudflare Stream".
 *
 * Removes data this plugin stored in WordPress. It does NOT delete any videos
 * from Cloudflare Stream — those live in your Cloudflare account and are not
 * touched here. To remove videos from Cloudflare, use the Cloudflare dashboard
 * or API.
 *
 * @package OffloadVideoToStream
 */

// Exit if this file is called directly or not during a real uninstall.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Remove plugin data for the current site in a multisite-safe way.
 */
function gd_cfs_uninstall_site_cleanup(): void {
    global $wpdb;

    delete_option('gd_cfs_options');
    wp_clear_scheduled_hook('gd_cfs_status_cron');

    // Per-user transients (notices + connection-test results).
    $like_notice  = $wpdb->esc_like('_transient_gd_cfs_notice_') . '%';
    $like_notice2 = $wpdb->esc_like('_transient_timeout_gd_cfs_notice_') . '%';
    $like_test    = $wpdb->esc_like('_transient_gd_cfs_connection_test_') . '%';
    $like_test2   = $wpdb->esc_like('_transient_timeout_gd_cfs_connection_test_') . '%';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup on uninstall.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_notice));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_notice2));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_test));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_test2));
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

    // Per-attachment meta this plugin created (local Stream metadata only).
    $meta_keys = [
        '_gd_cfs_uid',
        '_gd_cfs_state',
        '_gd_cfs_ready',
        '_gd_cfs_pct_complete',
        '_gd_cfs_thumbnail',
        '_gd_cfs_preview',
        '_gd_cfs_hls',
        '_gd_cfs_dash',
        '_gd_cfs_last_error',
        '_gd_cfs_uploaded_at',
        '_gd_cfs_checked_at',
        '_gd_cfs_result_json',
        '_gd_cfs_upload_method',
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
        gd_cfs_uninstall_site_cleanup();
        restore_current_blog();
    }
} else {
    gd_cfs_uninstall_site_cleanup();
}
