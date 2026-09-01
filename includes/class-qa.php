<?php
/**
 * Vibe Comments — Q&A mode (Feature #3, v3.15.0).
 *
 * Per-post Q&A: when enabled, a post's comment section renders as a
 * Stack-Overflow-style Q&A thread — the post is the Question, top-level
 * comments are Answers, reactions serve as upvotes, and the post author
 * can mark exactly one answer as Accepted (green check, hoisted to top).
 *
 * Storage law (why post meta, not comment meta):
 *   - `_vibe_qa_mode` (post meta)     — the per-post toggle. Post meta because
 *     the mode is a property of the POST, not of any comment. One row, one
 *     read, cached by WP's meta cache.
 *   - `_vibe_qa_accepted` (post meta) — the accepted answer's comment_ID.
 *     Post meta, NOT comment meta, because "accepted" is a property of the
 *     question (this post) — one answer among many, and un-accepting must
 *     clear the mark in one write, not hunt down the previously-flagged
 *     comment. It also keeps the flag OUT of format_comment_tree()'s
 *     per-comment meta lookups — is_pinned already does one get_comment_meta
 *     per comment; a second per-comment meta flag would double that query
 *     load for every comment on every load. One get_post_meta per REQUEST
 *   instead is a single row read, shared by every comment's payload.
 *
 * Cache law: both flags are universal truths (same for every visitor, like
 * is_pinned/is_edited), so they bake safely into the shared 120s list cache.
 * No per-requester overlay is needed for rendering — only the Accept BUTTON's
 * visibility is per-requester (gated by the localize config's canAccept, which
 * is computed fresh per page load and never cached).
 *
 * Permission law: accept/unaccept is the post author's call (classic model).
 * Users with moderate_comments (admins/editors) can also do it — a site owner
 * must be able to curate a neglected question thread. Nobody else, ever.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vibe_Comments_QA {

	const META_MODE     = '_vibe_qa_mode';
	const META_ACCEPTED = '_vibe_qa_accepted';

	public static function init() {
		// Editor sidebar toggle (classic meta box; renders in the sidebar
		// column on the block editor too).
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post',     array( __CLASS__, 'save_meta_box' ), 10, 2 );

		// Accept/unaccept answer — logged-in only (the author/moderator IS
		// logged in by definition; guests never see the button).
		add_action( 'wp_ajax_vibe_accept_answer', array( __CLASS__, 'ajax_accept_answer' ) );
	}

	// ── Toggle helpers ──────────────────────────────────────────────────

	/**
	 * Whether this post runs in Q&A mode.
	 *
	 * get_post_meta with a single key is object-cached after the first call
	 * anywhere in the request (schema output, localize config, AJAX handler
	 * all ask) — one DB touch per request no matter how many call sites.
	 */
	public static function is_qa_post( $post_id ) {
		if ( ! $post_id ) {
			return false;
		}
		return (bool) get_post_meta( (int) $post_id, self::META_MODE, true );
	}

	/**
	 * The accepted answer's comment_ID for this post, or 0 when none.
	 *
	 * Stored as a plain meta value; absint on the way out so a malformed
	 * value can never leak into comparisons as a truthy string.
	 */
	public static function accepted_answer_id( $post_id ) {
		if ( ! $post_id ) {
			return 0;
		}
		return absint( get_post_meta( (int) $post_id, self::META_ACCEPTED, true ) );
	}

	/**
	 * Whether the CURRENT visitor may accept/unaccept answers on this post.
	 * Author or moderator — checked fresh, never cached into any payload.
	 */
	public static function can_accept( $post_id ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( current_user_can( 'moderate_comments' ) ) {
			return true;
		}
		$post = get_post( $post_id );
		return $post && (int) get_current_user_id() === (int) $post->post_author;
	}

	/**
	 * Localize payload for the client. Single source of truth for everything
	 * the JS needs to know about this post's Q&A state.
	 */
	public static function localize_data( $post_id ) {
		if ( ! self::is_qa_post( $post_id ) ) {
			return false; // absent key = JS renders the classic comment UI
		}
		return array(
			'mode'        => true,
			'acceptedId'  => self::accepted_answer_id( $post_id ),
			'canAccept'   => self::can_accept( $post_id ),
			'questionUrl' => (string) get_permalink( $post_id ),
		);
	}

	/**
	 * Nonce for the accept call. House pattern: every privileged AJAX in
	 * this plugin (pin, edit, reply-push) rides the shared wp_rest nonce
	 * (config.nonce) — one nonce, one refresh cycle (vibe_refresh_nonce
	 * keeps cached pages valid). A per-feature nonce would need its own
	 * refresh endpoint or expire inside cached pages.
	 */
	public static function nonce() {
		return wp_create_nonce( 'wp_rest' );
	}

	// ── Editor sidebar toggle ────────────────────────────────────────────

	public static function add_meta_box() {
		// Only on post types that actually carry a comment section — a Q&A
		// toggle on a post type with no comments would be a dead control.
		$types = get_post_types_by_support( array( 'comments' ) );
		add_meta_box(
			'vibe-qa-mode',
			__( 'Vibe Q&A Mode', 'vibe-comments' ),
			array( __CLASS__, 'render_meta_box' ),
			$types,
			'side',
			'default'
		);
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'vibe_qa_mode', 'vibe_qa_mode_nonce' );
		$on = self::is_qa_post( $post->ID );
		?>
		<label for="vibe-qa-mode-toggle" style="display:block;line-height:1.6;">
			<input type="checkbox" id="vibe-qa-mode-toggle" name="vibe_qa_mode" value="1" <?php checked( $on ); ?> />
			<?php esc_html_e( 'Enable Q&A mode', 'vibe-comments' ); ?>
		</label>
		<p class="description" style="margin-top:4px;">
			<?php esc_html_e( 'Renders the comment section as a Q&A thread: the post is the question, comments become answers, and you can mark one answer as accepted.', 'vibe-comments' ); ?>
		</p>
		<?php
	}

	public static function save_meta_box( $post_id, $post ) {
		// The standard five rejections: autosave, revision, bad nonce,
		// insufficient cap, wrong post type. Order matters — nonce check
		// BEFORE cap so a forged save from an unprivileged user dies at the
		// nonce, and cap check before the write itself.
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['vibe_qa_mode_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['vibe_qa_mode_nonce'] ), 'vibe_qa_mode' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! post_type_supports( $post->post_type, 'comments' ) ) {
			return;
		}

		if ( ! empty( $_POST['vibe_qa_mode'] ) ) {
			update_post_meta( $post_id, self::META_MODE, '1' );
		} else {
			// Turning Q&A OFF also clears the accepted-answer mark — leaving
			// it would render a stray green badge in a classic thread if the
			// mode is ever re-enabled with the stale meta still pointing at
			// an answer the author may no longer endorse.
			delete_post_meta( $post_id, self::META_MODE );
			delete_post_meta( $post_id, self::META_ACCEPTED );
		}
	}

	// ── Accept / unaccept answer ────────────────────────────────────────

	/**
	 * Toggle semantics in one endpoint: pass the accepted comment to unaccept,
	 * a different comment to switch acceptance. Single write, idempotent.
	 */
	public static function ajax_accept_answer() {
		$comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
		$post_id    = isset( $_POST['post_id'] )    ? absint( $_POST['post_id'] )    : 0;

		// House nonce (wp_rest, same as pin/edit) — see nonce() docblock.
		if ( ! check_ajax_referer( 'wp_rest', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page.', 'vibe-comments' ) ), 403 );
		}

		$comment = $comment_id ? get_comment( $comment_id ) : null;
		// The comment must exist, be approved, and BELONG to this post —
		// accepting a comment from another post would write a cross-post
		// pointer into this post's meta.
		if ( ! $comment || (int) $comment->comment_post_ID !== $post_id || '1' !== (string) $comment->comment_approved ) {
			wp_send_json_error( array( 'message' => __( 'Answer not found.', 'vibe-comments' ) ), 404 );
		}

		if ( ! self::is_qa_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This post is not in Q&A mode.', 'vibe-comments' ) ), 400 );
		}

		if ( ! self::can_accept( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Only the post author or a moderator can accept answers.', 'vibe-comments' ) ), 403 );
		}

		$current = self::accepted_answer_id( $post_id );
		if ( $current === $comment_id ) {
			// Toggle OFF: accepting the already-accepted answer unaccepts it.
			delete_post_meta( $post_id, self::META_ACCEPTED );
		} else {
			update_post_meta( $post_id, self::META_ACCEPTED, (string) $comment_id );
		}

		// Purge the list cache so the hoist + badge reflect on the next
		// load/poll everywhere, for everyone.
		delete_transient( 'vc_load_' . $post_id );

		wp_send_json_success( array(
			'acceptedId' => self::accepted_answer_id( $post_id ),
		) );
	}
}
