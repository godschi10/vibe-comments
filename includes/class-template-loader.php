<?php
class Vibe_Comments_Template_Loader {
    public function __construct() {
        add_filter('comments_template', array($this, 'load_template'));
        add_filter('comment_form_defaults', array($this, 'disable_default_form'));
    }

    public function load_template($template) {
        if (!is_singular() || !comments_open()) {
            return $template;
        }

        $plugin_template = VIBE_COMMENTS_PLUGIN_DIR . 'templates/comments.php';

        if (file_exists($plugin_template)) {
            return $plugin_template;
        }

        return $template;
    }

    public function disable_default_form($defaults) {
        $defaults['comment_notes_before'] = '';
        $defaults['comment_notes_after']  = '';
        return $defaults;
    }

    /**
     * Static render callback for wp_list_comments
     * Defined here so it's always available before template loads
     */
    public static function render_comment($comment, $args, $depth) {
        static $db = null;
        if ($db === null) {
            $db = new Vibe_Comments_Database();
        }
        $like_count = $db->get_like_count($comment->comment_ID);
        $user_liked = is_user_logged_in() ? $db->user_has_liked($comment->comment_ID, get_current_user_id()) : false;
        $tag = ($args['style'] === 'div') ? 'div' : 'li';
        ?>
        <<?php echo $tag; ?> <?php comment_class(empty($args['has_children']) ? '' : 'parent', $comment); ?> id="comment-<?php comment_ID(); ?>">
            <article class="vibe-comment-body" id="div-comment-<?php comment_ID(); ?>">
                <header class="vibe-comment-header">
                    <div class="vibe-comment-avatar">
                        <?php echo get_avatar($comment, $args['avatar_size']); ?>
                    </div>
                    <div class="vibe-comment-meta">
                        <cite class="vibe-comment-author">
                            <?php echo get_comment_author_link($comment); ?>
                            <?php if ($comment->user_id && $comment->user_id == get_post_field('post_author', $comment->comment_post_ID)) : ?>
                                <span class="vibe-badge vibe-badge-author"><?php _e('Author', 'vibe-comments'); ?></span>
                            <?php endif; ?>
                        </cite>
                        <time class="vibe-comment-time" datetime="<?php comment_time('c'); ?>">
                            <?php echo human_time_diff(strtotime($comment->comment_date), current_time('timestamp')) . ' ' . __('ago', 'vibe-comments'); ?>
                        </time>
                    </div>
                </header>

                <div class="vibe-comment-content">
                    <?php if ($comment->comment_approved == '0') : ?>
                        <em class="vibe-awaiting"><?php _e('Your comment is awaiting moderation.', 'vibe-comments'); ?></em>
                    <?php endif; ?>
                    <?php comment_text(); ?>
                </div>

                <footer class="vibe-comment-footer">
                    <?php if (is_user_logged_in()) : ?>
                        <button type="button" class="vibe-like-btn <?php echo $user_liked ? 'liked' : ''; ?>" 
                                data-comment-id="<?php echo esc_attr($comment->comment_ID); ?>">
                            <svg class="vibe-heart" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                            </svg>
                            <span class="vibe-like-count"><?php echo number_format_i18n($like_count); ?></span>
                        </button>
                    <?php else : ?>
                        <span class="vibe-like-count-static">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                            </svg>
                            <?php echo number_format_i18n($like_count); ?>
                        </span>
                    <?php endif; ?>

                    <?php
                    // Reply button — all users can reply. Guests get the guest fields auto-shown.
                    printf(
                        '<button type="button" class="comment-reply-link vibe-reply-trigger" data-comment-id="%d" data-depth="%d" data-max-depth="%d">%s</button>',
                        esc_attr($comment->comment_ID),
                        esc_attr($depth),
                        esc_attr($args['max_depth']),
                        esc_html__('Reply', 'vibe-comments')
                    );
                    ?>
                </footer>
            </article>
        <?php
    }
}
