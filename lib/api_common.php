<?php
/**
 * Bizuno API WordPress Plugin - common support methods
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * DISCLAIMER
 * Do not edit or add to this file if you wish to upgrade Bizuno to newer
 * versions in the future. If you wish to customize Bizuno for your
 * needs please contact PhreeSoft for more information.
 *
 * @name       Bizuno ERP
 * @author     Dave Premo, Bizuno Project <support@bizuno.com>
 * @copyright  2008-2026, PhreeSoft, Inc.
 * @license    https://www.gnu.org/licenses/agpl-3.0.txt
 * @version    7.x Last Update: 2026-05-31
 * @filesource /lib/common.php
 */

namespace bizuno;

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BIZUNO_API_OPT_GROUP', 'bizuno_api_options' );
if ( ! defined( 'BIZUNO_SETTINGS_PAGE_SLUG' ) ) { define( 'BIZUNO_SETTINGS_PAGE_SLUG', 'bizuno-settings' ); } // shared Settings->Bizuno page slug

class api_common
{
    public $bizLib    = 'bizuno-accounting';
    public $api_local = false;

    function __construct()
    {
    }
    
    public function client_open()
    {
        
    }
    public function client_close()
    {

    }
    public function rest_open(\WP_REST_Request $request)
    {

        $this->user = \wp_get_current_user();
        $qParams = $request->get_params(); // retrieve the get parameters
        if (empty($qParams)) { 
            $qParams = $request->get_query_params();

        }
        return $qParams;
    }
    public function rest_close($output=[], $status=200)
    {
        $output['message'] = \bizuno_api_msg_all(); // all levels, Bizuno merges them into its message stack (sync/confirm results are success/info)
        return new \WP_REST_Response($output, $status);
    }

