<?php
/**
 * Reply Push Notifications - self-hosted, zero third parties.
 *
 * Lets a commenter opt in ("Notify me about replies" checkbox) to a web-push
 * alert when someone replies to THEIR comment. The browser subscription is
 * stored on the comment itself (`_vibe_reply_push` commentmeta), which gives
 * a perfect 1:1 notify-the-author-of-THIS-comment mapping with automatic
 * lifecycle: the comment is deleted → the subscription dies with it; the
 * plugin is uninstalled → uninstall.php sweeps the meta.
 *
 * DESIGN CONTRACT - this plugin does NOT ship its own push stack.
 * It integrates with a theme that already provides one (both GWill themes
 * ship the identical rail):
 *   - gwill_push_vapid()      → array{ publicKey, privateKey } (VAPID, autoload-off option)
 *   - gwill_push_stream()     → Minishlink\WebPush\WebPush instance (TTL 86400)
 *   - gwill_push_obfuscate() / gwill_push_deobfuscate() → key-at-rest helpers
 *   - sw.js 'push' handler    → payload contract: { title, body, icon, badge, url }
 * When those functions are absent (any other theme), the feature never arms:
 * the checkbox is not rendered, no REST/localize keys are emitted, and every
 * method below no-ops. No fatal, no dead UI.
 *
 * WHY NOT THE THEME'S SUBSCRIBER TABLE? The gwill_push_subscribers table is
 * the site-wide broadcast list ("new post" bell). Reply notifications are
 * per-comment and guest-inclusive - a guest has no user_id to join, and
 * attaching the site-wide list to a single comment would be wrong. The
 * browser push subscription itself is the SAME subscription either way
 * (one per origin + service worker); only the server-side routing differs.
 *
 * WHY SEND THROUGH THE THEME'S STREAM? The vendored minishlink library,
 * VAPID keys, and RFC 8291/8188 machinery all live in the theme. Duplicating
 * any of it in the plugin would violate the zero-duplication discipline and
 * double the maintenance. function_exists() is the integration contract.
 *
 * @package Vibe_Comments
 * @since   3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Vibe_Comments_Reply_Push {

    /** Comment meta key holding the subscriber blob for this comment's author. */
    const META_KEY = '_vibe_reply_push';

    /**
     * Per-process dedup: a comment approval can fire BOTH
     * transition_comment_status AND wp_set_comment_status (core calls the
     * transition from inside the setter), so a naive dual hook would push
     * twice. One notification per comment ID per request - static, dies with
     * the process, exactly like the theme's per-request memo caches.
     *
     * @var array<int, bool>
     */
    private static $notified = array();

    /* ────────────────────────────────────────────────────────────────────
     * Availability
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Is the push rail present and the feature enabled?
     *
     * Checks the THEME contract (functions, not class names - the theme is
     * procedural) plus the optional plugin filter so a site owner can turn
     * the feature off without touching code.
     *
     * @return bool
     */
    public static function is_available() {
        static $available = null;
        if ( null !== $available ) {
            return $available;
        }
        $available = false;
        if ( ! function_exists( 'gwill_push_vapid' )
            || ! function_exists( 'gwill_push_stream' )
            || ! function_exists( 'gwill_push_obfuscate' )
            || ! function_exists( 'gwill_push_deobfuscate' ) ) {
            return false;
        }
        // No VAPID keys → the theme itself can't send; treat as unarmed.
        if ( ! gwill_push_vapid() ) {
            return false;
        }
        /**
         * Filter whether reply push notifications are offered.
         *
         * @param bool $enabled Default true when the theme rail exists.
         */
        $available = (bool) apply_filters( 'vibe_comments_reply_push_enabled', true );
        return $available;
    }

    /**
     * The VAPID public key for the client-side subscribe call.
     *
     * @return string Empty string when unavailable.
     */
    public static function public_key() {
        if ( ! self::is_available() ) {
            return '';
        }
        $keys = gwill_push_vapid();
        return is_array( $keys ) && ! empty( $keys['publicKey'] ) ? (string) $keys['publicKey'] : '';
    }

    /* ────────────────────────────────────────────────────────────────────
     * Storage
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Validate and store a subscription against a comment.
     *
     * Mirrors the theme's gwill_push_subscribe_cb() validation exactly:
     * https-only endpoint, round-trip through the library's Subscription
     * factory (rejects malformed keys before they hit the DB), obfuscation
     * at rest. Stored base64'd because SQLite's $wpdb wrapper rejects raw
     * binary column values (same reason as the theme's table writes).
     *
     * @param int    $comment_id Comment the subscription belongs to.
     * @param string $endpoint   Push endpoint URL (https only).
     * @param string $p256dh     Client public key.
     * @param string $auth       Auth secret.
     * @return bool True on success.
     */
    public static function store( $comment_id, $endpoint, $p256dh, $auth ) {
        $comment_id = absint( $comment_id );
        if ( ! $comment_id || ! get_comment( $comment_id ) ) {
            return false;
        }
        if ( ! self::is_available() ) {
            return false;
        }

        $endpoint = esc_url_raw( $endpoint );
        $p256dh   = sanitize_text_field( $p256dh );
        $auth     = sanitize_text_field( $auth );

        if ( '' === $endpoint || '' === $p256dh || '' === $auth ) {
            return false;
        }
        if ( 'https' !== wp_parse_url( $endpoint, PHP_URL_SCHEME ) ) {
            return false;
        }

        // Round-trip validation - the same guard the theme's REST route uses.
        try {
            if ( ! class_exists( '\Minishlink\WebPush\Subscription' ) ) {
                return false;
            }
            \Minishlink\WebPush\Subscription::create( array(
                'endpoint'        => $endpoint,
                'keys'            => array(
                    'p256dh' => $p256dh,
                    'auth'   => $auth,
                ),
                'contentEncoding' => 'aes128gcm',
            ) );
        } catch ( \Throwable $e ) {
            return false;
        }

        $blob = wp_json_encode( array(
            'endpoint' => $endpoint,
            'p256dh'   => base64_encode( gwill_push_obfuscate( $p256dh ) ),
            'auth'     => base64_encode( gwill_push_obfuscate( $auth ) ),
        ) );

        if ( ! $blob ) {
            return false;
        }
        return (bool) update_comment_meta( $comment_id, self::META_KEY, $blob );
    }

    /**
     * Fetch a comment's stored subscription, deobfuscated.
     *
     * @param int $comment_id
     * @return array{endpoint:string,p256dh:string,auth:string}|null
     */
    public static function get_subscription( $comment_id ) {
        $blob = get_comment_meta( absint( $comment_id ), self::META_KEY, true );
        if ( ! is_string( $blob ) || '' === $blob ) {
            return null;
        }
        $data = json_decode( $blob, true );
        if ( ! is_array( $data )
            || empty( $data['endpoint'] ) || empty( $data['p256dh'] ) || empty( $data['auth'] ) ) {
            return null;
        }
        return array(
            'endpoint' => (string) $data['endpoint'],
            'p256dh'   => gwill_push_deobfuscate( base64_decode( $data['p256dh'] ) ),
            'auth'     => gwill_push_deobfuscate( base64_decode( $data['auth'] ) ),
        );
    }

    /**
     * Drop a comment's subscription (410/404 prune, or author opt-out).
     *
     * @param int $comment_id
     * @return bool
     */
    public static function delete( $comment_id ) {
        return delete_comment_meta( absint( $comment_id ), self::META_KEY );
    }

    /* ────────────────────────────────────────────────────────────────────
     * Notify
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Notify the parent comment's author that their comment got a reply.
     *
     * Fires on the reply's APPROVAL (instant or moderated - see the hooks
     * in class-ajax-handler.php). Guards:
     *   - top-level comments (parent=0) have no author to notify;
     *   - self-replies never notify (same author email replying to themself);
     *   - per-process dedup so dual status hooks can't double-push;
     *   - every failure path is silent-safe: a broken push must never
     *     disturb the comment flow that triggered it.
     *
     * @param WP_Comment|int $reply The newly approved reply.
     * @return bool True when a notification was actually queued.
     */
    public static function notify_parent( $reply ) {
        if ( ! self::is_available() ) {
            return false;
        }

        $reply = get_comment( $reply );
        if ( ! $reply || ! is_object( $reply ) ) {
            return false;
        }

        $reply_id = absint( $reply->comment_ID );
        if ( isset( self::$notified[ $reply_id ] ) ) {
            return false; // already handled this comment in this request
        }
        self::$notified[ $reply_id ] = true;

        $parent_id = absint( $reply->comment_parent );
        if ( $parent_id < 1 ) {
            return false; // not a reply - nothing to notify
        }
        if ( '1' !== (string) $reply->comment_approved ) {
            return false; // only approved replies are public events
        }

        $parent = get_comment( $parent_id );
        if ( ! $parent ) {
            return false;
        }

        // Self-reply guard: replying to your own comment is not an event.
        $reply_email  = strtolower( trim( (string) $reply->comment_author_email ) );
        $parent_email = strtolower( trim( (string) $parent->comment_author_email ) );
        if ( $reply_email && $reply_email === $parent_email ) {
            return false;
        }

        $subscription = self::get_subscription( $parent_id );
        if ( ! $subscription ) {
            return false; // parent author never opted in
        }

        $post = get_post( $reply->comment_post_ID );
        if ( ! $post ) {
            return false;
        }

        $replier = '' !== trim( (string) $reply->comment_author )
            ? trim( (string) $reply->comment_author )
            : __( 'Someone', 'vibe-comments' );

        $payload = array(
            /* translators: %s = replier's display name. */
            'title' => sprintf( __( '%s replied to your comment', 'vibe-comments' ), $replier ),
            'body'  => wp_trim_words( wp_strip_all_tags( $reply->comment_content ), 18, '…' ),
            'icon'  => self::icon(),
            'badge' => self::icon(),
            // Tap lands on the reply itself - the user has read their own
            // comment; the new content is the reply.
            'url'   => get_permalink( $post ) . '#comment-' . $reply_id,
            // v3.18.0 consent law: every push carries its own off-switch.
            // sw.js surfaces this as the notification's secondary action.
            'unsub_url' => Vibe_Comments_Unsubscribe::url( 'push', $parent_id ),
        );

        return self::send( $subscription, $payload, $parent_id );
    }

    /**
     * v3.8.0 - Public door for the mentions class to reuse the private
     * send() + prune contract (same stream, same sw.js payload, same
     * 410/404 self-cleaning). Nothing new is invented here - it is the
     * reply-push rail with a mention-shaped payload.
     *
     * @param array $subscription { endpoint, p256dh, auth }
     * @param array $payload      sw.js contract { title, body, icon, badge, url }
     * @param int   $comment_id   Meta owner for pruning.
     * @return bool
     */
    public static function send_mention( $subscription, $payload, $comment_id ) {
        // Mention payloads carry the same icon/badge as reply pushes -
        // normalize anything the caller left blank so sw.js renders a
        // complete notification every time.
        if ( empty( $payload['icon'] ) ) {
            $payload['icon'] = self::icon();
        }
        if ( empty( $payload['badge'] ) ) {
            $payload['badge'] = self::icon();
        }
        return self::send( $subscription, $payload, $comment_id );
    }

    /**
     * Queue + flush one notification through the theme's stream.
     *
     * Prunes the stored meta on 410 Gone / 404 - a revoked subscription
     * (user unsubscribed the bell, browser expired it) must clean itself
     * up, mirroring the theme's send loop.
     *
     * @param array $subscription { endpoint, p256dh, auth }
     * @param array $payload      sw.js payload contract.
     * @param int   $comment_id  Meta owner for pruning.
     * @return bool
     */
    private static function send( $subscription, $payload, $comment_id ) {
        try {
            if ( ! class_exists( '\Minishlink\WebPush\Subscription' ) ) {
                return false;
            }
            $stream = gwill_push_stream();
            if ( ! $stream ) {
                return false;
            }
            $stream->queueNotification(
                \Minishlink\WebPush\Subscription::create( array(
                    'endpoint'        => $subscription['endpoint'],
                    'keys'            => array(
                        'p256dh' => $subscription['p256dh'],
                        'auth'   => $subscription['auth'],
                    ),
                    'contentEncoding' => 'aes128gcm',
                ) ),
                wp_json_encode( $payload, JSON_UNESCAPED_SLASHES )
            );
            $reports = $stream->flush();
            if ( is_object( $reports ) && method_exists( $reports, 'current' ) ) {
                $reports = iterator_to_array( $reports );
            }
            if ( is_array( $reports ) ) {
                foreach ( $reports as $report ) {
                    $status = $report->getResponse()->getStatusCode();
                    if ( 410 === $status || 404 === $status ) {
                        self::delete( $comment_id );
                    }
                }
            }
            return true;
        } catch ( \Throwable $e ) {
            // Never let a push failure disturb the comment flow.
            if ( function_exists( 'vibe_log' ) ) {
                vibe_log( 'reply-push send failed: ' . $e->getMessage() );
            }
            return false;
        }
    }

    /**
     * Notification icon. Both GWill themes ship the identical path; the
     * filter lets any other rail-providing theme point at its own asset.
     *
     * @return string
     */
    private static function icon() {
        /**
         * Filter the reply-push notification icon URL.
         *
         * @param string $icon Default: <theme>/assets/brand/appicon-192.png.
         */
        return apply_filters(
            'vibe_comments_push_icon',
            get_template_directory_uri() . '/assets/brand/appicon-192.png'
        );
    }
}
