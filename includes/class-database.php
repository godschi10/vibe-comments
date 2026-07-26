<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
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
     * Cloudflare's published IP ranges (https://www.cloudflare.com/ips-v4 and
     * /ips-v6), as of this writing. Verified against two independent current
     * sources before hardcoding. Cloudflare changes these infrequently and
     * with advance notice — this is the same "refresh occasionally" approach
     * widely used by Cloudflare-integration plugins and server configs (see
     * e.g. the official real_ip_module / mod_cloudflare patterns). If
     * Cloudflare's ranges change and this list goes stale, the practical
     * failure mode is graceful: is_cloudflare_ip() returns false for genuine
     * Cloudflare traffic, and the code falls back to trusting REMOTE_ADDR
     * directly (Cloudflare's own edge IP) rather than CF-Connecting-IP —
     * rate limiting and guest identity still work, just keyed on Cloudflare's
     * edge IP instead of the real visitor IP until this list is refreshed.
     */
    private static $cf_ipv4_ranges = array(
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    );
    private static $cf_ipv6_ranges = array(
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    );

    /**
     * Check whether $ip falls within a given CIDR range. Handles both IPv4
     * (via 32-bit integer comparison) and IPv6 (via packed-binary byte
     * comparison, since PHP has no native 128-bit integer type).
     */
    private static function ip_in_cidr( $ip, $cidr ) {
        list( $subnet, $bits ) = explode( '/', $cidr );
        $bits = (int) $bits;

        if ( strpos( $ip, ':' ) !== false || strpos( $subnet, ':' ) !== false ) {
            // IPv6: compare packed binary representations byte-by-byte up to $bits.
            $ip_bin     = @inet_pton( $ip );
            $subnet_bin = @inet_pton( $subnet );
            if ( false === $ip_bin || false === $subnet_bin ) {
                return false;
            }
            $bytes    = intdiv( $bits, 8 );
            $rem_bits = $bits % 8;
            if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
                return false;
            }
            if ( $rem_bits > 0 ) {
                $mask = chr( 0xFF << ( 8 - $rem_bits ) & 0xFF );
                if ( ( $ip_bin[ $bytes ] & $mask ) !== ( $subnet_bin[ $bytes ] & $mask ) ) {
                    return false;
                }
            }
            return true;
        }

        // IPv4: standard netmask-and-compare on 32-bit integers.
        $ip_long     = ip2long( $ip );
        $subnet_long = ip2long( $subnet );
        if ( false === $ip_long || false === $subnet_long ) {
            return false;
        }
        $mask = -1 << ( 32 - $bits );
        return ( $ip_long & $mask ) === ( $subnet_long & $mask );
    }

    /**
     * Whether $ip genuinely belongs to Cloudflare's edge network.
     */
    private static function is_cloudflare_ip( $ip ) {
        $ranges = ( strpos( $ip, ':' ) !== false ) ? self::$cf_ipv6_ranges : self::$cf_ipv4_ranges;
        foreach ( $ranges as $cidr ) {
            if ( self::ip_in_cidr( $ip, $cidr ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve the real client IP.
     *
     * CF-Connecting-IP is only trusted if the ACTUAL TCP connection
     * (REMOTE_ADDR — set by the web server from the live socket, which a
     * client cannot spoof) genuinely originates from one of Cloudflare's own
     * published IP ranges. Without this check, any client could send an
     * arbitrary CF-Connecting-IP header directly to the origin server and
     * have it trusted verbatim — trivially bypassing IP-based rate limiting
     * (rotate the header value on every request) and polluting the guest
     * identity derivation this value feeds into. The existing docblock below
     * already correctly reasoned about this exact risk for X-Forwarded-For
     * ("it can be spoofed by any client") without applying the same
     * reasoning to CF-Connecting-IP, which is equally spoofable by anyone
     * who can reach the origin server directly.
     *
     * X-Forwarded-For remains intentionally ignored entirely — it can be
     * spoofed by any client and would allow rate-limit bypass.
     */
    public static function resolve_client_ip() {
        $remote = ! empty( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $remote = preg_replace( '/[^0-9a-fA-F:.]/', '', $remote );
        $remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';

        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::is_cloudflare_ip( $remote ) ) {
            $ip = preg_replace( '/[^0-9a-fA-F:.]/', '', $_SERVER['HTTP_CF_CONNECTING_IP'] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }
        return $remote;
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
            // Strict UUID v4 format check (canonical hyphenated form, the only
            // format our own getGuestId() in JS ever produces — via
            // crypto.randomUUID(), the manual getRandomValues() fallback, or
            // the Math.random() last resort, all three of which emit this exact
            // shape). Previously this used preg_replace() to strip non-UUID
            // characters and accepted anything left over in the 32-40 char range
            // — which meant two DIFFERENT malformed inputs containing different
            // disallowed characters could strip down to the IDENTICAL cleaned
            // string and collide on the same guest token. A strict format match
            // closes that off entirely: anything that isn't a well-formed UUID
            // falls straight through to the IP-based fallback below.
            if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $client_id ) ) {
                $salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'vibe-salt';
                return substr( md5( $salt . strtolower( $client_id ) ), 0, 32 );
            }
        }
        // Fallback: IP-based. Retains NAT-collision risk (H1) when no UUID is present
        // or when the supplied vibe_guest_id doesn't match the canonical UUID format.
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
    // NOTE: an object-cache-versioned comment-list caching subsystem
    // (get_comments_cache_version / increment_comments_cache_version /
    // get_comments_for_post / clear_post_comments_cache) previously lived here.
    // Removed after a full-codebase grep confirmed get_comments_for_post() —
    // the only function that would ever READ using that versioned scheme —
    // had zero callers anywhere in the plugin. The comment list is actually
    // served entirely through load_comments()'s own separate vc_load_* transient
    // cache in class-ajax-handler.php. The only thing this dead subsystem was
    // doing in production was one wasted wp_cache_set() call on every single
    // comment submission, bumping a version key nothing ever consulted.
    // -------------------------------------------------------------------------

    /**
     * Fetch the full descendant subtree for a SET of root comment IDs, scoped
     * via IN() at each level — never scans the whole post's comments.
     *
     * Used two ways:
     *   1. load_comments() passes the current page's top-level IDs (e.g. 10)
     *      to compute reply_count per thread, without fetching reply CONTENT
     *      at all for threads nobody has expanded yet.
     *   2. load_replies() passes a single clicked comment_id to fetch its
     *      entire nested conversation in one click (not one click per level).
     *
     * Replaces get_children_map()'s old behavior of fetching every approved
     * reply for the ENTIRE post on every single load_comments() call,
     * regardless of how many threads were actually on the current page or
     * how many were ever expanded. On a post with hundreds of replies spread
     * across many threads, that meant transferring full comment content
     * (text, author, email, agent string) for replies nobody would ever see
     * unless they happened to expand that exact thread — every single time
     * anyone loaded the comment section.
     *
     * @param  int   $post_id
     * @param  array $root_ids    Comment IDs to start from (their direct
     *                            children are level 1 of the result).
     * @param  int   $max_levels  Safety bound on recursion depth (default 4
     *                            covers this plugin's supported nesting).
     * @return array  [parent_comment_id => [WP_Comment, ...], ...] — same
     *                shape as the old get_children_map(), so format_comment_tree()
     *                needs zero changes to consume either one.
     */
    public function get_replies_map( $post_id, array $root_ids, $max_levels = 4 ) {
        global $wpdb;

        $post_id = absint( $post_id );
        $map     = [];
        $current_level_ids = array_values( array_filter( array_map( 'absint', $root_ids ) ) );

        for ( $level = 0; $level < $max_levels && ! empty( $current_level_ids ); $level++ ) {
            $placeholders = implode( ',', array_fill( 0, count( $current_level_ids ), '%d' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->comments}
                 WHERE comment_post_ID = %d
                   AND comment_parent IN ({$placeholders})
                   AND comment_approved = '1'
                 ORDER BY comment_date_gmt ASC",
                array_merge( [ $post_id ], $current_level_ids )
            ) );

            if ( empty( $rows ) ) break;

            $next_level_ids = [];
            foreach ( $rows as $row ) {
                $map[ (int) $row->comment_parent ][] = $row;
                $next_level_ids[] = (int) $row->comment_ID;
            }
            $current_level_ids = $next_level_ids;
        }

        return $map;
    }

    /**
     * Count total descendants (all levels) of a comment within an
     * already-fetched map from get_replies_map(). Used to compute
     * reply_count without re-querying.
     */
    public function count_descendants( $comment_id, array $map ) {
        $comment_id = (int) $comment_id;
        if ( empty( $map[ $comment_id ] ) ) return 0;

        $count = 0;
        foreach ( $map[ $comment_id ] as $child ) {
            $count += 1 + $this->count_descendants( (int) $child->comment_ID, $map );
        }
        return $count;
    }
}
