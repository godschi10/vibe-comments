<?php
/**
 * Plugin Name:       Vibe Comments
 * Plugin URI:        https://gwillchijioke.com
 * Description:       A performance-focused custom comment plugin with reactions, threaded replies, Gravatar, Google & WordPress authentication. Built with zero external dependencies and no DB bloat.
 * Version:           3.20.5
 * Author:            G-will Chijioke
 * Author URI:        https://gwillchijioke.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vibe-comments
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VIBE_COMMENTS_VERSION', '3.20.5');
define('VIBE_COMMENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VIBE_COMMENTS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include debug logger FIRST
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/debug-logger.php';

if ( defined( 'VIBE_COMMENTS_DEBUG_TOOLS' ) && VIBE_COMMENTS_DEBUG_TOOLS ) {
    vibe_log( 'Main plugin file loaded' );
}

// Activation
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-activator.php';
register_activation_hook(__FILE__, array('Vibe_Comments_Activator', 'activate'));

// Deactivation
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-deactivator.php';
register_deactivation_hook(__FILE__, array('Vibe_Comments_Deactivator', 'deactivate'));

if ( defined( 'VIBE_COMMENTS_DEBUG_TOOLS' ) && VIBE_COMMENTS_DEBUG_TOOLS ) {
    vibe_log( 'Loading core classes...' );
}

require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-database.php';

require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-rest-api.php';

require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-oauth-google.php';

require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-template-loader.php';

require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-reply-push.php';
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-reply-email.php';
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-mentions.php';
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-analytics.php';
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-spam-score.php';
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-qa.php';
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-digest.php';
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-unsubscribe.php';
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-secret.php';

require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-admin.php';
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-schema.php';
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-cli.php';

class Vibe_Comments {
    public function __construct() {
        if (defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS) {
            vibe_log('Vibe_Comments constructor called');
        }
        add_action('init', array($this, 'load_textdomain'));
        add_action('init', array($this, 'init'));
        add_action('rest_api_init', array($this, 'add_cache_headers'));

        // v3.18.0 - unsubscribe (public token rail + checkbox toggle).
        // BOOT-ORDER LAW (live-E2E catch, 2026-09-01): this MUST live in the
        // CONSTRUCTOR (plugins_loaded context), never inside init() - the
        // main class's init() method runs ON the 'init' hook, and WP never
        // dispatches a hook listener registered while that same hook is
        // mid-execution. The first attempt placed this call in init(): the
        // unsubscribe link silently rendered the homepage instead.
        try {
            Vibe_Comments_Unsubscribe::init();
        } catch (Throwable $e) {
            error_log('[Vibe Comments] FATAL in Unsubscribe::init(): ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /**
     * Load translation files from /languages. Without this call, __()/_e()
     * always fall back to the English source string even if .mo files exist -
     * the "Text Domain" plugin header alone does not wire up translations.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'vibe-comments',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }

    public function init() {
        if (defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS) {
            vibe_log('init hook fired');
        }

        $debug = defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS;

        // These two were the ONLY subsystem-touching calls in this method not
        // wrapped in try/catch(Throwable) - every other instantiation below
        // already was. If either of these throws at runtime for any reason
        // (a $wpdb issue, something host-specific), it previously took down
        // the entire site with an uncaught fatal instead of degrading
        // gracefully. Now matches the same pattern as everything else: log
        // via error_log() unconditionally (so it's visible even without
        // WP_DEBUG_LOG enabled - see debug-logger.php's own reasoning for
        // why error_log() specifically, not just vibe_log()), and let the
        // rest of the site keep functioning.
        try {
            Vibe_Comments_Activator::maybe_upgrade();
            if ($debug) { vibe_log('maybe_upgrade completed'); }
        } catch (Throwable $e) {
            error_log('[Vibe Comments] FATAL in maybe_upgrade(): ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            if ($debug) { vibe_log('maybe_upgrade ERROR: ' . $e->getMessage()); }
        }

        try {
            // JSON-LD structured data for comments (SEO).
            Vibe_Comments_Schema::init();
            // v3.15.0 - Q&A mode (meta box + accept-answer AJAX).
            Vibe_Comments_QA::init();
            // v3.17.0 - daily digest (cron worker + settings + preview).
            Vibe_Comments_Digest::init();
            if ($debug) { vibe_log('Schema instantiated'); }
        } catch (Throwable $e) {
            error_log('[Vibe Comments] FATAL in Schema::init(): ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            if ($debug) { vibe_log('Schema ERROR: ' . $e->getMessage()); }
        }

        try {
            new Vibe_Comments_REST_API();
            if ($debug) { vibe_log('REST API instantiated'); }
        } catch (Throwable $e) {
            // Always hit PHP's native log regardless of the debug flag - a fatal
            // subsystem failure must never go completely unrecorded.
            error_log('[Vibe Comments] REST API failed to load: ' . $e->getMessage());
            if ($debug) { vibe_log('REST API ERROR: ' . $e->getMessage()); }
        }

        // Only register OAuth hooks if Google login is enabled in settings.
        // Default: true if client credentials already exist (preserves existing installs).
        $vibe_google_settings = get_option('vibe_comments_google_settings', array());
        $vibe_google_enabled  = isset($vibe_google_settings['enable_google_login'])
            ? !empty($vibe_google_settings['enable_google_login'])
            : !empty($vibe_google_settings['client_id']);

        if ($vibe_google_enabled) {
            try {
                new Vibe_Comments_OAuth_Google();
                if ($debug) { vibe_log('OAuth instantiated'); }
            } catch (Throwable $e) {
                error_log('[Vibe Comments] OAuth failed to load: ' . $e->getMessage());
                if ($debug) { vibe_log('OAuth ERROR: ' . $e->getMessage()); }
            }
        }

        try {
            new Vibe_Comments_Template_Loader();
            if ($debug) { vibe_log('Template loader instantiated'); }
        } catch (Throwable $e) {
            error_log('[Vibe Comments] Template loader failed to load: ' . $e->getMessage());
            if ($debug) { vibe_log('Template loader ERROR: ' . $e->getMessage()); }
        }

        try {
            new Vibe_Comments_Ajax_Handler();
            if ($debug) { vibe_log('AJAX handler instantiated'); }
        } catch (Throwable $e) {
            error_log('[Vibe Comments] AJAX handler failed to load: ' . $e->getMessage());
            if ($debug) { vibe_log('AJAX handler ERROR: ' . $e->getMessage()); }
        }

        try {
            new Vibe_Comments_Admin();
            if ($debug) { vibe_log('Admin instantiated'); }
        } catch (Throwable $e) {
            error_log('[Vibe Comments] Admin failed to load: ' . $e->getMessage());
            if ($debug) { vibe_log('Admin ERROR: ' . $e->getMessage()); }
        }

        try {
            Vibe_Comments_Analytics::instance();
            if ($debug) { vibe_log('Analytics instantiated'); }
        } catch (Throwable $e) {
            error_log('[Vibe Comments] Analytics failed to load: ' . $e->getMessage());
            if ($debug) { vibe_log('Analytics ERROR: ' . $e->getMessage()); }
        }

        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        if ($debug) { vibe_log('Assets enqueue registered'); }
    }

    public function add_cache_headers() {
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return;
        }

        $route = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (strpos($route, 'vibe-comments') !== false) {
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
    }

    public function enqueue_assets() {
        // Matches class-template-loader.php's load_template() condition exactly
        // - that function was fixed in v3.5.0 to route to this plugin's
        // template whenever a post has existing comments, even if new
        // commenting is closed, so JSON-LD schema output isn't describing a
        // discussion the plugin's own UI can't display. This condition was
        // the other half of that same fix and was never updated to match -
        // the template rendered correctly, but its CSS/JS never loaded,
        // leaving visitors an unstyled heading and a "Load Comments" button
        // that did nothing when clicked.
        if ( class_exists( 'Vibe_Comments_Template_Loader' ) && Vibe_Comments_Template_Loader::should_render() ) {
            wp_enqueue_style(
                'vibe-comments',
                VIBE_COMMENTS_PLUGIN_URL . 'public/css/vibe-comments.css',
                array(),
                VIBE_COMMENTS_VERSION
            );

            wp_enqueue_script(
                'vibe-comments',
                VIBE_COMMENTS_PLUGIN_URL . 'public/js/vibe-comments.js',
                array(),
                VIBE_COMMENTS_VERSION,
                true
            );

            $vibe_gs      = get_option('vibe_comments_google_settings', array());
            $google_on    = isset($vibe_gs['enable_google_login'])
                ? !empty($vibe_gs['enable_google_login'])
                : !empty($vibe_gs['client_id']);

            wp_localize_script('vibe-comments', 'vibeComments', array(
                'nonce'            => wp_create_nonce('wp_rest'),
                'ajaxUrl'          => admin_url('admin-ajax.php'),
                'postId'           => get_the_ID(),
                'isLoggedIn'       => is_user_logged_in(),
                'isAdmin'          => current_user_can('moderate_comments'),
                // v3.7.0: reply-push client config. Empty publicKey/blank
                // flags = feature unarmed (no theme rail) - the JS never
                // shows the checkbox interactions beyond the markup that
                // is_available() already gates.
                'replyPush'        => Vibe_Comments_Reply_Push::is_available() ? array(
                    'publicKey' => Vibe_Comments_Reply_Push::public_key(),
                ) : false,
                // v3.8.0: mentionable authors for this post (autocomplete +
                // pill rendering). Client merges this seed list with a live
                // DOM scan of rendered comments - always current even mid-poll.
                'mentions'         => Vibe_Comments_Mentions::localize_data( get_the_ID() ),
                // v3.15.0: Q&A mode per post - false/absent on classic posts
                // (JS renders the classic UI), a config object on Q&A posts.
                // canAccept is requester-specific and computed fresh on every
                // page load - it never enters the shared list cache.
                'qa'               => Vibe_Comments_QA::localize_data( get_the_ID() ),
                'googleEnabled'    => $google_on,
                'maxCommentLength' => (int) apply_filters('vibe_comments_max_length', 2000),
                // Mirrors templates/comments.php's exact 3-way branch (0/1/many) so the
                // client-side heading refresh produces byte-identical grammar to the
                // server-rendered version - same simplification (2 plural forms, not a
                // full _n_noop() set), kept consistent rather than "more correct" in JS
                // only, which would make the two diverge for non-English locales.
                'oneCommentText'      => esc_html__('1 Comment', 'vibe-comments'),
                /* translators: %s = formatted number, e.g. "12 Comments" */
                'manyCommentsTemplate' => esc_html__('%s Comments', 'vibe-comments'),
                'debug'                 => defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS,
                // v3.19.0: ALL client-facing strings flow through here so a
                // translated install gets a translated UI. Source-language
                // English is the default inside __() itself - no translation
                // file needed to render, only to localize. str('key') in JS.
                'i18n'                  => array(
                    'loading'            => __('Loading…', 'vibe-comments'),
                    'loadingDots'        => __('Loading...', 'vibe-comments'),
                    'loadMore'           => __('Load More Comments', 'vibe-comments'),
                    'readMore'           => __('Read more', 'vibe-comments'),
                    'showLess'           => __('Show less', 'vibe-comments'),
                    'saving'             => __('Saving...', 'vibe-comments'),
                    'save'               => __('Save', 'vibe-comments'),
                    'edited'             => __('(edited)', 'vibe-comments'),
                    'discard'            => __('Discard', 'vibe-comments'),
                    'hideGuestForm'      => __('Hide Guest Form', 'vibe-comments'),
                    'commentAsGuest'     => __('Comment as Guest', 'vibe-comments'),
                    'hideReplies'        => __('Hide replies', 'vibe-comments'),
                    'viewReplies'        => __('View replies', 'vibe-comments'),
                    'connecting'         => __('Connecting…', 'vibe-comments'),
                    'searchComments'     => __('Search comments…', 'vibe-comments'),
                    'typeAtLeast2'       => __('Type at least 2 characters…', 'vibe-comments'),
                    'pinned'             => __('📌 Pinned', 'vibe-comments'),
                    'accepted'           => __('✓ Accepted', 'vibe-comments'),
                    'couldNotLoad'       => __('Could not load comments.', 'vibe-comments'),
                    'tryAgain'           => __('Try again', 'vibe-comments'),
                    'noCommentsYet'      => __('No comments yet', 'vibe-comments'),
                    'beFirst'            => __('Be the first to share your thoughts ✨', 'vibe-comments'),
                    'noCommentsFound'    => __('No comments found', 'vibe-comments'),
                    'tryDifferent'       => __('Try a different search term 🔎', 'vibe-comments'),
                    'found'              => __('%s found', 'vibe-comments'),
                    'showingFirst50'     => __(' (showing first 50)', 'vibe-comments'),
                    'commentingAs'       => __('Commenting as', 'vibe-comments'),
                    'notYou'             => __('Not you?', 'vibe-comments'),
                    'editFailed'         => __('Your comment didn\'t post. Check your connection and try again.', 'vibe-comments'),
                    'thanksNoName'      => __('Thanks! Your comment is pending review.', 'vibe-comments'),
                    'liveNoName'        => __('Your comment is live!', 'vibe-comments'),
                    'serverError'       => __('Couldn\'t reach the server for that. Try again in a moment.', 'vibe-comments'),
                    'searchAria'        => __('Search comments', 'vibe-comments'),
                    'pushBlocked'       => __('Notifications are blocked for this site in your browser settings.', 'vibe-comments'),
                    'reactFailed'       => __('Failed to react.', 'vibe-comments'),
                    'editFailedShort'   => __('Edit failed.', 'vibe-comments'),
                    'draftRestored'     => __('Draft restored - ', 'vibe-comments'),
                    'errWriteComment'   => __('Please write a comment.', 'vibe-comments'),
                    'errNameEmail'      => __('Please enter your name and email to comment.', 'vibe-comments'),
                    'errValidEmail'     => __('Please enter a valid email address.', 'vibe-comments'),
                    'serverFatal'       => __('A server fatal error occurred. Check error logs or contact support.', 'vibe-comments'),
                    'invalidResponse'   => __('Server returned an invalid response. Check browser console for details.', 'vibe-comments'),
                    'postFailed'        => __('Failed to post comment.', 'vibe-comments'),
                    'googleNotConfig'   => __('Google authentication is not configured.', 'vibe-comments'),
                    'googleLoginFailed' => __('Failed to initiate Google login.', 'vibe-comments'),
                    'reply'              => __('Reply', 'vibe-comments'),
                    'viewReplyCount'     => __('View %s reply', 'vibe-comments'),
                    'viewReplyCounts'    => __('View %s replies', 'vibe-comments'),
                    'pin'                => __('Pin', 'vibe-comments'),
                    'unpin'              => __('Unpin', 'vibe-comments'),
                    'unaccept'           => __('Unaccept', 'vibe-comments'),
                    'accept'             => __('✓ Accept', 'vibe-comments'),
                    'author'             => __('Author', 'vibe-comments'),
                    'pinnedBadge'        => __('📌 Pinned', 'vibe-comments'),
                    'notifyTitle'        => __('Reply alerts for this thread (emails and browser notifications) - click to switch', 'vibe-comments'),
                    'bellOn'             => __('🔔 On', 'vibe-comments'),
                    'bellOff'            => __('🔕 Off', 'vibe-comments'),
                    'noPushSupport'      => __('This browser does not support notifications.', 'vibe-comments'),
                    'pushWillNotify'     => __('You will get a push notification on this device when someone replies to your comment.', 'vibe-comments'),
                    'pushEnableFail'     => __('Could not enable notifications on this device.', 'vibe-comments'),
                    'thanksPending'      => __('Thanks %s! Your comment is pending review.', 'vibe-comments'),
                    'thanksLive'         => __('Thanks %s! Your comment is now live.', 'vibe-comments'),
                    'reactLike'          => __('Like', 'vibe-comments'),
                    'reactLove'          => __('Love', 'vibe-comments'),
                    'reactFire'          => __('Fire', 'vibe-comments'),
                    'reactHaha'          => __('Haha', 'vibe-comments'),
                    'editComment'       => __('Edit your comment', 'vibe-comments'),
                    'edit'              => __('Edit', 'vibe-comments'),
                    'cancel'            => __('Cancel', 'vibe-comments'),
                    'editWindow'        => __('5-minute window', 'vibe-comments'),
                    'editEmpty'         => __('Write something first.', 'vibe-comments'),
                    'sortNewest'        => __('Newest first', 'vibe-comments'),
                    'sortOldest'        => __('Oldest first', 'vibe-comments'),
                    'sortTop'           => __('Top - most reacted', 'vibe-comments'),
                    'bannerNew'         => __('↑ %s new - load', 'vibe-comments'),
                    'bannerComment'     => __('comment', 'vibe-comments'),
                    'bannerComments'    => __('comments', 'vibe-comments'),
                ),
            ));
        }
    }
}

try {
    new Vibe_Comments();
    if (defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS) {
        vibe_log('Main class instantiated successfully');
    }
} catch (Throwable $e) {
    // This is the single point of total plugin failure - always record it
    // regardless of the debug flag. Without this, the entire plugin can die
    // silently with zero trace anywhere on the server.
    error_log('[Vibe Comments] FATAL - plugin failed to initialize: ' . $e->getMessage());
    if (defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS) {
        vibe_log('Main class ERROR: ' . $e->getMessage());
    }
}

