<?php
/**
 * Debug logger — only writes when WP_DEBUG is true.
 * Function name kept as vibe_log() for compatibility.
 * Log file moved to wp-content/logs/ (out of webroot's direct reach).
 */

if (!defined('ABSPATH')) {
    exit;
}

function vibe_log($message) {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }

    $log_dir  = WP_CONTENT_DIR . '/logs';
    $log_file = $log_dir . '/vibe-comments-debug.log';

    if (!is_dir($log_dir)) {
        wp_mkdir_p($log_dir);
        @file_put_contents($log_dir . '/.htaccess', "Deny from all\n");
    }

    $line = gmdate('Y-m-d H:i:s') . ' UTC - ' . $message . PHP_EOL;
    @file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
}

if (defined('WP_DEBUG') && WP_DEBUG) {
    vibe_log('=== Plugin loading started ===');

    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_COMPILE_ERROR), true)) {
            vibe_log('FATAL ERROR: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        }
    });
}
