<?php
class Vibe_Comments_Admin {
    private $option_name = 'vibe_comments_google_settings';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function add_menu_page() {
        add_options_page(
            'Vibe Comments',
            'Vibe Comments',
            'manage_options',
            'vibe-comments',
            array($this, 'render_page')
        );
    }

    public function register_settings() {
        register_setting('vibe_comments', $this->option_name);

        add_settings_section(
            'vibe_google_section',
            'Google OAuth Settings',
            array($this, 'render_section'),
            'vibe-comments'
        );

        add_settings_field(
            'enable_google_login',
            'Enable Google Login',
            array($this, 'render_field'),
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
            array($this, 'render_field'),
            'vibe-comments',
            'vibe_google_section',
            array('field' => 'client_id', 'type' => 'text')
        );

        add_settings_field(
            'client_secret',
            'Google Client Secret',
            array($this, 'render_field'),
            'vibe-comments',
            'vibe_google_section',
            array('field' => 'client_secret', 'type' => 'text')
        );
    }

    public function render_section() {
        echo '<p>Enter your Google OAuth credentials. Create them at <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a>.</p>';
        echo '<p><strong>Authorized redirect URI:</strong> <code>' . esc_url(rest_url('vibe-comments/v1/google-callback')) . '</code></p>';
    }

    public function render_field($args) {
        $settings = get_option($this->option_name, array());
        $field    = $args['field'];
        $type     = isset($args['type']) ? $args['type'] : 'text';

        if ($type === 'checkbox') {
            // Smart default: enabled if credentials already exist (upgrade path),
            // disabled on fresh installs where no client_id has been set yet.
            $default = !empty($settings['client_id']);
            $value   = isset($settings[$field]) ? (bool) $settings[$field] : $default;
            printf(
                '<label><input type="checkbox" name="%s[%s]" value="1"%s> %s</label>',
                esc_attr($this->option_name),
                esc_attr($field),
                checked($value, true, false),
                esc_html(isset($args['description']) ? $args['description'] : '')
            );
            return;
        }

        $value = isset($settings[$field]) ? $settings[$field] : '';
        printf(
            '<input type="text" name="%s[%s]" value="%s" class="regular-text">',
            esc_attr($this->option_name),
            esc_attr($field),
            esc_attr($value)
        );
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <h1>Vibe Comments Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('vibe_comments');
                do_settings_sections('vibe-comments');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
