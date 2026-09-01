<?php
/**
 * WP-CLI Commands - Vibe Comments
 *
 * Provides CLI commands for cache management, count syncing, and debugging.
 *
 * @package Vibe_Comments
 * @since   3.5.7
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

/**
 * Purge all comment-related caches for a post or globally.
 */
WP_CLI::add_command( 'vibe-comments purge-cache', function( $args, $assoc_args ) {
    $post_id = isset( $assoc_args['post_id'] ) ? absint( $assoc_args['post_id'] ) : 0;

    if ( $post_id ) {
        if ( ! get_post( $post_id ) ) {
            WP_CLI::error( "Post #{$post_id} not found." );
        }
        Vibe_Comments_Ajax_Handler::purge_comments_data_cache( $post_id );
        WP_CLI::success( "Purged comment caches for post #{$post_id}." );
    } else {
        // Global purge - delete all vc_* transients
        global $wpdb;
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_vc_%'
                OR option_name LIKE '_transient_timeout_vc_%'"
        );
        do_action( 'litespeed_purge_tag', 'vibe-comments' );
        WP_CLI::success( "Purged {$deleted} comment cache transients globally." );
    }
}, [
    'shortdesc' => 'Purge comment caches (per post or globally).',
    'synopsis'  => '[--post_id=<id>]',
] );

/**
 * Synchronize comment counts for all posts or a specific post.
 */
WP_CLI::add_command( 'vibe-comments sync-counts', function( $args, $assoc_args ) {
    $post_id = isset( $assoc_args['post_id'] ) ? absint( $assoc_args['post_id'] ) : 0;

    if ( $post_id ) {
        $count = (int) get_comments( [
            'post_id' => $post_id,
            'status'  => 'approve',
            'count'   => true,
        ] );
        update_option( 'vibe_comment_count_' . $post_id, $count, false );
        delete_transient( 'vibe_count_' . $post_id );
        WP_CLI::success( "Synced count for post #{$post_id}: {$count} approved comments." );
    } else {
        global $wpdb;
        $posts = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'" );
        $synced = 0;

        // One grouped query for ALL posts (cleanup-audit N3, 2026-09-01) -
        // previously a get_comments() per post inside the loop. GROUP BY
        // returns only posts that HAVE comments; posts absent from the map
        // correctly sync to 0.
        $counts_map = array();
        $rows = $wpdb->get_results(
            "SELECT comment_post_ID, COUNT(*) AS n
             FROM {$wpdb->comments}
             WHERE comment_approved = '1' AND comment_post_ID IN (
                 SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'
             )
             GROUP BY comment_post_ID"
        );
        foreach ( $rows as $r ) {
            $counts_map[ (int) $r->comment_post_ID ] = (int) $r->n;
        }

        foreach ( $posts as $pid ) {
            $count = isset( $counts_map[ (int) $pid ] ) ? $counts_map[ (int) $pid ] : 0;
            update_option( 'vibe_comment_count_' . $pid, $count, false );
            delete_transient( 'vibe_count_' . $pid );
            $synced++;
        }
        WP_CLI::success( "Synced counts for {$synced} posts." );
    }
}, [
    'shortdesc' => 'Synchronize vibe_comment_count_* options with actual DB counts.',
    'synopsis'  => '[--post_id=<id>]',
] );

/**
 * Show debug log contents (if VIBE_COMMENTS_DEBUG_TOOLS is enabled).
 */
WP_CLI::add_command( 'vibe-comments debug-log', function( $args, $assoc_args ) {
    $log_file = WP_CONTENT_DIR . '/logs/vibe-comments-debug.log';

    if ( ! file_exists( $log_file ) ) {
        WP_CLI::error( "Debug log not found at {$log_file}. Ensure VIBE_COMMENTS_DEBUG_TOOLS is defined and true in wp-config.php." );
    }

    $lines = isset( $assoc_args['lines'] ) ? absint( $assoc_args['lines'] ) : 50;
    $follow = isset( $assoc_args['follow'] );

    if ( $follow ) {
        // Tail -f equivalent
        $handle = fopen( $log_file, 'r' );
        fseek( $handle, 0, SEEK_END );
        while ( true ) {
            $line = fgets( $handle );
            if ( $line ) {
                WP_CLI::line( rtrim( $line ) );
            } else {
                usleep( 100000 ); // 100ms
            }
        }
    } else {
        $content = file_get_contents( $log_file );
        $all_lines = explode( "\n", $content );
        $recent = array_slice( $all_lines, -$lines );
        foreach ( $recent as $line ) {
            WP_CLI::line( $line );
        }
    }
}, [
    'shortdesc' => 'View the Vibe Comments debug log.',
    'synopsis'  => '[--lines=<n>] [--follow]',
] );