    /** 
     * @param type $type
     * @param type $data
     * @param type $endPoint
     * @return type
     */
    function cURL( $type='get', $data=[], $endPoint='' )
    {
        $options = get_option( 'bizuno_general_options', [] ); // shared RESTful creds (url + api_token)

        $base_url = $options['url'] ?? '';
        $url      = trailingslashit( $base_url ) . '?bizRt=portal/api/' . $endPoint;
        // Build query string for GET. Strip empty values before encoding — WC's destination
        // array carries placeholders like address='', address_1='' during rate-shopping
        // (full address isn't entered until checkout), and ISPConfig + mod_security CRS
        // rules will 400 the request at the reverse proxy if too many empty params look
        // like enumeration/fuzzing. Keep `'0'` (legitimate value) but drop `''` and null.
        if ( is_array( $data ) ) {
            $data  = array_filter( $data, function ( $v ) { return $v !== '' && $v !== null; } );
            $rData = http_build_query( $data );
        } else {
            $rData = $data;
        }
        if ( 'get' === strtolower( $type ) && ! empty( $rData ) ) {
            $url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . $rData;
        }
        // Same-site loopback short-circuit. When the Bizuno core library is loaded in this
        // very PHP process (bizuno-accounting + library active on this site) AND the configured
        // server URL points back to this same host, a wp_remote_post() to ourselves is a
        // loopback HTTP request — which many hosts block, throttle, or deadlock (PHP-FPM
        // waiting on a free worker that is itself). Instead, invoke the Bizuno portal API
        // in-process and return the same JSON body the HTTP endpoint would have produced.
        // On any failure we fall through to the normal HTTP path below, so the local path
        // can never be worse than the prior behavior.
        if ( $this->isLocalBizuno( $url ) ) {
            $token     = ! empty( $options['api_token'] ) ? $this->decrypt_password( $options['api_token'] ) : '';
            $localBody = $this->localApiCall( $type, $endPoint, $rData, $token );
            if ( '' !== $localBody ) {
                // Mirror the messageStack merge the HTTP branch performs on the way out.
                $decoded = json_decode( $localBody, true );
                if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) && isset( $decoded['message'] ) ) {
                    \bizuno_api_msg_merge( $decoded['message'] );
                }
                return $localBody;
            }
            \bizuno_api_msg_add( 'Bizuno local (same-site) API call returned no data; falling back to HTTP loopback.', 'caution' );
        }
        // Bizuno's portal/api endpoints authenticate via the X-Bizuno-Token header below.
        // The Authorization header is a vestige from a pre-token mechanism that Bizuno's
        // validateApiToken() never reads — sending it here is pure cost: ISPConfig's
        // mod_security CRS rules flag Basic Authorization on certain patterns and 400 the
        // request at the reverse proxy before it reaches PHP. Dropped entirely. If a future
        // myExt endpoint legitimately needs Basic auth, re-add it conditionally on the
        // endpoint name rather than blanket-attaching it to every call.
        $headers = [
            'Accept'     => 'application/json',  // Assume JSON API
            'User-Agent' => 'Mozilla/5.0 (compatible; Bizuno-WP-Plugin/' . BIZUNO_API_VERSION . '; +https://www.bizuno.com)'];
        // Shared-secret token consumed by Bizuno's portal/api endpoints (shipGetRates, orderAdd, ediCron).
        // Stored encrypted in plugin options; decrypt before sending. Skip header when not configured —
        // Bizuno will refuse the request and the operator will see the misconfigure surface in logs.
        if ( ! empty( $options['api_token'] ) ) {
            $token = $this->decrypt_password( $options['api_token'] );
            if ( '' !== $token ) { $headers['X-Bizuno-Token'] = $token; }
        }
        // Content-Type is required for PHP to auto-populate $_POST on the Bizuno side.
        // wp_remote_post() only adds it automatically when `body` is an array — we build the
        // urlencoded string ourselves (above) so it has to be set explicitly. Without this,
        // Bizuno's portal/api/orderAdd reaches mapPost() with an empty $_POST, addressUpdate()
        // bails on "primary_name_required", and the response comes back {"result":"Fail","ID":null}.
        if ( 'POST' === strtoupper( $type ) ) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
        }
        // If you had other headers/cookies in $opts, merge here
        // $headers = array_merge( $headers, $additional_headers );
        // WP HTTP args
        $args = [
            'method'=>strtoupper($type), 'headers'=>$headers, 'timeout'=>30, 'sslverify'=>true, 'httpversion'=>'1.1', 'blocking'=>true];
        if ( 'POST' === strtoupper( $type ) ) { // POST body
            $args['body'] = $rData;  // Already a string (form-urlencoded or raw JSON)
            // If JSON payload needed: $args['body'] = wp_json_encode( $data );
            //     $args['headers']['Content-Type'] = 'application/json';
        }

        // Execute request
        if ( 'POST' === strtoupper( $type ) ) { $response = wp_remote_post( $url, $args ); }
        else                                  { $response = wp_remote_get( $url, $args ); }
        if ( is_wp_error( $response ) ) { // Handle WP_Error
            $error_msg = 'WP HTTP Error: ' . $response->get_error_message();

            \bizuno_api_msg_add( $error_msg, 'error' );
            return false;  // or return null / array() as needed
        }
        $body       = wp_remote_retrieve_body( $response ); // Get useful parts
        $status_code= wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status_code ) { \bizuno_api_msg_add( "Received HTTP $status_code from API.", 'caution' ); }
        if ( empty( $body ) )       { \bizuno_api_msg_add( "Oops! Received an empty response. Likely a connection/protocol issue (e.g., TLS/ALPN mismatch).", 'caution' ); }

        // Side effect: merge any messageStack the API returned into our local stack so the
        // operator sees its errors / cautions. Done WITHOUT changing the return type — the
        // helper consistently returns the raw body string, and callers (api_shipping.php:48,
        // api_order.php:167) decode it themselves. The previous "smart" return that swapped
        // between decoded-array and raw-string depending on the response shape was fataling
        // those callers with `json_decode(): Argument #1 must be of type string, array given`
        // every time the API returned a message key (which is most error responses).
        $decoded = json_decode( $body, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) && isset( $decoded['message'] ) ) {

            \bizuno_api_msg_merge( $decoded['message'] );
        }
        return $body;
    }

    /**
     * True when the Bizuno core library is loaded in THIS PHP process (i.e. the
     * bizuno-accounting plugin + its library are active on this same site) AND the
     * configured server URL resolves to this same host. In that situation an HTTP
     * call to the configured URL would be a loopback to ourselves, so the caller
     * should invoke the portal API in-process instead. Host comparison is www- and
     * case-insensitive and is checked against both home_url() and site_url().
     *
     * @param string $url The fully-built request URL (configured server + bizRt).
     * @return bool
     */
    private function isLocalBizuno( $url ) {
        // BIZUNO_FS_LIBRARY is defined by bizuno-accounting/portalCFG.php only when the
        // core library is booted in-process. No constant -> core is remote -> use HTTP.
        if ( ! defined( 'BIZUNO_FS_LIBRARY' ) ) { return false; }
        $target = wp_parse_url( $url, PHP_URL_HOST );
        if ( empty( $target ) ) { return false; }
        $norm = function( $host ) { return strtolower( preg_replace( '/^www\./i', '', (string) $host ) ); };
        $target = $norm( $target );
        foreach ( [ home_url(), site_url() ] as $selfUrl ) {
            $self = $norm( wp_parse_url( $selfUrl, PHP_URL_HOST ) );
            if ( '' !== $self && $target === $self ) { return true; }
        }
        return false;
    }

    /**
     * Invoke a Bizuno portal API endpoint in-process, returning the same JSON body the
     * HTTP endpoint (portalAPI.php?bizRt=portal/api/<endPoint>) would have produced.
     *
     * The Bizuno portal API methods read request state from $_POST / $_GET and the
     * X-Bizuno-Token header, set $layout['content'] to a JSON string, and return (the
     * fatal `exit()` lives in the view renderer, which we deliberately bypass). We mirror
     * the exact HTTP request bytes via $rData (already array_filter'd + url-encoded by the
     * caller), run the method, capture $layout['content'], and restore the original
     * superglobals so the live WooCommerce request is unaffected. Any error returns ''
     * so cURL() falls back to the HTTP path.
     *
     * @param string $type     'get' or 'post'.
     * @param string $endPoint portalApi method name (orderAdd, getSalesTax, shipGetRates...).
     * @param string $rData    URL-encoded request data (the same string sent over HTTP).
     * @param string $token    Decrypted shared API token for the X-Bizuno-Token header.
     * @return string JSON body, or '' on any failure (caller then falls back to HTTP).
     */
    private function localApiCall( $type, $endPoint, $rData, $token ) {
        $apiFile = BIZUNO_FS_LIBRARY . 'portal/api.php';
        if ( ! is_readable( $apiFile ) ) { return ''; }
        require_once $apiFile; // defines \bizuno\portalApi (namespaced, no side effects)
        if ( ! class_exists( '\bizuno\portalApi' ) || ! method_exists( '\bizuno\portalApi', $endPoint ) ) { return ''; }
        // Snapshot the live request state before we borrow the superglobals. This is an
        // outbound API *client* that temporarily populates $_POST/$_GET so the in-process
        // portal API (which reads from them) sees the same request a real HTTP call would —
        // it is NOT processing a form submission, so nonce verification does not apply. The
        // original state is restored verbatim in the finally block below.
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
        $savedPost = $_POST;
        $savedGet  = $_GET;
        $savedReq  = $_REQUEST;
        $hadToken  = array_key_exists( 'HTTP_X_BIZUNO_TOKEN', $_SERVER );
        $savedTok  = $hadToken ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_BIZUNO_TOKEN'] ) ) : null;
        // Rebuild $_POST/$_GET from the exact url-encoded bytes the HTTP path would send,
        // so values are strings with identical nesting (parse_str mirrors PHP's own parsing).
        $parsed = [];
        parse_str( (string) $rData, $parsed );
        if ( 'post' === strtolower( $type ) ) { $_POST = $parsed; $_GET = []; }
        else                                  { $_GET  = $parsed; $_POST = []; }
        $_REQUEST = array_merge( $_GET, $_POST );
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
        if ( '' !== (string) $token ) { $_SERVER['HTTP_X_BIZUNO_TOKEN'] = $token; }
        $layout = [];
        ob_start(); // swallow any stray output from the core so the WC page is not corrupted
        try {
            $api = new \bizuno\portalApi();
            $api->$endPoint( $layout );
        } catch ( \Throwable $e ) {
            \bizuno_api_msg_add( 'Local Bizuno API call (' . $endPoint . ') failed: ' . $e->getMessage(), 'caution' );
            $layout = [];
        } finally {
            ob_end_clean();
            $_POST    = $savedPost;
            $_GET     = $savedGet;
            $_REQUEST = $savedReq;
            if ( $hadToken ) { $_SERVER['HTTP_X_BIZUNO_TOKEN'] = $savedTok; }
            else             { unset( $_SERVER['HTTP_X_BIZUNO_TOKEN'] ); }
        }
        return ( isset( $layout['content'] ) && is_string( $layout['content'] ) ) ? $layout['content'] : '';
    }

    public function setNotices($resp=[])
    {

        // Bizuno's response shape varies by which layer rejected the request:
        //   - apiOrder::add() (compose succeeded)         → ['messages' => msgQueue()]   (plural)
        //   - portalApi::orderAdd() returning early       → ['message'  => $msgStack]    (singular, default JSON view)
        // Both carry the same {error|warning|info|success}=>[{text:..}] structure inside.
        // Accept either; the singular form is what surfaces "API user not found",
        // "Illegal Access", and other token/user failures from portal/api.php.
        $stack = [];
        if ( ! empty( $resp['messages'] ) && is_array( $resp['messages'] ) ) { $stack = $resp['messages']; }
        elseif ( ! empty( $resp['message'] ) && is_array( $resp['message'] ) ) { $stack = $resp['message']; }
        if ( empty( $stack ) ) { return; }
        $notices = [];
        $user_id = get_current_user_id();
        foreach ( ['error', 'warning', 'info', 'success'] as $type ) {
            $wc_type = $type==='success' ? 'success' : ( $type === 'error' ? 'error' : 'warning' );

            if ( empty( $stack[$type] ) ) { continue; }
            foreach ( $stack[$type] as $msg ) {

                $text = trim( is_array( $msg ) ? ( $msg['text'] ?? '' ) : (string) $msg );
                if ( $text ) { $notices[] = [ 'class'=>"notice notice-{$wc_type} is-dismissible", 'message'=>$text ]; }
            }
        }

        if ( !empty( $notices ) ) { \set_transient( "bizuno_order_download_notices_{$user_id}", $notices, 45 ); }
    }

    protected function encrypt_password( $password ) {
       if ( ! function_exists( 'openssl_encrypt' ) ) { return base64_encode( $password ); } // fallback – not ideal
       $key    = wp_salt( 'auth' ); // or wp_salt( 'secure_auth' ) or a constant from wp-config
       $method = 'aes-256-cbc';
       $iv_len = openssl_cipher_iv_length( $method );
       $iv     = openssl_random_pseudo_bytes( $iv_len );
       $encrypted = openssl_encrypt( $password, $method, substr( $key, 0, 32 ), 0, $iv );
       return $encrypted ? base64_encode( $encrypted . '::' . base64_encode( $iv ) ) : '';
    }

    public function decrypt_password( $encrypted ) {
        if ( empty( $encrypted ) ) { return ''; }
        // Values saved through encrypt_password() base64-encode `<ciphertext>::<base64-iv>`.
        // Anything else (operator pasted plaintext, or a value from before the encryption
        // wrapper was added) should round-trip as itself. The previous fallback double-base64-
        // decoded such inputs into binary garbage, which then went out as the X-Bizuno-Token
        // header value and tripped mod_security CRS rules at the receiving end (non-printable
        // bytes in HTTP headers are flagged as protocol violations and 400'd).
        $decoded = @base64_decode( $encrypted, true );  // strict mode — false on non-base64 input
        if ( $decoded === false || strpos( $decoded, '::' ) === false ) {
            return (string) $encrypted;  // plaintext / unrecognized format — pass through verbatim
        }
        $parts = explode( '::', $decoded, 2 );
        if ( count( $parts ) !== 2 ) { return (string) $encrypted; }
        $key    = wp_salt( 'auth' );
        $method = 'aes-256-cbc';
        $decrypted = openssl_decrypt( $parts[0], $method, substr( $key, 0, 32 ), 0, base64_decode( $parts[1] ) );
        return $decrypted !== false ? $decrypted : (string) $encrypted;
    }
}
