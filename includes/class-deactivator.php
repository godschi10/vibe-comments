<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
class Vibe_Comments_Deactivator {
    public static function deactivate() {
        // Clear scheduled hooks if any
        // We intentionally do NOT drop tables here to preserve data
        // Use uninstall.php for complete removal
    }
}
