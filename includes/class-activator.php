<?php
class Vibe_Comments_Activator {
    const DB_VERSION = '1.3.1';

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
     * Run on init — migrates existing installs without needing
     * the user to deactivate/reactivate the plugin.
     * Also flushes vc_load_* transients on any version change so stale
     * cached comment JSON never survives an upgrade.
     */
    public static function maybe_upgrade() {
        $installed = get_option('vibe_comments_db_version', '0');
        if (version_compare($installed, self::DB_VERSION, '>=')) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'vibe_comment_likes';

        // ── v1.1 → v1.2: guest_token ─────────────────────────────────────
        // MUST run BEFORE v1.2→v1.3. reaction_type is added AFTER guest_token,
        // so if guest_token doesn't exist yet, MySQL silently fails the ALTER
        // and reaction_type is never created. Running in ascending order fixes this.
        $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}` LIKE 'guest_token'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN `guest_token` VARCHAR(64) NOT NULL DEFAULT ''" );
            $wpdb->query( "ALTER TABLE `{$table_name}` DROP INDEX `unique_like`" );
            $wpdb->query( "ALTER TABLE `{$table_name}` ADD UNIQUE KEY `unique_like` (`comment_id`, `user_id`, `guest_token`)" );
        }

        // ── v1.2 → v1.3: reaction_type ───────────────────────────────────
        // Existing rows get reaction_type = 'like' via the DEFAULT clause.
        $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}` LIKE 'reaction_type'" );
        if ( empty( $col ) ) {
            $wpdb->query(
                "ALTER TABLE `{$table_name}`
                 ADD COLUMN `reaction_type` VARCHAR(20) NOT NULL DEFAULT 'like'
                 AFTER `guest_token`"
            );
        }

        // Flush all vc_load_* transients so the first request after upgrade
        // always re-runs the query against the live DB rather than serving
        // cached JSON that pre-dates the schema or code change.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_vc\_%'
                OR option_name LIKE '\_transient\_timeout\_vc\_%'"
        );

        update_option('vibe_comments_db_version', self::DB_VERSION);
    }
}
