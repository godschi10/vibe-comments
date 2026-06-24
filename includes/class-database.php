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
     * Daily guest token: IP + auth key + UTC date.
     * Rotates at midnight so a guest can react again the next day.
     */
    public static function get_guest_token() {
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
     * Logic:
     *   - Same type already stored  → DELETE   (toggle off)
     *   - Different type stored     → UPDATE    (switch reaction)
     *   - No reaction stored        → INSERT
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

        // Check for an existing reaction from this user on this comment.
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, reaction_type FROM {$this->table_name}
             WHERE comment_id = %d AND user_id = %d AND guest_token = %s",
            $comment_id, $user_id, $guest_token
        ) );

        if ( $existing ) {
            if ( $existing->reaction_type === $reaction_type ) {
                // Same reaction — toggle off.
                $result = $wpdb->delete( $this->table_name, [ 'id' => (int) $existing->id ], [ '%d' ] );
                if ( $result === false ) {
                    return new WP_Error( 'db_error', 'Failed to remove reaction.', [ 'status' => 500 ] );
                }
                $action        = 'removed';
                $user_reaction = null;
            } else {
                // Different reaction — switch type in place.
                $result = $wpdb->update(
                    $this->table_name,
                    [ 'reaction_type' => $reaction_type ],
                    [ 'id'            => (int) $existing->id ],
                    [ '%s' ],
                    [ '%d' ]
                );
                if ( $result === false ) {
                    return new WP_Error( 'db_error', 'Failed to update reaction.', [ 'status' => 500 ] );
                }
                $action        = 'switched';
                $user_reaction = $reaction_type;
            }
        } else {
            // No existing reaction — insert.
            $result = $wpdb->insert(
                $this->table_name,
                [
                    'comment_id'    => $comment_id,
                    'user_id'       => $user_id,
                    'guest_token'   => $guest_token,
                    'reaction_type' => $reaction_type,
                ],
                [ '%d', '%d', '%s', '%s' ]
            );
            if ( $result === false ) {
                return new WP_Error( 'db_error', 'Failed to save reaction.', [ 'status' => 500 ] );
            }
            $action        = 'added';
            $user_reaction = $reaction_type;
        }

        $this->invalidate_reaction_cache( $comment_id, $user_id, $guest_token );

        return [
            'action'        => $action,
            'user_reaction' => $user_reaction,
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
            // IDs are absint-sanitised — safe for direct interpolation.
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
