<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
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
        add_action('wp_ajax_vibe_pin_comment',              array($this, 'pin_comment')); // admin-only
        add_action('wp_ajax_vibe_get_comment_count',        array($this, 'get_comment_count'));
        add_action('wp_ajax_nopriv_vibe_get_comment_count', array($this, 'get_comment_count'));
        add_action('wp_ajax_vibe_load_replies',              array($this, 'load_replies'));
        add_action('wp_ajax_nopriv_vibe_load_replies',       array($this, 'load_replies'));

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
        $this->purge_reply_cache_if_needed($comment);
        // Reply push (v3.7.0): an approval making a REPLY public is the
        // notify event for the parent's author. The class self-guards:
        // unavailable rail, non-reply, self-reply, dedup — all no-op.
        Vibe_Comments_Reply_Push::notify_parent($comment);
        // Reply email (v3.9.0): same approval event through wp_mail() —
        // free, unlimited, any-server (SMTP constants or server mail).
        Vibe_Comments_Reply_Email::notify_parent($comment);
        // Mentions (v3.8.0): same approval event, mention-shaped payloads
        // to @mentioned authors. Self-guards mirror notify_parent()'s.
        Vibe_Comments_Mentions::notify_mentioned($comment);
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
        $this->purge_reply_cache_if_needed($comment);
    }

    /**
     * Fires when wp_set_comment_status() is called directly (trash, spam, unspam).
     * Recalculates the count for any status change — simpler than tracking direction.
     */
    public function on_comment_status_set($comment_id, $new_status) {
        $comment = get_comment($comment_id);
        if (!$comment) return;
        $this->sync_and_purge(intval($comment->comment_post_ID));
        $this->purge_reply_cache_if_needed($comment);
        // Reply push (v3.7.0): wp_set_comment_status('approve') is the
        // moderated-approval path (admin queue). Same self-guards; the
        // class's per-process dedup makes the dual-hook overlap safe.
        Vibe_Comments_Reply_Push::notify_parent($comment);
        // Reply email (v3.9.0) — moderated-approval path, wp_mail().
        Vibe_Comments_Reply_Email::notify_parent($comment);
        // Mentions (v3.8.0): same moderated-approval path, mention-shaped.
        Vibe_Comments_Mentions::notify_mentioned($comment);
    }

    /**
     * v3.4.0: if $comment is a reply (comment_parent > 0), bust its specific
     * parent's cached subtree (vc_replies_{parent}) so the change — new
     * reply, deleted reply, approved reply — is visible the next time
     * someone expands that exact thread, rather than waiting out the 120s
     * transient TTL. Scoped to the one known parent; no enumeration needed.
     * Top-level comments (parent=0) have nothing to purge here — their
     * reply_count is recomputed fresh on every load_comments() call already.
     */
    private function purge_reply_cache_if_needed($comment) {
        if (!$comment || !is_object($comment)) return;
        $parent = intval($comment->comment_parent);
        if ($parent > 0) {
            delete_transient('vc_replies_' . $parent);
        }
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
        // Primary purpose now: bust get_comment_count()'s short-TTL cache so the
        // next heading-refresh request (fired on every page load, see vibe-comments.js)
        // picks up the new count immediately instead of waiting out the TTL.
        delete_transient('vibe_count_' . $post_id);
        // NOTE: purge_page_cache() (full LiteSpeed/Cloudflare/W3TC/etc. page purge)
        // is intentionally NOT called here as of v3.3.3. It existed solely to keep
        // the comment-count heading fresh in the cached page HTML — that job is now
        // handled by the decoupled get_comment_count() endpoint below, which updates
        // just the heading text on page load without evicting the whole cached page.
        // A full page purge on every single comment was the actual scalability cost:
        // it evicted images/body/everything for one number, and still didn't deliver
        // instant freshness (the NEXT visitor paid the regeneration cost, not this one).
        // purge_page_cache() remains available below for direct/manual use if a future
        // need calls for a real full-page purge on comment events.
        $this->purge_comments_data_cache($post_id);
    }

    /**
     * Return a fresh nonce — rate-limited to prevent rapid-fire abuse.
     * Returns 429 when the cooldown transient is active (M3 fix — the original
     * code checked the transient but always sent a nonce anyway). The frontend
     * handles a failed nonce refresh gracefully; the old cached nonce remains
     * valid for up to 24 hours so rejecting a rapid-fire refresh is safe.
     */
    public function refresh_nonce() {
        $ip       = Vibe_Comments_Database::resolve_client_ip();
        $rate_key = 'vn_' . substr( md5( $ip ), 0, 16 );

        // Enforce a 2-second cooldown. The original code checked the transient
        // but always sent a new nonce regardless — the rate limit was decorative.
        // Now: if the key exists (request within last 2s), return the error.
        // The frontend catches a failed nonce refresh gracefully — the old cached
        // nonce remains valid for up to 24 hours, so this is safe to reject.
        if ( get_transient( $rate_key ) ) {
            wp_send_json_error( array( 'message' => __('Too many requests. Please wait a moment.', 'vibe-comments') ), 429 );
            return;
        }

        set_transient( $rate_key, 1, 2 );
        wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
    }

    /**
     * Full page cache purge across all major cache plugins (LiteSpeed,
     * Cloudflare API, WP Rocket, W3 Total Cache, WP Super Cache, Comet Cache).
     *
     * As of v3.3.3, no longer called automatically on new comments — its only
     * comment-related job (keeping the comment-count heading fresh) is now
     * handled by get_comment_count(), a decoupled lightweight endpoint that
     * updates just the heading text without evicting the entire cached page.
     * Kept available for direct/manual use — e.g. wire this back into
     * sync_and_purge() if some future feature needs a genuine full-page purge
     * on comment events, not just a count refresh.
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
     * Lightweight, decoupled comment-count endpoint (v3.3.3).
     *
     * Lets the "N Comments" heading self-correct to the live count on every
     * page load WITHOUT a full-page cache purge on every comment event. The
     * static cached page renders with whatever count was accurate at
     * cache-build time; this endpoint patches just the heading text
     * client-side (see fetchCommentCount() in vibe-comments.js).
     *
     * Scalability design:
     *   - Reads vibe_comment_count_{id}, an option already kept synchronously
     *     correct by sync_and_purge() on every comment event — zero live
     *     get_comments() COUNT query needed here, just an options read.
     *   - Wrapped in a short (20s) transient so concurrent requests across many
     *     simultaneous visitors collapse into a single DB read per TTL window,
     *     not one per visitor. sync_and_purge() busts this transient
     *     immediately on every comment event, so the window between "comment
     *     posted" and "endpoint reflects it" is at most the time until the
     *     next request after that, not the full 20s.
     *   - Sends a public, short-TTL Cache-Control header. [Likely, not
     *     guaranteed] some LiteSpeed/Cloudflare configurations cache this at
     *     the edge too, in which case most requests never reach PHP at all —
     *     but many cache-plugin defaults blanket-exclude admin-ajax.php
     *     regardless of response headers, so don't treat edge caching as a
     *     given. The transient layer above is the part that reliably protects
     *     the DB regardless of edge config.
     *   - No nonce required: read-only, zero side effects, same threat model
     *     as the public load_comments GET endpoint.
     */
    public function get_comment_count() {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        if (!$post_id || !get_post($post_id)) {
            wp_send_json_error(array('message' => __('Invalid post.', 'vibe-comments')));
            return;
        }

        $cache_key = 'vibe_count_' . $post_id;
        $count     = get_transient($cache_key);

        if (false === $count) {
            $count = (int) get_option('vibe_comment_count_' . $post_id, 0);
            set_transient($cache_key, $count, 20);
        }

        header('Cache-Control: public, max-age=20');
        wp_send_json_success(array('count' => (int) $count));
    }

    /**
     * Load comments for a post — paginated, top-level only (see reply_count
     * on each entry; full reply subtrees are fetched on demand via
     * load_replies()).
     *
     * CACHING STRATEGY (as of v3.5.0):
     *   Layer 1 — Transient (server, DB or Redis/Memcached if configured):
     *     The formatted comment JSON — WITHOUT any visitor-specific data —
     *     is stored in a transient keyed by post_id + page + per_page, 120s
     *     TTL. Subsequent requests within that window skip the comment-list
     *     query, get_replies_map(), and reaction-count queries entirely.
     *
     *   Layer 2 — apply_user_reactions_overlay() (never cached, every request):
     *     The requester's own user_reaction values are resolved and patched
     *     onto the response fresh, after retrieval from cache or a live
     *     query, every single time. This is why Cache-Control below is
     *     `private`, not `public`/`s-maxage` — every response this endpoint
     *     sends is personalized to whoever asked for it, so it must never be
     *     stored by a shared/edge cache (Cloudflare, LiteSpeed's edge tier,
     *     a corporate proxy) and replayed to a different visitor. Before this
     *     was added, this docblock (and the actual headers) correctly said
     *     `public, s-maxage=120` — that was only safe back when the response
     *     genuinely contained nothing visitor-specific.
     *
     *   Invalidation:
     *     The transient layer is cleared when a comment is approved, deleted,
     *     or reacted to, via purge_comments_data_cache() / sync_and_purge().
     */
    public function load_comments() {
        global $wpdb; // declared once here — used in both polling and non-polling branches
        $post_id  = isset($_GET['post_id'])  ? absint($_GET['post_id'])                    : 0;
        $page     = isset($_GET['page'])     ? max(1, absint($_GET['page']))               : 1;
        $per_page = isset($_GET['per_page']) ? min(50, max(1, absint($_GET['per_page']))) : 10;
        $since    = isset($_GET['since'])    ? absint($_GET['since'])                      : 0;

        if (!$post_id) {
            wp_send_json_error(array('message' => __('Invalid post.', 'vibe-comments')));
            return;
        }

        // Neither this nor load_replies() previously checked whether the
        // CURRENT VISITOR is actually allowed to see this post at all — only
        // that it existed and had approved comments. A post moved to draft
        // or private after collecting comments (a normal editorial action,
        // not an edge case), or password-protected, would still serve its
        // full comment content — author names, text, timestamps — to anyone
        // who called this endpoint with the post_id, completely bypassing
        // whatever visibility WordPress itself enforces on the post.
        //
        // FIX: current_user_can('read_post', ...) was the wrong check here.
        // For a PUBLISHED post, that meta capability resolves (per WordPress
        // core's own map_meta_cap()) to the PRIMITIVE 'read' capability — and
        // 'read' is only granted to authenticated roles (Subscriber and up).
        // An anonymous, logged-out visitor has zero roles and zero
        // capabilities, 'read' included — meaning this check returned FALSE
        // for every single anonymous visitor on every single post, published
        // or not. Since anonymous visitors are the overwhelming majority of
        // real traffic on any public blog, this rejected almost every
        // legitimate load_comments() call the moment it shipped.
        //
        // Corrected logic: a post is visible if its status is one of
        // WordPress's own registered PUBLIC statuses (get_post_stati() with
        // 'public' => true — 'publish' by default, but this also correctly
        // picks up any custom public statuses a site or plugin registers,
        // rather than hardcoding the literal string 'publish'). Only when
        // the post is NOT publicly visible does it fall back to
        // current_user_can('read_post', ...) — which is exactly the right
        // tool for THAT narrower question ("can this specific logged-in
        // user, e.g. the post's author or an editor, see their own
        // draft/private post"), rather than the wrong tool for "can the
        // general public see this at all."
        $post = get_post($post_id);
        $public_statuses    = get_post_stati(array('public' => true));
        $is_publicly_viewable = $post && in_array($post->post_status, $public_statuses, true);
        if (!$post || (!$is_publicly_viewable && !current_user_can('read_post', $post_id)) || post_password_required($post)) {
            wp_send_json_error(array('message' => __('Invalid post.', 'vibe-comments')));
            return;
        }

        // Resolved BEFORE the cache check on purpose. The comment list itself
        // (vc_load_* transient) is cached with NO user identity in its key —
        // it's the same cached blob served to every visitor of this post/page.
        // user_reaction must therefore NEVER be baked into that cached payload;
        // it's computed fresh per request and overlaid onto the comments array
        // AFTER retrieval, whether that retrieval was a cache hit or a fresh
        // query — see apply_user_reactions_overlay() below.
        $user_id     = get_current_user_id();
        $client_id   = isset($_GET['vibe_guest_id']) ? sanitize_text_field(wp_unslash($_GET['vibe_guest_id'])) : '';
        $guest_token = ($user_id > 0) ? '' : Vibe_Comments_Database::get_guest_token($client_id);

        // Polling requests (since > 0) are never cached — they're checking for
        // new comments in real time and must always hit the DB.
        $is_polling = $since > 0;

        if (!$is_polling) {
            // ── Layer 1: transient cache ─────────────────────────────────
            $cache_key = 'vc_load_' . $post_id . '_' . $page . '_' . $per_page;
            $cached    = get_transient($cache_key);
            if (false !== $cached) {
                $this->apply_user_reactions_overlay($cached, $user_id, $guest_token);
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
            // v3.4.0: newest top-level comments first by default (was ASC).
            // See vibe-comments.js initSortToggle() — the client-side toggle's
            // "restore original order" behavior now means "newest first",
            // since that's what this query sends as the default load order.
            'order'   => 'DESC',
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

        // v3.4.0: replies are no longer embedded in the initial payload — only
        // a reply_count per thread. Actual reply content is fetched on demand
        // via vibe_load_replies when the user clicks "View N replies" (see
        // load_replies() below). get_replies_map() is scoped to just THIS
        // page's top-level IDs via IN(), not a whole-post scan like the old
        // get_children_map() — cheap even when computing counts for many threads.
        $top_level_ids = array_map(function($c) { return (int) $c->comment_ID; }, $comments);
        $replies_map   = $db->get_replies_map($post_id, $top_level_ids, 4);
        $reactions_map = $db->get_reaction_counts_batch($top_level_ids);

        if (!empty($top_level_ids)) {
            update_meta_cache('comment', $top_level_ids);
        }

        $formatted = array();
        foreach ($comments as $comment) {
            $cid = (int) $comment->comment_ID;
            // depth=0: format_comment_tree() will not recurse into $replies_map
            // at all — children always come back empty. reply_count is added
            // separately so the client can render a "View N replies" link.
            $row = $this->format_comment_tree($comment, 0, $now, array(), $reactions_map, $post_author_id);
            $row['reply_count'] = $db->count_descendants($cid, $replies_map);
            $formatted[] = $row;
        }

        $result = array(
            'comments'        => $formatted,
            'total_count'     => $total_count,
            'top_level_count' => $top_level_count,
            'page'            => $page,
            'per_page'        => $per_page,
            'has_more'        => ($page * $per_page) < $top_level_count,
        );

        // Store in transient BEFORE the per-requester overlay below — this is
        // the anonymous, shared version (every comment's user_reaction is
        // still null at this point, from format_comment_tree()'s default).
        // Polling requests are excluded — they must always reflect live data.
        if (!$is_polling) {
            set_transient($cache_key, $result, 120);
        } else {
            // Polling with new comments: return fresh reaction counts for visible IDs.
            if (!empty($reaction_ids)) {
                $result['reaction_counts'] = $db->get_reaction_counts_batch($reaction_ids);
            }
        }

        // Personalize the response actually being sent, whether it was just
        // cached (anonymous) or is a polling response (never cached at all).
        // See apply_user_reactions_overlay() docblock for why this must never
        // be baked into the cached blob itself.
        $this->apply_user_reactions_overlay($result, $user_id, $guest_token);

        if (!$is_polling) {
            $this->set_public_cache_headers();
        } else {
            header('Cache-Control: no-store, private');
        }

        wp_send_json_success($result);
    }

    /**
     * On-demand reply fetch (v3.4.0) — fired when the user clicks
     * "View N replies" on a top-level comment. Returns that comment's
     * COMPLETE nested subtree (all levels) in one request, fully formatted
     * and ready to render via the same buildCommentTree() the client already
     * uses — so expanding a thread reveals the whole conversation in one
     * click, not one click per nesting level.
     *
     * Public, no nonce required: read-only, zero side effects, same threat
     * model as load_comments(). Cached for 120s per comment_id so a popular
     * thread being expanded by many concurrent visitors collapses to one
     * DB round-trip per cache window, not one per visitor.
     */
    public function load_replies() {
        $post_id    = isset($_GET['post_id'])    ? absint($_GET['post_id'])    : 0;
        $comment_id = isset($_GET['comment_id']) ? absint($_GET['comment_id']) : 0;

        if (!$post_id || !$comment_id) {
            wp_send_json_error(array('message' => __('Invalid request.', 'vibe-comments')));
            return;
        }

        // Same reasoning and same fix as load_comments() — see that function's
        // comment for the full explanation. This endpoint had the identical gap,
        // including the same now-corrected mistake with current_user_can('read_post').
        $post = get_post($post_id);
        $public_statuses      = get_post_stati(array('public' => true));
        $is_publicly_viewable = $post && in_array($post->post_status, $public_statuses, true);
        if (!$post || (!$is_publicly_viewable && !current_user_can('read_post', $post_id)) || post_password_required($post)) {
            wp_send_json_error(array('message' => __('Invalid request.', 'vibe-comments')));
            return;
        }

        $root = get_comment($comment_id);
        if (!$root || intval($root->comment_post_ID) !== $post_id || $root->comment_approved !== '1') {
            wp_send_json_error(array('message' => __('Comment not found.', 'vibe-comments')));
            return;
        }

        // Same reasoning as load_comments(): vc_replies_{id} has no user
        // identity in its key, so it must stay anonymous. Resolved here,
        // before the cache check, for the same reason.
        $user_id     = get_current_user_id();
        $client_id   = isset($_GET['vibe_guest_id']) ? sanitize_text_field(wp_unslash($_GET['vibe_guest_id'])) : '';
        $guest_token = ($user_id > 0) ? '' : Vibe_Comments_Database::get_guest_token($client_id);

        // Rate limit: 1 request per 2 seconds per IP+comment. This endpoint
        // had no rate limiting at all before this fix — every other AJAX
        // action in this file has one. Its cache is keyed per comment_id, so
        // unlike load_comments() (whose cache key is shared across an entire
        // page of comments), an attacker enumerating many different
        // comment_ids bypasses the cache benefit entirely; nothing else was
        // bounding request volume.
        $ip        = $this->get_remote_ip();
        $rate_key  = 'vlr_' . substr(md5($ip . $comment_id), 0, 16);
        if (get_transient($rate_key)) {
            wp_send_json_error(array('message' => __('Too many requests.', 'vibe-comments')), 429);
            return;
        }
        set_transient($rate_key, 1, 2);

        $cache_key = 'vc_replies_' . $comment_id;
        $cached    = get_transient($cache_key);
        if (false !== $cached) {
            $this->apply_user_reactions_overlay($cached, $user_id, $guest_token, 'replies');
            $this->set_public_cache_headers();
            wp_send_json_success($cached);
            return;
        }

        $db             = new Vibe_Comments_Database();
        $now            = current_time('timestamp', true);
        $post_author_id = intval(get_post_field('post_author', $post_id));

        // Fetch the entire subtree below $comment_id in one scoped call —
        // not the whole post's replies, only this thread's descendants.
        $replies_map = $db->get_replies_map($post_id, array($comment_id), 4);

        if (empty($replies_map[$comment_id])) {
            // Reply count said >0 but nothing's there now (e.g. all replies
            // were deleted/unapproved since the count was last cached).
            $result = array('replies' => array());
            set_transient($cache_key, $result, 120);
            $this->set_public_cache_headers();
            wp_send_json_success($result);
            return;
        }

        $all_ids = $this->collect_all_ids(array($root), $replies_map);
        $reactions_map = $db->get_reaction_counts_batch($all_ids);

        if (!empty($all_ids)) {
            update_meta_cache('comment', $all_ids);
        }

        // depth=3: recurse through the full fetched subtree — grandchildren
        // and deeper render immediately too, no further clicks needed.
        $replies = array();
        foreach ($replies_map[$comment_id] as $child) {
            $replies[] = $this->format_comment_tree($child, 3, $now, $replies_map, $reactions_map, $post_author_id);
        }

        $result = array('replies' => $replies);
        // Cache the anonymous version (user_reaction still null on every entry
        // at this point) BEFORE overlaying this specific requester's real state.
        set_transient($cache_key, $result, 120);
        $this->apply_user_reactions_overlay($result, $user_id, $guest_token, 'replies');
        $this->set_public_cache_headers();
        wp_send_json_success($result);
    }

    /**
     * Set Cache-Control headers for a load_comments()/load_replies() response.
     *
     * IMPORTANT: `private`, not `public`. Every response from these two
     * endpoints now carries the REQUESTER'S OWN user_reaction values on every
     * comment (see apply_user_reactions_overlay()) — the underlying comment
     * LIST is still cached anonymously via the vc_load_ and vc_replies_
     * transients (that layer is unaffected and still avoids the DB), but the
     * actual HTTP RESPONSE sent to any one visitor is personalized to them.
     * `public, s-maxage=120` (the previous value) explicitly permits shared
     * caches — Cloudflare, LiteSpeed's edge tier, corporate proxies — to
     * store ONE visitor's response and replay it to every OTHER visitor of
     * the same post for up to 120 seconds, meaning visitor B could be shown
     * visitor A's private "you reacted with ❤️" state as if it were their
     * own. `private` still permits the REQUESTER'S OWN browser to reuse its
     * own response (e.g. back/forward navigation within the cache window)
     * but explicitly forbids any shared/intermediate cache from storing it.
     */
    private function set_public_cache_headers() {
        header('Cache-Control: private, max-age=120');
        header('Vary: Accept-Encoding');
        // Do NOT tag this for LiteSpeed/edge storage — see docblock above.
    }

    /**
     * Patch the current requester's own user_reaction values onto an already-
     * built comments array — applied identically whether that array came from
     * a fresh DB-backed compute or an anonymous, shared vc_load_ or vc_replies_
     * cache hit. This is deliberately a SEPARATE step from format_comment_tree()
     * rather than baked into what gets $wpdb-computed-and-cached, because the
     * cache itself has no user identity in its key — it's the same stored blob
     * served to every visitor of a given post/page. Only ONE lookup query
     * (get_user_reactions_batch, itself internally batched) runs per request,
     * regardless of how many comments are being overlaid, and it never touches
     * the transient layer at all.
     *
     * Mutates $data in place. Safe to call with an empty/zero-comment payload
     * (the early-exit branches in load_comments()) — array_map over an empty
     * array is a no-op.
     *
     * @param array  &$data        Response array with a comment-list key (each
     *                              entry either a top-level comment from
     *                              load_comments(), or a reply from
     *                              load_replies() — both share the same shape
     *                              produced by format_comment_tree()).
     * @param int    $user_id
     * @param string $guest_token
     * @param string $list_key     Which key in $data holds the comment array —
     *                              'comments' for load_comments(), 'replies'
     *                              for load_replies(). Defaults to 'comments'.
     */
    private function apply_user_reactions_overlay(array &$data, $user_id, $guest_token, $list_key = 'comments') {
        if (empty($data[$list_key]) || (!$user_id && empty($guest_token))) {
            return;
        }

        $ids = array();
        foreach ($data[$list_key] as $c) {
            if (isset($c['id'])) { $ids[] = (int) $c['id']; }
        }
        if (empty($ids)) return;

        $db = new Vibe_Comments_Database();
        $user_reactions_map = $db->get_user_reactions_batch($ids, $user_id, $guest_token);

        foreach ($data[$list_key] as &$c) {
            if (isset($c['id']) && isset($user_reactions_map[$c['id']])) {
                $c['user_reaction'] = $user_reactions_map[$c['id']];
            }
        }
        unset($c); // break the reference — defensive, prevents accidental reuse below
    }

    /**
     * Purge all cached comment data for a post.
     * Called when a comment is approved, trashed, or deleted.
     * Covers both the server-side transients and edge caches.
     *
     * @param int $post_id
     * @return void
     */
    public static function purge_comments_data_cache( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }

        // Purge transients for pages 1–5 (covers almost all real posts).
        // Deep pages (6+) rarely get cached and regenerate on demand.
        foreach ( array( 10, 20, 50 ) as $per_page ) {
            for ( $p = 1; $p <= 5; $p++ ) {
                delete_transient( 'vc_load_' . $post_id . '_' . $p . '_' . $per_page );
            }
        }

        // Tell LiteSpeed to purge all responses tagged with 'vibe-comments'.
        do_action( 'litespeed_purge_tag', 'vibe-comments' );

        // Nginx Helper (FastCGI cache purge) -- purges the specific post URL.
        // Requires Nginx Helper plugin active with "Enable Purge" and "Purge Method: FastCGI".
        // Fires the nginx_helper_purge_url action with the post permalink.
        $url = get_permalink( $post_id );
        if ( $url ) {
            do_action( "nginx_helper_purge_url", $url );
        }
        // Cloudflare: purge via Cache-Tag if CF Pro/Ent is in use.
        // For CF Free/Pro without tags: the 2-minute TTL is the fallback.
        do_action( 'cloudflare_purge_by_tags', array( 'vibe-comments-' . $post_id ) );
    }

    /**
     * Instance method wrapper for backward compatibility.
     */
    public function purge_comments_data_cache_instance( $post_id ) {
        self::purge_comments_data_cache( $post_id );
    }

    /**
     * Toggle like via admin-ajax — supports both logged-in and guest users.
     */
    public function toggle_like() {
        if (!check_ajax_referer('wp_rest', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'vibe-comments')), 403);
            return;
        }

        $comment_id    = isset($_POST['comment_id'])    ? absint($_POST['comment_id'])             : 0;
        $reaction_type = isset($_POST['reaction_type']) ? sanitize_key($_POST['reaction_type'])    : 'like';
        $user_id       = get_current_user_id();
        // H1 fix: prefer the client-supplied UUID (generated by crypto.randomUUID()
        // in the JS and persisted in localStorage). Falls back to IP+date hash when
        // absent (legacy / API calls without JS). Logged-in users don't need a token.
        $client_id   = isset($_POST['vibe_guest_id']) ? sanitize_text_field(wp_unslash($_POST['vibe_guest_id'])) : '';
        $guest_token = ($user_id > 0) ? '' : Vibe_Comments_Database::get_guest_token($client_id);

        if (!$comment_id) {
            wp_send_json_error(array('message' => __('Invalid comment.', 'vibe-comments')));
            return;
        }

        // Server-side whitelist — never trust client-supplied reaction types.
        if (!in_array($reaction_type, Vibe_Comments_Database::REACTION_TYPES, true)) {
            wp_send_json_error(array('message' => __('Invalid reaction type.', 'vibe-comments')));
            return;
        }

        // Verify the comment exists and is approved.
        $comment_obj = get_comment($comment_id);
        if (!$comment_obj || $comment_obj->comment_approved !== '1') {
            wp_send_json_error(array('message' => __('Comment not found.', 'vibe-comments')));
            return;
        }

        // Rate limit: 5 s cooldown per user/guest per comment.
        // Transients persist in DB (or Redis/Memcached) so the limit is enforced
        // across ALL PHP workers — wp_cache_set() only works within a single
        // worker process and can be bypassed under concurrent load.
        $rate_key = 'vr_' . substr( md5( ( $user_id ?: $guest_token ) . $comment_id ), 0, 16 );
        if ( get_transient( $rate_key ) ) {
            wp_send_json_error( array( 'message' => __('Please wait before reacting again.', 'vibe-comments') ) );
            return;
        }
        set_transient( $rate_key, 1, 5 );

        $db     = new Vibe_Comments_Database();
        $result = $db->toggle_reaction($comment_id, $user_id, $guest_token, $reaction_type);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }

        // Purge the load_comments response cache for this post. Without this,
        // load_comments() keeps serving its 120-second-TTL vc_load_* snapshot,
        // which embeds reaction counts as they were at the time comments were
        // last loaded — predating this reaction. The toggle_like response above
        // shows the correct new count immediately (it's read fresh from the DB
        // right above), which is why the count appears to "work" — but a page
        // refresh within that 120-second window re-triggers load_comments(),
        // which then serves the stale cached snapshot instead of querying again,
        // making the reaction look like it never saved. Reusing the same purge
        // already proven correct for comment post/delete/status-change events.
        $this->purge_comments_data_cache((int) $comment_obj->comment_post_ID);

        wp_send_json_success(array(
            'reactions'     => $result['reactions'],
            'user_reaction' => $result['user_reaction'],
        ));
    }

    public function sync_likes() {
        if (!check_ajax_referer('wp_rest', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'vibe-comments')), 403);
            return;
        }

        // Rate limit: 1 sync per 3 seconds per IP. Without this, a client with
        // a valid nonce can fire unlimited batches of 100 IDs each, triggering
        // 2 DB queries per call — a simple amplification DoS against the DB.
        $ip       = $this->get_remote_ip();
        $sync_key = 'vs_sync_' . substr(md5($ip), 0, 16);
        if (get_transient($sync_key)) {
            wp_send_json_error(array('message' => __('Too many requests.', 'vibe-comments')), 429);
            return;
        }
        set_transient($sync_key, 1, 3);

        $comment_ids = isset($_POST['comment_ids']) ? (array) $_POST['comment_ids'] : array();
        $comment_ids = array_values(array_filter(array_map('absint', array_slice($comment_ids, 0, 100))));

        if (empty($comment_ids)) {
            wp_send_json_success(array('likes' => array()));
            return;
        }

        $user_id       = get_current_user_id();
        $client_id     = isset($_POST['vibe_guest_id']) ? sanitize_text_field(wp_unslash($_POST['vibe_guest_id'])) : '';
        $guest_token   = ($user_id > 0) ? '' : Vibe_Comments_Database::get_guest_token($client_id);
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
    private function format_comment_tree($comment, $depth = 3, $now = null, array $children_map = array(), array $reactions_map = array(), $post_author_id = 0, array $user_reactions_map = array()) {
        if ($now === null) $now = current_time('timestamp', true);

        $comment_id = intval($comment->comment_ID);
        $children   = array();

        if ($depth > 0 && !empty($children_map[$comment_id])) {
            foreach ($children_map[$comment_id] as $child) {
                $children[] = $this->format_comment_tree($child, $depth - 1, $now, $children_map, $reactions_map, $post_author_id, $user_reactions_map);
            }
        }

        // Use comment_author_email when present; otherwise derive a deterministic
        // per-comment email for Gravatar so each guest gets a unique identicon.
        // $guest_token is NOT in scope here (it's only available at request time
        // in toggle_reaction) — using $comment_id alone is sufficient since it's
        // unique per row and never reused.
        $email = !empty($comment->comment_author_email)
            ? $comment->comment_author_email
            : 'vibe.guest.' . md5( 'vibe_' . $comment_id ) . '@comments.local';

        return array(
            'id'            => $comment_id,
            'author'        => $comment->comment_author,
            'avatar'        => get_avatar_url($email, array('size' => 48)),
            'date'          => human_time_diff(strtotime($comment->comment_date_gmt), $now) . ' ago',
            'date_gmt'      => $comment->comment_date_gmt,
            'content'       => $comment->comment_content,
            'parent'        => intval($comment->comment_parent),
            'reactions'     => isset($reactions_map[$comment_id]) ? $reactions_map[$comment_id] : Vibe_Comments_Database::REACTION_DEFAULTS,
            // Was entirely absent from this return array before this fix — every
            // comment sent to the client always looked un-reacted-to on first
            // render, for EVERY visitor (guest or logged-in), regardless of
            // whether they actually had an active reaction. Logged-in users only
            // ever saw it corrected because initComments() unconditionally calls
            // syncReactions() for them; guests got no equivalent call on initial
            // load and only self-corrected after the first 30s polling tick.
            'user_reaction' => isset($user_reactions_map[$comment_id]) ? $user_reactions_map[$comment_id] : null,
            'approved'      => $comment->comment_approved,
            'is_author'     => ($post_author_id && intval($comment->user_id) === $post_author_id),
            'is_pinned'     => (bool) get_comment_meta($comment_id, '_vibe_pinned', true),
            'children'      => $children,
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
            wp_send_json_error(array('message' => __('Security check failed.', 'vibe-comments')), 403);
            return;
        }

        // H1 fix: read post_id before rate-limit check so the rate-limit key can be
        // scoped to IP + post. Previously the key was IP-only, which meant two
        // unrelated people behind the same NAT could block each other from commenting
        // on completely different posts. Scoping to IP + post_id eliminates that
        // cross-post collision while still enforcing a per-post cooldown.
        $ip        = $this->get_remote_ip();
        $pre_post  = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $rate_key  = 'vs_' . substr( md5( $ip . $pre_post ), 0, 16 );
        if ( get_transient( $rate_key ) ) {
            wp_send_json_error( array( 'message' => __('Please wait a moment before posting again.', 'vibe-comments') ), 429 );
            return;
        }
        set_transient( $rate_key, 1, 5 );

        try {
            $post_id = $pre_post; // already absint'd above
            $content = isset($_POST['content']) ? sanitize_textarea_field(wp_unslash($_POST['content'])) : '';
            $parent  = isset($_POST['parent'])  ? absint($_POST['parent'])                               : 0;
            $author  = isset($_POST['author'])  ? sanitize_text_field(wp_unslash($_POST['author']))      : '';
            $email   = isset($_POST['email'])   ? sanitize_email(wp_unslash($_POST['email']))            : '';

            // Enforce the same max length the frontend JS uses.
            // 65,535 is the DB column limit; the configured value (default 2,000) is
            // the UX limit — both must be checked so a direct API call can't bypass JS.
            $max_length = (int) apply_filters('vibe_comments_max_length', 2000);
            if (mb_strlen(trim($content)) < 1) {
                wp_send_json_error(array('message' => __('Comment cannot be empty.', 'vibe-comments')));
                return;
            }
            if (mb_strlen($content) > $max_length) {
                /* translators: %d: maximum allowed comment length in characters. */
                wp_send_json_error(array('message' => sprintf(__('Comment exceeds the %d character limit.', 'vibe-comments'), $max_length)));
                return;
            }

            // Author name length: tinytext column cap is 255 bytes.
            if (mb_strlen($author) > 255) {
                wp_send_json_error(array('message' => __('Name is too long (max 255 characters).', 'vibe-comments')));
                return;
            }

            if (!$post_id) {
                wp_send_json_error(array('message' => __('Invalid post.', 'vibe-comments')));
                return;
            }

            if (!comments_open($post_id)) {
                wp_send_json_error(array('message' => __('Comments are closed for this post.', 'vibe-comments')));
                return;
            }

            $post = get_post($post_id);
            if (!$post) {
                wp_send_json_error(array('message' => __('Invalid post.', 'vibe-comments')));
                return;
            }

            if ($parent > 0) {
                $parent_comment = get_comment($parent);
                if (!$parent_comment || intval($parent_comment->comment_post_ID) !== $post_id) {
                    wp_send_json_error(array('message' => __('Invalid parent comment.', 'vibe-comments')));
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
                $author_url   = esc_url_raw($user->user_url);
                $user_id      = $user->ID;
            } else {
                if (empty($author) || empty($email)) {
                    wp_send_json_error(array('message' => __('Name and email are required for guest comments.', 'vibe-comments')));
                    return;
                }
                if (!is_email($email)) {
                    wp_send_json_error(array('message' => __('Please enter a valid email address.', 'vibe-comments')));
                    return;
                }
                $author_name  = $author;
                $author_email = $email;
                $author_url   = '';
                $user_id      = 0;
                // wp_new_comment() + pre_comment_approved filters now determine approval.
                // The old hardcoded $approved = 0 for guests bypassed Akismet and
                // Discussion Settings ("all new comments must be manually approved").
            }

            // ── wp_new_comment() — the correct insertion path ─────────────
            // Using $wpdb->insert() directly bypasses every filter WordPress
            // and anti-spam plugins hook into:
            //   - preprocess_comment    (content normalization)
            //   - pre_comment_approved  (Akismet, Antispam Bee, CleanTalk)
            //   - comment_moderation    (Discussion Settings: manual approval, word lists)
            //   - comment_whitelist     ("must have a previously approved comment")
            //
            // wp_new_comment($data, true) runs all of these AND returns a
            // WP_Error on failure instead of calling wp_die() (the 2nd arg).
            // We keep our Throwable wrapper so third-party hooks that throw
            // don't crash the AJAX response even after the comment is saved.
            $comment_data = wp_slash( array(
                'comment_post_ID'      => $post_id,
                'comment_author'       => $author_name,
                'comment_author_email' => $author_email,
                'comment_author_url'   => $author_url,
                'comment_author_IP'    => $ip,
                'comment_date'         => $now,
                'comment_date_gmt'     => $now_gmt,
                'comment_content'      => $content,
                'comment_karma'        => 0,
                'comment_agent'        => $agent,
                'comment_type'         => 'comment',
                'comment_parent'       => $parent,
                'user_id'              => $user_id,
            ) );

            $comment_id = wp_new_comment( $comment_data, true );

            if ( is_wp_error( $comment_id ) ) {
                wp_send_json_error( array( 'message' => $comment_id->get_error_message() ) );
                return;
            }

            if ( ! $comment_id ) {
                wp_send_json_error( array( 'message' => __('Failed to post comment. Please try again.', 'vibe-comments') ) );
                return;
            }

            $comment  = get_comment( $comment_id );
            if ( ! $comment || ! is_object( $comment ) ) {
                wp_send_json_error( array( 'message' => __('Comment created but could not be retrieved.', 'vibe-comments') ) );
                return;
            }

            // wp_new_comment() determines the approved value from Discussion Settings,
            // Akismet verdict, and whitelist rules. Read the actual stored value.
            $approved = $comment->comment_approved;

            // wp_new_comment() already fires comment_post, clean_comment_cache,
            // and wp_update_comment_count internally. No need to repeat them.
            // (A block here previously called clear_post_comments_cache(), which
            // only bumped a wp_cache "comments_version_*" key — verified via a
            // full-codebase grep that nothing ever reads that versioned cache;
            // load_comments() uses its own separate vc_load_* transient scheme
            // entirely. Removed as dead weight; sync_and_purge() below is the
            // real, actually-consumed cache invalidation.)

            // Only purge the page cache when the comment is immediately public.
            // Pending / spam comments don't change the visible page — cache purge
            // for those happens via on_comment_approved() when admin approves them.
            if ($approved == 1) {
                $this->sync_and_purge($post_id);
                $this->purge_reply_cache_if_needed($comment);
                // Reply push (v3.7.0) — INSTANT-APPROVAL path. wp_new_comment()
                // does NOT fire transition_comment_status on first save (that
                // hook only runs on later admin status changes), so an
                // immediately-public reply is handled HERE. The class dedup
                // makes the overlap with the status hooks double-push-proof.
                Vibe_Comments_Reply_Push::notify_parent($comment);
                // Reply email (v3.9.0) — instant-approval path, wp_mail().
                Vibe_Comments_Reply_Email::notify_parent($comment);
                // Mentions (v3.8.0) — same instant-approval path.
                Vibe_Comments_Mentions::notify_mentioned($comment);
            }

            // ── Reply push opt-in (v3.7.0) ─────────────────────────────
            // The client only sends this when the user ticked "Notify me
            // about replies" AND the browser subscription was successfully
            // created/confirmed before submit. Everything is re-validated
            // server-side (https endpoint, key round-trip); a failure here
            // is swallowed — the comment itself is already saved and must
            // never be disturbed by a push-storage problem.
            if ( ! empty( $_POST['vibe_reply_push'] )
                && is_array( $_POST['vibe_reply_push'] ) ) {
                $rp = wp_unslash( $_POST['vibe_reply_push'] );
                Vibe_Comments_Reply_Push::store(
                    $comment_id,
                    isset( $rp['endpoint'] ) && is_string( $rp['endpoint'] ) ? $rp['endpoint'] : '',
                    isset( $rp['p256dh'] )   && is_string( $rp['p256dh'] )   ? $rp['p256dh']   : '',
                    isset( $rp['auth'] )     && is_string( $rp['auth'] )     ? $rp['auth']     : ''
                );
            }

            // ── Reply EMAIL opt-in (v3.9.0) ────────────────────────────
            // Consent flag only — the notification address is always the
            // comment's own author email (anti-abuse by construction).
            // Guest submits carry it as '1'; the JS never sends it unless
            // the user ticked the box. Logged-in users always have their
            // profile email on the comment.
            if ( isset( $_POST['vibe_reply_email'] )
                && '1' === (string) wp_unslash( $_POST['vibe_reply_email'] ) ) {
                Vibe_Comments_Reply_Email::store( $comment_id );
            }

            wp_send_json_success(array(
                'comment' => array(
                    'id'        => intval($comment->comment_ID),
                    'author'    => $comment->comment_author,
                    // L2 fix: use the same hash prefix as format_comment_tree() so a guest
                    // comment shows the same identicon immediately after posting as it does
                    // after a page reload. Previously 'vibe_guest_' here vs 'vibe_' there
                    // produced different MD5 hashes → different Gravatars for the same comment.
                    'avatar'    => get_avatar_url(
                        !empty($comment->comment_author_email)
                            ? $comment->comment_author_email
                            : ('vibe.guest.' . md5('vibe_' . $comment->comment_ID) . '@comments.local'),
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
                'awaiting_moderation' => ($approved != 1),
            ));

        } catch (Throwable $e) {
            error_log('Vibe Comments AJAX Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error(array('message' => __('An error occurred. Please try again.', 'vibe-comments')));
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
            wp_send_json_error(array('message' => __('Permission denied.', 'vibe-comments')));
            return;
        }

        $comment_id = absint($_POST['comment_id'] ?? 0);
        if (!$comment_id || !get_comment($comment_id)) {
            wp_send_json_error(array('message' => __('Invalid comment.', 'vibe-comments')));
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
