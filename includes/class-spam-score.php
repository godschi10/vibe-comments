<?php
/**
 * Heuristic spam scorer (Feature #6, v3.14.0).
 *
 * Pure, stateless, zero-dependency: the score is computed from the comment's
 * own text/author fields alone - no DB reads, no network, no stored drift.
 * Score 0–100 with per-heuristic reasons; label bands:
 *   < 30  Clean        (green)
 *   30–59 Suspicious   (amber)
 *   ≥ 60  Likely spam  (red)
 *
 * DISPLAY-ONLY by design: this class NEVER changes a comment's status. The
 * site's own moderation settings (manual approval, Akismet, keyword lists)
 * remain the sole judge - the badge only gives the human moderator a
 * why-flagged score at a glance. Auto-action was deliberately rejected in
 * the design review: a false positive that hides a real reader's comment
 * costs more than a false negative the moderator was already reviewing.
 *
 * Heuristics (each contributes points, total capped at 100):
 *  - Link count (the classic blog-spam tell)
 *  - Link-to-word ratio (link stuffing with filler text)
 *  - CAPS ratio (shouting)
 *  - Longest same-character run (aaaaa!!!!!)
 *  - Punctuation-run frequency (!!!! ????)
 *  - Known spam phrases (weighted keyword list)
 *  - Space-less long blob (gibberish / data-URI dumps)
 *  - Author-name signals (all-caps, keyword-stuffed)
 *
 * All heuristics are language-neutral on purpose - the site's audience may
 * comment in pidgin or mixed English, so only structural tells are used,
 * never grammar or vocabulary of legitimate languages.
 *
 * @package Vibe_Comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vibe_Comments_Spam_Score {

	/**
	 * Label bands. Kept public so the admin column and any future consumer
	 * (REST, CLI) render identical words for identical scores.
	 */
	const CLEAN_MAX       = 29;  // 0–29
	const SUSPICIOUS_MAX  = 59;  // 30–59

	/**
	 * Score a comment. Accepts a WP_Comment object or an array with the
	 * submit-shape keys (author, email, url, content) so the battery can
	 * drive it without WP loaded at all.
	 *
	 * @param  WP_Comment|array $c Comment object or array(author, email, url, content).
	 * @return array            array( 'score' => int 0-100, 'label' => string, 'reasons' => string[] )
	 */
	public static function score( $c ) {
		$author  = is_object( $c ) ? (string) ( $c->comment_author       ?? '' ) : (string) ( $c['author']  ?? '' );
		$email   = is_object( $c ) ? (string) ( $c->comment_author_email ?? '' ) : (string) ( $c['email']   ?? '' );
		$url     = is_object( $c ) ? (string) ( $c->comment_author_url    ?? '' ) : (string) ( $c['url']     ?? '' );
		$content = is_object( $c ) ? (string) ( $c->comment_content      ?? '' ) : (string) ( $c['content'] ?? '' );

		$points  = 0;
		$reasons = array();

		// ── 1. Link count - the classic blog-spam tell ──────────────────
		// URL regex tolerant of the forms wp_kses leaves behind in stored
		// content (plain-text http/https/www links and bare domains).
		preg_match_all( '/(?:https?:\/\/|www\.)[^\s<>"\']+/i', $content, $m );
		$links = count( $m[0] );
		if ( $links >= 5 ) { $points += 50; $reasons[] = "$links links"; }
		elseif ( $links === 4 ) { $points += 40; $reasons[] = '4 links'; }
		elseif ( $links === 3 ) { $points += 30; $reasons[] = '3 links'; }
		elseif ( $links === 2 ) { $points += 15; $reasons[] = '2 links'; }
		elseif ( $links === 1 ) { $points += 5;  $reasons[] = '1 link'; }

		// Author URL doesn't count toward body links (a homepage is normal),
		// but an author URL on a link-stuffed body IS an extra spam signal.
		if ( $url && $links >= 3 ) { $points += 5; $reasons[] = 'author URL'; }

		// ── 2. Link-to-word ratio - filler text around stuffed links ────
		$words = str_word_count( strip_tags( $content ) );
		if ( $links > 0 && $words > 0 && ( $links / $words ) > 0.2 ) {
			$points += 15;
			$reasons[] = 'link-stuffed';
		}

		// ── 3. CAPS ratio - shouting ────────────────────────────────────
		$letters = preg_replace( '/[^A-Za-z]/', '', $content );
		if ( mb_strlen( $letters ) >= 20 ) {
			$caps  = preg_replace( '/[^A-Z]/', '', $letters );
			$ratio = mb_strlen( $caps ) / max( 1, mb_strlen( $letters ) );
			if ( $ratio > 0.5 )      { $points += 20; $reasons[] = 'ALL-CAPS'; }
			elseif ( $ratio > 0.3 )  { $points += 10; $reasons[] = 'heavy caps'; }
		}

		// ── 4. Longest same-character run - aaaaa!!!!! ─────────────────
		if ( preg_match( '/(.)\1{5,}/u', $content ) ) {
			$points += 15;
			$reasons[] = 'repeated characters';
		}

		// ── 5. Punctuation-run frequency - !!! ??? ──────────────────────
		preg_match_all( '/[!?]{3,}/', $content, $pm );
		if ( count( $pm[0] ) >= 3 ) {
			$points += 10;
			$reasons[] = 'punctuation runs';
		}

		// ── 6. Known spam phrases - weighted keyword list ───────────────
		$phrases = array(
			'viagra', 'cialis', 'casino', 'gambling', 'lottery winner',
			'crypto giveaway', 'free bitcoins', 'bitcoin doubler',
			'seo services', 'increase traffic', 'boost your ranking',
			'buy followers', 'cheap hosting', 'replica watches',
			'make money online', 'work from home', 'earn $', 'earning app',
			'weight loss supplement', 'essay writing', 'paper writing',
			'loan offer', 'instant loan', 'forex signals', 'trading signals',
			'dating site', 'hot singles', 'click here to win',
		);
		$lower = ' ' . strtolower( $content . ' ' . $author . ' ' . $email ) . ' ';
		$hits  = 0;
		foreach ( $phrases as $p ) {
			if ( strpos( $lower, $p ) !== false ) { $hits++; }
		}
		if ( $hits > 0 ) {
			$points += 25 * min( $hits, 2 ); // cap: two phrase families max
			$reasons[] = $hits . ' spam phrase' . ( $hits > 1 ? 's' : '' );
		}

		// ── 7. Space-less long blob - gibberish / data-URI dumps ───────
		// 60+ non-space chars with no space at all is never human prose.
		$flat = preg_replace( '/\s+/u', '', strip_tags( $content ) );
		if ( mb_strlen( $flat ) >= 60 && strpos( $content, ' ' ) === false ) {
			$points += 40;
			$reasons[] = 'no spaces';
		}

		// ── 8. Author-name signals ──────────────────────────────────────
		if ( $author !== '' ) {
			$name_letters = preg_replace( '/[^A-Za-z]/', '', $author );
			if ( mb_strlen( $name_letters ) >= 4 && $name_letters === strtoupper( $name_letters ) ) {
				$points += 15;
				$reasons[] = 'ALL-CAPS name';
			}
			foreach ( array( 'seo', 'casino', 'viagra', 'crypto', 'forex', 'escort' ) as $kw ) {
				if ( stripos( $author, $kw ) !== false ) {
					$points += 20;
					$reasons[] = 'keyword name';
					break;
				}
			}
		}

		$score = max( 0, min( 100, $points ) );

		/**
		 * Filter the final spam score (all heuristics already applied).
		 * Returning a value outside 0–100 is clamped by the caller contract.
		 *
		 * @param int   $score   Computed score.
		 * @param array $reasons Matched heuristic reasons (by value).
		 * @param mixed $c       Original input comment.
		 */
		$score = (int) apply_filters( 'vibe_comments_spam_score', $score, $reasons, $c );

		return array(
			'score'   => max( 0, min( 100, $score ) ),
			'label'   => self::label( $score ),
			'reasons' => $reasons,
		);
	}

	/**
	 * Band label for a numeric score.
	 *
	 * @param  int $score 0–100.
	 * @return string      'clean' | 'suspicious' | 'likely-spam'
	 */
	public static function label( $score ) {
		if ( $score >= 60 )    return 'likely-spam';
		if ( $score >= 30 )    return 'suspicious';
		return 'clean';
	}

	/**
	 * Human words for a label key.
	 *
	 * @param  string $label Label key from self::label().
	 * @return string
	 */
	public static function label_text( $label ) {
		if ( 'likely-spam' === $label ) return __( 'Likely spam', 'vibe-comments' );
		if ( 'suspicious'  === $label ) return __( 'Suspicious', 'vibe-comments' );
		return __( 'Clean', 'vibe-comments' );
	}

	/**
	 * Admin-list badge markup for one comment. Escaped for attribute
	 * context; the reasons list rides the title tooltip so a moderator
	 * hovers once and sees exactly WHY the score is what it is.
	 *
	 * @param  WP_Comment $comment
	 * @return string     HTML (safe).
	 */
	public static function badge_html( $comment ) {
		$r     = self::score( $comment );
		$label = self::label_text( $r['label'] );
		$why   = $r['reasons'] ? implode( ', ', $r['reasons'] ) : __( 'no spam signals', 'vibe-comments' );
		return sprintf(
			'<span class="vibe-spam-badge vibe-spam-%1$s" title="%2$s">%3$s %4$d%%</span>',
			esc_attr( $r['label'] ),
			esc_attr( $why ),
			esc_html( $label ),
			(int) $r['score']
		);
	}
}
