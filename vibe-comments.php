1|<?php
2|/**
3| * Plugin Name:       Vibe Comments
4| * Plugin URI:        https://gwillchijioke.com
5| * Description:       A performance-focused custom comment plugin with reactions, threaded replies, Gravatar, Google & WordPress authentication. Built with zero external dependencies and no DB bloat.
6|7| * Version:           3.5.8
8|9| * Version:           3.5.7
10|11| * Author:            G-will Chijioke
12| * Author URI:        https://gwillchijioke.com
13| * License:           GPL v2 or later
14| * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
15| * Text Domain:       vibe-comments
16| * Requires at least: 6.0
17| * Requires PHP:      7.4
18| */
19|
20|if (!defined('ABSPATH')) {
21|    exit;
22|}
23|
24|define('VIBE_COMMENTS_VERSION', '3.5.7');
25|define('VIBE_COMMENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
26|define('VIBE_COMMENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
27|
28|// Include debug logger FIRST
29|require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/debug-logger.php';
30|
31|if ( defined( 'VIBE_COMMENTS_DEBUG_TOOLS' ) && VIBE_COMMENTS_DEBUG_TOOLS ) {
32|    vibe_log( 'Main plugin file loaded' );
33|}
34|
35|// Activation
36|require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-activator.php';
37|register_activation_hook(__FILE__, array('Vibe_Comments_Activator', 'activate'));
38|
39|// Deactivation
40|require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-deactivator.php';
41|register_deactivation_hook(__FILE__, array('Vibe_Comments_Deactivator', 'deactivate'));
42|
43|if ( defined( 'VIBE_COMMENTS_DEBUG_TOOLS' ) && VIBE_COMMENTS_DEBUG_TOOLS ) {
44|    vibe_log( 'Loading core classes...' );
45|}
46|
47|require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-database.php';
48|
49|require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-rest-api.php';
50|
51|require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-oauth-google.php';
52|
53|require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-template-loader.php';
54|
55|require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-ajax-handler.php';
56|
57|require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-admin.php';
58|require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-schema.php';
59|require_once VIBE_COMMENTS_PLUGIN_DIR . 'includes/class-cli.php';
60|
61|class Vibe_Comments {
62|    public function __construct() {
63|        if (defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS) {
64|            vibe_log('Vibe_Comments constructor called');
65|        }
66|        add_action('init', array($this, 'load_textdomain'));
67|        add_action('init', array($this, 'init'));
68|        add_action('rest_api_init', array($this, 'add_cache_headers'));
69|    }
70|
71|    /**
72|     * Load translation files from /languages. Without this call, __()/_e()
73|     * always fall back to the English source string even if .mo files exist —
74|     * the "Text Domain" plugin header alone does not wire up translations.
75|     */
76|    public function load_textdomain() {
77|        load_plugin_textdomain(
78|            'vibe-comments',
79|            false,
80|            dirname( plugin_basename( __FILE__ ) ) . '/languages'
81|        );
82|    }
83|
84|    public function init() {
85|        if (defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS) {
86|            vibe_log('init hook fired');
87|        }
88|
89|        $debug = defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS;
90|
91|        // These two were the ONLY subsystem-touching calls in this method not
92|        // wrapped in try/catch(Throwable) — every other instantiation below
93|        // already was. If either of these throws at runtime for any reason
94|        // (a $wpdb issue, something host-specific), it previously took down
95|        // the entire site with an uncaught fatal instead of degrading
96|        // gracefully. Now matches the same pattern as everything else: log
97|        // via error_log() unconditionally (so it's visible even without
98|        // WP_DEBUG_LOG enabled — see debug-logger.php's own reasoning for
99|        // why error_log() specifically, not just vibe_log()), and let the
100|        // rest of the site keep functioning.
101|        try {
102|            Vibe_Comments_Activator::maybe_upgrade();
103|            if ($debug) { vibe_log('maybe_upgrade completed'); }
104|        } catch (Throwable $e) {
105|            error_log('[Vibe Comments] FATAL in maybe_upgrade(): ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
106|            if ($debug) { vibe_log('maybe_upgrade ERROR: ' . $e->getMessage()); }
107|        }
108|
109|        try {
110|            // JSON-LD structured data for comments (SEO).
111|            Vibe_Comments_Schema::init();
112|            if ($debug) { vibe_log('Schema instantiated'); }
113|        } catch (Throwable $e) {
114|            error_log('[Vibe Comments] FATAL in Schema::init(): ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
115|            if ($debug) { vibe_log('Schema ERROR: ' . $e->getMessage()); }
116|        }
117|
118|        try {
119|            new Vibe_Comments_REST_API();
120|            if ($debug) { vibe_log('REST API instantiated'); }
121|        } catch (Throwable $e) {
122|            // Always hit PHP's native log regardless of the debug flag — a fatal
123|            // subsystem failure must never go completely unrecorded.
124|            error_log('[Vibe Comments] REST API failed to load: ' . $e->getMessage());
125|            if ($debug) { vibe_log('REST API ERROR: ' . $e->getMessage()); }
126|        }
127|
128|        // Only register OAuth hooks if Google login is enabled in settings.
129|        // Default: true if client credentials already exist (preserves existing installs).
130|        $vibe_google_settings = get_option('vibe_comments_google_settings', array());
131|        $vibe_google_enabled  = isset($vibe_google_settings['enable_google_login'])
132|            ? !empty($vibe_google_settings['enable_google_login'])
133|            : !empty($vibe_google_settings['client_id']);
134|
135|        if ($vibe_google_enabled) {
136|            try {
137|                new Vibe_Comments_OAuth_Google();
138|                if ($debug) { vibe_log('OAuth instantiated'); }
139|            } catch (Throwable $e) {
140|                error_log('[Vibe Comments] OAuth failed to load: ' . $e->getMessage());
141|                if ($debug) { vibe_log('OAuth ERROR: ' . $e->getMessage()); }
142|            }
143|        }
144|
145|        try {
146|            new Vibe_Comments_Template_Loader();
147|            if ($debug) { vibe_log('Template loader instantiated'); }
148|        } catch (Throwable $e) {
149|            error_log('[Vibe Comments] Template loader failed to load: ' . $e->getMessage());
150|            if ($debug) { vibe_log('Template loader ERROR: ' . $e->getMessage()); }
151|        }
152|
153|        try {
154|            new Vibe_Comments_Ajax_Handler();
155|            if ($debug) { vibe_log('AJAX handler instantiated'); }
156|        } catch (Throwable $e) {
157|            error_log('[Vibe Comments] AJAX handler failed to load: ' . $e->getMessage());
158|            if ($debug) { vibe_log('AJAX handler ERROR: ' . $e->getMessage()); }
159|        }
160|
161|        try {
162|            new Vibe_Comments_Admin();
163|            if ($debug) { vibe_log('Admin instantiated'); }
164|        } catch (Throwable $e) {
165|            error_log('[Vibe Comments] Admin failed to load: ' . $e->getMessage());
166|            if ($debug) { vibe_log('Admin ERROR: ' . $e->getMessage()); }
167|        }
168|
169|        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
170|        if ($debug) { vibe_log('Assets enqueue registered'); }
171|    }
172|
173|    public function add_cache_headers() {
174|        if (!defined('REST_REQUEST') || !REST_REQUEST) {
175|            return;
176|        }
177|
178|        $route = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
179|        if (strpos($route, 'vibe-comments') !== false) {
180|            header('Cache-Control: no-cache, no-store, must-revalidate');
181|            header('Pragma: no-cache');
182|            header('Expires: 0');
183|        }
184|    }
185|
186|    public function enqueue_assets() {
187|        // Matches class-template-loader.php's load_template() condition exactly
188|        // — that function was fixed in v3.5.0 to route to this plugin's
189|        // template whenever a post has existing comments, even if new
190|        // commenting is closed, so JSON-LD schema output isn't describing a
191|        // discussion the plugin's own UI can't display. This condition was
192|        // the other half of that same fix and was never updated to match —
193|        // the template rendered correctly, but its CSS/JS never loaded,
194|        // leaving visitors an unstyled heading and a "Load Comments" button
195|        // that did nothing when clicked.
196|        if (is_singular() && (comments_open() || (int) get_comments_number() > 0)) {
197|            wp_enqueue_style(
198|                'vibe-comments',
199|                VIBE_COMMENTS_PLUGIN_URL . 'public/css/vibe-comments.css',
200|                array(),
201|                VIBE_COMMENTS_VERSION
202|            );
203|
204|            wp_enqueue_script(
205|                'vibe-comments',
206|                VIBE_COMMENTS_PLUGIN_URL . 'public/js/vibe-comments.js',
207|                array(),
208|                VIBE_COMMENTS_VERSION,
209|                true
210|            );
211|
212|            $vibe_gs      = get_option('vibe_comments_google_settings', array());
213|            $google_on    = isset($vibe_gs['enable_google_login'])
214|                ? !empty($vibe_gs['enable_google_login'])
215|                : !empty($vibe_gs['client_id']);
216|
217|            wp_localize_script('vibe-comments', 'vibeComments', array(
218|                'nonce'            => wp_create_nonce('wp_rest'),
219|                'ajaxUrl'          => admin_url('admin-ajax.php'),
220|                'postId'           => get_the_ID(),
221|                'isLoggedIn'       => is_user_logged_in(),
222|                'isAdmin'          => current_user_can('moderate_comments'),
223|                'googleEnabled'    => $google_on,
224|                'maxCommentLength' => (int) apply_filters('vibe_comments_max_length', 2000),
225|                // Mirrors templates/comments.php's exact 3-way branch (0/1/many) so the
226|                // client-side heading refresh produces byte-identical grammar to the
227|                // server-rendered version — same simplification (2 plural forms, not a
228|                // full _n_noop() set), kept consistent rather than "more correct" in JS
229|                // only, which would make the two diverge for non-English locales.
230|                'oneCommentText'      => esc_html__('1 Comment', 'vibe-comments'),
231|                /* translators: %s = formatted number, e.g. "12 Comments" */
232|                'manyCommentsTemplate' => esc_html__('%s Comments', 'vibe-comments'),
233|                // Lets JS-side silent .catch() blocks (e.g. fetchCommentCount())
234|                // optionally console.warn for a developer who deliberately
235|                // turned on VIBE_COMMENTS_DEBUG_TOOLS, without ever logging
236|                // anything to a real visitor's browser console by default.
237|                'debug'                 => defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS,
238|            ));
239|        }
240|    }
241|}
242|
243|try {
244|    new Vibe_Comments();
245|    if (defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS) {
246|        vibe_log('Main class instantiated successfully');
247|    }
248|} catch (Throwable $e) {
249|    // This is the single point of total plugin failure — always record it
250|    // regardless of the debug flag. Without this, the entire plugin can die
251|    // silently with zero trace anywhere on the server.
252|    error_log('[Vibe Comments] FATAL — plugin failed to initialize: ' . $e->getMessage());
253|    if (defined('VIBE_COMMENTS_DEBUG_TOOLS') && VIBE_COMMENTS_DEBUG_TOOLS) {
254|        vibe_log('Main class ERROR: ' . $e->getMessage());
255|    }
256|}
257|
258|