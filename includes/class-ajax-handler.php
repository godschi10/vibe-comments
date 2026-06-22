<?php
class Vibe_Comments_Ajax_Handler {
    public function __construct() {
        add_action('wp_ajax_vibe_submit_comment',           array($this, 'submit_comment'));
        add_action('wp_ajax_nopriv_vibe_submit_comment',    array($this, 'submit_comment'));
        add_action('wp_ajax_vibe_refresh_nonce',            array($this, 'refresh_nonce'));
        add_action('wp_ajax_nopriv_vibe_refresh_nonce',     array($this, 'refresh_nonce'));
        add_action('wp_ajax_vibe_load_comments',            array($this, 'load_comments'));
        add_action('wp_ajax_nopriv_vibe_load_comments',     array($this, 'load_comments'));
        add_action('wp_ajax_vibe_sync_likes',               array($this, 'sync_likes'));
        add_action('wp_ajax_nopriv_vibe_sync_likes',        array($this, 'sync_likes'));
        add_action('wp_ajax_vibe_toggle_like',              array($this, 'toggle_like'));
        add_action('wp_ajax_nopriv_vibe_toggle_like',       array($this, 'toggle_like'));
        add_action('wp_ajax_vibe_get_comment_count',        array($this, 'get_comment_count'));
        add_action('wp_ajax_nopriv_vibe_get_comment_count', array($this, 'get_comment_count'));
        add_action('wp_ajax_vibe_pin_comment',              array($this, 'pin_comment')); // admin-only

        // Purge page cache when a pending comment is approved in WP admin.
        // This covers the case where a guest comment goes to moderation and an
        // admin approves it — the post page becomes stale at that point.
        add_action('transition_comment_status', array($this, 'on_comment_approved'),  10, 3);
        add_action('delete_comment',            array($this, 'on_comment_deleted'),   10, 2);
        add_action('wp_set_comment_status',     array($this, 'on_comment_status_set'), 10, 2);
    }

    /**
     * Fires on every comment status change.
     *
     * Persists an accurate comment count to wp_options BEFORE purging the
     * page cache. This means the cache rebuild that follows reads the fresh
     * count from get_option() with zero live DB query — no AJAX, no stale
     * number, no hidden heading tricks needed.
     *
     * Covers both directions:
     *   pending/spam → approved   (comment becomes public — count up)
     *   approved     → trash/spam (comment removed from public — count down)
     */
    public function on_comment_approved($new_status, $old_status, $comment) {
        $was_public = ($old_status === 'approved');
        $is_public  = ($new_status === 'approved');
        // Nothing changed from the public's perspective — bail early.
        if ($was_public === $is_public || $new_status === $old_status) return;
        $this->sync_and_purge(intval($comment->comment_post_ID));
    }

    /**
     * Fires when a comment is permanently deleted.
     * wp_delete_comment() passes the full comment object as 2nd arg.
     */
    public function on_comment_deleted($comment_id, $comment) {
        if (!$comment || !is_object($comment)) return;
        // Only bust if the deleted comment was publicly visible.
        if ($comment->comment_approved !== '1') return;
        $this->sync_and_purge(intval($comment->comment_post_ID));
    }

    /**
     * Fires when wp_set_comment_status() is called directly (trash, spam, unspam).
     * Recalculates the count for any status change — simpler than tracking direction.
     */
    public function on_comment_status_set($comment_id, $new_status) {
        $comment = get_comment($comment_id);
        if (!$comment) return;
        $this->sync_and_purge(intval($comment->comment_post_ID));
    }

    /**
     * Single source of truth for count persistence + cache busting.
     * Recalculates the approved count from DB, writes to wp_options BEFORE
     * purging edge caches — so the next PHP render reads the fresh value.
     * All three comment hooks funnel through here.
     */
    private function sync_and_purge($post_id) {
        if (!$post_id) return;
        $count = (int) get_comments(array(
            'post_id' => $post_id,
            'status'  => 'approve',
            'count'   => true,
        ));
        // autoload=false keeps this out of WP's global options preload on every page.
        update_option('vibe_comment_count_' . $post_id, $count, false);
        delete_transient('vibe_count_' . $post_id);
        $this->purge_page_cache($post_id);
        $this->purge_comments_data_cache($post_id);
    }

