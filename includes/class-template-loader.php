<?php
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
     * Only applies on singular post/page views with comments open.
     */
    public function load_template( $template ) {
        if ( ! is_singular() || ! comments_open() ) {
            return $template;
        }
        $plugin_template = VIBE_COMMENTS_PLUGIN_DIR . 'templates/comments.php';
        return file_exists( $plugin_template ) ? $plugin_template : $template;
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
