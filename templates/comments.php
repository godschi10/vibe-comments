<?php
/**
 * Vibe Comments Template
 * Overrides default comments.php via comments_template filter.
 *
 * Architecture:
 *   - Comment count is read from wp_options (written on every approval, zero live DB query).
 *   - Everything (toolbar, list, form) is hidden until the user clicks "Load Comments".
 *   - The page is fully static and cache-safe — no PHP count functions on the hot path.
 */

if (!class_exists('Vibe_Comments_Database')) {
    require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-database.php';
}

if (!comments_open() && !have_comments()) {
    return;
}

// ── Persistent comment count ─────────────────────────────────────────────────
//
// get_option() reads from WP's in-memory options cache (or Redis/Memcached).
// Zero live DB query on every render — the option is updated by on_comment_approved()
// before the page cache is purged, so the value is always accurate when the
// cache rebuilds.
//
// On first render (option not yet seeded), fall back to get_comments_number()
// and seed the option so future renders are free.
$vibe_count = get_option('vibe_comment_count_' . get_the_ID());
if (false === $vibe_count) {
    $vibe_count = (int) get_comments_number();
    update_option('vibe_comment_count_' . get_the_ID(), $vibe_count, false);
}
$vibe_count = (int) $vibe_count;

if ($vibe_count === 0) {
    $vibe_heading = '';                                       // CSS :empty hides the h2
} elseif ($vibe_count === 1) {
    $vibe_heading = esc_html__('1 Comment', 'vibe-comments');
} else {
    $vibe_heading = esc_html(sprintf(
        /* translators: %s = formatted number */
        __('%s Comments', 'vibe-comments'),
        number_format_i18n($vibe_count)
    ));
}
?>

