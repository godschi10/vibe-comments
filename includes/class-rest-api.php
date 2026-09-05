<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Vibe Comments REST API
 *
 * The plugin's primary AJAX surface is admin-ajax.php (class-ajax-handler.php).
 * This class registers only debug/diagnostic REST routes (/test,
 * /debug-comment, both gated behind VIBE_COMMENTS_DEBUG_TOOLS) - it does NOT
 * register the Google OAuth callback route. That route (/google-callback) is
 * registered entirely independently, in class-oauth-google.php's own
 * register_callback_route(), hooked separately to rest_api_init. (This
 * docblock previously claimed this class registered the OAuth callback -
 * it never did; that was a separate stale-comment issue from the "WP_DEBUG
 * mode" phrasing fixed elsewhere in this same file.)
 *
 * All OTHER REST endpoints that existed in earlier versions have been
 * removed because:
 *
 *   1. The JS frontend no longer calls them (migrated to admin-ajax in v2.0.0).
 *   2. The DB methods they depended on (toggle_like, user_has_liked, etc.) were
 *      replaced by the reactions system and no longer exist. Leaving the routes
 *      registered would cause a PHP fatal error on any direct HTTP request.
 *   3. Their rate limiters used wp_cache_get/set (worker-local), which is
 *      bypassable under concurrent load - the security model was wrong.
 *
 * Debug endpoints are gated behind the VIBE_COMMENTS_DEBUG_TOOLS constant, not
 * WP_DEBUG - see debug-logger.php for why: WP_DEBUG is commonly left on in
 * production for error capture, which is not the same as consenting to expose
 * debug-only REST routes.
 */
class Vibe_Comments_REST_API {
    private $namespace = 'vibe-comments/v1';

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        // ── Debug only ──────────────────────────────────────────────────
        // Gated behind VIBE_COMMENTS_DEBUG_TOOLS (not WP_DEBUG).
        // WP_DEBUG is often enabled on production sites for error logging
        // - that should not silently expose a comment insertion endpoint.
        // Add to wp-config.php to enable:
        //   define('VIBE_COMMENTS_DEBUG_TOOLS', true);
        if (defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS) {
            register_rest_route($this->namespace, '/test', array(
                'methods'             => 'GET',
                'callback'            => function() {
                    return new WP_REST_Response(array(
                        'success' => true,
                        'message' => 'Vibe Comments REST API is working',
                        'version' => VIBE_COMMENTS_VERSION,
                    ), 200);
                },
                'permission_callback' => function() { return current_user_can('manage_options'); },
            ));

            register_rest_route($this->namespace, '/debug-comment', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'debug_comment'),
                'permission_callback' => function() { return current_user_can('manage_options'); },
                'args' => array(
                    'post_id' => array('required' => true, 'validate_callback' => 'is_numeric'),
                    'content' => array('required' => true),
                    'author'  => array('default' => ''),
                    'email'   => array('default' => ''),
                ),
            ));
        }
    }

    /**
     * Debug endpoint - admin + VIBE_COMMENTS_DEBUG_TOOLS only.
     * Step-by-step comment insertion for diagnosing integration issues.
     */
    public function debug_comment($request) {
        $steps = array();
        try {
            $post_id = absint($request->get_param('post_id'));
            $content = sanitize_textarea_field($request->get_param('content'));
            $author  = sanitize_text_field($request->get_param('author'));
            $email   = sanitize_email($request->get_param('email'));

            $steps[] = 'Step 1: Params parsed  -  post_id=' . $post_id;

            if (!comments_open($post_id)) {
                return new WP_REST_Response(array('steps' => $steps, 'error' => 'Comments closed'), 200);
            }
            $steps[] = 'Step 2: Comments are open';

            $post = get_post($post_id);
            if (!$post) {
                return new WP_REST_Response(array('steps' => $steps, 'error' => 'Post not found'), 200);
            }
            $steps[] = 'Step 3: Post exists  -  ' . esc_html($post->post_title);

            $comment_data = wp_slash(array(
                'comment_post_ID'      => $post_id,
                'comment_content'      => $content,
                'comment_parent'       => 0,
                'comment_type'         => 'comment',
                'comment_date'         => current_time('mysql'),
                'comment_date_gmt'     => current_time('mysql', 1),
                'comment_approved'     => 1,
                'comment_author'       => $author ?: 'Anonymous',
                'comment_author_email' => $email  ?: 'anonymous@example.com',
                'comment_author_url'   => '',
                'comment_author_IP'    => Vibe_Comments_Database::resolve_client_ip(),
                'comment_karma'        => 0,
                'user_id'              => 0,
            ));
            $steps[] = 'Step 4: Comment data built';

            $comment_id = wp_insert_comment($comment_data);
            $steps[] = 'Step 5: wp_insert_comment returned ' . ($comment_id ? 'ID=' . $comment_id : 'FALSE');

            if ($comment_id) {
                try {
                    do_action('comment_post', $comment_id, 1, $comment_data);
                    $steps[] = 'Step 6: comment_post action fired';
                } catch (Throwable $e) {
                    $steps[] = 'Step 6: comment_post FAILED  -  ' . $e->getMessage();
                }
                return new WP_REST_Response(array('success' => true, 'steps' => $steps, 'comment_id' => $comment_id), 200);
            }

            global $wpdb;
            $steps[] = 'DB last_error: ' . ($wpdb->last_error ?: 'none');
            return new WP_REST_Response(array('steps' => $steps, 'error' => 'wp_insert_comment returned false'), 200);

        } catch (Throwable $e) {
            $steps[] = 'FATAL: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            return new WP_REST_Response(array('steps' => $steps, 'error' => $e->getMessage()), 500);
        }
    }
}
