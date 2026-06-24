<?php
/**
 * Vibe Comments — Uninstall handler.
 * Runs when the plugin is deleted (not just deactivated) via the WP admin.
 *
 * Removes:
 *   - Custom reactions table
 *   - All per-post comment count options (vibe_comment_count_{post_id})
 *   - _vibe_pinned commentmeta on all comments
 *   - Plugin settings and DB version option
 *   - All plugin transients
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// ── 1. Drop the custom reactions table ────────────────────────────────────
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'vibe_comment_likes' );

// ── 2. Delete all per-post comment count options ──────────────────────────
// These are stored as vibe_comment_count_{post_id} with autoload=false.
// Can't use delete_option() without knowing every post ID — use a LIKE query.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE 'vibe\_comment\_count\_%'"
);

// ── 3. Remove _vibe_pinned commentmeta from all comments ─────────────────
$wpdb->delete(
    $wpdb->commentmeta,
    array( 'meta_key' => '_vibe_pinned' ),
    array( '%s' )
);

// ── 4. Delete plugin settings and version option ──────────────────────────
delete_option( 'vibe_comments_db_version' );
delete_option( 'vibe_comments_google_settings' );

// ── 5. Delete all plugin transients ──────────────────────────────────────
// Covers: vibe_count_{id}, vc_load_{id}, vn_{hash}, vs_{hash}, vr_{hash},
//         vibe_google_jwks, vibe_oauth_state_{hash}.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_vc\_%'
        OR option_name LIKE '\_transient\_vibe\_%'
        OR option_name LIKE '\_transient\_vn\_%'
        OR option_name LIKE '\_transient\_vs\_%'
        OR option_name LIKE '\_transient\_vr\_%'
        OR option_name LIKE '\_transient\_timeout\_vc\_%'
        OR option_name LIKE '\_transient\_timeout\_vibe\_%'
        OR option_name LIKE '\_transient\_timeout\_vn\_%'
        OR option_name LIKE '\_transient\_timeout\_vs\_%'
        OR option_name LIKE '\_transient\_timeout\_vr\_%'"
);
