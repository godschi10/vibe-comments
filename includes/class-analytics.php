<?php
/**
 * Comment Analytics Dashboard - every comment stat, one screen.
 *
 * A top-level "Vibe Comments" admin page (capability: moderate_comments, so
 * editors see it too - it's comment data, not plugin settings). All queries
 * are driver-portable (MySQL AND the SQLite dropin): the time-series are
 * computed in PHP from ONE bulk fetch (no DATE_FORMAT/strftime dialect
 * risk), and SQL is used only for GROUP BY leaderboards.
 *
 * Zero dependencies: charts are hand-built SVG (bars + donut), tables are
 * plain HTML, styles enqueued via vibe-admin.css scoped to admin screens.
 * Results are cached 5 minutes in a transient; the "Refresh" link busts it
 * with a nonce.
 *
 * @package Vibe_Comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vibe_Comments_Analytics {

	const CACHE_KEY = 'vibe_analytics_cache';
	const CACHE_TTL = 300; // 5 minutes
	const MENU_SLUG = 'vibe-analytics';
	const PAGE_CAP  = 'moderate_comments';

	/** @var Vibe_Comments_Analytics|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Top-level menu + Analytics submenu. The Settings page keeps its legacy
	 * home under Settings (back-compat); a second link is added here for
	 * discoverability.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Vibe Comments', 'vibe-comments' ),
			__( 'Vibe Comments', 'vibe-comments' ),
			self::PAGE_CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-format-chat',
			24
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Comment Analytics', 'vibe-comments' ),
			__( 'Analytics', 'vibe-comments' ),
			self::PAGE_CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Vibe Comments Settings', 'vibe-comments' ),
			__( 'Settings', 'vibe-comments' ),
			'manage_options',
			'vibe-comments',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * v3.11.0 - Settings submenu delegate. The old registration passed
	 * array( 'Vibe_Comments_Admin', 'render_page' ) - a NON-static method as
	 * a class-string callable, which PHP 8.3 rejects in isolation. WP only
	 * tolerated it because the duplicate 'vibe-comments' slug resolved to
	 * the legacy Settings registration's valid instance callable. This
	 * delegate is a real instance method on $this, and it renders through
	 * an actual Vibe_Comments_Admin instance - valid regardless of which
	 * registration wins the slug.
	 */
	public function render_settings_page() {
		( new Vibe_Comments_Admin() )->render_page();
	}

	/* ══════════════════════════════════════════════════════════════════════
	 * DATA - every stat, computed fresh or from the 5-minute cache
	 * ════════════════════════════════════════════════════════════════════ */

	/**
	 * The full stats payload.
	 *
	 * @param bool $force_refresh
	 * @return array
	 */
	public function get_stats( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		global $wpdb;
		$stats = array();

		/* ── 1. Status counts (every comment, every status) ───────────── */
		$rows  = $wpdb->get_results(
			"SELECT comment_approved AS st, COUNT(*) AS cnt
			 FROM {$wpdb->comments}
			 GROUP BY comment_approved"
		);
		$status = array(
			'approved' => 0,
			'pending'  => 0,
			'spam'     => 0,
			'trash'    => 0,
			'other'    => 0,
		);
		$total_all = 0;
		foreach ( $rows as $row ) {
			$cnt = (int) $row->cnt;
			$total_all += $cnt;
			if ( '1' === (string) $row->st ) {
				$status['approved'] += $cnt;
			} elseif ( '0' === (string) $row->st ) {
				$status['pending'] += $cnt;
			} elseif ( 'spam' === (string) $row->st ) {
				$status['spam'] += $cnt;
			} elseif ( 'trash' === (string) $row->st ) {
				$status['trash'] += $cnt;
			} else {
				$status['other'] += $cnt;
			}
		}
		$stats['status']    = $status;
		$stats['total_all'] = $total_all;

		/* ── 2. ONE portable bulk fetch: every approved comment's core
		 * fields. All time-series, threading and velocity stats derive
		 * from this in PHP - no SQL date functions (driver-portable). ── */
		$bulk = $wpdb->get_results(
			"SELECT comment_ID AS id, comment_parent AS parent,
			        comment_date_gmt AS d, comment_author_email AS email,
			        user_id AS uid, LENGTH(comment_content) AS len
			 FROM {$wpdb->comments}
			 WHERE comment_approved = '1'",
			ARRAY_A
		);

		$stats['totals']  = $this->compute_totals( $bulk );
		$stats['series']  = $this->compute_series( $bulk );
		$stats['quality'] = $this->compute_quality( $bulk );

		/* ── 3. Reaction engine (custom table) ────────────────────────── */
		$likes_table = $wpdb->prefix . 'vibe_comment_likes';
		$reactions   = array( 'like' => 0, 'heart' => 0, 'fire' => 0, 'laugh' => 0 );
		$total_rx    = 0;
		$rx_rows     = $wpdb->get_results(
			"SELECT reaction_type AS rt, COUNT(*) AS cnt
			 FROM {$likes_table}
			 GROUP BY reaction_type"
		);
		if ( is_array( $rx_rows ) ) {
			foreach ( $rx_rows as $row ) {
				if ( isset( $reactions[ $row->rt ] ) ) {
					$reactions[ $row->rt ] = (int) $row->cnt;
					$total_rx += (int) $row->cnt;
				}
			}
		}
		$stats['reactions']       = $reactions;
		$stats['reactions_total'] = $total_rx;

		/* ── 4. Engagement rails (commentmeta counters) ────────────────── */
		$stats['push_subs']  = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE meta_key = '_vibe_reply_push'"
		);
		$stats['email_opts'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE meta_key = '_vibe_reply_email'"
		);
		$stats['pinned']     = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE meta_key = '_vibe_pinned'"
		);

		/* ── 5. Top posts by approved comments ─────────────────────────── */
		$stats['top_posts'] = $wpdb->get_results(
			"SELECT p.ID AS id, p.post_title AS title, COUNT(c.comment_ID) AS cnt
			 FROM {$wpdb->comments} c
			 JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
			 WHERE c.comment_approved = '1'
			 GROUP BY p.ID, p.post_title
			 ORDER BY cnt DESC, p.ID ASC
			 LIMIT 10"
		);

		/* ── 6. Top commenters (deduped by email) ─────────────────────── */
		$stats['top_commenters'] = $wpdb->get_results(
			"SELECT comment_author AS author, COUNT(*) AS cnt
			 FROM {$wpdb->comments}
			 WHERE comment_approved = '1' AND comment_author_email != ''
			 GROUP BY LOWER(comment_author_email), comment_author
			 ORDER BY cnt DESC
			 LIMIT 10"
		);

		/* ── 7. Most-reacted comments ─────────────────────────────────── */
		$stats['top_reacted'] = array();
		if ( $total_rx > 0 ) {
			$stats['top_reacted'] = $wpdb->get_results(
				"SELECT l.comment_id AS cid, COUNT(*) AS cnt,
				        SUBSTR(c.comment_content, 1, 90) AS excerpt,
				        p.post_title AS post_title
				 FROM {$likes_table} l
				 JOIN {$wpdb->comments} c ON c.comment_ID = l.comment_id
				 JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
				 WHERE c.comment_approved = '1'
				 GROUP BY l.comment_id, c.comment_content, p.post_title
				 ORDER BY cnt DESC
				 LIMIT 5"
			);
		}

		set_transient( self::CACHE_KEY, $stats, self::CACHE_TTL );
		return $stats;
	}

	/**
	 * Totals derivable from the bulk rows.
	 */
	private function compute_totals( $bulk ) {
		$t = array(
			'approved'   => count( $bulk ),
			'replies'    => 0,
			'top_level'  => 0,
			'guests'     => 0,
			'members'    => 0,
			'unique'     => 0,
			'avg_len'    => 0,
			'max_thread' => 0,
		);
		if ( empty( $bulk ) ) {
			return $t;
		}

		$emails     = array();
		$parent_map = array();
		$len_sum    = 0;

		foreach ( $bulk as $row ) {
			$id     = (int) $row['id'];
			$parent = (int) $row['parent'];
			$parent_map[ $id ] = $parent;
			if ( $parent > 0 ) {
				$t['replies']++;
			} else {
				$t['top_level']++;
			}
			if ( (int) $row['uid'] > 0 ) {
				$t['members']++;
			} else {
				$t['guests']++;
			}
			$email = strtolower( trim( (string) $row['email'] ) );
			if ( '' !== $email ) {
				$emails[ $email ] = true;
			}
			$len_sum += (int) $row['len'];
		}

		$t['unique']  = count( $emails );
		$t['avg_len'] = (int) round( $len_sum / max( 1, count( $bulk ) ) );

		// Deepest thread = longest parent chain (memoized walk).
		$depth_cache = array();
		foreach ( $parent_map as $id => $parent ) {
			$depth = 0;
			$cur   = $id;
			$seen  = array( $id => true );
			while ( $cur > 0 && isset( $parent_map[ $cur ] ) && $depth < 50 ) {
				$cur = (int) $parent_map[ $cur ];
				if ( isset( $seen[ $cur ] ) ) {
					break; // cycle guard
				}
				$seen[ $cur ] = true;
				$depth++;
				if ( isset( $depth_cache[ $cur ] ) ) {
					$depth += $depth_cache[ $cur ];
					break;
				}
			}
			$depth_cache[ $id ] = $depth;
			if ( $depth > $t['max_thread'] ) {
				$t['max_thread'] = $depth;
			}
		}

		return $t;
	}

	/**
	 * Time-series: monthly (last 12), hourly (24), weekday (7).
	 */
	private function compute_series( $bulk ) {
		$monthly = array();
		$hourly  = array_fill( 0, 24, 0 );
		$weekday = array_fill( 0, 7, 0 ); // 0=Sun … 6=Sat

		foreach ( $bulk as $row ) {
			$d = (string) $row['d']; // 'YYYY-MM-DD HH:MM:SS'
			if ( strlen( $d ) < 13 ) {
				continue;
			}
			$ym   = substr( $d, 0, 7 );
			$hour = (int) substr( $d, 11, 2 );
			$ts   = gmmktime(
				(int) substr( $d, 11, 2 ), (int) substr( $d, 14, 2 ), (int) substr( $d, 17, 2 ),
				(int) substr( $d, 5, 2 ),  (int) substr( $d, 8, 2 ),  (int) substr( $d, 0, 4 )
			);
			$wday = (int) gmdate( 'w', $ts );

			if ( isset( $monthly[ $ym ] ) ) {
				$monthly[ $ym ]++;
			} else {
				$monthly[ $ym ] = 1;
			}
			if ( $hour >= 0 && $hour < 24 ) {
				$hourly[ $hour ]++;
			}
			if ( $wday >= 0 && $wday < 7 ) {
				$weekday[ $wday ]++;
			}
		}
		ksort( $monthly );
		$monthly = array_slice( $monthly, -12, null, true );

		return array(
			'monthly' => $monthly, // 'YYYY-MM' => count
			'hourly'  => $hourly,  // 0..23 => count
			'weekday' => $weekday, // 0..6 => count
		);
	}

	/**
	 * Engagement quality metrics.
	 */
	private function compute_quality( $bulk ) {
		$q = array(
			'reply_velocity' => null, // avg seconds from parent to first reply
			'replied_pct'     => 0,   // % of top-level comments with ≥1 reply
		);
		if ( empty( $bulk ) ) {
			return $q;
		}

		$ts_map     = array(); // id => unix ts
		$parent_map = array();
		$first_reply = array(); // parent_id => earliest reply gap (secs)

		foreach ( $bulk as $row ) {
			$id = (int) $row['id'];
			$ts_map[ $id ]      = strtotime( (string) $row['d'] . ' UTC' );
			$parent_map[ $id ]  = (int) $row['parent'];
		}

		foreach ( $bulk as $row ) {
			$parent = (int) $row['parent'];
			if ( $parent > 0 && isset( $ts_map[ $parent ] ) ) {
				$gap = $ts_map[ (int) $row['id'] ] - $ts_map[ $parent ];
				if ( ! isset( $first_reply[ $parent ] ) || $gap < $first_reply[ $parent ] ) {
					$first_reply[ $parent ] = $gap;
				}
			}
		}

		$top_with_replies = 0;
		$top_total        = 0;
		$gaps             = array();
		foreach ( $parent_map as $id => $parent ) {
			if ( 0 === $parent ) {
				$top_total++;
				if ( isset( $first_reply[ $id ] ) ) {
					$top_with_replies++;
				}
			}
		}
		foreach ( $first_reply as $gap ) {
			if ( $gap >= 0 && $gap < 315360000 ) {
				$gaps[] = $gap;
			}
		}

		if ( ! empty( $gaps ) ) {
			$q['reply_velocity'] = (int) round( array_sum( $gaps ) / count( $gaps ) );
		}
		if ( $top_total > 0 ) {
			$q['replied_pct'] = (int) round( 100 * $top_with_replies / $top_total );
		}
		return $q;
	}

	/* ══════════════════════════════════════════════════════════════════════
	 * RENDER
	 * ════════════════════════════════════════════════════════════════════ */

	public function render_page() {
		if ( ! current_user_can( self::PAGE_CAP ) ) {
			return;
		}

		$refresh = isset( $_GET['vibe_refresh'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['vibe_refresh'] ) ), 'vibe_analytics_refresh' );
		$stats   = $this->get_stats( $refresh );
		$nonce   = wp_create_nonce( 'vibe_analytics_refresh' );
		?>
		<div class="wrap vibe-analytics">
			<h1 class="vibe-an-title">
				<?php esc_html_e( 'Comment Analytics', 'vibe-comments' ); ?>
				<a class="page-title-action"
				   href="<?php echo esc_url( add_query_arg( array( 'vibe_refresh' => $nonce ) ) ); ?>">
					<?php esc_html_e( 'Refresh data', 'vibe-comments' ); ?>
				</a>
			</h1>
			<p class="vibe-an-sub">
				<?php
				printf(
					/* translators: %s = number of minutes. */
					esc_html__( 'Live from the comments tables  -  cached %d min. Reactions, push/email opt-ins, threading and velocity in one place.', 'vibe-comments' ),
					(int) ( self::CACHE_TTL / 60 )
				);
				?>
			</p>

			<?php $this->render_cards( $stats ); ?>
			<?php $this->render_charts( $stats ); ?>
			<?php $this->render_boards( $stats ); ?>
		</div>
		<!-- styles enqueued via class-admin::enqueue_admin_assets (vibe-admin.css) -->
		<?php
	}

	private function render_cards( $s ) {
		$t = $s['totals'];
		$cards = array(
			array( $s['total_all'], __( 'All comments (every status)', 'vibe-comments' ) ),
			array( $t['approved'], __( 'Approved', 'vibe-comments' ) ),
			array( $s['status']['pending'], __( 'Awaiting moderation', 'vibe-comments' ) ),
			array( $s['status']['spam'], __( 'Spam', 'vibe-comments' ) ),
			array( $t['unique'], __( 'Unique commenters', 'vibe-comments' ) ),
			array( $s['reactions_total'], __( 'Reactions given', 'vibe-comments' ) ),
			array( $t['replies'], __( 'Replies (threaded)', 'vibe-comments' ) ),
			array( $t['top_level'], __( 'Top-level comments', 'vibe-comments' ) ),
			array( $s['push_subs'], __( 'Push subscriptions', 'vibe-comments' ) ),
			array( $s['email_opts'], __( 'Email opt-ins', 'vibe-comments' ) ),
			array( $s['pinned'], __( 'Pinned comments', 'vibe-comments' ) ),
			array( $t['guests'], __( 'Guest comments', 'vibe-comments' ) ),
			array( $t['members'], __( 'Member comments', 'vibe-comments' ) ),
			array( $t['max_thread'], __( 'Deepest thread', 'vibe-comments' ) ),
			array( $t['avg_len'], __( 'Avg comment length (chars)', 'vibe-comments' ) ),
		);
		$vel = $s['quality']['reply_velocity'];
		$cards[] = array(
			null === $vel ? ' - ' : human_time_diff( 0, max( 60, $vel ) ),
			__( 'Avg time to first reply', 'vibe-comments' ),
		);
		echo '<div class="vibe-an-grid">';
		foreach ( $cards as $card ) {
			printf(
				'<div class="vibe-an-card"><div class="vibe-an-num">%s</div><div class="vibe-an-lbl">%s</div></div>',
				esc_html( is_int( $card[0] ) ? number_format_i18n( $card[0] ) : (string) $card[0] ),
				esc_html( $card[1] )
			);
		}
		echo '</div>';
	}

	private function render_charts( $s ) {
		$rx = $s['reactions'];
		?>
		<div class="vibe-an-two">
			<div class="vibe-an-chart">
				<h3><?php esc_html_e( 'Comments per month (12 months)', 'vibe-comments' ); ?></h3>
				<?php echo $this->svg_bars( $s['series']['monthly'], array( $this, 'fmt_month' ) ); // phpcs:ignore ?>
			</div>
			<div class="vibe-an-chart">
				<h3><?php esc_html_e( 'Reactions split', 'vibe-comments' ); ?></h3>
				<?php echo $this->svg_donut( $rx ); // phpcs:ignore ?>
			</div>
			<div class="vibe-an-chart">
				<h3><?php esc_html_e( 'Comments by hour of day (UTC)', 'vibe-comments' ); ?></h3>
				<?php echo $this->svg_bars( $s['series']['hourly'], array( $this, 'fmt_hour' ) ); // phpcs:ignore ?>
			</div>
			<div class="vibe-an-chart">
				<h3><?php esc_html_e( 'Comments by weekday', 'vibe-comments' ); ?></h3>
				<?php echo $this->svg_bars( $s['series']['weekday'], array( $this, 'fmt_weekday' ) ); // phpcs:ignore ?>
			</div>
		</div>
		<?php
	}

	private function fmt_month( $k ) {
		$m = array( '', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' );
		$n = (int) substr( $k, 5, 2 );
		return ( $m[ $n ] ?? $n ) . ' ' . substr( (string) $k, 2, 2 );
	}
	private function fmt_hour( $k ) {
		return str_pad( (string) $k, 2, '0', STR_PAD_LEFT ) . 'h';
	}
	private function fmt_weekday( $k ) {
		$d = array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' );
		return $d[ (int) $k ] ?? (string) $k;
	}

	/**
	 * Pure-SVG bar chart. $data: label => count.
	 */
	private function svg_bars( $data, $labeler ) {
		$values = array_values( $data );
		$labels = array_keys( $data );
		$n      = count( $values );
		if ( 0 === $n ) {
			return '<p style="color:#646970">' . esc_html__( 'No data yet.', 'vibe-comments' ) . '</p>';
		}
		$max = max( 1, max( $values ) );
		$w   = 560;
		$h   = 220;
		$svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg" role="img">';

		$bar_w = ( $w - 110 ) / $n;
		$i     = 0;
		foreach ( $values as $v ) {
			$bh = round( ( $v / $max ) * ( $h - 80 ) );
			$x  = 70 + $i * $bar_w;
			$y  = $h - 40 - $bh;
			$svg .= sprintf(
				'<rect x="%s" y="%s" width="%s" height="%s" rx="2" fill="#2271b1"><title>%s: %s</title></rect>',
				(int) ( $x + 1 ),
				(int) $y,
				max( 3, (int) ( $bar_w - 3 ) ),
				max( 0, (int) $bh ),
				esc_attr( call_user_func( $labeler, $labels[ $i ] ) ),
				esc_attr( number_format_i18n( $v ) )
			);
			if ( $n <= 14 || 0 === $i % (int) ceil( $n / 14 ) ) {
				$svg .= sprintf(
					'<text x="%s" y="%s" font-size="10" fill="#646970" text-anchor="middle">%s</text>',
					(int) ( $x + $bar_w / 2 ),
					$h - 22,
					esc_html( call_user_func( $labeler, $labels[ $i ] ) )
				);
			}
			if ( $v > 0 && ( $n <= 14 || $v === $max ) ) {
				$svg .= sprintf(
					'<text x="%s" y="%s" font-size="10" fill="#3c434a" text-anchor="middle">%s</text>',
					(int) ( $x + $bar_w / 2 ),
					max( 12, (int) ( $y - 4 ) ),
					esc_html( number_format_i18n( $v ) )
				);
			}
			$i++;
		}
		$svg .= '</svg>';
		return $svg;
	}

	/**
	 * Pure-SVG donut for the reaction split.
	 */
	private function svg_donut( $rx ) {
		$total = array_sum( $rx );
		$icons = array(
			'like'  => '&#128077;',
			'heart' => '&#10084;',
			'fire'  => '&#128293;',
			'laugh' => '&#128514;',
		);
		$colors = array(
			'like'  => '#2271b1',
			'heart' => '#d63638',
			'fire'  => '#dba617',
			'laugh' => '#00a32a',
		);
		if ( $total < 1 ) {
			return '<p style="color:#646970">' . esc_html__( 'No reactions yet.', 'vibe-comments' ) . '</p>';
		}
		$cx = 140; $cy = 110; $r = 80; $inner = 48;
		$svg = '<svg viewBox="0 0 560 220" xmlns="http://www.w3.org/2000/svg" role="img">';
		$start = - M_PI / 2;
		foreach ( $rx as $type => $cnt ) {
			if ( $cnt < 1 ) {
				continue;
			}
			$frac = $cnt / $total;
			$end  = $start + $frac * 2 * M_PI;
			$x1 = $cx + $r * cos( $start );    $y1 = $cy + $r * sin( $start );
			$x2 = $cx + $r * cos( $end );      $y2 = $cy + $r * sin( $end );
			$x3 = $cx + $inner * cos( $end );  $y3 = $cy + $inner * sin( $end );
			$x4 = $cx + $inner * cos( $start ); $y4 = $cy + $inner * sin( $start );
			$large = ( $frac > 0.5 ) ? 1 : 0;
			$svg .= sprintf(
				'<path d="M %s %s A %s %s 0 %d 1 %s %s L %s %s A %s %s 0 %d 0 %s %s Z" fill="%s"><title>%s: %s</title></path>',
				round( $x1, 1 ), round( $y1, 1 ), $r, $r, $large, round( $x2, 1 ), round( $y2, 1 ),
				round( $x3, 1 ), round( $y3, 1 ), $inner, $inner, $large, round( $x4, 1 ), round( $y4, 1 ),
				$colors[ $type ],
				esc_attr( $type ),
				esc_attr( number_format_i18n( $cnt ) )
			);
			$start = $end;
		}
		$svg .= sprintf( '<text x="%d" y="%d" font-size="20" font-weight="600" fill="#1d2327" text-anchor="middle">%s</text>', $cx, $cy, esc_html( number_format_i18n( $total ) ) );
		$svg .= sprintf( '<text x="%d" y="%d" font-size="11" fill="#646970" text-anchor="middle">total</text>', $cx, $cy + 16 );

		$lx = 270; $ly = 40;
		foreach ( $rx as $type => $cnt ) {
			$pct = (int) round( 100 * $cnt / max( 1, $total ) );
			$svg .= sprintf(
				'<circle cx="%d" cy="%d" r="6" fill="%s"></circle><text x="%d" y="%d" font-size="13" fill="#3c434a">%s %s</text><text x="%d" y="%d" font-size="13" font-weight="600" fill="#1d2327">%s (%d%%)</text>',
				$lx, $ly, $colors[ $type ],
				$lx + 12, $ly + 4, $icons[ $type ], esc_html( ucfirst( $type ) ),
				$lx + 150, $ly + 4, esc_html( number_format_i18n( $cnt ) ), $pct
			);
			$ly += 38;
		}
		$svg .= '</svg>';
		return $svg;
	}

	private function render_boards( $s ) {
		?>
		<h2 class="vibe-an-sec"><?php esc_html_e( 'Leaderboards', 'vibe-comments' ); ?></h2>
		<div class="vibe-an-two">
			<div>
				<div class="vibe-an-table-wrap"><table class="vibe-an-table">
					<thead><tr><th>#</th><th><?php esc_html_e( 'Post', 'vibe-comments' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Comments', 'vibe-comments' ); ?></th></tr></thead>
					<tbody>
					<?php if ( empty( $s['top_posts'] ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No comments yet.', 'vibe-comments' ); ?></td></tr>
					<?php else : foreach ( $s['top_posts'] as $i => $p ) : ?>
						<tr>
							<td class="num"><?php echo (int) ( $i + 1 ); ?></td>
							<td>
								<a href="<?php echo esc_url( get_permalink( (int) $p->id ) ); ?>" target="_blank" rel="noopener">
									<?php echo esc_html( $p->title ? $p->title : __( '(no title)', 'vibe-comments' ) ); ?>
								</a>
							</td>
							<td class="num" style="text-align:right"><span class="vibe-an-count-pill"><?php echo esc_html( number_format_i18n( (int) $p->cnt ) ); ?></span></td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table></div>
			</div>
			<div>
				<div class="vibe-an-table-wrap"><table class="vibe-an-table">
					<thead><tr><th>#</th><th><?php esc_html_e( 'Commenter', 'vibe-comments' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Comments', 'vibe-comments' ); ?></th></tr></thead>
					<tbody>
					<?php if ( empty( $s['top_commenters'] ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No commenters yet.', 'vibe-comments' ); ?></td></tr>
					<?php else : foreach ( $s['top_commenters'] as $i => $c ) : ?>
						<tr>
							<td class="num"><?php echo (int) ( $i + 1 ); ?></td>
							<td><?php echo esc_html( $c->author ); ?></td>
							<td class="num" style="text-align:right"><span class="vibe-an-count-pill"><?php echo esc_html( number_format_i18n( (int) $c->cnt ) ); ?></span></td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table></div>
			</div>
		</div>

		<h2 class="vibe-an-sec"><?php esc_html_e( 'Most-reacted comments', 'vibe-comments' ); ?></h2>
		<div class="vibe-an-table-wrap"><table class="vibe-an-table">
			<thead><tr><th><?php esc_html_e( 'Reactions', 'vibe-comments' ); ?></th><th><?php esc_html_e( 'Comment', 'vibe-comments' ); ?></th><th><?php esc_html_e( 'On post', 'vibe-comments' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $s['top_reacted'] ) ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'No reactions yet.', 'vibe-comments' ); ?></td></tr>
			<?php else : foreach ( $s['top_reacted'] as $r ) : ?>
				<tr>
					<td class="num"><span class="vibe-an-count-pill"><?php echo esc_html( number_format_i18n( (int) $r->cnt ) ); ?></span></td>
					<td><span class="vibe-an-excerpt"><?php echo esc_html( wp_strip_all_tags( $r->excerpt ) ); ?></span></td>
					<td><span class="vibe-an-excerpt"><?php echo esc_html( $r->post_title ); ?></span></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table></div>

		<h2 class="vibe-an-sec"><?php esc_html_e( 'Engagement quality', 'vibe-comments' ); ?></h2>
		<div class="vibe-an-two">
			<div>
				<div class="vibe-an-table-wrap"><table class="vibe-an-table">
					<tbody>
					<tr><th><?php esc_html_e( 'Top-level comments answered by a reply', 'vibe-comments' ); ?></th><td class="num"><?php echo esc_html( (string) $s['quality']['replied_pct'] ); ?>%</td></tr>
					<tr><th><?php esc_html_e( 'Avg time from comment to first reply', 'vibe-comments' ); ?></th><td class="num"><?php echo esc_html( null === $s['quality']['reply_velocity'] ? ' - ' : human_time_diff( 0, max( 60, (int) $s['quality']['reply_velocity'] ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Deepest thread (replies deep)', 'vibe-comments' ); ?></th><td class="num"><?php echo esc_html( number_format_i18n( (int) $s['totals']['max_thread'] ) ); ?></td></tr>
					</tbody>
				</table></div>
			</div>
			<div>
				<div class="vibe-an-table-wrap"><table class="vibe-an-table">
					<tbody>
					<tr><th><?php esc_html_e( 'Guest vs member comments', 'vibe-comments' ); ?></th><td class="num"><?php echo esc_html( number_format_i18n( (int) $s['totals']['guests'] ) ); ?> / <?php echo esc_html( number_format_i18n( (int) $s['totals']['members'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Comments per post (avg, top 10 posts)', 'vibe-comments' ); ?></th><td class="num"><?php
						$sum = 0;
						foreach ( (array) $s['top_posts'] as $p ) { $sum += (int) $p->cnt; }
						echo esc_html( empty( $s['top_posts'] ) ? ' - ' : number_format_i18n( $sum / count( $s['top_posts'] ), 1 ) );
					?></td></tr>
					<tr><th><?php esc_html_e( 'Push + email subscribers (total rails)', 'vibe-comments' ); ?></th><td class="num"><?php echo esc_html( number_format_i18n( (int) $s['push_subs'] + (int) $s['email_opts'] ) ); ?></td></tr>
					</tbody>
				</table></div>
			</div>
		</div>
		<?php
	}
}
