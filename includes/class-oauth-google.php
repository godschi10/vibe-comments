<?php
/**
 * Google OAuth 2.0 handler for Vibe Comments.
 *
 * Security hardening over the original implementation:
 *  - Full RS256 JWT signature verification against Google's JWKS
 *  - email_verified === true enforced before trusting the email claim
 *  - JWKS cached for 1 hour to avoid repeated remote fetches
 *  - wp_generate_password(8, false) instead of uniqid() for username suffix
 */
class Vibe_Comments_OAuth_Google {
    private $option_name = 'vibe_comments_google_settings';

    public function __construct() {
        add_action('init',         array($this, 'maybe_handle_oauth'));
        add_action('rest_api_init', array($this, 'register_callback_route'));
        add_action('wp_ajax_vibe_google_auth',        array($this, 'ajax_google_auth'));
        add_action('wp_ajax_nopriv_vibe_google_auth', array($this, 'ajax_google_auth'));
    }

    public function register_callback_route() {
        register_rest_route('vibe-comments/v1', '/google-callback', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'maybe_handle_oauth_rest'),
            'permission_callback' => '__return_true',
        ));
    }

    public function maybe_handle_oauth_rest($request) {
        $_GET['vibe-google-callback'] = '1';
        $_GET['code']                 = $request->get_param('code')  ?? '';
        $_GET['state']                = $request->get_param('state') ?? '';
        $this->maybe_handle_oauth();
        return new WP_REST_Response(array('error' => 'OAuth callback failed.'), 400);
    }

    public function ajax_google_auth() {
        check_ajax_referer('wp_rest', 'nonce');

        $settings  = get_option($this->option_name, array());
        $client_id = isset($settings['client_id']) ? $settings['client_id'] : '';

        if (empty($client_id)) {
            wp_send_json_error('Google Client ID not configured.');
            return;
        }

        $return_url = '';
        if (!empty($_POST['return_url'])) {
            $candidate  = esc_url_raw(wp_unslash($_POST['return_url']));
            $return_url = wp_validate_redirect($candidate, '') ?: '';
        }
        if (empty($return_url)) {
            $return_url = wp_get_referer() ?: home_url();
        }

        $state = wp_create_nonce('vibe_google_oauth_state');
        set_transient('vibe_google_state_' . md5($state), $return_url, 600);

        $redirect_uri = rest_url('vibe-comments/v1/google-callback');

        $auth_url = add_query_arg(array(
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ), 'https://accounts.google.com/o/oauth2/v2/auth');

        wp_send_json_success(array('auth_url' => $auth_url));
    }

    public function maybe_handle_oauth() {
        if (!isset($_GET['vibe-google-callback'])) return;

        $code  = sanitize_text_field($_GET['code']  ?? '');
        $state = sanitize_text_field($_GET['state'] ?? '');

        if (empty($state) || !wp_verify_nonce($state, 'vibe_google_oauth_state')) {
            wp_die('Invalid or expired state parameter. Please try again.');
        }

        $return_url = get_transient('vibe_google_state_' . md5($state)) ?: home_url();
        delete_transient('vibe_google_state_' . md5($state));

        $settings      = get_option($this->option_name, array());
        $client_id     = $settings['client_id']     ?? '';
        $client_secret = $settings['client_secret'] ?? '';

        if (empty($client_id) || empty($client_secret) || empty($code)) {
            wp_die('Missing OAuth configuration.');
        }

        // Exchange authorisation code for tokens.
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => rest_url('vibe-comments/v1/google-callback'),
                'grant_type'    => 'authorization_code',
            ),
        ));

        if (is_wp_error($response)) {
            wp_die('Token exchange failed: ' . esc_html($response->get_error_message()));
        }

        $token_data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($token_data['id_token'])) {
            $error = $token_data['error'] ?? 'Unknown error';
            wp_die('Failed to retrieve ID token: ' . esc_html($error));
        }

        // ── JWT verification ────────────────────────────────────────────────
        // Verifies the RS256 signature against Google's public JWKS.
        // Without this, any attacker who knows your client_id (it's public)
        // can forge a JWT and log in as any user, including admins.
        $payload = $this->verify_jwt($token_data['id_token'], $client_id);

        if (null === $payload) {
            wp_die('Token signature verification failed. Please try again.');
        }

        // Enforce email_verified — Google can return unverified emails
        // (e.g. from federated identity providers). Never trust unverified emails.
        if (empty($payload['email_verified']) || $payload['email_verified'] !== true) {
            wp_die('Your Google email address is not verified. Please verify your Google account first.');
        }

        if (empty($payload['email'])) {
            wp_die('Invalid token payload: email claim missing.');
        }

        $email = sanitize_email($payload['email']);
        $name  = sanitize_text_field($payload['name'] ?? explode('@', $email)[0]);

        $user = get_user_by('email', $email);

        if (!$user) {
            $email_parts = explode('@', $email);
            $base        = sanitize_user($email_parts[0]);
            // wp_generate_password(8, false) — cryptographically random alphanumeric.
            // Replaces the original uniqid() which had a limited collision window.
            $suffix      = wp_generate_password(8, false);
            $username    = sanitize_user($base . '_' . $suffix);

            $user_id = wp_insert_user(array(
                'user_login'   => $username,
                'user_email'   => $email,
                'display_name' => $name,
                'user_pass'    => wp_generate_password(),
                'role'         => 'subscriber',
            ));

            if (is_wp_error($user_id)) {
                wp_die('User creation failed: ' . esc_html($user_id->get_error_message()));
            }

            $user = get_user_by('id', $user_id);
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        wp_safe_redirect($return_url);
        exit;
    }

    // ── JWT verification ─────────────────────────────────────────────────────

    /**
     * Verify a Google ID token's RS256 signature against Google's JWKS.
     *
     * Returns the decoded payload array on success, null on any failure.
     * Any failure (network, bad signature, expired, wrong audience) returns null —
     * the caller must treat null as an authentication failure.
     *
     * @param  string $jwt       The raw id_token string from Google's token endpoint.
     * @param  string $client_id Your Google OAuth client ID.
     * @return array|null        Verified payload or null.
     */
    private function verify_jwt( $jwt, $client_id ) {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;

        // Decode header to find the key ID (kid) used to sign this token.
        $header = json_decode(
            base64_decode(strtr($parts[0], '-_', '+/')), true
        );
        if (empty($header['kid']) || ($header['alg'] ?? '') !== 'RS256') return null;

        // Decode payload — not trusted until signature is verified.
        $payload = json_decode(
            base64_decode(strtr($parts[1], '-_', '+/')), true
        );
        if (!is_array($payload)) return null;

        // aud must match this app's client_id.
        if (empty($payload['aud']) || $payload['aud'] !== $client_id) return null;

        // Token must not be expired (with 60s clock skew tolerance).
        if (empty($payload['exp']) || time() > ($payload['exp'] + 60)) return null;

        // iss must be Google.
        $valid_issuers = array('https://accounts.google.com', 'accounts.google.com');
        if (empty($payload['iss']) || !in_array($payload['iss'], $valid_issuers, true)) return null;

        // Fetch Google's JWKS — cached for 1 hour.
        $jwks = get_transient('vibe_google_jwks');
        if (false === $jwks) {
            $res = wp_remote_get('https://www.googleapis.com/oauth2/v3/certs', array(
                'timeout' => 5,
            ));
            if (is_wp_error($res)) return null;
            $jwks = json_decode(wp_remote_retrieve_body($res), true);
            if (empty($jwks['keys'])) return null;
            set_transient('vibe_google_jwks', $jwks, HOUR_IN_SECONDS);
        }

        // Find the key matching the token's kid.
        $matching_key = null;
        foreach ($jwks['keys'] ?? array() as $key) {
            if (($key['kid'] ?? '') === $header['kid']) {
                $matching_key = $key;
                break;
            }
        }
        if (null === $matching_key) {
            // kid not found — JWKS may be stale. Bust cache and try once more.
            delete_transient('vibe_google_jwks');
            return null;
        }

        // Convert the JWK to a PEM public key for openssl_verify().
        $pem = $this->jwk_to_pem($matching_key);
        if (null === $pem) return null;

        // Verify RS256 signature.
        $signing_input = $parts[0] . '.' . $parts[1];
        $signature     = base64_decode(strtr($parts[2], '-_', '+/'));
        $result        = openssl_verify($signing_input, $signature, $pem, OPENSSL_ALGO_SHA256);

        return ($result === 1) ? $payload : null;
    }

    /**
     * Convert a Google JWK (RSA public key) to a PEM string.
     *
     * Google's JWKS returns RSA keys as { kty, n, e, kid, ... }.
     * openssl_verify() needs a PEM-encoded public key.
     *
     * @param  array       $jwk  A single key object from Google's JWKS response.
     * @return string|null       PEM public key or null on failure.
     */
    private function jwk_to_pem( array $jwk ) {
        if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }

        // Decode the base64url-encoded modulus (n) and exponent (e).
        $modulus  = base64_decode(strtr($jwk['n'], '-_', '+/'));
        $exponent = base64_decode(strtr($jwk['e'], '-_', '+/'));

        if (!$modulus || !$exponent) return null;

        // Encode as ASN.1 DER — the binary format openssl expects.
        // RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
        $mod_len = strlen($modulus);
        $exp_len = strlen($exponent);

        // Prepend 0x00 if high bit is set (prevents misinterpretation as negative).
        if (ord($modulus[0]) & 0x80) { $modulus  = "\x00" . $modulus;  $mod_len++; }
        if (ord($exponent[0]) & 0x80){ $exponent = "\x00" . $exponent; $exp_len++; }

        $modulus_der  = "\x02" . $this->der_length($mod_len) . $modulus;
        $exponent_der = "\x02" . $this->der_length($exp_len) . $exponent;

        $rsa_key_der  = "\x30" . $this->der_length(strlen($modulus_der) + strlen($exponent_der))
                      . $modulus_der . $exponent_der;

        // Wrap in SubjectPublicKeyInfo structure with RSA OID.
        $rsa_oid      = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $spki_der     = "\x30" . $this->der_length(strlen($rsa_oid) + 1 + $this->der_length_size(strlen($rsa_key_der)) + strlen($rsa_key_der))
                      . $rsa_oid
                      . "\x03" . $this->der_length(1 + strlen($rsa_key_der)) . "\x00"
                      . $rsa_key_der;

        return "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($spki_der), 64, "\n")
             . "-----END PUBLIC KEY-----\n";
    }

    /** Encode a DER length field. */
    private function der_length( $len ) {
        if ($len < 128) return chr($len);
        $bytes = '';
        $tmp = $len;
        while ($tmp > 0) { $bytes = chr($tmp & 0xff) . $bytes; $tmp >>= 8; }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /** Return the byte size of a DER-encoded length field. */
    private function der_length_size( $len ) {
        if ($len < 128) return 1;
        $bytes = 0;
        while ($len > 0) { $bytes++; $len >>= 8; }
        return 1 + $bytes;
    }
}
