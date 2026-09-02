<?php
/**
 * Vibe Comments - Unsubscribe (Feature #10, v3.18.0).
 *
 * The consent laws (born 2026-09-01, King-reported gap: "people can't
 * unsubscribe from comments alerts"):
 *
 *   1. Every notification rail that has an opt-in MUST have a matching
 *      opt-out, reachable WITHOUT logging in (consent given as a guest
 *      must be revocable as a guest).
 *   2. Every outbound notification email carries a working unsubscribe
 *      link - a load-bearing requirement enforced at build time by the
 *      notify path calling the footer builder.
 *   3. The token is a keyed signature over the rail + comment ID, not a
 *      stored secret: state lives in the consent meta itself (deleting
 *      the meta IS the expiry), so there is no second source of truth
 *      to drift or leak.
 *
 * Rails covered: reply-email (_vibe_reply_email), reply-push
 * (_vibe_reply_push). Mentions follow the reply-push subscription of
 * the mentioned comment (clearing the push subscription clears mention
 * notifications for that comment - one switch, no orphan consent).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vibe_Comments_Unsubscribe {

	const QUERY_ARG = 'vibe_unsub';

	public static function init() {
		// Public unsubscribe handler - runs on init (before any template), works
		// for logged-out visitors, no nonce (the token IS the capability).
		add_action( 'init', array( __CLASS__, 'maybe_handle' ) );

		// AJAX: flip the checkbox on one's own comment (logged-in or guest
		// with the same guest-token rail the comment used at submit time).
		add_action( 'wp_ajax_vibe_toggle_notify',        array( __CLASS__, 'ajax_toggle' ) );
		add_action( 'wp_ajax_nopriv_vibe_toggle_notify', array( __CLASS__, 'ajax_toggle' ) );
	}

	// ── Tokens ────────────────────────────────────────────────────────

	/**
	 * Keyed signature token for (rail, comment). AUTH_KEY salts it, so
	 * tokens are unforgeable per site and carry no stored secret.
	 */
	public static function token( $rail, $comment_id ) {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'vibe-salt';
		return substr( hash_hmac( 'sha256', $rail . '|' . absint( $comment_id ), $salt ), 0, 32 );
	}

	public static function verify_token( $rail, $comment_id, $token ) {
		return is_string( $token ) && 32 === strlen( $token )
			&& hash_equals( self::token( $rail, $comment_id ), $token );
	}

	/**
	 * The unsubscribe URL for a rail+comment. Used by the email footer
	 * builder and any UI surface. home_url-based - the comment ID encodes
	 * the thread, so no post dependency.
	 */
	public static function url( $rail, $comment_id ) {
		return add_query_arg(
			rawurlencode( self::QUERY_ARG ),
			self::token( $rail, $comment_id ) . '-' . absint( $comment_id ) . '-' . $rail,
			home_url( '/' )
		);
	}

	// ── Public handler ─────────────────────────────────────────────────

	public static function maybe_handle() {
		if ( empty( $_GET[ self::QUERY_ARG ] ) ) {
			return;
		}
		$raw   = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG ] ) );
		$parts = explode( '-', $raw );
		if ( 3 !== count( $parts ) ) {
			return;
		}
		list( $token, $cid, $rail ) = $parts;

		$rails = array( 'email', 'push' );
		if ( ! in_array( $rail, $rails, true ) || ! self::verify_token( $rail, (int) $cid, $token ) ) {
			self::render_page( false, $rail, 0 );
			exit;
		}

		$comment_id = absint( $cid );
		$removed    = self::clear_consent( $rail, $comment_id );

		self::render_page( $removed, $rail, $comment_id );
		exit;
	}

	/**
	 * Clear one rail's consent for a comment. Returns what actually
	 * happened so the page can speak the truth.
	 */
	public static function clear_consent( $rail, $comment_id ) {
		if ( 'email' === $rail ) {
			return (bool) delete_comment_meta( $comment_id, Vibe_Comments_Reply_Email::META_KEY );
		}
		if ( 'push' === $rail ) {
			return (bool) delete_comment_meta( $comment_id, Vibe_Comments_Reply_Push::META_KEY );
		}
		return false;
	}

	// ── AJAX toggle (own-comment checkbox) ──────────────────────────────

	public static function ajax_toggle() {
		if ( ! check_ajax_referer( 'wp_rest', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page.', 'vibe-comments' ) ), 403 );
		}

		$comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
		$client_id  = isset( $_POST['vibe_guest_id'] ) ? sanitize_text_field( wp_unslash( $_POST['vibe_guest_id'] ) ) : '';
		$on         = ! empty( $_POST['notify'] ) && '1' === $_POST['notify'];

		$comment = $comment_id ? get_comment( $comment_id ) : null;
		if ( ! $comment || '1' !== (string) $comment->comment_approved ) {
			wp_send_json_error( array( 'message' => __( 'Comment not found.', 'vibe-comments' ) ), 404 );
		}

		// Ownership: the author (user id) or the guest token rail used at
		// submit time - the same rail edit_comment trusts.
		$user_id = get_current_user_id();
		$owner   = get_comment_meta( $comment_id, '_vibe_owner', true );
		$owns    = false;
		if ( $user_id > 0 && (int) $comment->user_id === $user_id ) {
			$owns = true;
		} elseif ( '' !== $client_id && $owner && hash_equals( (string) $owner, (string) Vibe_Comments_Database::get_guest_token( $client_id ) ) ) {
			$owns = true;
		}
		if ( ! $owns ) {
			wp_send_json_error( array( 'message' => __( 'You can only change notifications on your own comments.', 'vibe-comments' ) ), 403 );
		}

		// Dual-rail consent law: the pill reflects and controls BOTH rails
		// this comment may have. Toggling ON restores the rails the comment
		// HAD (or email if neither); toggling OFF clears both. A push
		// subscriber therefore sees the same bell and the same one-tap
		// off-switch as an email subscriber.
		if ( $on ) {
			update_comment_meta( $comment_id, Vibe_Comments_Reply_Email::META_KEY, 1 );
		} else {
			delete_comment_meta( $comment_id, Vibe_Comments_Reply_Email::META_KEY );
			delete_comment_meta( $comment_id, Vibe_Comments_Reply_Push::META_KEY );
		}

		wp_send_json_success( array(
			'notify'    => $on,
			'push_too'  => $on ? (bool) get_comment_meta( $comment_id, Vibe_Comments_Reply_Push::META_KEY, true ) : false,
		) );
	}

	// ── The unsubscribe page ────────────────────────────────────────────

	private static function render_page( $removed, $rail, $comment_id ) {
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$label = 'email' === $rail ? 'reply emails' : 'reply notifications';

		status_header( 200 );
		nocache_headers();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( sprintf( __( 'Notifications - %s', 'vibe-comments' ), $site ) ); ?></title>
<style>
body { margin:0; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif; background:#f4f4f5; color:#18181b; }
.card { max-width:520px; margin:64px auto; background:#fff; border:1px solid #e4e4e7; border-radius:12px; padding:32px; }
.brand { font-size:12px; letter-spacing:2px; text-transform:uppercase; color:#b8860b; font-weight:700; }
h1 { font-size:22px; margin:10px 0 8px; }
p { font-size:14px; color:#52525b; line-height:1.6; margin:0 0 16px; }
.ok { color:#15803d; font-weight:700; }
</style>
</head>
<body>
<div class="card">
	<div class="brand"><?php echo esc_html( $site ); ?></div>
	<?php if ( $removed ) : ?>
		<h1><?php echo esc_html__( 'Unsubscribed', 'vibe-comments' ); ?></h1>
		<p class="ok"><?php echo esc_html( sprintf( __( "You won't get %s on this thread anymore.", 'vibe-comments' ), $label ) ); ?></p>
		<p><?php echo esc_html__( 'You can turn notifications back on anytime from the comment form on the post.', 'vibe-comments' ); ?></p>
	<?php else : ?>
		<h1><?php echo esc_html__( 'Notifications', 'vibe-comments' ); ?></h1>
		<p><?php echo esc_html__( 'This link has expired or the subscription was already removed. You will not receive further notifications.', 'vibe-comments' ); ?></p>
	<?php endif; ?>
	<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html__( 'Back to the site', 'vibe-comments' ); ?></a></p>
</div>
</body>
</html>
		<?php
	}
}
