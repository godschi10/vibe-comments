<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Hooks the plugin's custom template into WordPress's comments system.
 *
 * All comment rendering is handled client-side via AJAX (class-ajax-handler.php).
 * The old render_comment() static method (wp_list_comments callback) has been
 * removed — it called DB methods that no longer exist (get_like_count,
 * user_has_liked) and was never reachable once the AJAX architecture was adopted.
 */
class Vibe_Comments_Template_Loader {
    public function __construct() {
        add_filter( 'comments_template',   array( $this, 'load_template' ) );
        add_filter( 'comment_form_defaults', array( $this, 'disable_default_form' ) );
    }

    /**
     * Replace WordPress's native comments template with ours.
     *
     * Applies whenever commenting is open OR the post already has approved
     * comments — NOT just when comments are open. Previously this bailed on
     * !comments_open() unconditionally, which meant ANY post with commenting
     * closed (a common state for archived content) fell through to the
     * theme's default comment template instead of ours, regardless of
     * whether that post had existing comments. templates/comments.php
     * itself was already written to handle "closed but has comments"
     * gracefully (see its own top-of-file gate) — that logic has been
     * unreachable this whole time because THIS filter redirected away from
     * our template before it was ever included. Also meant class-schema.php's
     * JSON-LD output (which correctly outputs data for closed-with-comments
     * posts) was describing a comment list visitors couldn't actually see
     * through this plugin's own UI. get_comments_number() is used rather
     * than have_comments() because it's a lighter, safe-anywhere template
     * tag with no dependency on WP's comment query loop having run yet —
     * the same primitive templates/comments.php already relies on for its
     * own count display a few lines further into that file.
     */
    public function load_template( $template ) {
        if ( ! self::should_render() ) {
            return $template;
        }
        $plugin_template = VIBE_COMMENTS_PLUGIN_DIR . 'templates/comments.php';
        return file_exists( $plugin_template ) ? $plugin_template : $template;
    }

    /**
     * Single source of truth for "does this singular view use the Vibe UI?"
     *
     * TRUE when commenting is open OR the post already has approved comments
     * (closed-but-has-comments posts still render the Vibe list). Consumed by
     * load_template() above AND by the enqueue_assets() condition in the main
     * plugin file — keep exactly ONE copy of this logic (v3.6.1 dedup; these
     * two previously drifted apart once and broke CSS/JS loading).
     */
    public static function should_render() {
        if ( ! is_singular() ) {
            return false;
        }
        return comments_open() || (int) get_comments_number() > 0;
    }

    /**
     * Remove the default "Your email address will not be published" notice
     * and the after-form XHTML help text — irrelevant since we render our own form.
     */
    public function disable_default_form( $defaults ) {
        $defaults['comment_notes_before'] = '';
        $defaults['comment_notes_after']  = '';
        return $defaults;
    }
}
