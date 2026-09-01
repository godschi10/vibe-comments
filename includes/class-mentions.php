<?php
/**
 * Mentions - @name pills + push notifications, riding the reply-push rail.
 *
 * RENDERING is client-side (vibe-comments.js renderMentions()): the stored
 * comment_content keeps plain "@Name" text, so any other renderer (feeds,
 * admin, no-JS) shows the natural plaintext form. Pills appear only in the
 * interactive comment list where the mentionable-author list is known.
 *
 * NOTIFICATIONS are stateless server-side: on approval, parse @Name tokens
 * from the content, resolve each to the author's most recent comment on
 * THIS post that carries a reply-push subscription, and send through the
 * exact rail proven in v3.7.0 (Vibe_Comments_Reply_Push::send()). All the
 * reply-push guards apply by construction: rail present, subscription
 * exists, approvals only, prune-on-410, per-request dedup.
 *
 * Portability law: everything degrades to plaintext when the theme rail is
 * absent - pills still render (client-side), notifications silently skip.
 *
 * @package Vibe_Comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vibe_Comments_Mentions {

	/**
	 * Max mentions notified per comment. A mention storm (spam content with
	 * 50 @names) must not create 50 pushes through the theme's stream.
	 */
	const NOTIFY_CAP = 5;

	/**
	 * Mentionable authors for a post, localized to the client.
	 *
	 * Approved commenters on the post + the post author, deduped by
	 * lowercased name. The post author is included even with zero
	 * comments (a "@PostAuthor ping" is a legitimate first interaction).
	 * The CURRENT user's own name is excluded (self-mention is a no-op).
	 *
	 * @param int $post_id
	 * @return array<array{id:int, name:string}> 5 most recent, newest first.
	 */
	public static function mentionable( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return array();
		}

		$seen   = array();
		$result = array();

		$post_author = get_post_field( 'post_author', $post_id );
		if ( $post_author ) {
			$user = get_userdata( $post_author );
			if ( $user && ! empty( $user->display_name ) ) {
				$name = trim( $user->display_name );
				if ( '' !== $name ) {
					$seen[ strtolower( $name ) ]   = true;
					$result[] = array(
						'id'   => 0,
						'name' => $name,
					);
				}
			}
		}

		$comments = get_comments(
			array(
				'post_id'   => $post_id,
				'status'    => 'approve',
				'number'    => 50,
				'order'     => 'DESC',
				'orderby'   => 'comment_date_gmt',
			)
		);

		foreach ( $comments as $comment ) {
			$name = trim( (string) $comment->comment_author );
			$key  = strtolower( $name );
			if ( '' === $name || isset( $seen[ $key ] ) ) {
				continue;
		}
			$seen[ $key ] = true;
			$result[]      = array(
				'id'   => absint( $comment->comment_ID ),
				'name' => $name,
			);
		}

		/**
		 * Filter the mentionable author list for a post.
		 *
		 * @param array $result  { id, name } pairs, newest first.
		 * @param int   $post_id
		 */
		return apply_filters( 'vibe_comments_mentionable', $result, $post_id );
	}

	/**
	 * Parse @mentions from raw comment content, matched against the
	 * mentionable list. Longest name first, terminator-guarded.
	 *
	 * Returns each mentioned author ONCE with the comment id of their
	 * most recent comment on the post (0 for the post author - resolved
	 * at notify time; the post author's subscription lives on their own
	 * latest comment on the post, if any).
	 *
	 * @param string $content Raw comment_content (already DB-stored text).
	 * @param int    $post_id
	 * @return array<array{name:string, comment_id:int}> matched mentions.
	 */
	public static function parse( $content, $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 || '' === trim( (string) $content ) ) {
			return array();
		}

		$mentionable = self::mentionable( $post_id );
		if ( empty( $mentionable ) ) {
			return array();
		}

		// Longest-first matching: "Ada Lovelace" must win over "Ada".
		usort( $mentionable, static function( $a, $b ) {
			return strlen( $b['name'] ) - strlen( $a['name'] );
		} );

		$matched = array();
		$raw     = (string) $content;

		foreach ( $mentionable as $candidate ) {
			$name  = (string) $candidate['name'];
			if ( '' === $name ) {
				continue;
			}
			$token = '@' . $name;

			// First unclaimed occurrence wins (one pill per name, one notify).
			$at = self::find_unclaimed( $raw, $token, $matched );
			if ( false === $at ) {
				continue;
			}

			$matched[ $at ] = array(
				'name'       => $name,
				'comment_id' => absint( $candidate['id'] ),
			);
		}

		// Sort matches left-to-right for stable, predictable output.
		ksort( $matched );
		return array_values( $matched );
	}

	/**
	 * Find the first @token occurrence that is a standalone mention
	 * (terminator guard) and not already covered by a longer match.
	 *
	 * @param string $raw
	 * @param string $token  "@Name" candidate.
	 @param array  $matched Existing matches keyed by byte offset.
	 * @return int|false Byte offset of the @, or false.
	 */
	private static function find_unclaimed( $raw, $token, $matched ) {
		$len   = strlen( $raw );
		$tlen  = strlen( $token );
		$start = 0;

		while ( true ) {
			$at = stripos( $raw, $token, $start );
			if ( false === $at ) {
				return false;
			}

			// Terminator: char after the token must not be [a-zA-Z0-9_] -
			// "@Ada," matches, "@Adae" does not. Guard multi-word names too.
			$next = $at + $tlen;
			if ( $next < $len && self::is_word_char( $raw[ $next ] ) ) {
				$start = $at + 1;
				continue;
			}

			// Pre-boundary: char before @ must not be a word char (alphanumeric
			// or underscore) - "email@Ada" is an address, not a mention.
			if ( $at > 0 && self::is_word_char( $raw[ $at - 1 ] ) ) {
				$start = $at + 1;
				continue;
			}

			// Claimed by a longer match already? Only accept if this
			// occurrence is outside every claimed span.
			$overlaps = false;
			foreach ( $matched as $off => $m ) {
				$mlen = strlen( '@' . $m['name'] );
				if ( $at >= $off && $at < $off + $mlen ) {
					$overlaps = true;
					break;
				}
			}
			if ( $overlaps ) {
				$start = $at +  1;
				continue;
			}

			return $at;
		}
	}

	/**
	 * True for [A-Za-z0-9_] plus anything >= 0x80 (multibyte name tails -
	 * byte-safe: we only test the byte AFTER the match, which for UTF-8 is
	 * either an ASCII terminator or a continuation byte (0x80-0xBF), treated
	 * as part of the name).
	 */
	private static function is_word_char( $byte ) {
		return $byte >= '0' && $byte <= '9'
			|| $byte >= 'A' && $byte <= 'Z'
			|| $byte >= 'a' && $byte <= 'z'
			|| $byte === '_'
			|| ord( $byte ) >= 0x80;
	}

	/**
	 * Notify every mentioned author, riding the reply-push rail.
	 *
	 * Called from the same three approval paths as notify_parent(): instant
	 * approval, transition to approved, admin status-set. Guards:
	 * - rail absent          → silent no-op (portability law)
	 * - no subscription      → skip (never invented notifications)
 * - self-mention         → skip (mentioning yourself is not an event)
	 * - parent already       → skip (the reply push already fired for them;
	 *   notified                 the mention pill renders, but no double buzz)
	 * - NOTIFY_CAP           → at most 5 pushes per comment
	 *
	 * @param WP_Comment $comment The newly-approved comment.
	 * @return int Number of notifications actually sent.
	 */
	public static function notify_mentioned( $comment ) {
		if ( ! Vibe_Comments_Reply_Push::is_available() ) {
			return 0;
		}

		$comment = get_comment( $comment );
		if ( ! $comment || ! is_object( $comment ) ) {
			return 0;
		}
		if ( '1' !== (string) $comment->comment_approved ) {
			return 0;
		}

		$mentions = self::parse( $comment->comment_content, $comment->comment_post_ID );
		if ( empty( $mentions ) ) {
			return 0;
		}

		$parent_id = absint( $comment->comment_parent );
		$sent      = 0;

		// Hoisted out of the loop (cleanup-audit N3, 2026-09-01): the parent
		// comment and the post are the SAME object on every iteration -
		// fetching them once turns two per-mention queries into zero.
		$parent = $parent_id > 0 ? get_comment( $parent_id ) : null;
		$post    = get_post( $comment->comment_post_ID );

		foreach ( $mentions as $mention ) {
			if ( $sent >= self::NOTIFY_CAP ) {
				break;
			}

			$name = $mention['name'];

			// Self-mention skip.
			if ( strtolower( trim( $name ) ) === strtolower( trim( (string) $comment->comment_author ) ) ) {
				continue;
			}

			// If this mention targets the direct parent's author, the
			// reply-push already notified them - do not double-buzz.
			if ( $parent_id > 0 ) {
				if ( $parent && strtolower( trim( (string) $parent->comment_author ) ) === strtolower( $name ) ) {
					continue;
				}
			}

			// Resolve the subscription: the mentioned author's most recent
			// comment on THIS post carrying _vibe_reply_push. For the post
			// author (comment_id 0 from mentionable()), scan their comments.
			$owner = self::resolve_subscription_owner( $mention, $comment );
			if ( ! $owner ) {
				continue;
			}

			$subscription = Vibe_Comments_Reply_Push::get_subscription( $owner );
			if ( ! $subscription ) {
				continue;
			}

			if ( ! $post ) {
				continue;
			}

			$mentioner = '' !== trim( (string) $comment->comment_author )
				? trim( (string) $comment->comment_author )
				: __( 'Someone', 'vibe-comments' );

			/* translators: 1: mentioner name, 2: mentioned author name. */
			$title = sprintf(
				__( '%1$s mentioned you in a comment', 'vibe-comments' ),
				$mentioner
			);

			$payload = array(
				'title' => $title,
				'body'  => wp_trim_words( wp_strip_all_tags( $comment->comment_content ), 18, '…' ),
				// icon/badge normalized by send_mention() to the theme icon.
				'url'   => get_permalink( $post ) . '#comment-' . absint( $comment->comment_ID ),
			);

			// Reuse the v3.7.0 send() contract via a public shim: the
			// send() is private, so notify through the class's own door -
			// Vibe_Comments_Reply_Push::send_mention() (added v3.8.0).
			$ok = Vibe_Comments_Reply_Push::send_mention( $subscription, $payload, $owner );
			if ( $ok ) {
				$sent++;
			}
		}

		return $sent;
	}

	/**
	 * Resolve the comment that owns the mentioned author's subscription.
	 *
	 * Subscription ownership is per-comment: a user may have subscribed on
	 * an older comment and not re-subscribed since. The exact candidate set
	 * is every SUBSCRIBED comment on this post (meta_key query), matched by
	 * author name, newest first. The post author is covered naturally: if
	 * they commented + subscribed, their row is in the set.
	 *
	 * @param array     $mention { name, comment_id }
	 * @param WP_Comment $comment The newly-approved comment.
	 * @return int Comment ID owning a live subscription, or 0.
	 */
	private static function resolve_subscription_owner( $mention, $comment ) {
		$post_id = absint( $comment->comment_post_ID );
		$name    = strtolower( trim( (string) $mention['name'] ) );
		if ( $post_id < 1 || '' === $name ) {
			return 0;
		}

		// Every subscribed comment on this post - the exact candidate set.
		$subscribed = get_comments( array(
			'post_id'  => $post_id,
			'status'  => 'approve',
			'number'  => 20,
			'meta_key' => Vibe_Comments_Reply_Push::META_KEY,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		) );

		foreach ( $subscribed as $candidate ) {
			if ( strtolower( trim( (string) $candidate->comment_author ) ) === $name ) {
				return absint( $candidate->comment_ID );
			}
		}

		return 0;
	}

	/**
	 * Localize the mentionable list (called from the main enqueue).
	 *
	 * @param int $post_id
	 * @return array For wp_localize_script.
	 */
	public static function localize_data( $post_id ) {
		return array(
			'enabled'   => true,
			'authors'   => self::mentionable( $post_id ),
			// Longest-name-first is enforced client-side too (render + dropdown).
		);
	}
}
