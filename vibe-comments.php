<?php
/**
 * Plugin Name:       Vibe Comments
 * Plugin URI:        https://gwillchijioke.com
 * Description:       A performance-focused custom comment plugin with reactions, threaded replies, Gravatar, Google & WordPress authentication. Built with zero external dependencies and no DB bloat.
 * Version:           3.5.7
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

define('VIBE_COMMENTS_VERSION', '3.5.7');
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
    }

    /**
     * Load translation files from /languages. Without this call, __()/_e()
     * always fall back to the English source string even if .mo files exist —
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
        // wrapped in try/catch(Throwable) — every other instantiation below
        // already was. If either of these throws at runtime for any reason
        // (a $wpdb issue, something host-specific), it previously took down
        // the entire site with an uncaught fatal instead of degrading
        // gracefully. Now matches the same pattern as everything else: log
        // via error_log() unconditionally (so it's visible even without
        // WP_DEBUG_LOG enabled — see debug-logger.php's own reasoning for
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
            if ($debug) { vibe_log('Schema instantiated'); }
        } catch (Throwable $e) {
            error_log('[Vibe Comments] FATAL in Schema::init(): ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            if ($debug) { vibe_log('Schema ERROR: ' . $e->getMessage()); }
        }

        try {
            new Vibe_Comments_REST_API();
            if ($debug) { vibe_log('REST API instantiated'); }
        } catch (Throwable $e) {
            // Always hit PHP's native log regardless of the debug flag — a fatal
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
        // — that function was fixed in v3.5.0 to route to this plugin's
        // template whenever a post has existing comments, even if new
        // commenting is closed, so JSON-LD schema output isn't describing a
        // discussion the plugin's own UI can't display. This condition was
        // the other half of that same fix and was never updated to match —
        // the template rendered correctly, but its CSS/JS never loaded,
        // leaving visitors an unstyled heading and a "Load Comments" button
        // that did nothing when clicked.
        if (is_singular() && (comments_open() || (int) get_comments_number() > 0)) {
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
                'googleEnabled'    => $google_on,
                'maxCommentLength' => (int) apply_filters('vibe_comments_max_length', 2000),
                // Mirrors templates/comments.php's exact 3-way branch (0/1/many) so the
                // client-side heading refresh produces byte-identical grammar to the
                // server-rendered version — same simplification (2 plural forms, not a
                // full _n_noop() set), kept consistent rather than "more correct" in JS
                // only, which would make the two diverge for non-English locales.
                'oneCommentText'      => esc_html__('1 Comment', 'vibe-comments'),
                /* translators: %s = formatted number, e.g. "12 Comments" */
                'manyCommentsTemplate' => esc_html__('%s Comments', 'vibe-comments'),
                // Lets JS-side silent .catch() blocks (e.g. fetchCommentCount())
                // optionally console.warn for a developer who deliberately
                // turned on VIBE_COMMENTS_DEBUG_TOOLS, without ever logging
                // anything to a real visitor's browser console by default.
                'debug'                 => defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS,
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
    // This is the single point of total plugin failure — always record it
    // regardless of the debug flag. Without this, the entire plugin can die
    // silently with zero trace anywhere on the server.
    error_log('[Vibe Comments] FATAL — plugin failed to initialize: ' . $e->getMessage());
    if (defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS) {
        vibe_log('Main class ERROR: ' . $e->getMessage());
    }
}

