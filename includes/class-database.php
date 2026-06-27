<?php
class Vibe_Comments_Database {
    private $table_name;
    private $cache_group = 'vibe_comments';

    /** Allowed reaction types — whitelist checked on every write. */
    const REACTION_TYPES = ['like', 'heart', 'fire', 'laugh'];

    /** Default reaction counts array returned when a comment has no reactions. */
    const REACTION_DEFAULTS = ['like' => 0, 'heart' => 0, 'fire' => 0, 'laugh' => 0];

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'vibe_comment_likes';
    }

    // -------------------------------------------------------------------------
    // IP / token helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the real client IP.
     * Trusts CF-Connecting-IP (Cloudflare) only. X-Forwarded-For is intentionally
     * ignored — it can be spoofed by any client and would allow rate-limit bypass.
     */
    public static function resolve_client_ip() {
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = preg_replace( '/[^0-9a-fA-F:.]/', '', $_SERVER['HTTP_CF_CONNECTING_IP'] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }
        $remote = ! empty( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $ip     = preg_replace( '/[^0-9a-fA-F:.]/', '', $remote );
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
    }

    /**
     * Derive a 32-char guest token for DB storage.
     *
     * Preferred path (H1 fix): accept the client-supplied UUID from localStorage
     * (sent as `vibe_guest_id` in POST/GET by the JS). Hash it with AUTH_KEY so
     * the raw UUID is never stored, and so the same UUID produces a different
     * token on different sites. Stable across page loads for that browser until
     * the user clears localStorage — no daily rotation needed.
     *
     * Fallback path (legacy / no UUID): derive from IP + AUTH_KEY + UTC date,
     * same as before. This path still collides for users behind the same NAT
     * (the original H1 problem) but is preserved for direct API calls or very
     * old browsers.
     *
     * @param  string $client_id  UUID from localStorage, passed by caller after
     *                            reading from $_POST['vibe_guest_id'].
     * @return string             32 hex chars.
     */
    public static function get_guest_token( $client_id = '' ) {
        if ( ! empty( $client_id ) ) {
            // Allow UUID format chars only: hex digits and hyphens.
            $clean = preg_replace( '/[^a-zA-Z0-9\-]/', '', $client_id );
            // Plausible UUID range: 32 chars (no hyphens) to 36 chars (with hyphens).
            // Accept up to 40 to be tolerant of minor format variations.
            if ( strlen( $clean ) >= 32 && strlen( $clean ) <= 40 ) {
                $salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'vibe-salt';
                return substr( md5( $salt . $clean ), 0, 32 );
            }
        }
        // Fallback: IP-based. Retains NAT-collision risk (H1) when no UUID is present.
        $ip   = self::resolve_client_ip();
        $salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'vibe-salt';
        return substr( md5( $ip . $salt . gmdate( 'Y-m-d' ) ), 0, 32 );
    }

    // -------------------------------------------------------------------------
    // Reaction toggle (insert / update / delete)
    // -------------------------------------------------------------------------

    /**
     * Toggle or switch a reaction on a comment.
     *
     * Race-safe without a SELECT:
     *
     *   Step 1 — DELETE WHERE reaction_type matches the requested type.
     *     • 1 row deleted  → user had this exact reaction → toggle off.
     *     • 0 rows deleted → either they have a different reaction, or none at all
     *                        → fall through to the upsert.
     *
     *   Step 2 (if step 1 deleted nothing) — INSERT ... ON DUPLICATE KEY UPDATE.
     *     • No row exists  → inserts a new reaction (INSERT path).
     *     • Different type → triggers ON DUPLICATE KEY UPDATE; sets the new type
     *                        atomically in a single statement (no separate UPDATE).
     *     Both are handled by one atomic SQL statement — no window for a duplicate.
     *
     * The UNIQUE KEY (comment_id, user_id, guest_token) in the schema is the DB-level
     * invariant that makes ON DUPLICATE KEY UPDATE target the right row.
     *
     * JS consumer note: `action` is kept in the response for potential debugging,
     * but the frontend never reads it — it only consumes `reactions` and `user_reaction`.
     * 'switched' is folded into 'added' since the distinction is meaningless to JS.
     *
     * @param  int    $comment_id
     * @param  int    $user_id       0 for guests
     * @param  string $guest_token   empty for logged-in users
     * @param  string $reaction_type one of self::REACTION_TYPES
     * @return array|WP_Error  ['action', 'user_reaction', 'reactions']
     */
    public function toggle_reaction( $comment_id, $user_id, $guest_token, $reaction_type ) {
        global $wpdb;

        $comment_id    = absint( $comment_id );
        $user_id       = absint( $user_id );
        $guest_token   = sanitize_text_field( $guest_token );
        $reaction_type = sanitize_key( $reaction_type );

        if ( ! $comment_id ) {
            return new WP_Error( 'invalid_data', 'Invalid comment ID.', [ 'status' => 400 ] );
        }
        if ( ! in_array( $reaction_type, self::REACTION_TYPES, true ) ) {
            return new WP_Error( 'invalid_reaction', 'Invalid reaction type.', [ 'status' => 400 ] );
        }
        if ( $user_id === 0 && empty( $guest_token ) ) {
            return new WP_Error( 'invalid_data', 'Guest token required.', [ 'status' => 400 ] );
        }

        // ── Step 1: attempt toggle-off ────────────────────────────────────────
        // Delete only if the stored reaction_type is the SAME as what was clicked.
        // $wpdb->delete() returns int (rows affected) or false (DB error).
        // 0 rows affected is not an error here — it means "no match for this type."
        $deleted = $wpdb->delete(
            $this->table_name,
            [
                'comment_id'    => $comment_id,
                'user_id'       => $user_id,
                'guest_token'   => $guest_token,
                'reaction_type' => $reaction_type,
            ],
            [ '%d', '%d', '%s', '%s' ]
        );

        if ( $deleted === false ) {
            return new WP_Error( 'db_error', 'Failed to remove reaction.', [ 'status' => 500 ] );
        }

        if ( $deleted > 0 ) {
            // Successfully toggled off.
            $this->invalidate_reaction_cache( $comment_id, $user_id, $guest_token );
            return [
                'action'        => 'removed',
                'user_reaction' => null,
                'reactions'     => $this->get_reaction_counts( $comment_id ),
            ];
        }

        // ── Step 2: insert-or-switch (atomic) ────────────────────────────────
        // INSERT ... ON DUPLICATE KEY UPDATE collapses both "no prior reaction"
        // (INSERT) and "different prior reaction" (switch via UPDATE) into one
        // atomic statement. No separate SELECT or UPDATE needed.
        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$this->table_name} (comment_id, user_id, guest_token, reaction_type)
             VALUES (%d, %d, %s, %s)
             ON DUPLICATE KEY UPDATE reaction_type = VALUES(reaction_type)",
            $comment_id, $user_id, $guest_token, $reaction_type
        ) );

        if ( $result === false ) {
            return new WP_Error( 'db_error', 'Failed to save reaction.', [ 'status' => 500 ] );
        }

        $this->invalidate_reaction_cache( $comment_id, $user_id, $guest_token );

        return [
            'action'        => 'added',
            'user_reaction' => $reaction_type,
            'reactions'     => $this->get_reaction_counts( $comment_id ),
        ];
    }

    // -------------------------------------------------------------------------
    // Single-comment counts
    // -------------------------------------------------------------------------

    /**
     * Return reaction counts for one comment.
     * Result is object-cached for 5 minutes.
     *
     * @return array ['like' => int, 'heart' => int, 'fire' => int, 'laugh' => int]
     */
    public function get_reaction_counts( $comment_id ) {
        global $wpdb;
        $comment_id = absint( $comment_id );
        $cache_key  = 'reactions_' . $comment_id;
        $counts     = wp_cache_get( $cache_key, $this->cache_group );

        if ( false === $counts ) {
            $counts = self::REACTION_DEFAULTS;
            $rows   = $wpdb->get_results( $wpdb->prepare(
                "SELECT reaction_type, COUNT(*) AS cnt
                 FROM {$this->table_name}
                 WHERE comment_id = %d
                 GROUP BY reaction_type",
                $comment_id
            ) );
            foreach ( $rows as $row ) {
                if ( array_key_exists( $row->reaction_type, $counts ) ) {
                    $counts[ $row->reaction_type ] = (int) $row->cnt;
                }
            }
            wp_cache_set( $cache_key, $counts, $this->cache_group, 300 );
        }

        return $counts;
    }

    // -------------------------------------------------------------------------
    // Batch operations (eliminate N+1 on comment list load)
    // -------------------------------------------------------------------------

    /**
     * Fetch reaction counts for multiple comments in a SINGLE SQL query.
     * Uses WP object cache per-comment; only queries DB for uncached IDs.
     *
     * @param  int[]  $comment_ids
     * @return array  [comment_id => ['like' => int, 'heart' => int, ...]]
     */
    public function get_reaction_counts_batch( array $comment_ids ) {
        global $wpdb;

        $comment_ids = array_values( array_unique( array_filter( array_map( 'absint', $comment_ids ) ) ) );
        if ( empty( $comment_ids ) ) return [];

        $results  = [];
        $uncached = [];

        foreach ( $comment_ids as $id ) {
            $cached = wp_cache_get( 'reactions_' . $id, $this->cache_group );
            if ( false !== $cached ) {
                $results[ $id ] = $cached;
            } else {
                $results[ $id ] = self::REACTION_DEFAULTS;
                $uncached[]     = $id;
            }
        }

        if ( ! empty( $uncached ) ) {
            // IDs are absint()-guaranteed PHP integers at this point — no injection
            // vector. Direct interpolation is safe and avoids variadic-spread
            // incompatibilities across WordPress versions.
            $id_list = implode( ',', $uncached );
            $rows    = $wpdb->get_results(
                "SELECT comment_id, reaction_type, COUNT(*) AS cnt
                 FROM {$this->table_name}
                 WHERE comment_id IN ({$id_list})
                 GROUP BY comment_id, reaction_type"
            );
            foreach ( $rows as $row ) {
                $id   = (int) $row->comment_id;
                $type = $row->reaction_type;
                if ( array_key_exists( $type, self::REACTION_DEFAULTS ) ) {
                    $results[ $id ][ $type ] = (int) $row->cnt;
                }
            }
            // Populate cache for all uncached IDs, even those with no reactions.
            foreach ( $uncached as $id ) {
                wp_cache_set( 'reactions_' . $id, $results[ $id ], $this->cache_group, 300 );
            }
        }

        return $results;
    }

    /**
     * Fetch each user's reaction type for multiple comments in a SINGLE query.
     * Returns null for comments where the user has not reacted.
     *
     * Cache note: we store '' for "no reaction" (null) because WP cache backends
     * cannot reliably distinguish a stored null from a cache miss.
     *
     * @param  int[]  $comment_ids
     * @param  int    $user_id
     * @param  string $guest_token
     * @return array  [comment_id => string|null]  e.g. [42 => 'fire', 99 => null]
     */
    public function get_user_reactions_batch( array $comment_ids, $user_id, $guest_token = '' ) {
        global $wpdb;

        $comment_ids = array_values( array_unique( array_filter( array_map( 'absint', $comment_ids ) ) ) );
        if ( empty( $comment_ids ) ) return [];

        $user_id  = absint( $user_id );
        $results  = array_fill_keys( $comment_ids, null );
        $uncached = [];

        foreach ( $comment_ids as $id ) {
            $cache_key = 'user_reaction_' . $id . '_' . $user_id . '_' . md5( $guest_token );
            $cached    = wp_cache_get( $cache_key, $this->cache_group );
            if ( false !== $cached ) {
                // '' = cached "no reaction"; any other string = the reaction type.
                $results[ $id ] = $cached === '' ? null : $cached;
            } else {
                $uncached[] = $id;
            }
        }

        if ( ! empty( $uncached ) ) {
            // IDs are absint()-guaranteed integers — safe to interpolate directly.
            // The WHERE predicates (user_id, guest_token) use prepare() because
            // those values come from external sources and ARE user-controlled.
            $id_list = implode( ',', $uncached );
            $rows    = $wpdb->get_results( $wpdb->prepare(
                "SELECT comment_id, reaction_type
                 FROM {$this->table_name}
                 WHERE comment_id IN ({$id_list})
                   AND user_id = %d AND guest_token = %s",
                $user_id,
                (string) $guest_token
            ) );

            $found = [];
            foreach ( $rows as $row ) {
                $found[ (int) $row->comment_id ] = $row->reaction_type;
            }

            foreach ( $uncached as $id ) {
                $type        = isset( $found[ $id ] ) ? $found[ $id ] : null;
                $results[$id] = $type;
                $cache_key   = 'user_reaction_' . $id . '_' . $user_id . '_' . md5( $guest_token );
                // Store '' for null so we can distinguish miss (false) from "no reaction" ('').
                wp_cache_set( $cache_key, $type ?? '', $this->cache_group, 300 );
            }
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Cache invalidation
    // -------------------------------------------------------------------------

    private function invalidate_reaction_cache( $comment_id, $user_id = 0, $guest_token = '' ) {
        wp_cache_delete( 'reactions_' . $comment_id, $this->cache_group );
        $cache_key = 'user_reaction_' . $comment_id . '_' . absint( $user_id ) . '_' . md5( $guest_token );
        wp_cache_delete( $cache_key, $this->cache_group );
    }

    // -------------------------------------------------------------------------
    // Comment list cache (unchanged)
    // -------------------------------------------------------------------------

    public function get_comments_cache_version( $post_id ) {
        $post_id = absint( $post_id );
        $version = wp_cache_get( 'comments_version_' . $post_id, $this->cache_group );
        if ( false === $version ) {
            $version = time();
            wp_cache_set( 'comments_version_' . $post_id, $version, $this->cache_group, 86400 );
        }
        return $version;
    }

    public function increment_comments_cache_version( $post_id ) {
        $post_id     = absint( $post_id );
        $new_version = time();
        wp_cache_set( 'comments_version_' . $post_id, $new_version, $this->cache_group, 86400 );
        return $new_version;
    }

    public function get_comments_for_post( $post_id, $args = [] ) {
        $post_id   = absint( $post_id );
        $version   = $this->get_comments_cache_version( $post_id );
        $cache_key = 'comments_' . $post_id . '_v' . $version . '_' . md5( serialize( $args ) );

        $comments = wp_cache_get( $cache_key, $this->cache_group );
        if ( false === $comments ) {
            $default_args = [
                'post_id' => $post_id,
                'status'  => 'approve',
                'orderby' => 'comment_date_gmt',
                'order'   => 'ASC',
            ];
            $comments = get_comments( array_merge( $default_args, $args ) );
            wp_cache_set( $cache_key, $comments, $this->cache_group, 60 );
        }
        return $comments;
    }

    public function clear_post_comments_cache( $post_id ) {
        $this->increment_comments_cache_version( $post_id );
    }

    /**
     * Load all nested comments for a post in one query.
     * Returns [parent_id => [comment_rows]].
     */
    public function get_children_map( $post_id ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->comments}
             WHERE comment_post_ID = %d
               AND comment_parent > 0
               AND comment_approved = '1'
             ORDER BY comment_date_gmt ASC",
            absint( $post_id )
        ) );

        $map = [];
        foreach ( $rows as $row ) {
            $map[ (int) $row->comment_parent ][] = $row;
        }
        return $map;
    }
}
