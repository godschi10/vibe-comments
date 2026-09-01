<?php
/**
 * Debug logger - only writes when VIBE_COMMENTS_DEBUG_TOOLS is explicitly enabled.
 *
 * Gate: define('VIBE_COMMENTS_DEBUG_TOOLS', true) in wp-config.php.
 *
 * Deliberately does NOT gate on WP_DEBUG because WP_DEBUG is frequently enabled
 * on production sites for error capture - leaving a predictable log file at a
 * guessable URL on any server that doesn't enforce .htaccess (Nginx, LiteSpeed
 * in some configs). VIBE_COMMENTS_DEBUG_TOOLS is a deliberate opt-in by a
 * developer, not an ambient configuration flag.
 *
 * Log file: wp-content/logs/vibe-comments-debug.log
 * Directory is created on first write; .htaccess blocks direct Apache access.
 * On Nginx/LiteSpeed, add a server-level deny for /wp-content/logs/ if needed.
 */

if (!defined('ABSPATH')) {
    exit;
}

if ( ! function_exists( 'vibe_log' ) ) {
function vibe_log($message) {
    if (!defined('VIBE_COMMENTS_DEBUG_TOOLS') || !VIBE_COMMENTS_DEBUG_TOOLS) {
        return;
    }
    // Wrap all filesystem work in try/catch. The logger must never crash the
    // plugin - a read-only filesystem or bad permissions should fail silently.
    try {
        $log_dir  = WP_CONTENT_DIR . '/logs';
        $log_file = $log_dir . '/vibe-comments-debug.log';

        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
            // Apache only. For Nginx/LiteSpeed, add a server-level block for /wp-content/logs/.
            @file_put_contents($log_dir . '/.htaccess', "Require all denied\n");
        }

        $line = gmdate('Y-m-d H:i:s') . ' UTC - ' . $message . PHP_EOL;
        @file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
    } catch ( Throwable $e ) {
        // Silently swallow - logging must never be the reason the plugin fails.
    }
}
} // end function_exists vibe_log

if (defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS) {
    vibe_log('=== Plugin loading started ===');

    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_COMPILE_ERROR), true)) {
            vibe_log('FATAL ERROR: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        }
    });
}