    /**
     * Return a fresh nonce — rate-limited to prevent rapid-fire abuse.
     * Always returns a valid nonce (even when rate-limited) so the UI never breaks.
     */
    public function refresh_nonce() {
        $ip       = Vibe_Comments_Database::resolve_client_ip();
        $rate_key = 'vn_' . substr( md5( $ip ), 0, 16 );
        if ( ! get_transient( $rate_key ) ) {
            set_transient( $rate_key, 1, 2 ); // 2-second cooldown per IP
        }
        wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
    }

    /**
     * Return live comment count — cached 2 minutes to reduce DB load.
     * Cache is invalidated whenever a new approved comment is added.
     */
    public function get_comment_count() {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        if (!$post_id || !get_post($post_id)) {
            wp_send_json_error(array('message' => 'Invalid post.'));
            return;
        }

        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        do_action('litespeed_control_set_nocache', 'vibe-comment-count');

        // Transient — shared across all PHP workers, unlike wp_cache_set().
        $cache_key = 'vibe_count_' . $post_id;
        $count     = get_transient($cache_key);

        if (false === $count) {
            $count = (int) get_comments(array(
                'post_id' => $post_id,
                'status'  => 'approve',
                'count'   => true,
            ));
            set_transient($cache_key, $count, 120);
        }

        wp_send_json_success(array('count' => $count));
    }

    /**
     * Purge the cached version of a post across all major cache plugins.
     * Called after a comment is successfully submitted so the next visitor
     * sees the updated comment count in the PHP-rendered heading.
     */
    private function purge_page_cache( $post_id ) {
        // Bust the comment count transient so the next count request hits the DB.
        delete_transient( 'vibe_count_' . $post_id );

        // LiteSpeed Cache (Hetzner VPS / OpenLiteSpeed — instant purge by post)
        do_action( 'litespeed_purge_post', $post_id );

        // ── Cloudflare API purge ──────────────────────────────────────────
        // Works on ALL CF plans including Free — purges by URL, not Cache-Tag.
        // Credentials are read from constants first (defined in wp-config.php,
        // keeps secrets out of the DB), then wp_options as a fallback.
        //
        // To enable, add to wp-config.php:
        //   define('VIBE_CF_ZONE_ID',    'your-zone-id');
        //   define('VIBE_CF_API_TOKEN',  'your-api-token-with-Cache-Purge-permission');
        //
        // OR store via options (e.g. in functions.php, once):
        //   update_option('vibe_cf_zone_id',   'your-zone-id');
        //   update_option('vibe_cf_api_token',  'your-api-token');
        $zone_id = defined( 'VIBE_CF_ZONE_ID' )   ? VIBE_CF_ZONE_ID   : get_option( 'vibe_cf_zone_id', '' );
        $token   = defined( 'VIBE_CF_API_TOKEN' )  ? VIBE_CF_API_TOKEN : get_option( 'vibe_cf_api_token', '' );

        if ( $zone_id && $token ) {
            $url = get_permalink( $post_id );
            if ( $url ) {
                wp_remote_post(
                    'https://api.cloudflare.com/client/v4/zones/' . sanitize_text_field( $zone_id ) . '/purge_cache',
                    array(
                        'method'   => 'POST',
                        'headers'  => array(
                            'Authorization' => 'Bearer ' . sanitize_text_field( $token ),
                            'Content-Type'  => 'application/json',
                        ),
                        'body'     => wp_json_encode( array( 'files' => array( esc_url_raw( $url ) ) ) ),
                        'timeout'  => 5,
                        'blocking' => false, // fire-and-forget — never slow down approval flow
                    )
                );
            }
        }

        // WP Rocket
        if ( function_exists( 'rocket_clean_post' ) )  { rocket_clean_post( $post_id ); }

        // W3 Total Cache
        if ( function_exists( 'w3tc_flush_post' ) )    { w3tc_flush_post( $post_id ); }

        // WP Super Cache
        if ( function_exists( 'wp_cache_post_change' ) ) { wp_cache_post_change( $post_id ); }

        // Comet Cache / ZenCache
        if ( class_exists( 'comet_cache' ) )           { \comet_cache::clear(); }
    }

