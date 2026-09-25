<?php
/**
 * Bizuno API WordPress Plugin - native WordPress messaging
 *
 * Replaces the former Bizuno messageStack / \bizuno\msgAdd dependency. Defined in
 * the GLOBAL namespace with plugin-unique names so nothing collides with the Bizuno
 * library (bizuno-accounting's \bizuno\msgAdd, messageStack, etc.) when both are active.
 *
 * Messages accumulate per-request (read back into REST responses via
 * bizuno_api_msg_get()) and surface in wp-admin through the standard admin_notices hook.
 *
 * @name       Bizuno API
 * @author     PhreeSoft, Inc. <support@phreesoft.com>
 * @copyright  2008-2026, PhreeSoft, Inc.
 * @license    https://www.gnu.org/licenses/agpl-3.0.txt
 * @version    7.x Last Update: 2026-05-31
 * @filesource /lib/wp_messages.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'bizuno_api_msg_level' ) ) {
    /** Normalize a Bizuno-style level to a WordPress notice class. */
    function bizuno_api_msg_level( $level ) {
        $level = strtolower( (string) $level );
        if ( $level === 'caution' ) { $level = 'warning'; }
        return in_array( $level, [ 'error', 'warning', 'success', 'info' ], true ) ? $level : 'error';
    }
}

if ( ! function_exists( 'bizuno_api_msg_add' ) ) {
    /**
     * Queue a message. Returns false so existing `return bizuno_api_msg_add(...)` call
     * sites keep their "report and bail" semantics.
     */
    function bizuno_api_msg_add( $message, $level = 'error' ) {
        $level = bizuno_api_msg_level( $level );
        if ( ! isset( $GLOBALS['bizuno_api_messages'] ) ) { $GLOBALS['bizuno_api_messages'] = []; }
        $GLOBALS['bizuno_api_messages'][ $level ][] = (string) $message;
        return false;
    }
}

if ( ! function_exists( 'bizuno_api_msg_merge' ) ) {
    /** Merge a returned {error|warning|info|success}=>[{text:..}|..] structure into the queue. */
    function bizuno_api_msg_merge( $messages ) {
        if ( ! is_array( $messages ) ) { return; }
        foreach ( $messages as $level => $items ) {
            foreach ( (array) $items as $item ) {
                $text = is_array( $item ) ? ( $item['text'] ?? '' ) : (string) $item;
                if ( $text !== '' ) { bizuno_api_msg_add( $text, $level ); }
            }
        }
    }
}

if ( ! function_exists( 'bizuno_api_msg_get' ) ) {
    /** Return queued messages of a level as a single string (used in REST responses). */
    function bizuno_api_msg_get( $level = 'error' ) {
        $level = bizuno_api_msg_level( $level );
        $msgs  = $GLOBALS['bizuno_api_messages'][ $level ] ?? [];
        return implode( ' ', $msgs );
    }
}

if ( ! function_exists( 'bizuno_api_msg_all' ) ) {
    /**
     * Return every queued message in the Bizuno msgStack shape {error|warning|info|success}=>[['text'=>..]]
     * so REST callers see the success/info results too, not just errors. Empty array when nothing is queued.
     */
    function bizuno_api_msg_all() {
        $out = [];
        foreach ( ( $GLOBALS['bizuno_api_messages'] ?? [] ) as $level => $items ) {
            foreach ( (array) $items as $text ) {
                $row = [ 'text' => $text ];
                if ( 'info' === $level ) { $row['title'] = 'Information'; }
                $out[ $level ][] = $row;
            }
        }
        return $out;
    }
}

if ( ! function_exists( 'bizuno_api_render_notices' ) ) {
    /** Render queued messages as WordPress admin notices. */
    function bizuno_api_render_notices() {
        $all = $GLOBALS['bizuno_api_messages'] ?? [];
        foreach ( $all as $level => $items ) {
            foreach ( (array) $items as $text ) {
                printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                    esc_attr( $level ), wp_kses_post( $text ) );
            }
        }
        $GLOBALS['bizuno_api_messages'] = [];
    }
    add_action( 'admin_notices', 'bizuno_api_render_notices' );
}
