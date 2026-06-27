<?php
/**
 * Vibe Comments — JSON-LD structured data output.
 *
 * Outputs a Schema.org @graph block in <head> on singular posts containing:
 *   - WebPage entity with commentCount (Google uses this as a quality signal)
 *   - Individual Comment entities for each approved comment
 *   - parentItem links for threaded replies
 *
 * Why in wp_head rather than inline HTML:
 *   Comments load via AJAX — Googlebot may not execute the click that triggers
 *   the load, so comments are invisible to crawlers. JSON-LD in the page head
 *   is always present in the initial HTTP response and requires no JS execution.
 *
 * Compatibility:
 *   The WebPage @id uses the plain post URL (no fragment). Google merges same-URL
 *   entities across multiple JSON-LD blocks, so commentCount safely augments
 *   whatever Article/WebPage schema Yoast SEO, Rank Math, or the theme outputs —
 *   no duplication, no conflict.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Vibe_Comments_Schema {

    public static function init() {
        add_action( 'wp_head', [ self::class, 'output' ], 20 );
    }

    public static function output() {
        if ( ! is_singular() ) {
            return;
        }

        $post_id = get_the_ID();
        if ( ! $post_id || ! comments_open( $post_id ) ) {
            return;
        }

        // Stored count is updated on every comment approval/deletion —
        // no extra COUNT(*) query needed here.
        $count = (int) get_option( 'vibe_comment_count_' . $post_id, 0 );

        // Fetch approved comments (cap at 100 to keep JSON-LD compact).
        // WordPress internally caches get_comments() for the request lifetime.
        $comments = get_comments( [
            'post_id' => $post_id,
            'status'  => 'approve',
            'number'  => 100,
            'orderby' => 'comment_date_gmt',
            'order'   => 'ASC',
        ] );

        if ( empty( $comments ) && $count === 0 ) {
            return;
        }

        $post_url = get_permalink( $post_id );
        $graph    = [];

        // ── WebPage entity with commentCount ──────────────────────────────
        // commentCount is a direct ranking signal for engagement. Google merges
        // this with any existing WebPage/Article entity for the same URL.
        $graph[] = [
            '@type'        => 'WebPage',
            '@id'          => $post_url,
            'url'          => $post_url,
            'commentCount' => $count ?: count( $comments ),
        ];

        // ── Individual Comment entities ───────────────────────────────────
        foreach ( $comments as $comment ) {
            $cid         = (int) $comment->comment_ID;
            $comment_url = $post_url . '#comment-' . $cid;

            // Strip HTML, collapse whitespace, cap at 500 chars.
            $text = wp_strip_all_tags( $comment->comment_content );
            $text = trim( preg_replace( '/\s+/', ' ', $text ) );
            if ( mb_strlen( $text ) > 500 ) {
                $text = mb_substr( $text, 0, 497 ) . '…';
            }
            if ( $text === '' ) {
                continue; // Skip empty or media-only comments.
            }

            $entry = [
                '@type'         => 'Comment',
                '@id'           => $comment_url,
                'url'           => $comment_url,
                'text'          => $text,
                'datePublished' => gmdate( 'c', strtotime( $comment->comment_date_gmt ) ),
                'author'        => [
                    '@type' => 'Person',
                    'name'  => $comment->comment_author,
                ],
                'about'         => [ '@id' => $post_url ],
            ];

            // Author website (logged-in users with a URL set in profile).
            if ( ! empty( $comment->comment_author_url ) ) {
                $entry['author']['url'] = esc_url( $comment->comment_author_url );
            }

            // Threaded reply: link back to parent comment.
            if ( (int) $comment->comment_parent > 0 ) {
                $entry['parentItem'] = [
                    '@id' => $post_url . '#comment-' . (int) $comment->comment_parent,
                ];
            }

            $graph[] = $entry;
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];

        // JSON_UNESCAPED_UNICODE keeps emoji/international text readable.
        // JSON_UNESCAPED_SLASHES avoids \/  noise in URLs.
        echo "\n<script type=\"application/ld+json\">\n"
            . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            . "\n</script>\n";
    }
}