    /**
     * Load comments for a post — paginated, with children nested.
     *
     * CACHING STRATEGY (two layers):
     *   Layer 1 — Transient (server, ~5s for first request):
     *     The formatted comment JSON is stored in a transient keyed by
     *     post_id + page + per_page. Subsequent requests within 2 minutes
     *     skip all DB queries entirely.
     *
     *   Layer 2 — Cloudflare / LiteSpeed edge cache (~2 min TTL):
     *     Cache-Control: public, max-age=120 tells both CF and LiteSpeed
     *     to cache this GET response at the edge. A post with 10,000
     *     visitors within 2 minutes costs exactly 1 PHP boot, not 10,000.
     *
     *   Invalidation:
     *     Both layers are cleared when a comment is approved or deleted
     *     via purge_comments_data_cache() hooked to comment status changes.
     *
     * NOTE: user-specific reaction state is NOT included here.
     * syncReactions() fetches that separately and is never cached.
     */
    public function load_comments() {
        $post_id  = isset($_GET['post_id'])  ? absint($_GET['post_id'])                    : 0;
        $page     = isset($_GET['page'])     ? max(1, absint($_GET['page']))               : 1;
        $per_page = isset($_GET['per_page']) ? min(50, max(1, absint($_GET['per_page']))) : 10;
        $since    = isset($_GET['since'])    ? absint($_GET['since'])                      : 0;

        if (!$post_id) {
            wp_send_json_error(array('message' => 'Invalid post.'));
            return;
        }

        // Polling requests (since > 0) are never cached — they're checking for
        // new comments in real time and must always hit the DB.
        $is_polling = $since > 0;

        if (!$is_polling) {
            // ── Layer 1: transient cache ─────────────────────────────────
            $cache_key = 'vc_load_' . $post_id . '_' . $page . '_' . $per_page;
            $cached    = get_transient($cache_key);
            if (false !== $cached) {
                $this->set_public_cache_headers();
                wp_send_json_success($cached);
                return;
            }
        } else {
            // ── Polling branch: optimised for minimal server cost ─────────
            //
            // Accept comment IDs from the client so we can return fresh
            // reaction counts for all visible comments in the SAME request —
            // no separate syncReactions() call needed on the poll interval.
            $reaction_ids = isset($_GET['comment_ids'])
                ? array_slice(
                    array_filter(array_map('absint', (array) $_GET['comment_ids'])),
                    0, 100
                  )
                : array();

            // Fast check: one integer query to see if anything is new.
            // If nothing is new AND no reaction IDs to refresh, return immediately
            // without running the heavier comment-list query.
            global $wpdb;
            $new_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->comments}
                 WHERE comment_post_ID = %d
                   AND comment_approved = '1'
                   AND comment_date_gmt > %s",
                $post_id,
                gmdate('Y-m-d H:i:s', $since)
            ));

            if ($new_count === 0 && empty($reaction_ids)) {
                // Nothing changed — cheapest possible response.
                header('Cache-Control: no-store, private');
                wp_send_json_success(array('comments' => array(), 'timestamp' => time()));
                return;
            }

            if ($new_count === 0 && !empty($reaction_ids)) {
                // No new comments, but return fresh reaction counts for visible ones.
                $db             = new Vibe_Comments_Database();
                $reaction_counts = $db->get_reaction_counts_batch($reaction_ids);
                header('Cache-Control: no-store, private');
                wp_send_json_success(array(
                    'comments'        => array(),
                    'reaction_counts' => $reaction_counts,
                    'timestamp'       => time(),
                ));
                return;
            }
        }

        $args = array(
            'post_id' => $post_id,
            'status'  => 'approve',
            'orderby' => 'comment_date_gmt',
            'order'   => 'ASC',
            'number'  => $per_page,
            'offset'  => ($page - 1) * $per_page,
            'parent'  => 0,
        );

        if ($is_polling) {
            $args['date_query'] = array(array(
                'after'     => gmdate('Y-m-d H:i:s', $since),
                'inclusive' => false,
            ));
        }

        $comments = get_comments($args);

        global $wpdb;
        $count_row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total, SUM(comment_parent = 0) AS top_level
             FROM {$wpdb->comments}
             WHERE comment_post_ID = %d AND comment_approved = '1'",
            $post_id
        ));
        $total_count     = $count_row ? intval($count_row->total)     : 0;
        $top_level_count = $count_row ? intval($count_row->top_level) : 0;

        $db             = new Vibe_Comments_Database();
        $now            = current_time('timestamp', true);
        $post_author_id = intval(get_post_field('post_author', $post_id));

        $children_map  = $db->get_children_map($post_id);
        $all_ids       = $this->collect_all_ids($comments, $children_map);
        $reactions_map = $db->get_reaction_counts_batch($all_ids);

        if (!empty($all_ids)) {
            update_meta_cache('comment', $all_ids);
        }

        $formatted = array();
        foreach ($comments as $comment) {
            $formatted[] = $this->format_comment_tree($comment, 3, $now, $children_map, $reactions_map, $post_author_id);
        }

        $result = array(
            'comments'        => $formatted,
            'total_count'     => $total_count,
            'top_level_count' => $top_level_count,
            'page'            => $page,
            'per_page'        => $per_page,
            'has_more'        => ($page * $per_page) < $top_level_count,
        );

        // Store in transient and instruct edge caches to cache the response.
        // Polling requests are excluded — they must always reflect live data.
        if (!$is_polling) {
            set_transient($cache_key, $result, 120);
            $this->set_public_cache_headers();
        } else {
            // Polling with new comments: also return reaction counts for visible IDs.
            if (!empty($reaction_ids)) {
                $db = new Vibe_Comments_Database();
                $result['reaction_counts'] = $db->get_reaction_counts_batch($reaction_ids);
            }
            header('Cache-Control: no-store, private');
        }

        wp_send_json_success($result);
    }

    /**
     * Set Cache-Control headers that tell Cloudflare and LiteSpeed to
     * cache this response at the edge for 2 minutes.
     * s-maxage targets shared/proxy caches (CF, Varnish).
     * max-age targets the browser cache as a fallback.
     */
    private function set_public_cache_headers() {
        header('Cache-Control: public, max-age=120, s-maxage=120');
        header('Vary: Accept-Encoding');
        // Tell LiteSpeed Cache to store this response.
        do_action('litespeed_control_set_maxage', 120);
        do_action('litespeed_tag_add', 'vibe-comments');
    }

    /**
     * Purge all cached comment data for a post.
     * Called when a comment is approved, trashed, or deleted.
     * Covers both the server-side transients and edge caches.
     */
    public function purge_comments_data_cache($post_id) {
        $post_id = absint($post_id);
        if (!$post_id) return;

        // Purge transients for pages 1–5 (covers almost all real posts).
        // Deep pages (6+) rarely get cached and regenerate on demand.
        foreach (array(10, 20, 50) as $per_page) {
            for ($p = 1; $p <= 5; $p++) {
                delete_transient('vc_load_' . $post_id . '_' . $p . '_' . $per_page);
            }
        }

        // Tell LiteSpeed to purge all responses tagged with 'vibe-comments'.
        do_action('litespeed_purge_tag', 'vibe-comments');

        // Cloudflare: purge via Cache-Tag if CF Pro/Ent is in use.
        // For CF Free/Pro without tags: the 2-minute TTL is the fallback.
        do_action('cloudflare_purge_by_tags', array('vibe-comments-' . $post_id));
    }

    /**
     * Toggle like via admin-ajax — supports both logged-in and guest users.
     */
    public function toggle_like() {
        if (!check_ajax_referer('wp_rest', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed.'), 403);
            return;
        }

        $comment_id    = isset($_POST['comment_id'])    ? absint($_POST['comment_id'])             : 0;
        $reaction_type = isset($_POST['reaction_type']) ? sanitize_key($_POST['reaction_type'])    : 'like';
        $user_id       = get_current_user_id();
        $guest_token   = ($user_id > 0) ? '' : Vibe_Comments_Database::get_guest_token();

        if (!$comment_id) {
            wp_send_json_error(array('message' => 'Invalid comment.'));
            return;
        }

        // Server-side whitelist — never trust client-supplied reaction types.
        if (!in_array($reaction_type, Vibe_Comments_Database::REACTION_TYPES, true)) {
            wp_send_json_error(array('message' => 'Invalid reaction type.'));
            return;
        }

        // Verify the comment exists and is approved.
        $comment_obj = get_comment($comment_id);
        if (!$comment_obj || $comment_obj->comment_approved !== '1') {
            wp_send_json_error(array('message' => 'Comment not found.'));
            return;
        }

        // Rate limit: 5 s cooldown per user/guest per comment.
        // Transients persist in DB (or Redis/Memcached) so the limit is enforced
        // across ALL PHP workers — wp_cache_set() only works within a single
        // worker process and can be bypassed under concurrent load.
        $rate_key = 'vr_' . substr( md5( ( $user_id ?: $guest_token ) . $comment_id ), 0, 16 );
        if ( get_transient( $rate_key ) ) {
            wp_send_json_error( array( 'message' => 'Please wait before reacting again.' ) );
            return;
        }
        set_transient( $rate_key, 1, 5 );

        $db     = new Vibe_Comments_Database();
        $result = $db->toggle_reaction($comment_id, $user_id, $guest_token, $reaction_type);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }

        wp_send_json_success(array(
            'reactions'     => $result['reactions'],
            'user_reaction' => $result['user_reaction'],
        ));
    }

    public function sync_likes() {
        if (!check_ajax_referer('wp_rest', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed.'), 403);
            return;
        }

        $comment_ids = isset($_POST['comment_ids']) ? (array) $_POST['comment_ids'] : array();
        $comment_ids = array_values(array_filter(array_map('absint', array_slice($comment_ids, 0, 100))));

        if (empty($comment_ids)) {
            wp_send_json_success(array('likes' => array()));
            return;
        }

        $user_id       = get_current_user_id();
        $guest_token   = ($user_id > 0) ? '' : Vibe_Comments_Database::get_guest_token();
        $db            = new Vibe_Comments_Database();

        // Two queries total regardless of how many IDs — batch fetch both.
        $counts        = $db->get_reaction_counts_batch($comment_ids);
        $user_reactions = $db->get_user_reactions_batch($comment_ids, $user_id, $guest_token);

        $results = array();
        foreach ($comment_ids as $id) {
            $results[$id] = array(
                'reactions'     => isset($counts[$id])         ? $counts[$id]         : Vibe_Comments_Database::REACTION_DEFAULTS,
                'user_reaction' => isset($user_reactions[$id]) ? $user_reactions[$id] : null,
            );
        }

        wp_send_json_success(array('likes' => $results));
    }

    /**
     * Format a comment recursively using pre-built maps.
     * No DB queries — all data supplied by the caller.
     */
    private function format_comment_tree($comment, $depth = 3, $now = null, array $children_map = array(), array $reactions_map = array(), $post_author_id = 0) {
        if ($now === null) $now = current_time('timestamp', true);

        $comment_id = intval($comment->comment_ID);
        $children   = array();

        if ($depth > 0 && !empty($children_map[$comment_id])) {
            foreach ($children_map[$comment_id] as $child) {
                $children[] = $this->format_comment_tree($child, $depth - 1, $now, $children_map, $reactions_map, $post_author_id);
            }
        }

        $email = !empty($comment->comment_author_email)
            ? $comment->comment_author_email
            : 'vibe.guest.' . md5('vibe_guest_' . $comment_id . '_' . ($guest_token ?? '')) . '@comments.local';

        return array(
            'id'        => $comment_id,
            'author'    => $comment->comment_author,
            'avatar'    => get_avatar_url($email, array('size' => 48)),
            'date'      => human_time_diff(strtotime($comment->comment_date_gmt), $now) . ' ago',
            'date_gmt'  => $comment->comment_date_gmt,
            'content'   => $comment->comment_content,
            'parent'    => intval($comment->comment_parent),
            'reactions' => isset($reactions_map[$comment_id]) ? $reactions_map[$comment_id] : Vibe_Comments_Database::REACTION_DEFAULTS,
            'approved'  => $comment->comment_approved,
            'is_author' => ($post_author_id && intval($comment->user_id) === $post_author_id),
            'is_pinned' => (bool) get_comment_meta($comment_id, '_vibe_pinned', true),
            'children'  => $children,
        );
    }

    /**
     * Collect all comment IDs reachable from the given top-level comments
     * via the children_map. Used to batch the like count query.
     */
    private function collect_all_ids(array $top_level, array $children_map) {
        $ids = array();
        foreach ($top_level as $c) {
            $this->collect_ids_recursive(intval($c->comment_ID), $children_map, $ids);
        }
        return array_unique($ids);
    }

    private function collect_ids_recursive($id, array $children_map, array &$ids) {
        $ids[] = $id;
        if (!empty($children_map[$id])) {
            foreach ($children_map[$id] as $child) {
                $this->collect_ids_recursive(intval($child->comment_ID), $children_map, $ids);
            }
        }
    }

    public function submit_comment() {
        // Honeypot — bots fill hidden fields, humans skip them.
        // Return convincing fake-success so bots don't retry with a different approach.
        if (!empty($_POST['vibe_hp'])) {
            wp_send_json_success(array('awaiting_moderation' => true));
            return;
        }

        // CSRF check — must match nonce passed in JS (config.nonce from wp_localize_script)
        if (!check_ajax_referer('wp_rest', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed.'), 403);
            return;
        }

        // Rate limiting: 5-second cooldown per IP.
        // Uses transients (DB/Redis/Memcached) so the limit is shared across
        // all PHP workers. wp_cache_set() is worker-local and bypassable.
        $ip       = $this->get_remote_ip();
        $rate_key = 'vs_' . substr( md5( $ip ), 0, 16 );
        if ( get_transient( $rate_key ) ) {
            wp_send_json_error( array( 'message' => 'Please wait a moment before posting again.' ), 429 );
            return;
        }
        set_transient( $rate_key, 1, 5 );

        try {
            $post_id = isset($_POST['post_id']) ? absint($_POST['post_id'])                               : 0;
            $content = isset($_POST['content']) ? sanitize_textarea_field(wp_unslash($_POST['content'])) : '';
            $parent  = isset($_POST['parent'])  ? absint($_POST['parent'])                               : 0;
            $author  = isset($_POST['author'])  ? sanitize_text_field(wp_unslash($_POST['author']))      : '';
            $email   = isset($_POST['email'])   ? sanitize_email(wp_unslash($_POST['email']))            : '';

            // Enforce the same max length the frontend JS uses.
            // 65,535 is the DB column limit; the configured value (default 2,000) is
            // the UX limit — both must be checked so a direct API call can't bypass JS.
            $max_length = (int) apply_filters('vibe_comments_max_length', 2000);
            if (mb_strlen(trim($content)) < 1) {
                wp_send_json_error(array('message' => 'Comment cannot be empty.'));
                return;
            }
            if (mb_strlen($content) > $max_length) {
                wp_send_json_error(array('message' => sprintf('Comment exceeds the %d character limit.', $max_length)));
                return;
            }

            // Author name length: tinytext column cap is 255 bytes.
            if (mb_strlen($author) > 255) {
                wp_send_json_error(array('message' => 'Name is too long (max 255 characters).'));
                return;
            }

            if (!$post_id) {
                wp_send_json_error(array('message' => 'Invalid post.'));
                return;
            }

            if (!comments_open($post_id)) {
                wp_send_json_error(array('message' => 'Comments are closed for this post.'));
                return;
            }

            $post = get_post($post_id);
            if (!$post) {
                wp_send_json_error(array('message' => 'Invalid post.'));
                return;
            }

            if ($parent > 0) {
                $parent_comment = get_comment($parent);
                if (!$parent_comment || intval($parent_comment->comment_post_ID) !== $post_id) {
                    wp_send_json_error(array('message' => 'Invalid parent comment.'));
                    return;
                }
            }

            global $wpdb;
            $now     = current_time('mysql');
            $now_gmt = current_time('mysql', 1);
            $agent   = $this->get_user_agent();

            if (is_user_logged_in()) {
                $user         = wp_get_current_user();
                $author_name  = !empty($user->display_name) ? $user->display_name : $user->user_login;
                $author_email = $user->user_email;
                $author_url   = esc_url_raw($user->user_url); // sanitize — user_url could be javascript: URI
                $user_id      = $user->ID;
                $approved     = 1;
            } else {
                if (empty($author) || empty($email)) {
                    wp_send_json_error(array('message' => 'Name and email are required for guest comments.'));
                    return;
                }
                if (!is_email($email)) {
                    wp_send_json_error(array('message' => 'Please enter a valid email address.'));
                    return;
                }
                $author_name  = $author;
                $author_email = $email;
                $author_url   = '';
                $user_id      = 0;
                $approved     = 0;
            }

            $inserted = $wpdb->insert(
                $wpdb->comments,
                array(
                    'comment_post_ID'      => $post_id,
                    'comment_author'       => $author_name,
                    'comment_author_email' => $author_email,
                    'comment_author_url'   => $author_url,
                    'comment_author_IP'    => $ip,
                    'comment_date'         => $now,
                    'comment_date_gmt'     => $now_gmt,
                    'comment_content'      => $content,
                    'comment_karma'        => 0,
                    'comment_approved'     => $approved,
                    'comment_agent'        => $agent,
                    'comment_type'         => 'comment',
                    'comment_parent'       => $parent,
                    'user_id'              => $user_id,
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d')
            );

            if ($inserted === false) {
                error_log('Vibe Comments AJAX: DB insert failed. Error: ' . $wpdb->last_error);
                wp_send_json_error(array('message' => 'Failed to post comment. Please try again.'));
                return;
            }

            $comment_id = $wpdb->insert_id;

            // Clear WP object caches (bypassed by direct $wpdb->insert)
            clean_comment_cache($comment_id);
            clean_post_cache($post_id);
            wp_update_comment_count($post_id);

            $comment = get_comment($comment_id);
            if (!$comment || !is_object($comment)) {
                wp_send_json_error(array('message' => 'Comment created but could not be retrieved.'));
                return;
            }

            // Fire comment_post so notification plugins still receive the event.
            // Wrapped in Throwable: if another plugin crashes here, the comment is
            // already saved — we log and continue rather than failing the response.
            try {
                do_action('comment_post', $comment_id, $approved, array(
                    'comment_post_ID'      => $post_id,
                    'comment_author'       => $author_name,
                    'comment_author_email' => $author_email,
                    'comment_parent'       => $parent,
                    'user_id'              => $user_id,
                    'comment_approved'     => $approved,
                ));
            } catch (Throwable $e) {
                error_log('Vibe Comments: comment_post hook threw: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            }

            // Clear plugin's own comment list cache
            if (class_exists('Vibe_Comments_Database')) {
                try {
                    $db = new Vibe_Comments_Database();
                    $db->clear_post_comments_cache($post_id);
                } catch (Throwable $e) {
                    error_log('Vibe Comments: cache clear error: ' . $e->getMessage());
                }
            }

            // Only purge the page cache when the comment is immediately public.
            // Pending guest comments don't change the visible page — cache purge
            // for those happens via on_comment_approved() when admin approves them.
            if ($approved == 1) {
                delete_transient('vibe_count_' . $post_id);
                $this->purge_page_cache($post_id);
            }

            wp_send_json_success(array(
                'comment' => array(
                    'id'        => intval($comment->comment_ID),
                    'author'    => $comment->comment_author,
                    'avatar'    => get_avatar_url(
                        !empty($comment->comment_author_email) ? $comment->comment_author_email : ('vibe.guest.' . md5('vibe_guest_' . $comment->comment_ID) . '@comments.local'),
                        array('size' => 48)
                    ),
                    'date'      => human_time_diff(strtotime($comment->comment_date_gmt), current_time('timestamp', true)) . ' ago',
                    'date_gmt'  => $comment->comment_date_gmt,
                    'content'   => $comment->comment_content,
                    'parent'    => intval($comment->comment_parent),
                    'reactions' => Vibe_Comments_Database::REACTION_DEFAULTS,
                    'approved'  => $comment->comment_approved,
                    'is_author' => (is_user_logged_in() && intval(get_current_user_id()) === intval(get_post_field('post_author', $post_id))),
                    'is_pinned' => false,
                ),
                'awaiting_moderation' => ($approved == 0),
            ));

        } catch (Throwable $e) {
            error_log('Vibe Comments AJAX Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error(array('message' => 'An error occurred. Please try again.'));
        }
    }

    private function get_remote_ip() {
        return Vibe_Comments_Database::resolve_client_ip();
    }

    private function get_user_agent() {
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            return substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 254);
        }
        return '';
    }

    /**
     * Toggle pinned state on a comment. Admin-only.
     * Stores _vibe_pinned in commentmeta — no new tables.
     */
    public function pin_comment() {
        if (!check_ajax_referer('wp_rest', 'nonce', false) || !current_user_can('moderate_comments')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }

        $comment_id = absint($_POST['comment_id'] ?? 0);
        if (!$comment_id || !get_comment($comment_id)) {
            wp_send_json_error(array('message' => 'Invalid comment.'));
            return;
        }

        $pin = !empty($_POST['pin']) && '1' === $_POST['pin'];

        if ($pin) {
            update_comment_meta($comment_id, '_vibe_pinned', 1);
        } else {
            delete_comment_meta($comment_id, '_vibe_pinned');
        }

        wp_send_json_success(array('pinned' => $pin, 'comment_id' => $comment_id));
    }
}
