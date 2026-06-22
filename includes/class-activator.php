<?php
class Vibe_Comments_Activator {
    const DB_VERSION = '1.3.0';

    public static function activate() {
        global $wpdb;
        $table_name      = $wpdb->prefix . 'vibe_comment_likes';
        $charset_collate = $wpdb->get_charset_collate();

        // reaction_type allows multiple reaction flavours per row.
        // Each user gets exactly one reaction per comment (the UNIQUE KEY
        // covers both logged-in and guest paths without collision).
        // Existing installs: existing rows get reaction_type = 'like' via DEFAULT.
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            comment_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            guest_token VARCHAR(64) NOT NULL DEFAULT '',
            reaction_type VARCHAR(20) NOT NULL DEFAULT 'like',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_like (comment_id, user_id, guest_token),
            KEY comment_id (comment_id),
            KEY user_id (user_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('vibe_comments_db_version', self::DB_VERSION);
    }

    /**
     * Run on plugins_loaded — migrates existing installs without needing
     * the user to deactivate/reactivate the plugin.
     */
    public static function maybe_upgrade() {
        $installed = get_option('vibe_comments_db_version', '0');
        if (version_compare($installed, self::DB_VERSION, '>=')) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'vibe_comment_likes';

        // v1.2 → v1.3: add reaction_type column.
        // Existing likes become reaction_type = 'like' via the DEFAULT clause.
        $col = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}` LIKE 'reaction_type'");
        if (empty($col)) {
            $wpdb->query(
                "ALTER TABLE `{$table_name}`
                 ADD COLUMN `reaction_type` VARCHAR(20) NOT NULL DEFAULT 'like'
                 AFTER `guest_token`"
            );
        }

        // v1.1 → v1.2: add guest_token column (kept for installs still on v1.1).
        $col = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}` LIKE 'guest_token'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `guest_token` VARCHAR(64) NOT NULL DEFAULT ''");
            $wpdb->query("ALTER TABLE `{$table_name}` DROP INDEX `unique_like`");
            $wpdb->query("ALTER TABLE `{$table_name}` ADD UNIQUE KEY `unique_like` (`comment_id`, `user_id`, `guest_token`)");
        }

        update_option('vibe_comments_db_version', self::DB_VERSION);
    }
}
