<?php
/**
 * Vibe Comments - JSON-LD structured data output.
 *
 * Outputs a Schema.org @graph block in <head> on singular posts containing:
 *   - WebPage entity with commentCount (Google uses this as a quality signal)
 *   - Individual Comment entities for each approved comment
 *   - parentItem links for threaded replies
 *
 * Why in wp_head rather than inline HTML:
 *   Comments load via AJAX - Googlebot may not execute the click that triggers
 *   the load, so comments are invisible to crawlers. JSON-LD in the page head
 *   is always present in the initial HTTP response and requires no JS execution.
 *
 * Compatibility:
 *   The WebPage @id uses the plain post URL (no fragment). Google merges same-URL
 *   entities across multiple JSON-LD blocks, so commentCount safely augments
 *   whatever Article/WebPage schema Yoast SEO, Rank Math, or the theme outputs -
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
        if ( ! $post_id ) {
            return;
        }

        // Stored count is updated on every comment approval/deletion -
        // no extra COUNT(*) query needed here.
        $count = (int) get_option( 'vibe_comment_count_' . $post_id, 0 );

        // Output schema if comments are open OR existing approved comments exist.
        // A post with commenting closed but 50 existing comments still has
        // discussion data Google should index - skipping schema wastes the SEO signal.
        if ( ! comments_open( $post_id ) && $count === 0 ) {
            return;
        }

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

        // ── v3.15.0 Q&A mode: QAPage replaces WebPage+Comment ──────────
        // When the post runs in Q&A mode, the comment section IS a question
        // thread - serving Comment entities would tell Google "page with
        // comments" while the page itself now renders (and is intended, by
        // the author's explicit toggle) as a question with answers. QAPage
        // is the schema.org type for exactly this shape and is eligible for
        // rich results. Replaces, not augments: mixing WebPage+Comment AND
        // QAPage+Answer for the same discussion would be two contradictory
        // descriptions of the same content to the same crawler.
        if ( Vibe_Comments_QA::is_qa_post( $post_id ) ) {
            self::output_qa( $post_id, $post_url, $comments );
            return;
        }

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
                    // wp_strip_all_tags() here matches the protection already
                    // applied to $text above. comment_content gets it; this field
                    // did not, and JSON_UNESCAPED_SLASHES is enabled a few lines
                    // down - the exact combination that lets a literal
                    // </script> sequence in an author name break out of this
                    // <script type="application/ld+json"> block and inject
                    // arbitrary HTML into the page <head>. This plugin's own
                    // submission path (sanitize_text_field() in submit_comment())
                    // already strips such sequences, but that offers no
                    // protection here - this reads whatever is in wp_comments
                    // right now, regardless of how it got there: admin editing
                    // (admins can typically post unfiltered HTML in WP), CSV/XML
                    // import, a different plugin, or legacy pre-this-plugin data.
                    'name'  => wp_strip_all_tags( $comment->comment_author ),
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

        // JSON_HEX_TAG escapes < and > as \u003C/\u003E within string values -
        // this is what actually closes the </script>-breakout risk at the
        // encoding layer itself, for EVERY field, not just the ones this file
        // remembers to wp_strip_all_tags() individually. Still fully valid,
        // spec-compliant JSON - a JSON-LD parser decodes \u003C back to the
        // literal character exactly as if it had been unescaped, so this is
        // purely a raw-HTML-output-level protection with zero effect on how
        // Google (or anything else) actually interprets the structured data.
        // JSON_HEX_AMP is the standard companion flag for the same reason.
        // JSON_UNESCAPED_UNICODE keeps emoji/international text readable.
        // JSON_UNESCAPED_SLASHES avoids \/  noise in URLs - safe to keep
        // purely for readability now that JSON_HEX_TAG independently handles
        // the actual angle-bracket risk regardless of slash-escaping.
        echo "\n<script type=\"application/ld+json\">\n"
            . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP )
            . "\n</script>\n";
    }

    /**
     * v3.15.0 - QAPage schema for Q&A-mode posts.
     *
     * Shape (schema.org QAPage):
     *   QAPage
     *     └ mainEntity: Question
     *         ├ name        = post title
     *         ├ text        = post excerpt (the question body)
     *         ├ upvoteCount = total reactions across answers (proxy; each
     *         │              Answer carries its own upvoteCount too)
     *         ├ answerCount = approved top-level comments
     *         ├ acceptedAnswer = the marked answer (comment_ID in meta), if any
     *         └ suggestedAnswer[] = every other approved answer
     *
     * Why upvoteCount proxies from reactions: this minimal cut has no
     * separate vote table - reactions ARE the vote UI (design decision,
     * agreed with the King). Sums are computed once per request from the
     * reactions map, not per-comment queries.
     */
    private static function output_qa( $post_id, $post_url, $comments ) {
        $accepted_id = Vibe_Comments_QA::accepted_answer_id( $post_id );
        $accepted    = null;
        $answers     = [];

        // Reaction totals in ONE batch query (not per-comment get_reaction_counts
        // calls - that would be N queries for N answers on every page load).
        $db            = new Vibe_Comments_Database();
        $top_ids       = array_map( function( $c ) { return (int) $c->comment_ID; },
            array_filter( $comments, function( $c ) { return 0 === (int) $c->comment_parent; } ) );
        $reaction_map  = $db->get_reaction_counts_batch( $top_ids );

        foreach ( $comments as $comment ) {
            // Top-level comments only: in Q&A mode, replies attach to
            // answers (and to the accepted answer's own thread) - they are
            // discussion, not answers. Schema-wise each answer stands alone.
            if ( (int) $comment->comment_parent > 0 ) {
                continue;
            }

            $cid = (int) $comment->comment_ID;
            $text = wp_strip_all_tags( $comment->comment_content );
            $text = trim( preg_replace( '/\s+/', ' ', $text ) );
            if ( mb_strlen( $text ) > 500 ) {
                $text = mb_substr( $text, 0, 497 ) . '…';
            }
            if ( '' === $text ) {
                continue;
            }

            // upvoteCount proxies from total reactions - reactions ARE the vote
            // UI in this minimal cut (design decision, agreed with the King).
            $total = isset( $reaction_map[ $cid ] ) ? array_sum( array_map( 'intval', (array) $reaction_map[ $cid ] ) ) : 0;

            $answer = [
                '@type'         => 'Answer',
                '@id'           => $post_url . '#comment-' . $cid,
                'url'           => $post_url . '#comment-' . $cid,
                'text'          => $text,
                'datePublished' => gmdate( 'c', strtotime( $comment->comment_date_gmt ) ),
                'author'        => [
                    '@type' => 'Person',
                    'name'  => wp_strip_all_tags( $comment->comment_author ),
                ],
                'upvoteCount'   => $total,
            ];

            if ( $cid === $accepted_id ) {
                $accepted = $answer;
            } else {
                $answers[] = $answer;
            }
        }

        $question_text = wp_strip_all_tags( get_post_field( 'post_excerpt', $post_id ) );
        $question_text = trim( preg_replace( '/\s+/', ' ', $question_text ) );
        if ( '' === $question_text ) {
            // No excerpt: fall back to the post content's first 500 chars -
            // a Question with no body text is semantically thin for rich
            // results, and the title alone may not carry the full question.
            $question_text = wp_strip_all_tags( get_post_field( 'post_content', $post_id ) );
            $question_text = trim( preg_replace( '/\s+/', ' ', $question_text ) );
            if ( mb_strlen( $question_text ) > 500 ) {
                $question_text = mb_substr( $question_text, 0, 497 ) . '…';
            }
        }

        $question = [
            '@type'      => 'Question',
            '@id'        => $post_url . '#question',
            'name'       => wp_strip_all_tags( get_the_title( $post_id ) ),
            'text'       => $question_text,
            'answerCount' => count( $answers ) + ( $accepted ? 1 : 0 ),
        ];
        if ( $accepted ) {
            $question['acceptedAnswer'] = $accepted;
        }
        if ( ! empty( $answers ) ) {
            $question['suggestedAnswer'] = $answers;
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type'      => 'QAPage',
                    '@id'        => $post_url,
                    'url'        => $post_url,
                    'mainEntity' => $question,
                ],
            ],
        ];

        echo "\n<script type=\"application/ld+json\">\n"
            . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP )
            . "\n</script>\n";
    }
}
