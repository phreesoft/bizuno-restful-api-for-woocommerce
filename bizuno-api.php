<?php
/**
 * Plugin Name:       Bizuno RESTful API for WooCommerce
 * Plugin URI:        https://github.com/phreesoft/bizuno-restful-api-for-woocommerce
 * Description:       Secure RESTful API bridge for real-time WooCommerce ↔ Bizuno ERP sync: orders, inventory, customers, prices & more.
 * Version:           7.4.3
 * Requires at least: 6.5
 * Tested up to:      7.0
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            PhreeSoft, Inc.
 * Author URI:        https://www.phreesoft.com
 * License:           AGPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain:       bizuno-restful-api-for-woocommerce
 * Domain Path:       /locale
 * WC requires at least: 8.0
 * WC tested up to:   9.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! defined( 'BIZUNO_API_VERSION' ) ) { define( 'BIZUNO_API_VERSION', '7.4.3' ); }

// Library files for plugin operations
require_once ( dirname(__FILE__) . '/lib/wp_messages.php' ); // native WordPress messaging (no Bizuno library dependency)
require_once ( dirname(__FILE__) . '/lib/api_common.php' );
require_once ( dirname(__FILE__) . '/lib/api_admin.php' );
require_once ( dirname(__FILE__) . '/lib/api_order.php' );
require_once ( dirname(__FILE__) . '/lib/api_product.php' );
require_once ( dirname(__FILE__) . '/lib/api_shipping.php' );

class bizuno_api
{
    private $bizEnabled= false;
    private $bizLib    = 'bizuno-accounting'; // needed to load Bizuno environment
    public  $admin;
    public  $order;
    public  $product;
    public  $shipping;

    public function __construct()
    {
        register_activation_hook  ( __FILE__ , [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__ , [ $this, 'deactivate' ] );
        // Declare WooCommerce HPOS (custom order tables) compatibility. Without this, WooCommerce
        // flags the plugin as incompatible on HPOS stores. All order access here uses the CRUD
        // API (wc_get_order / get_meta / save), which is HPOS-safe.
        add_action( 'before_woocommerce_init', function() {
            if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
            }
        } );
        $this->initializeBizuno();
        $this->admin    = new \bizuno\api_admin();
        $this->order    = new \bizuno\api_order();
        $this->product  = new \bizuno\api_product();
        $this->shipping = new \bizuno\api_shipping();
        // WordPress Actions
        add_action ( 'init',                    [ $this, 'initializePlugin' ] );
        add_action ( 'admin_menu',              [ $this->admin, 'bizuno_ensure_shared_menu_and_general_tab' ], 8 ); // shared Settings->Bizuno page (register-once guarded)
        add_action ( 'admin_init',              [ $this->admin, 'bizuno_register_general_settings' ] );             // shared RESTful creds (register-once guarded)
        add_action ( 'admin_init',              [ $this->admin, 'bizuno_api_register_settings' ] );
        add_action ( 'admin_menu',              [ $this->admin, 'bizuno_api_add_setting_submenu' ] );
        add_action ( 'admin_notices',           [ $this, 'bizAdminNotices' ], 20 );
        add_action ( 'rest_api_init',           [ $this, 'bizuno_api_register_rest' ] );
        add_action ( 'woocommerce_init',        [ $this, 'bizuno_api_woocommerce_init' ] );
        add_action ( 'plugins_loaded',          [ $this, 'bizuno_api_plugins_loaded' ] );
        add_action ( 'bizuno_api_image_process',[ $this->product, 'cron_image' ] );
        // WordPress Filters
        add_filter ( 'bizuno_settings_tabs',    [ $this->admin, 'bizuno_api_register_tab' ] );
        add_filter ( 'mime_types',              [ $this, 'biz_allow_webp_upload' ] ); // filter to allow mime type .webp images to be uploaded
        // WooCommerce hooks
        if ( is_plugin_active ( 'woocommerce/woocommerce.php' ) ) {
            // WooCommerce Actions
            add_action ( 'woocommerce_before_calculate_totals',              [ $this->order,    'bizuno_before_calculate_totals' ], 9999 );
            add_action ( 'manage_shop_order_posts_custom_column',            [ $this->admin,    'bizuno_api_order_column_content' ], 25, 2 ); // Work with Legacy
            add_action ( 'woocommerce_shop_order_list_table_custom_column',  [ $this->admin,    'bizuno_api_order_column_content_hpos' ], 25, 2 ); // Works with HPOS
            add_action ( 'woocommerce_admin_order_preview_actions_end',      [ $this->admin,    'bizuno_api_order_preview_action' ] );
            add_action ( 'admin_post_bizuno_export_order',                   [ $this->order,    'bizuno_export_order_handler' ] );
            add_action ( 'woocommerce_order_action_bizuno_export_order',     [ $this->order,    'bizuno_order_meta_box_action' ] );
            // The woocommerce_payment_complete hook does not fire in the Payfabric plugin so hook the thank you page
//          add_action ( 'woocommerce_payment_complete',                     [ $this->order,    'bizuno_api_post_payment' ], 10, 1 );
            add_action ( 'woocommerce_thankyou',                             [ $this->order,    'bizuno_api_post_payment' ], 20, 1 );
            add_action ( 'woocommerce_review_order_before_cart_contents',    [ $this->shipping, 'bizuno_validate_order' ], 10 );
            add_action ( 'woocommerce_after_checkout_validation',            [ $this->shipping, 'bizuno_validate_order' ], 10 );
            add_action ( 'shutdown',                                         [ $this,           'bizuno_write_debug' ], 999999 );
            add_action ( 'woocommerce_shipping_init',                        'bizuno_shipping_method_init' );
            // WooCommerce Filters
            add_filter ( 'woocommerce_quantity_input_args',                  [ $this->order,    'bizuno_enforce_bulk_increment'], 10, 2 );
            add_filter ( 'woocommerce_add_to_cart_validation',               [ $this->order,    'bizuno_validate_bulk_quantity'], 10, 3 );
            add_filter ( 'woocommerce_shipping_methods',                     [ $this->shipping, 'add_bizuno_shipping_method' ] );
            add_filter ( 'wc_order_statuses',                                [ $this->admin,    'add_shipped_to_order_statuses' ] );
            add_filter ( 'manage_edit-shop_order_columns',                   [ $this->admin,    'bizuno_api_order_column_header' ], 20 ); // Works with legacy
            add_filter ( 'woocommerce_shop_order_list_table_columns',        [ $this->admin,    'bizuno_api_order_column_header_hpos' ], 20 ); // works with HPOS
            add_filter ( 'woocommerce_admin_order_preview_actions',          [ $this->admin,    'bizuno_add_export_preview_action_button' ], 10, 2 );
            add_filter ( 'woocommerce_admin_order_preview_get_order_details',[ $this->admin,    'add_bizuno_export_preview_data' ], 10, 2 );
            add_filter ( 'woocommerce_order_actions',                        [ $this->admin,    'add_bizuno_to_order_actions_dropdown' ] );
            // WooCommerce Shortcodes
            add_shortcode ( 'bizuno_api_price_discounts', [ $this->product, 'bizuno_api_price_discounts_sc' ] );
        }
    }

    public function bizuno_api_plugins_loaded() {
        if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) { return; }
        bizuno_shipping_method_init();
    }

    private function initializeBizuno()
    {   // Self-contained: messaging is loaded via lib/wp_messages.php (native WordPress, no
        // Bizuno library / bizuno-accounting dependency). Nothing else to bootstrap.
        $this->bizEnabled = true;
    }

    public function initializePlugin()
    {
        add_rewrite_endpoint( 'biz-account-wallet',   EP_ROOT | EP_PAGES ); // for WC add wallet endpoint
        register_post_status( 'wc-shipped', [
            'label'                     => _x( 'Shipped', 'Order status', 'bizuno-restful-api-for-woocommerce' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s is replaced with the number of orders in this status */
            'label_count'               => _n_noop( 'Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>', 'bizuno-restful-api-for-woocommerce' ) ] );
    }
    
    public function bizuno_api_woocommerce_init()
    {
        if ( ! WC()->is_rest_api_request() ) { return; }
        WC()->frontend_includes();
        if ( null === WC()->session ) {
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
        }
        if ( null === WC()->customer ) {
            WC()->customer = new WC_Customer( 0 );
        }
        if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) { wc_load_cart(); }
    }
    
    public function bizuno_api_register_rest()
    {
        if ( is_plugin_active ( 'woocommerce/woocommerce.php' ) ) { // From Bizuno -> WordPress (uplink)
            register_rest_route( 'bizuno-api/v1', 'product/update',  ['methods' => 'POST','args'=>[],
                'callback' => [ new \bizuno\api_product(), 'product_update' ], 'permission_callback' => [$this, 'check_access'] ] );
            register_rest_route( 'bizuno-api/v1', 'product/refresh', ['methods' => 'PUT', 'args'=>[],
                'callback' => [ new \bizuno\api_product(), 'product_refresh' ],'permission_callback' => [$this, 'check_access'] ] );
            register_rest_route( 'bizuno-api/v1', 'product/sync',    ['methods' => 'POST','args'=>[],
                'callback' => [ new \bizuno\api_product(), 'product_sync' ],   'permission_callback' => [$this, 'check_access'] ] );
            register_rest_route( 'bizuno-api/v1', 'order/confirm',   ['methods' => 'POST','args'=>[],
                'callback' => [ new \bizuno\api_order(),   'order_confirm' ],  'permission_callback' => [$this, 'check_access'] ] );
        }
    }
    
    public function check_access( WP_REST_Request $request ) {

        // wp_authenticate() takes a user name or an email, sanitize_email() blanked a plain user name ("Missing credentials.")
        $email = sanitize_text_field( wp_unslash( (string) $request->get_header( 'email' ) ) );
        $pass  = $request->get_header( 'pass' );
        
        if ( empty( $email ) || empty( $pass ) ) {
            return new WP_Error( 'rest_forbidden', esc_html__( 'Missing credentials.', 'bizuno-restful-api-for-woocommerce' ), array( 'status' => 401 ) );
        }

        $user = wp_authenticate( $email, $pass );

        if ( is_wp_error( $user ) ) {
            return new WP_Error( 'rest_forbidden', esc_html__( 'Invalid credentials.', 'bizuno-restful-api-for-woocommerce' ), array( 'status' => 401 ) );
        }

        // Authentication is not authorization: these endpoints create/update
        // products and change order status, so require a store-management
        // capability. Without this, any valid low-privilege account (e.g. a
        // subscriber) could trigger product sync/refresh/update and order
        // confirmation.
        if ( ! user_can( $user->ID, 'manage_woocommerce' ) ) {
            return new WP_Error( 'rest_forbidden', esc_html__( 'Insufficient permissions.', 'bizuno-restful-api-for-woocommerce' ), array( 'status' => 403 ) );
        }

        return true;
    }

    public function biz_allow_webp_upload($existing_mimes) { // allows image uploads of mime type webp
        $existing_mimes['webp'] = 'image/webp';
        return $existing_mimes;
    }
    
    public function bizAdminNotices() {
        $user_id = get_current_user_id();
        $key     = "bizuno_order_download_notices_{$user_id}";
        $notices = get_transient( $key );
        if ( ! $notices ) { return; }
        delete_transient( $key );
        foreach ( $notices as $n ) { printf( '<div class="%s"><p>%s</p></div>', esc_attr( $n['class'] ), wp_kses_post( $n['message'] ) ); }
    }
    
    public function bizuno_write_debug() {
        // Debug trace logging was removed during decouple; nothing to flush at shutdown.
    }

    public function activate()
    {
        if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) { // set all existing orders to downloaded to hide Download button for past orders
            // Optional: Skip migration if already done
            if ( get_option( 'bizuno_order_migration_done', false ) ) { return; }
            $batch_size   = 200; // adjust based on your server (100–500 is usually safe)
            $offset       = 0;
            $updated      = 0;
            $orders_total = 0;
            do {
                $orders = wc_get_orders( [ 'limit'=>$batch_size, 'offset'=>$offset, 'return'=>'objects', 'status'=>'any', 'orderby'=>'ID', 'order'=>'ASC', 'paginate'=>false ] );
                if ( empty( $orders ) ) { break; }
                foreach ( $orders as $order ) { // Only update if not already marked (optional but saves unnecessary writes)
                    if ( 'yes' !== $order->get_meta( 'bizuno_order_exported', true ) ) {
                        $order->update_meta_data( 'bizuno_order_exported', 'yes' );
                        $order->save();
                        $updated++;
                    }
                }
                $orders_total += count( $orders );
                $offset       += $batch_size;
                sleep( 1 ); // Give server a tiny breather (optional)
            } while ( true );  // loop ends when $orders is empty
            update_option( 'bizuno_order_migration_done', true );
            if ( function_exists( 'wc_get_logger' ) ) { // Log final result (or show admin notice)
                wc_get_logger()->info( "Bizuno order export flag update complete. Total orders: $orders_total, Updated: $updated" );
            }
        }
        if (!wp_next_scheduled('bizuno_api_image_process')) { wp_schedule_event(time(), 'hourly', 'bizuno_api_image_process'); }
    }
    
    public function deactivate()
    {
        if (wp_next_scheduled('bizuno_api_image_process')) { wp_clear_scheduled_hook('bizuno_api_image_process'); }
    }
}
new bizuno_api();