/**
 * List all cached comment data transients.
 */
WP_CLI::add_command( 'vibe-comments list-caches', function( $args, $assoc_args ) {
    global $wpdb;

    $limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 20;

    $transients = $wpdb->get_results( $wpdb->prepare(
        "SELECT option_name, option_value
         FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_vc_%'
         ORDER BY option_name DESC
         LIMIT %d",
        $limit
    ) );

    if ( empty( $transients ) ) {
        WP_CLI::success( 'No comment caches found.' );
        return;
    }

    $table = [];
    foreach ( $transients as $t ) {
        $name = $t->option_name;
        $val  = maybe_unserialize( $t->option_value );
        $size = strlen( serialize( $val ) );
        $type = 'unknown';
        if ( strpos( $name, 'vc_load_' ) !== false ) $type = 'load_comments';
        elseif ( strpos( $name, 'vc_replies_' ) !== false ) $type = 'load_replies';
        elseif ( strpos( $name, 'vc_count_' ) !== false ) $type = 'get_comment_count';
        elseif ( strpos( $name, 'vibe_count_' ) !== false ) $type = 'get_comment_count (legacy)';
        elseif ( strpos( $name, 'reactions_' ) !== false ) $type = 'reaction_counts';

        $table[] = [
            'name' => $name,
            'type' => $type,
            'size' => size_format( $size ),
        ];
    }

    WP_CLI\Utils\format_items( 'table', $table, [ 'name', 'type', 'size' ] );
}, [
    'shortdesc' => 'List comment cache transients.',
    'synopsis'  => '[--limit=<n>]',
] );

/**
 * Check health of the plugin (schema, caches, settings).
 */
WP_CLI::add_command( 'vibe-comments health-check', function( $args, $assoc_args ) {
    $issues = [];

    // 1. Check table exists
    global $wpdb;
    $table = $wpdb->prefix . 'vibe_comment_likes';
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $table !== $exists ) {
        $issues[] = "Table {$table} does not exist.";
    } else {
        WP_CLI::success( "Table {$table} exists." );
    }

    // 2. Check schema version
    $db_version = get_option( 'vibe_comments_db_version', 'unknown' );
    $expected   = defined( 'Vibe_Comments_Activator::DB_VERSION' ) ? Vibe_Comments_Activator::DB_VERSION : 'unknown';
    if ( version_compare( $db_version, $expected, '>=' ) ) {
        WP_CLI::success( "DB version {$db_version} (expected {$expected})." );
    } else {
        $issues[] = "DB version {$db_version} is behind expected {$expected}. Run maybe_upgrade().";
    }

    // 3. Check for legacy MD5 guest_tokens
    $legacy = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE LENGTH(guest_token) = 32" );
    if ( $legacy > 0 ) {
        $issues[] = "{$legacy} legacy MD5 guest_tokens found (should be 64-char SHA256).";
    } else {
        WP_CLI::success( "All guest_tokens are SHA256 (64 chars)." );
    }

    // 4. Check cache transients
    $transient_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_vc_%'" );
    WP_CLI::line( "Active comment cache transients: {$transient_count}" );

    // 5. Check settings
    $google_settings = get_option( 'vibe_comments_google_settings', [] );
    if ( empty( $google_settings['client_id'] ) ) {
        WP_CLI::warning( 'Google OAuth not configured (client_id missing).' );
    } else {
        WP_CLI::success( 'Google OAuth configured.' );
    }

    // 6. Check Cloudflare purge credentials
    $cf_zone = defined( 'VIBE_CF_ZONE_ID' ) ? VIBE_CF_ZONE_ID : get_option( 'vibe_cf_zone_id', '' );
    $cf_token = defined( 'VIBE_CF_API_TOKEN' ) ? VIBE_CF_API_TOKEN : get_option( 'vibe_cf_api_token', '' );
    if ( $cf_zone && $cf_token ) {
        WP_CLI::success( 'Cloudflare purge credentials configured.' );
    } else {
        WP_CLI::warning( 'Cloudflare purge credentials not configured (optional).' );
    }

    if ( ! empty( $issues ) ) {
        WP_CLI::error_multi_line( $issues );
        WP_CLI::error( 'Health check failed.', 1 );
    } else {
        WP_CLI::success( 'All health checks passed.' );
    }
}, [
    'shortdesc' => 'Run health checks on the Vibe Comments installation.',
] );