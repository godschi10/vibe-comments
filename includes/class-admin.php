<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
class Vibe_Comments_Admin {
    private $option_name = 'vibe_comments_google_settings';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
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
     * Without this, any string — including HTML and JS — can be stored.
     */
    public function sanitize_settings( $input ) {
        $clean = array();

        // Unlike text fields, an HTML checkbox submits NOTHING when unchecked —
        // it never appears in $_POST at all. Guarding this with isset() (as the
        // other fields correctly do) meant unchecking "Enable Google Login" and
        // saving silently dropped the key from the stored option entirely, rather
        // than storing false. The consumption logic in vibe-comments.php then
        // fell through to "enabled if client_id is set" — re-enabling Google
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
                $clean['client_secret'] = sanitize_text_field( $input['client_secret'] );
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
            // Never pre-fill the secret — password inputs should not be populated
            // from storage. If the admin wants to change it, they type a new value;
            // if they leave it blank, sanitize_settings() preserves the existing one.
            printf(
                '<input type="password" name="%s[%s]" value="" class="regular-text" autocomplete="new-password" placeholder="%s">',
                esc_attr( $this->option_name ),
                esc_attr( $field ),
                esc_attr( isset( $settings[ $field ] ) && $settings[ $field ] ? '(saved — leave blank to keep)' : 'Paste secret here' )
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
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