register_uninstall_hook(__FILE__, 'bizuno_api_uninstall');
function bizuno_api_uninstall() {
    global $wpdb;

    // === 1. Legacy CPT orders (pre-HPOS or compatibility mode) ===
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", 'bizuno_order_exported' ) );

    // === 2. HPOS orders (WooCommerce 7.1+ with HPOS enabled) ===
    $table_name = $wpdb->prefix . 'wc_orders_meta';

    // Only attempt delete if the table actually exists
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name ) {
        $wpdb->query(
            $wpdb->prepare(
                // Use %i placeholder for identifiers (table/column names) – available in WP 6.2+
                // If supporting < WP 6.2, fall back to direct interpolation with comment suppression
                "DELETE FROM %i WHERE meta_key = %s", $table_name, 'bizuno_order_exported' ) );
    }

    // Optional logging – only in debug mode, and use wc_get_logger() for WooCommerce context (better)
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->debug(
                'Bizuno API uninstall: Removed bizuno_order_exported meta from postmeta and wc_orders_meta.',
                array( 'source' => 'bizuno-api' )
            );
        }
    }
}

/***************************************************************************************************/
//  Adds Bizuno shipping method class to Calculate cart freight charges using Bizuno shipping preferences
/***************************************************************************************************/
function bizuno_shipping_method_init()
{
    if (!class_exists('Bizuno_API_Shipping_Method')) {
        class Bizuno_API_Shipping_Method extends WC_Shipping_Method
        {
            public function __construct( $instance_id = 0 )
            {
                $this->id                 = 'bizuno_shipping';
                $this->title              = __( 'Bizuno Shipping Calculator', 'bizuno-restful-api-for-woocommerce' );
                $this->instance_id        = absint( $instance_id );
                $this->method_title       = __( 'Bizuno Shipping', 'bizuno-restful-api-for-woocommerce' );
                $this->method_description = __( 'Calculate shipping methods and costs through the Bizuno Accounting plugin', 'bizuno-restful-api-for-woocommerce' );
                $this->supports           = ['shipping-zones', 'instance-settings', 'instance-settings-modal', ];
                $this->init();
            }
            public function init()
            {
                $this->init_form_fields();
                $this->init_settings();
                add_action( 'woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
            }
            public function init_form_fields()
            { // The settings
                $this->instance_form_fields = [
                    'enabled'=> [ 'title'=> __( 'Enable', 'bizuno-restful-api-for-woocommerce' ),'type'=>'checkbox','default'=>'no',
                        'description'=> __( 'Enable Bizuno Accounting calculated shipping', 'bizuno-restful-api-for-woocommerce' ) ],
                    'title'  => [ 'title'=> __( 'Title', 'bizuno-restful-api-for-woocommerce' ), 'type'=>'text',    'default'=> __( 'Shipper Preference', 'bizuno-restful-api-for-woocommerce' ),
                        'description'=> __( 'Title to be display on site', 'bizuno-restful-api-for-woocommerce' ) ] ];
            }
            public function calculate_shipping( $package=[] )
            {
                $admin = new \bizuno\api_admin();
                $api   = new \bizuno\api_shipping();
                $rates = $api->getRates($package);
                foreach ($rates as $rate) {
                    $wooRate = ['id'=>$rate['id'], 'label'=>$rate['title'], 'cost'=>$rate['quote']];
                    $this->add_rate( $wooRate );
                }
            }
        }
    }
}
