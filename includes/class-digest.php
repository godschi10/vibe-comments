<?php
/**
 * Vibe Comments - Daily Digest Email (Feature #9, v3.17.0).
 *
 * ONE email per day to the site admin: yesterday's comment activity in a
 * single branded summary - counts, top-reacted, awaiting moderation, spam
 * scores, top authors, per-post breakdown. Every entry links straight to
 * its admin row so a busy owner can act in one click.
 *
 * Why admin-only (design decision, agreed with the King): subscriber
 * digests are a different product - consent rules, per-reader state,
 * unsubscribe machinery, storm risk. The King asked for a morning paper,
 * not a mailing list. One recipient, zero consent surface, zero storm.
 *
 * Delivery follows v3.9.0: the plugin never touches SMTP.
 * It calls wp_mail() - wherever mail works, this works. On THIS host the
 * three walls stand (no sendmail binary, port 25 blocked, empty Brevo key),
 * so the rail is: cron fires daily → digest BUILT → wp_mail() attempts →
 * honest error-log if the transport is down. The moment the Brevo key
 * lands in wp-config.php, the same cron lights up with zero further work.
 * Until then, the preview button renders the exact HTML that would be sent.
 *
 * Scheduling: a single-event self-chaining cron - each run reschedules
 * the next 07:00 UTC (08:00 WAT). Single-event chains are idempotent under
 * re-activation (arm() refuses to double-schedule) where recurring
 * schedules double-fire.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vibe_Comments_Digest {

	const CRON_HOOK   = 'vibe_daily_digest';
	const PREVIEW_CAP = 'moderate_comments';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'update_option_vibe_digest_settings', array( __CLASS__, 'maybe_arm' ), 10, 0 );
		add_action( 'wp_ajax_vibe_digest_preview', array( __CLASS__, 'ajax_preview' ) );
	}

	// ── Scheduling ─────────────────────────────────────────────────────

	public static function maybe_arm() {
		$settings = get_option( 'vibe_digest_settings', array() );
		if ( ! empty( $settings['enabled'] ) ) {
			self::arm();
		} else {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/** Schedule the next 07:00 UTC run if none is pending. Idempotent. */
	public static function arm() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return; // already armed - never double-schedule
		}
		wp_schedule_single_event( self::next_run_ts(), self::CRON_HOOK );
	}

	/** Next 07:00 UTC (08:00 WAT - the King's morning) from now. */
	public static function next_run_ts() {
		$now  = current_time( 'timestamp', true );
		$next = gmmktime( 7, 0, 0, (int) gmdate( 'n', $now ), (int) gmdate( 'j', $now ), (int) gmdate( 'Y', $now ) );
		if ( $next <= $now ) {
			$next += DAY_IN_SECONDS;
		}
		return $next;
	}

	// ── The worker ─────────────────────────────────────────────────────

	public static function run() {
		$settings = get_option( 'vibe_digest_settings', array() );
		if ( empty( $settings['enabled'] ) ) {
			return; // disarmed without clearing - die quietly
		}

		self::send_digest( self::build_digest( self::window_start(), self::window_end() ) );

		// Self-chain: tomorrow 07:00 UTC.
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_single_event( self::next_run_ts(), self::CRON_HOOK );

		update_option( 'vibe_digest_last_run', current_time( 'mysql' ), false );
	}

	/**
	 * Window: yesterday 00:00-24:00 UTC. A digest at 07:00 about the FULL
	 * previous calendar day - the numbers describe a day, which is what
	 * "daily digest" means to a human.
	 */
	public static function window_start() {
		return gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp', true ) - DAY_IN_SECONDS );
	}
	public static function window_end() {
		return gmdate( 'Y-m-d 23:59:59', current_time( 'timestamp', true ) - DAY_IN_SECONDS );
	}

	// ── Build ──────────────────────────────────────────────────────────

	/**
	 * Assemble the digest data + HTML. Build is separate from send so the
	 * preview and the cron share ONE path - no drift between what You
	 * preview and what the inbox receives.
	 */
	public static function build_digest( $start_gmt, $end_gmt ) {
		global $wpdb;

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$admin_url = admin_url( 'edit-comments.php' );

		// ── 1. Headline counts (one query) ─────────────────────────────
		$counts = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				SUM(comment_approved = '1')      AS approved,
				SUM(comment_approved = '0')      AS pending,
				SUM(comment_parent = 0)          AS top_level,
				SUM(comment_parent > 0)          AS replies
			 FROM {$wpdb->comments}
			 WHERE comment_date_gmt >= %s AND comment_date_gmt <= %s
			   AND comment_approved IN ('0','1')",
			$start_gmt, $end_gmt
		), ARRAY_A );
		$counts = array_map( 'intval', $counts ?: array() );

		if ( ( $counts['approved'] + $counts['pending'] ) === 0 ) {
			return array(
				'empty'   => true,
				'subject' => '[' . $site_name . '] Daily digest - a quiet day',
				'html'    => self::empty_day_html( $site_name ),
			);
		}

		// ── 2. The comments (for lists) ────────────────────────────────
		$comments = $wpdb->get_results( $wpdb->prepare(
			"SELECT comment_ID, comment_author, comment_author_email, comment_content,
					comment_date_gmt, comment_post_ID, comment_approved, comment_parent
			 FROM {$wpdb->comments}
			 WHERE comment_date_gmt >= %s AND comment_date_gmt <= %s
			   AND comment_approved IN ('0','1')
			 ORDER BY comment_date_gmt DESC
			 LIMIT 100",
			$start_gmt, $end_gmt
		), ARRAY_A );

		// ── 3. Top-reacted (batch, one query) ───────────────────────────
		$db        = new Vibe_Comments_Database();
		$ids       = array_map( function( $c ) { return (int) $c['comment_ID']; }, $comments );
		$react_map = $ids ? $db->get_reaction_counts_batch( $ids ) : array();
		$scored    = array();
		foreach ( $comments as $c ) {
			$cid = (int) $c['comment_ID'];
			$tot = isset( $react_map[ $cid ] ) ? array_sum( array_map( 'intval', (array) $react_map[ $cid ] ) ) : 0;
			if ( $tot > 0 ) $scored[ $cid ] = $tot;
		}
		arsort( $scored );

		// ── 4. Spam scores for pending (v3.14.0 rail) ───────────────────
		$pending_scores = array();
		foreach ( $comments as $c ) {
			if ( '0' === (string) $c['comment_approved'] ) {
				$pending_scores[ (int) $c['comment_ID'] ] = Vibe_Comments_Spam_Score::score( (object) $c );
			}
		}

		// ── 5. Per-post breakdown ──────────────────────────────────────
		$per_post = array();
		foreach ( $comments as $c ) {
			$pid = (int) $c['comment_post_ID'];
			if ( ! isset( $per_post[ $pid ] ) ) $per_post[ $pid ] = array( 'total' => 0, 'pending' => 0 );
			$per_post[ $pid ]['total']++;
			if ( '0' === (string) $c['comment_approved'] ) $per_post[ $pid ]['pending']++;
		}
		$post_titles = array();
		if ( ! empty( $per_post ) ) {
			$pids = array_keys( $per_post );
			$pl  = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($pl)", $pids ) ) as $p ) {
				$post_titles[ (int) $p->ID ] = $p->post_title;
			}
		}

		// ── 6. Top authors ─────────────────────────────────────────────
		$authors = array();
		foreach ( $comments as $c ) {
			$a = $c['comment_author'];
			if ( ! isset( $authors[ $a ] ) ) $authors[ $a ] = 0;
			$authors[ $a ]++;
		}
		arsort( $authors );
		$authors = array_slice( $authors, 0, 5, true );

		// ── Assemble HTML ──────────────────────────────────────────────
		$day_label = gmdate( 'l j F', strtotime( $start_gmt ) );
		$rows      = '';

		// Pending section first - the actionable morning list.
		if ( $counts['pending'] > 0 ) {
			$rows .= '<tr><td style="padding:18px 0 0 0;">'
				. '<h2 style="margin:0 0 10px 0;font-size:16px;color:#1f2937;">⚠️ Awaiting moderation - ' . (int) $counts['pending'] . '</h2>';
			foreach ( $comments as $c ) {
				if ( '0' !== (string) $c['comment_approved'] ) continue;
				$cid = (int) $c['comment_ID'];
				$sc  = isset( $pending_scores[ $cid ] ) ? $pending_scores[ $cid ] : array( 'score' => 0, 'label' => 'clean' );
				$color = $sc['score'] >= 60 ? '#b91c1c' : ( $sc['score'] >= 30 ? '#b45309' : '#15803d' );
				$rows .= '<div style="margin:0 0 8px 0;padding:10px 12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;">'
					. '<div style="font-size:13px;color:#374151;"><strong>' . esc_html( $c['comment_author'] ) . '</strong>'
					. ' <span style="color:' . esc_attr( $color ) . ';font-weight:600;">' . esc_html( $sc['label'] . ' ' . $sc['score'] . '%' ) . '</span>'
					. ' - <a href="' . esc_url( admin_url( 'comment.php?action=editcomment&c=' . $cid ) ) . '" style="color:#2563eb;">review</a></div>'
					. '<div style="font-size:13px;color:#4b5563;margin-top:4px;">' . esc_html( wp_html_excerpt( wp_strip_all_tags( $c['comment_content'] ), 140 ) ) . '</div>'
					. '</div>';
			}
			$rows .= '</td></tr>';
		}

		// Top-reacted.
		if ( ! empty( $scored ) ) {
			$rows .= '<tr><td style="padding:18px 0 0 0;">'
				. '<h2 style="margin:0 0 10px 0;font-size:16px;color:#1f2937;">🔥 Most-reacted yesterday</h2>';
			$i = 0;
			foreach ( $scored as $cid => $tot ) {
				if ( $i >= 5 ) break;
				$c = null;
				foreach ( $comments as $row ) { if ( (int) $row['comment_ID'] === $cid ) { $c = $row; break; } }
				if ( ! $c ) continue;
				$rows .= '<div style="margin:0 0 8px 0;padding:10px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;">'
					. '<div style="font-size:13px;color:#374151;"><strong>' . esc_html( $c['comment_author'] ) . '</strong> · ' . (int) $tot . ' reactions</div>'
					. '<div style="font-size:13px;color:#4b5563;margin-top:4px;">' . esc_html( wp_html_excerpt( wp_strip_all_tags( $c['comment_content'] ), 120 ) ) . '</div>'
					. '</div>';
				$i++;
			}
			$rows .= '</td></tr>';
		}

		// Per-post table.
		if ( ! empty( $per_post ) ) {
			uasort( $per_post, function( $a, $b ) { return $b['total'] - $a['total']; } );
			$rows .= '<tr><td style="padding:18px 0 0 0;">'
				. '<h2 style="margin:0 0 10px 0;font-size:16px;color:#1f2937;">📊 By post</h2><table style="width:100%;border-collapse:collapse;font-size:13px;">';
			foreach ( $per_post as $pid => $pp ) {
				$rows .= '<tr>'
					. '<td style="padding:6px 10px 6px 0;border-bottom:1px solid #f3f4f6;"><a href="' . esc_url( get_permalink( $pid ) ) . '" style="color:#2563eb;">' . esc_html( $post_titles[ $pid ] ?? ( 'Post #' . $pid ) ) . '</a></td>'
					. '<td style="padding:6px 0;border-bottom:1px solid #f3f4f6;text-align:right;color:#374151;">' . (int) $pp['total'] . ( $pp['pending'] ? ' <span style="color:#b45309;">(' . (int) $pp['pending'] . ' pending)</span>' : '' ) . '</td>'
					. '</tr>';
			}
			$rows .= '</table></td></tr>';
		}

		// Top authors.
		if ( ! empty( $authors ) ) {
			$rows .= '<tr><td style="padding:18px 0 0 0;">'
				. '<h2 style="margin:0 0 10px 0;font-size:16px;color:#1f2937;">🏆 Top voices</h2><div style="font-size:13px;color:#374151;">'
				. implode( ' · ', array_map( function( $n ) { return '<strong>' . esc_html( $n ) . '</strong>'; }, array_keys( $authors ) ) )
				. '</div></td></tr>';
		}

		$subject = sprintf( '[%s] Daily digest - %d comments, %d pending', $site_name, $counts['approved'], $counts['pending'] );
		$html    = self::wrap_html( $site_name, $day_label, $counts, $rows, $admin_url );

		return array(
			'empty'   => false,
			'subject' => $subject,
			'html'    => $html,
			'counts'  => $counts,
		);
	}

	private static function empty_day_html( $site_name ) {
		return self::wrap_html( $site_name, gmdate( 'l j F', strtotime( self::window_start() ) ),
			array( 'approved' => 0, 'pending' => 0, 'top_level' => 0, 'replies' => 0 ),
			'<tr><td style="padding:18px 0;"><div style="font-size:14px;color:#4b5563;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:16px;">A quiet day - no new comments yesterday. Nothing awaiting moderation. 🌤️</div></td></tr>',
			admin_url( 'edit-comments.php' ) );
	}

	/** The branded wrapper (mirrors the reply-email's dark header + gold). */
	private static function wrap_html( $site_name, $day_label, $counts, $rows, $admin_url ) {
		return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">'
			. '<div style="max-width:600px;margin:0 auto;padding:24px 16px;">'
			. '<div style="background:#0a0a0a;border-radius:10px 10px 0 0;padding:24px 28px;">'
			. '<div style="font-size:12px;letter-spacing:2px;color:#b8860b;text-transform:uppercase;font-weight:700;">' . esc_html( $site_name ) . '</div>'
			. '<div style="font-size:22px;font-weight:700;color:#ffffff;margin-top:6px;">Daily Digest</div>'
			. '<div style="font-size:13px;color:#a1a1aa;margin-top:4px;">' . esc_html( $day_label ) . '</div>'
			. '</div>'
			. '<div style="background:#ffffff;border:1px solid #e4e4e7;border-top:none;border-radius:0 0 10px 10px;padding:24px 28px;">'
			. '<table style="width:100%;"><tr><td>'
			. '<table style="width:100%;border-collapse:separate;border-spacing:8px 0;">'
			. '<tr>'
			. '<td style="width:25%;background:#eff6ff;border-radius:6px;padding:12px;text-align:center;"><div style="font-size:20px;font-weight:700;color:#1d4ed8;">' . (int) $counts['approved'] . '</div><div style="font-size:11px;color:#6b7280;">approved</div></td>'
			. '<td style="width:25%;background:#fff7ed;border-radius:6px;padding:12px;text-align:center;"><div style="font-size:20px;font-weight:700;color:#b45309;">' . (int) $counts['pending'] . '</div><div style="font-size:11px;color:#6b7280;">pending</div></td>'
			. '<td style="width:25%;background:#f0fdf4;border-radius:6px;padding:12px;text-align:center;"><div style="font-size:20px;font-weight:700;color:#15803d;">' . (int) $counts['top_level'] . '</div><div style="font-size:11px;color:#6b7280;">comments</div></td>'
			. '<td style="width:25%;background:#faf5ff;border-radius:6px;padding:12px;text-align:center;"><div style="font-size:20px;font-weight:700;color:#7e22ce;">' . (int) $counts['replies'] . '</div><div style="font-size:11px;color:#6b7280;">replies</div></td>'
			. '</tr></table>'
			. '</td></tr>'
			. $rows
			. '<tr><td style="padding:24px 0 0 0;border-top:1px solid #f3f4f6;"><div style="font-size:12px;color:#9ca3af;">'
			. 'Sent by Vibe Comments · <a href="' . esc_url( $admin_url ) . '" style="color:#6b7280;">Open the moderation queue</a>'
			. '</div></td></tr>'
			. '</table></div></div></body></html>';
	}

	// ── Send ──────────────────────────────────────────────────────────

	public static function send_digest( $digest ) {
		$settings = get_option( 'vibe_digest_settings', array() );
		$to       = ! empty( $settings['email'] ) ? $settings['email'] : get_option( 'admin_email' );

		$sent = wp_mail(
			$to,
			$digest['subject'],
			$digest['html'],
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		if ( ! $sent ) {
			error_log( 'Vibe digest: wp_mail returned false for ' . $to . ' (SMTP constants / transport - see wp-config GWILL_SMTP_*).' );
		}
		return $sent;
	}

	// ── Admin settings + preview ───────────────────────────────────────

	public static function register_settings() {
		register_setting(
			'vibe_comments',
			'vibe_digest_settings',
			array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ), 'default' => array() )
		);

		add_settings_section( 'vibe_digest_section', __( 'Daily Digest', 'vibe-comments' ), array( __CLASS__, 'render_section_intro' ), 'vibe-comments' );

		add_settings_field( 'vibe_digest_enabled', __( 'Enable daily digest', 'vibe-comments' ),
			array( __CLASS__, 'render_enabled_field' ), 'vibe-comments', 'vibe_digest_section' );
		add_settings_field( 'vibe_digest_email', __( 'Send to', 'vibe-comments' ),
			array( __CLASS__, 'render_email_field' ), 'vibe-comments', 'vibe_digest_section' );
	}

	public static function sanitize_settings( $in ) {
		$out = array();
		$out['enabled'] = empty( $in['enabled'] ) ? 0 : 1;
		$out['email']   = isset( $in['email'] ) ? sanitize_email( $in['email'] ) : '';
		if ( $out['enabled'] && ! $out['email'] ) {
			$out['email'] = get_option( 'admin_email' );
		}
		return $out;
	}

	public static function render_section_intro() {
		echo '<p>' . esc_html__( 'One email each morning (08:00 WAT): yesterday\'s comment activity - counts, pending queue with spam scores, most-reacted, top posts and voices. Preview renders the exact email.', 'vibe-comments' ) . '</p>';
	}

	public static function render_enabled_field() {
		$s = get_option( 'vibe_digest_settings', array() );
		echo '<label><input type="checkbox" name="vibe_digest_settings[enabled]" value="1" ' . checked( ! empty( $s['enabled'] ), true, false ) . ' /> '
			. esc_html__( 'Send the daily digest', 'vibe-comments' ) . '</label>';
	}

	public static function render_email_field() {
		$s = get_option( 'vibe_digest_settings', array() );
		echo '<input type="email" class="regular-text" name="vibe_digest_settings[email]" value="' . esc_attr( $s['email'] ?? '' ) . '" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '" />';
	}

	/** Preview: the SMTP-free window into the exact digest. */
	public static function ajax_preview() {
		if ( ! current_user_can( self::PREVIEW_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Moderators only.', 'vibe-comments' ) ), 403 );
		}
		check_ajax_referer( 'wp_rest', 'nonce', false );
		$digest = self::build_digest( self::window_start(), self::window_end() );
		wp_send_json_success( array(
			'subject' => $digest['subject'],
			'html'    => $digest['html'],
			'counts'  => $digest['counts'] ?? array(),
		) );
	}
}
