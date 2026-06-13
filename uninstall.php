<?php
/**
 * Uninstall handler for "Voffload for Cloudflare Stream".
 *
 * Removes data this plugin stored in WordPress. It does NOT delete any videos
 * from Cloudflare Stream — those live in your Cloudflare account and are not
 * touched here. To remove videos from Cloudflare, use the Cloudflare dashboard
 * or API.
 *
 * @package VoffloadCFS
 */

// Exit if this file is called directly or not during a real uninstall.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Remove plugin data for the current site in a multisite-safe way.
 */
function voffloadcfs_uninstall_site_cleanup(): void {
    global $wpdb;

    delete_option('voffloadcfs_options');
    wp_clear_scheduled_hook('voffloadcfs_status_cron');

    // Per-user transients (notices + connection-test results).
    $like_notice  = $wpdb->esc_like('_transient_voffloadcfs_notice_') . '%';
    $like_notice2 = $wpdb->esc_like('_transient_timeout_voffloadcfs_notice_') . '%';
    $like_test    = $wpdb->esc_like('_transient_voffloadcfs_connection_test_') . '%';
    $like_test2   = $wpdb->esc_like('_transient_timeout_voffloadcfs_connection_test_') . '%';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup on uninstall.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_notice));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_notice2));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_test));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_test2));
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

    // Per-attachment meta this plugin created (local Stream metadata only).
    $meta_keys = [
        '_voffloadcfs_uid',
        '_voffloadcfs_state',
        '_voffloadcfs_ready',
        '_voffloadcfs_pct_complete',
        '_voffloadcfs_thumbnail',
        '_voffloadcfs_preview',
        '_voffloadcfs_hls',
        '_voffloadcfs_dash',
        '_voffloadcfs_last_error',
        '_voffloadcfs_uploaded_at',
        '_voffloadcfs_checked_at',
        '_voffloadcfs_result_json',
        '_voffloadcfs_upload_method',
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
        voffloadcfs_uninstall_site_cleanup();
        restore_current_blog();
    }
} else {
    voffloadcfs_uninstall_site_cleanup();
}
