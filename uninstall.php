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
 *
 * GUARD: if the canonical vibe-comments plugin is still active (possible when
 * an off-slug copy such as vibe-comments-v3_2_5 is being deleted), bail
 * immediately — the shared table and options must not be destroyed while the
 * main plugin is still using them. This was the exact scenario that caused
 * production reaction data loss in June 2026.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// ── Active-plugin guard ───────────────────────────────────────────────────
// Check both single-site and (if multisite) network-active plugins.
$canonical       = 'vibe-comments/vibe-comments.php';
$active          = (array) get_option( 'active_plugins', [] );
$network_active  = is_multisite()
    ? array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) )
    : [];

if ( in_array( $canonical, $active, true ) || in_array( $canonical, $network_active, true ) ) {
    // The main plugin is still active — preserve all shared data.
    exit;
}

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

// ── 3a. Remove _vibe_reply_push commentmeta (v3.7.0 reply notifications) ──
$wpdb->delete(
    $wpdb->commentmeta,
    array( 'meta_key' => '_vibe_reply_push' ),
    array( '%s' )
);

// ── 3b. Remove _vibe_reply_email commentmeta (v3.9.0 email notifications) ──
$wpdb->delete(
    $wpdb->commentmeta,
    array( 'meta_key' => '_vibe_reply_email' ),
    array( '%s' )
);

// ── 3c. Remove _vibe_owner + _vibe_edited commentmeta (v3.13.0 edit window) ──
$wpdb->delete(
    $wpdb->commentmeta,
    array( 'meta_key' => '_vibe_owner' ),
    array( '%s' )
);
$wpdb->delete(
    $wpdb->commentmeta,
    array( 'meta_key' => '_vibe_edited' ),
    array( '%s' )
);

// ── 3d. Remove _vibe_qa_mode + _vibe_qa_accepted POSTMETA (v3.15.0 Q&A) ────
// Found by the 2026-09-01 conflict audit: uninstall touched commentmeta only —
// these two postmeta keys (the Q&A toggle + accepted-answer pointer, stored
// on the POST, not the comment) survived uninstall as orphans on every post
// that ever ran Q&A mode.
$wpdb->delete(
    $wpdb->postmeta,
    array( 'meta_key' => '_vibe_qa_mode' ),
    array( '%s' )
);
$wpdb->delete(
    $wpdb->postmeta,
    array( 'meta_key' => '_vibe_qa_accepted' ),
    array( '%s' )
);

// ── 4. Delete plugin settings and version option ──────────────────────────
delete_option( 'vibe_comments_db_version' );
delete_option( 'vibe_comments_google_settings' );
// v3.17.0 digest settings + last-run marker (audit finding: never swept).
delete_option( 'vibe_digest_settings' );
delete_option( 'vibe_digest_last_run' );

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
