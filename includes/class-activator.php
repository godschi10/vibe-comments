<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
class Vibe_Comments_Activator {
    const DB_VERSION = '1.4.0';

    public static function activate() {
        global $wpdb;
        $table_name      = $wpdb->prefix . 'vibe_comment_likes';
        $charset_collate = $wpdb->get_charset_collate();

        // reaction_type allows multiple reaction flavours per row.
        // Each user gets exactly one reaction per comment (the UNIQUE KEY
        // covers both logged-in and guest paths without collision).
        // Existing installs: existing rows get reaction_type = 'like' via DEFAULT.
        // guest_token is VARCHAR(64) — SHA256 produces exactly 64 hex chars.
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
        // Tracks whether EVERY step below actually succeeded. Previously none
        // of these $wpdb->query() return values were checked — a partially
        // failed migration (e.g. the ADD COLUMN succeeds but the subsequent
        // DROP INDEX fails for some reason) would silently continue to the
        // NEXT statement anyway, then still call update_option() at the end
        // regardless, permanently marking a broken migration as "done." Since
        // maybe_upgrade() only runs when the stored version is behind
        // DB_VERSION, that means it would NEVER run again — the table stays
        // in a broken, inconsistent state with no further attempt to
        // self-heal and no error surfaced to the site admin anywhere.
        $ok = true;

        // ── v1.1 → v1.2: guest_token ─────────────────────────────────────
        // MUST run BEFORE v1.2→v1.3. reaction_type is added AFTER guest_token,
        // so if guest_token doesn't exist yet, MySQL silently fails the ALTER
        // and reaction_type is never created. Running in ascending order fixes this.
        //
        // Column addition and index correction are checked INDEPENDENTLY
        // (two separate guards below) rather than one "does guest_token
        // exist" check covering all three ALTER statements. With a single
        // coarse guard, a partial failure — ADD COLUMN succeeds, but DROP
        // INDEX or the re-ADD fails — would still correctly set $ok=false
        // and prevent update_option() from advancing (that part already
        // worked). But on the NEXT request, the guard would find the column
        // already exists and skip the ENTIRE block, including the index
        // correction that never actually completed — silently leaving
        // unique_like without guest_token in it, forever, with no further
        // retry attempt.
        $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}` LIKE 'guest_token'" );
        if ( empty( $col ) ) {
            $r1 = $wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN `guest_token` VARCHAR(64) NOT NULL DEFAULT ''" );
            if ( false === $r1 ) {
                $ok = false;
                error_log( '[Vibe Comments] Migration v1.1→v1.2 (add guest_token column) failed: ' . $wpdb->last_error );
            }
        }

        // Checked independently of the column-existence guard above, so this
        // retries correctly even if guest_token already exists from a prior
        // partial attempt that got this far but failed on the index itself.
        if ( $ok ) {
            $idx = $wpdb->get_results( $wpdb->prepare(
                "SHOW INDEX FROM `{$table_name}` WHERE Key_name = %s AND Column_name = %s",
                'unique_like', 'guest_token'
            ) );
            if ( empty( $idx ) ) {
                $r2 = $wpdb->query( "ALTER TABLE `{$table_name}` DROP INDEX `unique_like`" );
                $r3 = $wpdb->query( "ALTER TABLE `{$table_name}` ADD UNIQUE KEY `unique_like` (`comment_id`, `user_id`, `guest_token`)" );
                if ( false === $r2 || false === $r3 ) {
                    $ok = false;
                    error_log( '[Vibe Comments] Migration v1.1→v1.2 (unique_like index) failed: ' . $wpdb->last_error );
                }
            }
        }

        // ── v1.2 → v1.3: reaction_type ───────────────────────────────────
        // Existing rows get reaction_type = 'like' via the DEFAULT clause.
        // Only attempted if the previous step succeeded (or wasn't needed) —
        // no point trying to add reaction_type AFTER guest_token if
        // guest_token itself just failed to get created.
        if ( $ok ) {
            $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}` LIKE 'reaction_type'" );
            if ( empty( $col ) ) {
                $r4 = $wpdb->query(
                    "ALTER TABLE `{$table_name}`
                     ADD COLUMN `reaction_type` VARCHAR(20) NOT NULL DEFAULT 'like'
                     AFTER `guest_token`"
                );
                if ( false === $r4 ) {
                    $ok = false;
                    error_log( '[Vibe Comments] Migration v1.2→v1.3 (reaction_type) failed: ' . $wpdb->last_error );
                }
            }
        }

        // ── v1.3 → v1.4: guest_token to SHA256 (64 chars) ─────────────────────
        // guest_token column is already VARCHAR(64) from v1.1→v1.2.
        // Existing tokens are 32-char MD5. We need to note legacy tokens exist
        // and they will be regenerated to SHA256 on next user interaction.
        if ( $ok ) {
            $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}` LIKE 'guest_token'" );
            if ( ! empty( $col ) ) {
                // Check if any existing tokens are 32 chars (old MD5 format)
                $old_tokens = $wpdb->get_results( "SELECT guest_token FROM `{$table_name}` WHERE LENGTH(guest_token) = 32" );
                if ( ! empty( $old_tokens ) ) {
                    // We can't directly reverse MD5 to get the original UUID/IP.
                    // Legacy tokens will be regenerated to SHA256 on next user interaction
                    // via get_guest_token() in class-database.php.
                    // The UNIQUE KEY still works because old tokens are unique.
                    error_log( '[Vibe Comments] Migration v1.3→v1.4: ' . count( $old_tokens ) . ' legacy MD5 guest_tokens detected. They will be regenerated to SHA256 on next user interaction.' );
                }
            }
        }

        if ( ! $ok ) {
            // Do NOT update_option() here — leaving the stored version behind
            // DB_VERSION means maybe_upgrade() will genuinely retry on the
            // next request, rather than permanently giving up after one
            // failed attempt. The transient flush below still runs regardless
            // (harmless either way, and if some columns DID succeed before
            // the failure, stale cached JSON still shouldn't survive it).
        }

        // Flush all vc_load_* transients so the first request after upgrade
        // always re-runs the query against the live DB rather than serving
        // cached JSON that pre-dates the schema or code change.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_vc\_%'
                OR option_name LIKE '\_transient\_timeout\_vc\_%'"
        );

        if ( $ok ) {
            update_option('vibe_comments_db_version', self::DB_VERSION);
        }
    }
}
