<?php
/**
 * Plugin Name:       Vibe Comments
 * Plugin URI:        https://gwillchijioke.com
 * Description:       A performance-focused custom comment plugin with reactions, threaded replies, Gravatar, Google & WordPress authentication. Built with zero external dependencies and no DB bloat.
 * Version:           3.2.4
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

define('VIBE_COMMENTS_VERSION', '3.2.4');
define('VIBE_COMMENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VIBE_COMMENTS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include debug logger FIRST
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/debug-logger.php';

vibe_log('Main plugin file loaded');

// Activation
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-activator.php';
register_activation_hook(__FILE__, array('Vibe_Comments_Activator', 'activate'));

// Deactivation
require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-deactivator.php';
register_deactivation_hook(__FILE__, array('Vibe_Comments_Deactivator', 'deactivate'));

// Core classes
vibe_log('Loading core classes...');

require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-database.php';
vibe_log('Database class loaded');

require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-rest-api.php';
vibe_log('REST API class loaded');

require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-oauth-google.php';
vibe_log('OAuth class loaded');

require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-template-loader.php';
vibe_log('Template loader class loaded');

require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-ajax-handler.php';
vibe_log('AJAX handler loaded');

require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-admin.php';
vibe_log('Admin class loaded');

class Vibe_Comments {
    public function __construct() {
        vibe_log('Vibe_Comments constructor called');
        add_action('init', array($this, 'init'));
        add_action('rest_api_init', array($this, 'add_cache_headers'));
    }

    public function init() {
        vibe_log('init hook fired');

        // Run DB migration if needed (adds guest_token column for guest likes)
        Vibe_Comments_Activator::maybe_upgrade();

        try {
            new Vibe_Comments_REST_API();
            vibe_log('REST API instantiated');
        } catch (Throwable $e) {
            vibe_log('REST API ERROR: ' . $e->getMessage());
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
                vibe_log('OAuth instantiated');
            } catch (Throwable $e) {
                vibe_log('OAuth ERROR: ' . $e->getMessage());
            }
        }

        try {
            new Vibe_Comments_Template_Loader();
            vibe_log('Template loader instantiated');
        } catch (Throwable $e) {
            vibe_log('Template loader ERROR: ' . $e->getMessage());
        }

        try {
            new Vibe_Comments_Ajax_Handler();
            vibe_log('AJAX handler instantiated');
        } catch (Throwable $e) {
            vibe_log('AJAX handler ERROR: ' . $e->getMessage());
        }

        try {
            new Vibe_Comments_Admin();
            vibe_log('Admin instantiated');
        } catch (Throwable $e) {
            vibe_log('Admin ERROR: ' . $e->getMessage());
        }

        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        vibe_log('Assets enqueue registered');
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
        if (is_singular() && comments_open()) {
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
                'restUrl'          => esc_url_raw(rest_url('vibe-comments/v1/')),
                'nonce'            => wp_create_nonce('wp_rest'),
                'ajaxUrl'          => admin_url('admin-ajax.php'),
                'postId'           => get_the_ID(),
                'isLoggedIn'       => is_user_logged_in(),
                'isAdmin'          => current_user_can('moderate_comments'),
                'loginUrl'         => wp_login_url(get_permalink()),
                'siteName'         => get_bloginfo('name'),
                'googleAuth'       => esc_url_raw(rest_url('vibe-comments/v1/google-auth')),
                'googleEnabled'    => $google_on,
                'maxCommentLength' => (int) apply_filters('vibe_comments_max_length', 2000),
            ));
        }
    }
}

vibe_log('Instantiating main class...');
try {
    new Vibe_Comments();
    vibe_log('Main class instantiated successfully');
} catch (Throwable $e) {
    vibe_log('Main class ERROR: ' . $e->getMessage());
}

vibe_log('=== Plugin loading complete ===');
