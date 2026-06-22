<?php
/**
 * Fired when the plugin is uninstalled.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete custom table
global $wpdb;
$table_name = $wpdb->prefix . 'vibe_comment_likes';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Delete options
delete_option('vibe_comments_db_version');
delete_option('vibe_comments_google_settings');

// Clean up any transients or user meta if added in future
