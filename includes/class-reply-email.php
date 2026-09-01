<?php
/**
 * Reply notifications via EMAIL - free, unlimited, any-server.
 *
 * The plugin never touches SMTP itself: it calls wp_mail(), the universal
 * WordPress mail channel. Wherever wp_mail works, this works - zero-config
 * on hosts with server mail (cPanel/Exim/LiteSpeed), or via the GWILL_SMTP_*
 * constants the themes already support (phpmailer_init rail in inc/forms.php)
 * where outbound port 25 is blocked (like this VPS).
 *
 * Consent model: a flag in commentmeta (_vibe_reply_email) on the comment
 * itself - the notification address is ALWAYS the comment's own author email,
 * so the feature can never be used to email a stranger. Lifecycle is
 * automatic: comment deleted → consent gone. uninstall.php sweeps it.
 *
 * Anti-storm: a reply that becomes public notifies once (per-process dedup);
 * a brigaded thread can email one address at most 3 times per hour
 * (transient counter), then goes silent until the window passes.
 *
 * @package Vibe_Comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vibe_Comments_Reply_Email {

	/**
	 * Consent flag - commentmeta key. Value is '1' (the address itself is the
	 * comment's comment_author_email; we store only the consent, never a
	 * second copy of the address).
	 */
	const META_KEY = '_vibe_reply_email';

	/**
	 * Max emails to one parent-comment author per hour. A legitimate thread
	 * rarely exceeds this; a brigade cannot bury an inbox.
	 */
	const HOURLY_CAP = 3;

	/**
	 * Per-process dedup - the same reply can pass through more than one
	 * approval hook (instant + transition + status-set); the overlap must
	 * never double-send. Mirrors Vibe_Comments_Reply_Push::$notified.
	 *
	 * @var array<int, true>
	 */
	private static $sent = array();

	/**
	 * Record consent on a comment (submit path). The flag is only stored for
	 * a comment that exists - a failure here must never disturb the comment
	 * itself.
	 *
	 * @param int $comment_id
	 * @return bool
	 */
	public static function store( $comment_id ) {
		$comment_id = absint( $comment_id );
		if ( ! $comment_id || ! get_comment( $comment_id ) ) {
			return false;
		}
		return (bool) update_comment_meta( $comment_id, self::META_KEY, '1' );
	}

	/**
	 * Does this comment's author want reply emails, and is their address
	 * deliverable?
	 *
	 * @param int $comment_id
	 * @return bool
	 */
	public static function opted_in( $comment_id ) {
		$comment = get_comment( absint( $comment_id ) );
		if ( ! $comment ) {
			return false;
		}
		if ( '1' !== (string) get_comment_meta( $comment_id, self::META_KEY, true ) ) {
			return false;
		}
		$email = trim( (string) $comment->comment_author_email );
		return '' !== $email && (bool) is_email( $email );
	}

	/**
	 * The notify event - a reply just became publicly visible. Called from
	 * the same three approval paths as the push notifier; guards:
	 *
	 *   - wp_mail missing        → silent no-op
	 *   - not a reply             → no-op
	 *   - reply not approved      → no-op (only public events notify)
	 *   - self-reply              → no-op (replying to yourself is not an event)
	 *   - parent not opted in    → no-op
	 *   - already sent (process)  → no-op (hook overlap dedup)
	 *   - hourly cap exceeded    → no-op (anti-storm)
	 *
	 * A transport failure is swallowed and logged - the comment flow must
	 * never be disturbed by an email problem.
	 *
	 * @param WP_Comment|int $reply
	 * @return bool True if an email was handed to the transport.
	 */
	public static function notify_parent( $reply ) {
		if ( ! function_exists( 'wp_mail' ) ) {
			return false;
		}

		$reply = get_comment( $reply );
		if ( ! $reply || ! is_object( $reply ) ) {
			return false;
		}

		$reply_id = absint( $reply->comment_ID );
		if ( isset( self::$sent[ $reply_id ] ) ) {
			return false; // already handled this comment in this request
		}

		$parent_id = absint( $reply->comment_parent );
		if ( $parent_id < 1 ) {
			return false; // not a reply
		}
		if ( '1' !== (string) $reply->comment_approved ) {
			return false; // only approved replies are public events
		}

		$parent = get_comment( $parent_id );
		if ( ! $parent ) {
			return false;
		}

		// Self-reply guard - same email means the same person.
		$reply_email  = strtolower( trim( (string) $reply->comment_author_email ) );
		$parent_email = strtolower( trim( (string) $parent->comment_author_email ) );
		if ( $reply_email && $reply_email === $parent_email ) {
			return false;
		}

		if ( ! self::opted_in( $parent_id ) ) {
			return false;
		}

		// Anti-storm cap - max HOURLY_CAP sends to this parent per hour.
		$rate_key = 'vibe_re_rate_' . $parent_id;
		$sent_h   = (int) get_transient( $rate_key );
		if ( $sent_h >= self::HOURLY_CAP ) {
			return false;
		}
		if ( $sent_h > 0 ) {
			set_transient( $rate_key, $sent_h + 1, HOUR_IN_SECONDS );
		} else {
			set_transient( $rate_key, 1, HOUR_IN_SECONDS );
		}

		// Claim now - whatever happens below, this reply notifies once.
		self::$sent[ $reply_id ] = true;

		$post = get_post( $reply->comment_post_ID );
		if ( ! $post ) {
			return false;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$replier   = '' !== trim( (string) $reply->comment_author )
			? trim( (string) $reply->comment_author )
			: __( 'Someone', 'vibe-comments' );

		$subject = sprintf(
			/* translators: 1: replier name, 2: site name. */
			__( '%1$s replied to your comment on %2$s', 'vibe-comments' ),
			$replier,
			$site_name
		);

		$body = self::build_body( $parent, $reply, $post, $replier, $site_name );

		$sent = wp_mail(
			$parent->comment_author_email,
			$subject,
			$body,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		/**
		 * Fires after a reply-notification email is handed to the transport.
		 *
		 * @param bool  $sent      Transport result (false = relay refused).
		 * @param int   $parent_id The notified comment.
		 * @param int   $reply_id  The reply that triggered it.
		 */
		do_action( 'vibe_comments_reply_email_sent', $sent, $parent_id, $reply_id );

		if ( ! $sent && function_exists( 'error_log' ) ) {
			error_log( 'Vibe reply-email: wp_mail returned false for parent ' . $parent_id . ' (check SMTP constants / server mail).' );
		}

		return (bool) $sent;
	}

	/**
	 * Branded, minimal, email-client-safe HTML. Inline styles only (email
	 * clients strip <style>); dark header strip matching the themes' contact
	 * emails; one card with the reply excerpt; one CTA to the reply anchor;
	 * honest footer stating how consent was given.
	 *
	 * @param WP_Comment $parent
	 * @param WP_Comment $reply
	 * @param WP_Post    $post
	 * @param string     $replier
	 * @param string     $site_name
	 * @return string
	 */
	private static function build_body( $parent, $reply, $post, $replier, $site_name ) {
		$home     = home_url( '/' );
		$link     = get_permalink( $post ) . '#comment-' . absint( $reply->comment_ID );
		$excerpt  = wp_trim_words( wp_strip_all_tags( $reply->comment_content ), 30, '…' );
		$parent_q = wp_trim_words( wp_strip_all_tags( $parent->comment_content ), 12, '…' );

		// All dynamic values pass through esc_html()/esc_url() - email HTML
		// is still HTML.
		return ''
		. '<div style="max-width:560px;margin:0 auto;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;">'
		. '<div style="background:#111827;color:#f9fafb;padding:20px 24px;border-radius:8px 8px 0 0;">'
		. '<span style="font-size:16px;font-weight:700;">' . esc_html( $site_name ) . '</span>'
		. '<span style="float:right;font-size:12px;color:#9ca3af;">' . esc_html__( 'New reply', 'vibe-comments' ) . '</span>'
		. '</div>'
		. '<div style="background:#ffffff;border:1px solid #e5e7eb;border-top:0;padding:24px;border-radius:0 0 8px 8px;">'
		. '<p style="margin:0 0 14px;font-size:15px;color:#111827;">'
		. '<strong>' . esc_html( $replier ) . '</strong> '
		. esc_html__( 'replied to your comment', 'vibe-comments' )
		. ' <span style="color:#6b7280;">&ldquo;' . esc_html( $parent_q ) . '&rdquo;</span>'
		. '</p>'
		. '<div style="border-left:3px solid #6b7280;padding:6px 14px;margin:0 0 18px;color:#374151;font-size:14px;">'
		. esc_html( $excerpt )
		. '</div>'
		. '<p style="margin:0 0 22px;">'
		. '<a href="' . esc_url( $link ) . '" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:6px;font-size:14px;font-weight:600;">'
		. esc_html__( 'Read the reply', 'vibe-comments' ) . '</a>'
		. '</p>'
		. '<p style="margin:0;font-size:12px;color:#9ca3af;line-height:1.6;">'
		. esc_html__( 'You opted in via the "Email me about replies" checkbox on your comment. This is the only way this address receives these emails.', 'vibe-comments' )
		. '<br><a href="' . esc_url( Vibe_Comments_Unsubscribe::url( 'email', $parent->comment_ID ) ) . '" style="color:#9ca3af;">' . esc_html__( 'Stop these emails for this thread', 'vibe-comments' ) . '</a>'
		. ' &middot; <a href="' . esc_url( $home ) . '" style="color:#9ca3af;">' . esc_html( $site_name ) . '</a>'
		. '</p>'
		. '</div>'
		. '</div>';
	}
}