<section id="vibe-comments" class="vibe-comments-section">

    <!--
        Heading text comes from wp_options — not from a live DB query.
        Empty when count = 0 so CSS :empty hides it automatically.
        JS overwrites the text with the accurate live total after Load is clicked.
    -->
    <h2 class="vibe-comments-title" id="vibe-comments-title"><?php echo $vibe_heading; ?></h2>

    <!-- Static trigger — no count, cached forever, zero staleness risk -->
    <div id="vibe-comments-trigger">
        <button type="button" id="vibe-load-comments-btn" class="vibe-btn vibe-btn-load-comments">
            <?php _e('Load Comments', 'vibe-comments'); ?>
        </button>
    </div>

    <?php /* A1 fix: this entire comment system — list, form, reactions, everything
             — is AJAX-driven with no server-rendered fallback content anywhere in
             this template. Without this, a visitor with JavaScript disabled sees
             a "Load Comments" button that does nothing when clicked, with zero
             indication of why. This doesn't attempt a full non-JS posting path
             (that would mean rebuilding a parallel submission system this plugin
             deliberately moved away from) — just an honest explanation, plus the
             existing comment count so at least that much is visible either way. */ ?>
    <noscript>
        <p class="vibe-noscript-notice">
            <?php
            if ( $vibe_count > 0 ) {
                printf(
                    /* translators: %s: number of existing comments, already formatted. */
                    esc_html( _n(
                        'This page has %s comment. Enable JavaScript to view and join the discussion.',
                        'This page has %s comments. Enable JavaScript to view and join the discussion.',
                        $vibe_count,
                        'vibe-comments'
                    ) ),
                    esc_html( number_format_i18n( $vibe_count ) )
                );
            } else {
                esc_html_e( 'Enable JavaScript to view and join the discussion.', 'vibe-comments' );
            }
            ?>
        </p>
    </noscript>

    <!--
        Everything below is hidden until the button is clicked.
        This keeps the page completely static: no AJAX, no DB, no rendering cost
        until the user explicitly requests the comment section.
    -->
    <div id="vibe-comments-container" style="display:none;">

        <!-- Sort icon + search — revealed by JS once comments are loaded -->
        <div class="vibe-comments-toolbar" id="vibe-comments-toolbar" style="display:none;">
            <button type="button" id="vibe-sort-toggle" class="vibe-sort-icon-btn"
                    title="<?php esc_attr_e('Newest first', 'vibe-comments'); ?>"
                    data-mode="newest">
                <svg viewBox="0 0 16 16" width="13" height="13" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <line x1="2" y1="4"  x2="14" y2="4"/>
                    <line x1="4" y1="8"  x2="12" y2="8"/>
                    <line x1="6" y1="12" x2="10" y2="12"/>
                </svg>
                <span class="vibe-sort-label">&#8595;</span>
            </button>
        </div>

        <ul class="vibe-comment-list" id="vibe-comment-list"></ul>

        <div class="vibe-load-more-wrap" id="vibe-load-more-wrap" style="display:none;">
            <button type="button" class="vibe-btn vibe-btn-load-more" id="vibe-load-more">
                <?php _e('Load More Comments', 'vibe-comments'); ?>
            </button>
        </div>

        <?php if (comments_open()) : ?>
            <div class="vibe-comment-form-wrapper" id="vibe-form-wrapper">

                <?php if (is_user_logged_in()) : ?>
                    <div class="vibe-user-bar">
                        <?php echo get_avatar(wp_get_current_user()->ID, 32); ?>
                        <span><?php echo esc_html(wp_get_current_user()->display_name); ?></span>
                        <a href="<?php echo esc_url(wp_logout_url(get_permalink())); ?>"
                           class="vibe-logout">
                            <?php _e('Log out', 'vibe-comments'); ?>
                        </a>
                    </div>
                <?php else : ?>
                    <?php
                    $vibe_settings      = get_option('vibe_comments_google_settings', array());
                    $google_enabled     = isset($vibe_settings['enable_google_login'])
                        ? !empty($vibe_settings['enable_google_login'])
                        : !empty($vibe_settings['client_id']);
                    $google_configured  = !empty($vibe_settings['client_id']);
                    ?>
                    <div class="vibe-auth-bar">
                        <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>"
                           class="vibe-btn vibe-btn-wp">
                            <?php echo esc_html(sprintf(
                                __('Login to %s', 'vibe-comments'),
                                get_bloginfo('name')
                            )); ?>
                        </a>
                        <?php if ($google_enabled && $google_configured) : ?>
                        <button type="button" class="vibe-btn vibe-btn-google" id="vibe-google-login">
                            <?php _e('Continue with Google', 'vibe-comments'); ?>
                        </button>
                        <?php endif; ?>
                        <span class="vibe-or"><?php _e('or', 'vibe-comments'); ?></span>
                        <button type="button" class="vibe-btn vibe-btn-guest" id="vibe-guest-toggle">
                            <?php _e('Comment as Guest', 'vibe-comments'); ?>
                        </button>
                    </div>
                <?php endif; ?>

                <form id="vibe-comment-form" class="vibe-comment-form" method="post" action="#">
                    <input type="hidden" name="comment_post_ID" value="<?php echo esc_attr(get_the_ID()); ?>" />
                    <input type="hidden" name="comment_parent" id="vibe-comment-parent" value="0" />

                    <?php if (!is_user_logged_in()) : ?>
                        <div class="vibe-guest-fields" id="vibe-guest-fields" style="display:none;">
                            <div class="vibe-field">
                                <label for="vibe-author">
                                    <?php _e('Name', 'vibe-comments'); ?>
                                    <span aria-hidden="true" style="color:#ef4444"> *</span>
                                </label>
                                <input type="text" id="vibe-author" name="author"
                                       required placeholder="<?php esc_attr_e('Your name', 'vibe-comments'); ?>" />
                            </div>
                            <div class="vibe-field">
                                <label for="vibe-email">
                                    <?php _e('Email', 'vibe-comments'); ?>
                                    <span aria-hidden="true" style="color:#ef4444"> *</span>
                                </label>
                                <input type="email" id="vibe-email" name="email"
                                       required placeholder="<?php esc_attr_e('your@email.com', 'vibe-comments'); ?>" />
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="vibe-field">
                        <label for="vibe-comment-content">
                            <?php _e('Your comment', 'vibe-comments'); ?>
                        </label>
                        <textarea id="vibe-comment-content" name="comment" rows="4" required
                                  aria-describedby="vibe-char-counter"
                                  placeholder="<?php esc_attr_e('What do you think?', 'vibe-comments'); ?>"></textarea>
                        <div class="vibe-char-counter" id="vibe-char-counter" aria-live="polite">
                            <span id="vibe-char-count">0</span> / <span id="vibe-char-max">2000</span>
                        </div>
                    </div>

                    <?php /* Honeypot — off-screen, bots fill it, humans never see it */ ?>
                    <input type="text" name="vibe_hp" value="" class="vibe-hp-field"
                           aria-hidden="true" tabindex="-1" autocomplete="off" />

                    <?php if ( Vibe_Comments_Reply_Push::is_available() ) : ?>
                    <div class="vibe-reply-push-optin" id="vibe-reply-push-optin">
                        <label for="vibe-reply-push-checkbox" class="vibe-reply-push-label">
                            <input type="checkbox" id="vibe-reply-push-checkbox" name="vibe_reply_push_optin" value="1" />
                            <span><?php esc_html_e( 'Notify me about replies', 'vibe-comments' ); ?></span>
                        </label>
                        <p class="vibe-reply-push-note" id="vibe-reply-push-note" hidden>
                            <?php esc_html_e( 'You will get a push notification on this device when someone replies to your comment.', 'vibe-comments' ); ?>
                        </p>
                    </div>
                    <?php endif; ?>

                    <?php /* v3.9.0 — email opt-in: rides wp_mail(), works on any
                           server (server mail or the themes' SMTP rail). Consent
                           flag on the comment; address = the comment's own email. */ ?>
                    <div class="vibe-reply-push-optin vibe-reply-email-optin" id="vibe-reply-email-optin">
                        <label for="vibe-reply-email-checkbox" class="vibe-reply-push-label">
                            <input type="checkbox" id="vibe-reply-email-checkbox" name="vibe_reply_email_optin" value="1" />
                            <span><?php esc_html_e( 'Email me about replies', 'vibe-comments' ); ?></span>
                        </label>
                        <p class="vibe-reply-push-note" id="vibe-reply-email-note" hidden>
                            <?php esc_html_e( 'You will get an email at the address above when someone replies to your comment.', 'vibe-comments' ); ?>
                        </p>
                    </div>

                    <div class="vibe-form-actions">
                        <button type="submit" class="vibe-btn vibe-btn-primary" id="vibe-submit-btn">
                            <?php _e('Post Comment', 'vibe-comments'); ?>
                        </button>
                        <button type="button" class="vibe-btn vibe-btn-cancel"
                                id="vibe-cancel-reply" style="display:none;">
                            <?php _e('Cancel Reply', 'vibe-comments'); ?>
                        </button>
                    </div>
                </form>

            </div><!-- .vibe-comment-form-wrapper -->
        <?php else : ?>
            <p class="vibe-closed"><?php _e('Comments are closed.', 'vibe-comments'); ?></p>
        <?php endif; ?>

    </div><!-- #vibe-comments-container -->

</section><!-- #vibe-comments -->
