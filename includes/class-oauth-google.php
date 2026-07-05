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
        // The REST route is the only callback Google ever hits.
        // The init hook (which also processed OAuth callbacks) has been removed —
        // it exposed the same processing logic on any front-end URL via
        // ?vibe-google-callback=1, creating unnecessary attack surface.
        add_action('rest_api_init', array($this, 'register_callback_route'));
        add_action('wp_ajax_vibe_google_auth',        array($this, 'ajax_google_auth'));
        add_action('wp_ajax_nopriv_vibe_google_auth', array($this, 'ajax_google_auth'));
    }

    public function register_callback_route() {
        register_rest_route( 'vibe-comments/v1', '/google-callback', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_oauth_rest_callback' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * REST callback — the only entry point for Google's OAuth redirect.
     * Validates state against both the transient AND the browser cookie
     * to prevent login-CSRF on anonymous-user nonces (C2 fix).
     */
    public function handle_oauth_rest_callback( $request ) {
        $code  = sanitize_text_field( $request->get_param('code')  ?? '' );
        $state = sanitize_text_field( $request->get_param('state') ?? '' );
        $this->process_oauth_callback( $code, $state );
        // process_oauth_callback always wp_safe_redirect()+exit or wp_die() — never reaches here.
        return new WP_REST_Response( array( 'error' => 'OAuth callback failed.' ), 400 );
    }

    public function ajax_google_auth() {
        if ( ! check_ajax_referer( 'wp_rest', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'vibe-comments' ) ), 403 );
            return;
        }

        $settings  = get_option( $this->option_name, array() );
        $client_id = isset( $settings['client_id'] ) ? $settings['client_id'] : '';

        if ( empty( $client_id ) ) {
            wp_send_json_error( 'Google Client ID not configured.' );
            return;
        }

        $return_url = '';
        if ( ! empty( $_POST['return_url'] ) ) {
            $candidate  = esc_url_raw( wp_unslash( $_POST['return_url'] ) );
            $return_url = wp_validate_redirect( $candidate, '' ) ?: '';
        }
        if ( empty( $return_url ) ) {
            $return_url = wp_get_referer() ?: home_url();
        }

        // ── C2 fix: crypto-random state, browser-bound via cookie ────────
        // wp_create_nonce() for anonymous users always produces the same value
        // within a ~12-hour tick (uid=0, session_token=''), making all anon
        // visitors share the same state — login-CSRF attack is trivial.
        // Fix: generate a 32-char cryptographically random token, store the
        // return_url in a transient keyed by its hash, and simultaneously
        // set an HttpOnly SameSite=Lax cookie with the same token. The callback
        // validates BOTH the transient AND the cookie, so the token is bound
        // to the initiating browser — not just a server-side time window.
        $state      = wp_generate_password( 32, false, false );
        $state_hash = md5( $state );

        set_transient( 'vibe_oauth_state_' . $state_hash, $return_url, 600 );

        // Derive the cookie path from the actual REST URL so the cookie is
        // sent to the callback route even on subdirectory WordPress installs
        // (e.g. /blog/wp-json/... instead of /wp-json/...).
        $callback_path = wp_parse_url(
            rest_url( 'vibe-comments/v1/google-callback' ),
            PHP_URL_PATH
        );

        // Secure flag only on HTTPS; SameSite=Lax prevents cross-origin use.
        $cookie_options = array(
            'expires'  => time() + 600,
            'path'     => $callback_path,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        );
        // setcookie() with options array requires PHP 7.3+ (our min is 7.4).
        setcookie( 'vibe_oauth_state', $state, $cookie_options );

        $redirect_uri = rest_url( 'vibe-comments/v1/google-callback' );

        $auth_url = add_query_arg( array(
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ), 'https://accounts.google.com/o/oauth2/v2/auth' );

        wp_send_json_success( array( 'auth_url' => $auth_url ) );
    }

    /**
     * Process the OAuth callback from Google.
     * Called exclusively from handle_oauth_rest_callback() (the REST route).
     * Validates state via BOTH transient AND browser cookie to prevent login-CSRF.
     */
    public function process_oauth_callback( $code, $state ) {
        $return_url = home_url();

        // ── State validation: transient + cookie binding ──────────────────
        // The state token must match what we stored in the transient AND what
        // we set in the browser cookie. An attacker who obtains a valid state
        // from one victim cannot replay it from a different browser because
        // the cookie won't travel with the attacker's request.
        $state_hash   = md5( sanitize_text_field( $state ) );
        $cookie_state = isset( $_COOKIE['vibe_oauth_state'] )
            ? sanitize_text_field( wp_unslash( $_COOKIE['vibe_oauth_state'] ) )
            : '';

        $stored_url = get_transient( 'vibe_oauth_state_' . $state_hash );

        if ( false === $stored_url ) {
            $this->oauth_error( $return_url, __('OAuth state has expired. Please try signing in again.', 'vibe-comments') );
        }
        delete_transient( 'vibe_oauth_state_' . $state_hash );
        // Clear the cookie regardless of outcome.
        setcookie( 'vibe_oauth_state', '', array(
            'expires'  => time() - 3600,
            'path'     => wp_parse_url( rest_url( 'vibe-comments/v1/google-callback' ), PHP_URL_PATH ),
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ) );

        $return_url = $stored_url;

        // Cookie must match the state in the URL — proves this browser initiated the flow.
        if ( empty( $cookie_state ) || ! hash_equals( $state, $cookie_state ) ) {
            $this->oauth_error( $return_url, __('Invalid OAuth state. Please try signing in again.', 'vibe-comments') );
        }

        if ( empty( $code ) ) {
            $this->oauth_error( $return_url, __('Missing OAuth authorization code.', 'vibe-comments') );
        }

        $settings      = get_option( $this->option_name, array() );
        $client_id     = $settings['client_id']     ?? '';
        $client_secret = $settings['client_secret'] ?? '';

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            $this->oauth_error( $return_url, __('Google Sign-In is not configured. Please contact the site administrator.', 'vibe-comments') );
        }

        // Exchange authorization code for tokens.
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'body' => array(
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => rest_url( 'vibe-comments/v1/google-callback' ),
                'grant_type'    => 'authorization_code',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            $this->oauth_error( $return_url, __('Could not connect to Google. Please try again.', 'vibe-comments') );
        }

        $token_data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $token_data['id_token'] ) ) {
            $this->oauth_error( $return_url, __('Google sign-in failed. Please try again.', 'vibe-comments') );
        }

        // Full RS256 signature verification against Google's live JWKS.
        $payload = $this->verify_jwt( $token_data['id_token'], $client_id );
        if ( null === $payload ) {
            $this->oauth_error( $return_url, __('Token verification failed. Please try again.', 'vibe-comments') );
        }

        // Reject unverified emails — Google can return these from federated providers.
        if ( empty( $payload['email_verified'] ) || $payload['email_verified'] !== true ) {
            $this->oauth_error( $return_url, __('Your Google email address is not verified. Please verify your Google account and try again.', 'vibe-comments') );
        }

        if ( empty( $payload['email'] ) ) {
            $this->oauth_error( $return_url, __('Google did not return an email address. Please try again.', 'vibe-comments') );
        }

        $email = sanitize_email( $payload['email'] );
        $name  = sanitize_text_field( $payload['name'] ?? explode( '@', $email )[0] );

        $user = get_user_by( 'email', $email );

        if ( ! $user ) {
            $email_parts = explode( '@', $email );
            $base        = sanitize_user( $email_parts[0] );
            // wp_generate_password(8, false) — cryptographically random alphanumeric.
            $suffix      = wp_generate_password( 8, false );
            $username    = sanitize_user( $base . '_' . $suffix );

            // L3 fix: respect the site's configured default_role, not hardcoded 'subscriber'.
            $default_role = get_option( 'default_role', 'subscriber' );

            $user_id = wp_insert_user( array(
                'user_login'   => $username,
                'user_email'   => $email,
                'display_name' => $name,
                'user_pass'    => wp_generate_password(),
                'role'         => $default_role,
            ) );

            if ( is_wp_error( $user_id ) ) {
                $this->oauth_error( $return_url, __('Could not create your account. Please try again.', 'vibe-comments') );
            }

            $user = get_user_by( 'id', $user_id );
        }

        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );
        wp_safe_redirect( $return_url );
        exit;
    }

    /**
     * Redirect to the post with an inline error message rather than wp_die().
     * The JS `showError()` infrastructure in the comment widget will display it.
     * Uses wp_safe_redirect() so we never redirect to an external URL.
     */
    private function oauth_error( $return_url, $message ) {
        $redirect = add_query_arg( array(
            'vibe_auth_error' => urlencode( $message ),
        ), $return_url ?: home_url() );
        wp_safe_redirect( $redirect );
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

        // Fetch Google's JWKS — cached for 1 hour. Find the key matching the
        // token's kid, retrying ONCE with a forced-fresh fetch if not found.
        //
        // Google rotates its signing keys periodically. If a token was signed
        // with a key that rotated in AFTER our transient was cached, the kid
        // won't be in our stale cached set. Previously this comment claimed
        // "try once more" but the code only busted the cache for NEXT time and
        // failed the CURRENT request — a user hitting a rotation window got a
        // hard login failure and had to manually retry. This now actually
        // retries within the same request: attempt 1 uses whatever's cached
        // (or fetches if nothing is), and only if the kid genuinely isn't
        // found does attempt 2 force a fresh network fetch and try again
        // before giving up for real.
        $matching_key = null;
        for ( $attempt = 0; $attempt < 2 && null === $matching_key; $attempt++ ) {
            $jwks = ( $attempt === 0 ) ? get_transient( 'vibe_google_jwks' ) : false;

            if ( false === $jwks ) {
                $res = wp_remote_get( 'https://www.googleapis.com/oauth2/v3/certs', array(
                    'timeout' => 5,
                ) );
                if ( is_wp_error( $res ) ) return null;
                $jwks = json_decode( wp_remote_retrieve_body( $res ), true );
                if ( empty( $jwks['keys'] ) ) return null;
                set_transient( 'vibe_google_jwks', $jwks, HOUR_IN_SECONDS );
            }

            foreach ( $jwks['keys'] ?? array() as $key ) {
                if ( ( $key['kid'] ?? '' ) === $header['kid'] ) {
                    $matching_key = $key;
                    break;
                }
            }

            if ( null === $matching_key && $attempt === 0 ) {
                // Cached set didn't have it — force a genuine network refetch
                // on the next loop iteration rather than trusting the cache again.
                delete_transient( 'vibe_google_jwks' );
            }
        }
        if ( null === $matching_key ) return null;

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

        // RSA OID: 1.2.840.113549.1.1.1 — identifies this as an RSA public key.
        $rsa_oid = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

        // Build the BIT STRING object first so its total length is known exactly.
        // Constructing the outer SEQUENCE length by pre-computing parts separately
        // was the source of the off-by-one bug (the BIT STRING leading 0x00 byte
        // was included in the inner length but excluded from the outer, making the
        // outer SEQUENCE 1 byte short for every real RSA key).
        $bitstring_content = "\x00" . $rsa_key_der;
        $bitstring          = "\x03" . $this->der_length( strlen( $bitstring_content ) ) . $bitstring_content;
        $spki_der           = "\x30" . $this->der_length( strlen( $rsa_oid ) + strlen( $bitstring ) ) . $rsa_oid . $bitstring;

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
}
