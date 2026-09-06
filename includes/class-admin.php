<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
class Vibe_Comments_Admin {
    private $option_name = 'vibe_comments_google_settings';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        // v3.14.0 - Spam-score column in the WP admin comments list
        // (Feature #6: heuristic scorer, display-only).
        add_filter( 'manage_edit-comments_columns',        array( $this, 'spam_column_header' ) );
        add_filter( 'manage_edit-comments_sortable_columns', array( $this, 'spam_column_sortable' ) );
        add_action( 'manage_comments_custom_column',      array( $this, 'spam_column_render' ), 10, 2 );
        // (spam-column CSS now loads via enqueue_admin_assets - v3.17.4)
        // Bulk-level convenience on pending: sort queue by score via
        // pre_get_comments is NOT added - WP has no score field to sort on;
        // the column itself carries the info (sortable = false, honest).
    }

    /**
     * Admin assets, hook-suffix-gated so each screen loads only what it
     * uses. One consolidated stylesheet and one script file serve all
     * three admin surfaces (replaces the old inline <style>/<script>
     * blocks - cleanup-audit finding N1, resolved 2026-09-01).
     */
    public function enqueue_admin_assets( $hook ) {
        $base = VIBE_COMMENTS_PLUGIN_URL . 'public/';

        // Spam-score column: comments list + moderation queue.
        if ( 'edit-comments.php' === $hook ) {
            wp_enqueue_style( 'vibe-admin', $base . 'css/vibe-admin.css', array(), VIBE_COMMENTS_VERSION );
            return;
        }

        // Analytics dashboard + the settings screens (both the options page
        // registered here and the analytics-registered Settings submenu
        // share the 'vibe-comments' slug; toplevel covers the dashboard).
        if ( false !== strpos( $hook, 'vibe-analytics' ) || false !== strpos( $hook, 'vibe-comments' ) ) {
            wp_enqueue_style( 'vibe-admin', $base . 'css/vibe-admin.css', array(), VIBE_COMMENTS_VERSION );
            wp_enqueue_script( 'vibe-admin', $base . 'js/vibe-admin.js', array(), VIBE_COMMENTS_VERSION, true );
            wp_localize_script( 'vibe-admin', 'vibeAdmin', array(
                'i18n' => array(
                    'building'      => __( 'Building…', 'vibe-comments' ),
                    'previewFailed' => __( 'Preview failed.', 'vibe-comments' ),
                ),
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
            ) );
        }
    }

    public function add_menu_page() {
        add_options_page(
            'Vibe Comments',
            'Vibe Comments',
            'manage_options',
            'vibe-comments',
            array( $this, 'render_page' )
        );
    }

    public function register_settings() {
        // L6 fix: add sanitize_callback so the option is never saved raw.
        register_setting( 'vibe_comments', $this->option_name, array(
            'sanitize_callback' => array( $this, 'sanitize_settings' ),
        ) );

        add_settings_section(
            'vibe_google_section',
            'Google OAuth Settings',
            array( $this, 'render_section' ),
            'vibe-comments'
        );

        add_settings_field(
            'enable_google_login',
            'Enable Google Login',
            array( $this, 'render_field' ),
            'vibe-comments',
            'vibe_google_section',
            array(
                'field'       => 'enable_google_login',
                'type'        => 'checkbox',
                'description' => 'Show "Continue with Google" button on the comment form.',
            )
        );

        add_settings_field(
            'client_id',
            'Google Client ID',
            array( $this, 'render_field' ),
            'vibe-comments',
            'vibe_google_section',
            array( 'field' => 'client_id', 'type' => 'text' )
        );

        add_settings_field(
            'client_secret',
            'Google Client Secret',
            array( $this, 'render_field' ),
            'vibe-comments',
            'vibe_google_section',
            // L6 fix: password input so the secret is not visible in the browser
            // DOM, not stored in browser autofill, and not captured by screen recorders.
            array( 'field' => 'client_secret', 'type' => 'password' )
        );
    }

    /**
     * Sanitize each field before it is saved to wp_options.
     * Without this, any string - including HTML and JS - can be stored.
     */
    public function sanitize_settings( $input ) {
        $clean = array();

        // Unlike text fields, an HTML checkbox submits NOTHING when unchecked -
        // it never appears in $_POST at all. Guarding this with isset() (as the
        // other fields correctly do) meant unchecking "Enable Google Login" and
        // saving silently dropped the key from the stored option entirely, rather
        // than storing false. The consumption logic in vibe-comments.php then
        // fell through to "enabled if client_id is set" - re-enabling Google
        // login the admin had just explicitly turned off. Confirmed this form has
        // exactly one <form> covering all three fields together (render_page()
        // above), so absence here unambiguously means "checkbox was unchecked,"
        // not "this field isn't part of this submission."
        $clean['enable_google_login'] = ! empty( $input['enable_google_login'] );
        if ( isset( $input['client_id'] ) ) {
            // Client IDs are alphanumeric + hyphens + dots. Strip anything else.
            $clean['client_id'] = sanitize_text_field( $input['client_id'] );
        }
        if ( isset( $input['client_secret'] ) ) {
            // Preserve an existing secret if the field was submitted empty
            // (password inputs are never pre-filled for security).
            $existing = get_option( $this->option_name, array() );
            if ( '' === trim( $input['client_secret'] ) ) {
                $clean['client_secret'] = $existing['client_secret'] ?? '';
            } else {
                // v3.18.2: sealed at rest (sodium secretbox, AUTH_KEY-derived
                // key). The value in wp_options never carries the plaintext.
                $clean['client_secret'] = Vibe_Comments_Secret::seal(
                    sanitize_text_field( $input['client_secret'] )
                );
            }
        }

        return $clean;
    }

    public function render_section() {
        echo '<p>Enter your Google OAuth credentials. Create them at <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a>.</p>';
        echo '<p><strong>Authorized redirect URI:</strong> <code>' . esc_url( rest_url( 'vibe-comments/v1/google-callback' ) ) . '</code></p>';
    }

    public function render_field( $args ) {
        $settings = get_option( $this->option_name, array() );
        $field    = $args['field'];
        $type     = isset( $args['type'] ) ? $args['type'] : 'text';

        if ( $type === 'checkbox' ) {
            $default = ! empty( $settings['client_id'] );
            $value   = isset( $settings[ $field ] ) ? (bool) $settings[ $field ] : $default;
            printf(
                '<label><input type="checkbox" name="%s[%s]" value="1"%s> %s</label>',
                esc_attr( $this->option_name ),
                esc_attr( $field ),
                checked( $value, true, false ),
                esc_html( $args['description'] ?? '' )
            );
            return;
        }

        if ( $type === 'password' ) {
            // Never pre-fill the secret - password inputs should not be populated
            // from storage. If the admin wants to change it, they type a new value;
            // if they leave it blank, sanitize_settings() preserves the existing one.
            // Neutral placeholder regardless of stored state - a "(saved)"
            // hint would tell any options-reader the exact storage shape
            // (cleanup-audit N2, tightened 2026-09-01: the sanitize path's
            // keep-on-empty behavior already preserves the stored secret).
            printf(
                '<input type="password" name="%s[%s]" value="" class="regular-text" autocomplete="new-password" placeholder="Client secret - leave blank to keep the saved one">',
                esc_attr( $this->option_name ),
                esc_attr( $field )
            );
            return;
        }

        $value = isset( $settings[ $field ] ) ? $settings[ $field ] : '';
        printf(
            '<input type="text" name="%s[%s]" value="%s" class="regular-text">',
            esc_attr( $this->option_name ),
            esc_attr( $field ),
            esc_attr( $value )
        );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1>Vibe Comments Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'vibe_comments' );
                do_settings_sections( 'vibe-comments' );

                // v3.17.0 - digest preview: renders the exact email HTML.
                // The SMTP-free window: works regardless of mail transport.
                ?>
                <hr />
                <h3>Digest Preview</h3>
                <p class="description">Renders the exact digest email for yesterday - the same build path the morning cron uses. Sends nothing.</p>
                <p>
                    <button type="button" class="button button-secondary" id="vibe-digest-preview-btn"
                            data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
                        Preview today's digest
                    </button>
                    <span id="vibe-digest-preview-status" style="margin-left:8px;"></span>
                </p>
                <div id="vibe-digest-preview-wrap" style="display:none;margin-top:12px;">
                    <div style="margin-bottom:6px;"><strong id="vibe-digest-preview-subject"></strong></div>
                    <iframe id="vibe-digest-preview-frame"></iframe>
                </div>
                <?php
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /* ══════════════════════════════════════════════════════════════════════
     * v3.14.0 - Spam-score column (Feature #6)
     * Display-only heuristic badge on every comment row in wp-admin.
     * ══════════════════════════════════════════════════════════════════════ */

    /**
     * Add the "Spam" column header after "In Response To".
     */
    public function spam_column_header( $columns ) {
        $new = array();
        foreach ( $columns as $k => $v ) {
            $new[ $k ] = $v;
            if ( 'in_response_to' === $k ) {
                $new['vibe_spam'] = __( 'Spam', 'vibe-comments' );
            }
        }
        // Edge: some WP versions use 'response_to'; if the anchor never
        // matched, append at the end so the column always exists.
        if ( ! isset( $new['vibe_spam'] ) ) {
            $new['vibe_spam'] = __( 'Spam', 'vibe-comments' );
        }
        return $new;
    }

    /**
     * Not sortable - WP has no persisted score field to ORDER BY; a fake
     * sort would lie. The badge alone carries the information.
     */
    public function spam_column_sortable( $columns ) {
        return $columns; // deliberately unchanged
    }

    /**
     * Render the badge for one comment row.
     */
    public function spam_column_render( $column, $comment_id ) {
        if ( 'vibe_spam' !== $column ) {
            return;
        }
        $comment = get_comment( $comment_id );
        if ( ! $comment ) {
            echo '-';
            return;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput -- badge_html() escapes internally.
        echo Vibe_Comments_Spam_Score::badge_html( $comment );
    }

    /**
     * Column styles: fixed narrow width + the three band colors, matching
     * WP admin badge aesthetics (subtle tints, not loud blocks).
     */
}
