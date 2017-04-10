<?php

/*
    Plugin Name: Logitrail
    Description: Integrate checkout shipping with Logitrail
    Version: 1.0.10
    Author: <a href="mailto:petri@codaone.fi">Petri Kanerva</a> | <a href="http://www.codaone.fi/">Codaone Oy</a>
*/

if(!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Require the Logitrail ApiClient
require_once( 'includes/ApiClient.php' );

// Require getallheaders polyfill
require_once('includes/getallheaders.php' );

// Required functions
if(!function_exists('logitrail_is_woocommerce_active')) {
    require_once( 'logitrail-includes/logitrail-functions.php' );
}

// WC active check
if(!logitrail_is_woocommerce_active()) {
    return;
}

define("LOGITRAIL_ID", "logitrail_shipping");

/**
 * Logitrail class
 */

class Logitrail_WooCommerce {

    protected static $db_version = '0.2.1';
    protected static $tables = array(
        'debug' => 'logitrail_debug',
        'log'   => 'logitrail_webhook_log'
    );

    private $merchant_id;
    private $secret_key;

    private $debug_mode;

    /**
     * Constructor
     */
    public function __construct() {
        register_uninstall_hook(__FILE__, array($this, 'logitrail_uninstall'));

        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'wf_plugin_action_links' ) );
        add_action( 'woocommerce_shipping_init', array( $this, 'logitrail_shipping_init') );
        add_filter( 'woocommerce_shipping_methods', array( $this, 'logitrail_add_method') );

        add_action( 'woocommerce_checkout_shipping', array($this, 'logitrail_get_template') );
        add_action( 'woocommerce_thankyou', array($this, 'logitrail_payment_complete'), 10, 1 );

        add_action( 'wc_ajax_logitrail', array($this, 'logitrail_get_form' ) );
        add_action( 'wc_ajax_logitrail_setprice', array($this, 'logitrail_set_price'));
        add_action( 'wc_ajax_logitrail_export_products', array( $this, 'export_products' ) );

        add_action( 'wc_ajax_logitrail_debug_log', array( $this, 'get_debug_log' ) );
        add_action( 'wc_ajax_logitrail_debug_log_clear', array( $this, 'clear_debug_log' ) );

        add_filter( 'woocommerce_locate_template', array($this, 'logitrail_woocommerce_locate_template'), 10, 3 );

        add_action( 'woocommerce_review_order_before_shipping', array($this, 'logitrail_woocommerce_review_order_before_shipping'), 5, 1 );

        add_action( 'save_post', array($this, 'logitrail_create_product'), 10, 2);

        add_filter( 'woocommerce_cart_shipping_method_full_label', array($this, 'logitrail_remove_label'), 10, 2 );

        // essentially disable WooCommerce's shipping rates cache
        add_filter( 'woocommerce_checkout_update_order_review', array($this, 'clear_wc_shipping_rates_cache'), 10, 2);

        // extra barcode field stuff
        add_action( 'woocommerce_product_options_general_product_data', array($this, 'logitrail_add_barcode'));
        add_action( 'woocommerce_process_product_meta', array($this, 'logitrail_barcode_save'));

        add_action( 'woocommerce_product_options_shipping', array($this, 'logitrail_add_enable_shipping'));
        add_action( 'woocommerce_process_product_meta', array($this, 'logitrail_enable_shipping_save'));

        add_action( 'woocommerce_product_after_variable_attributes', array($this, 'logitrail_variation_settings_fields'), 10, 3 );
        add_action( 'woocommerce_save_product_variation', array($this, 'logitrail_save_variation_settings_fields'), 10, 2 );

        add_action( 'admin_notices', array($this, 'logitrail_notifications'));

        add_action('woocommerce_after_checkout_validation', array(&$this, 'validate_shipping_method'));

        add_action( 'rest_api_init',  array($this, 'register_api_hooks' ));

        add_action( 'plugins_loaded', array($this, 'logitrail_update_db_check' ));

        // add possbile table prefix for db tables to be created
        global $wpdb;
        foreach (self::$tables as $name => &$table){
            $table = $wpdb->prefix.$table;
        }

        $settings = get_option('woocommerce_logitrail_shipping_settings');

        $this->debug_mode = ($settings['debug_mode'] === 'yes' ? true : false);
    }

    public function validate_shipping_method($data = '') {
        global $woocommerce;
        $unique_id = $woocommerce->session->get_customer_id();
        $shipping_method = get_transient('logitrail_' . $unique_id . '_type');
        $shippable = get_transient('logitrail_'. $unique_id . '_shipping');
        if (!$shipping_method && $shippable) {
            wc_add_notice( apply_filters( 'woocommerce_checkout_required_field_notice', 'Valitse toimitustapa.'), 'error' );
        }
    }

    /**
     * Add new endpoints.
     */
    public static function add_endpoint() {
        add_rewrite_endpoint( 'lt-products', EP_ALL, 'ltProducts' );
    }

    /**
     * Plugin page links
     */
    public function wf_plugin_action_links( $links ) {
        $plugin_links = array(
            '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=shipping&section=logitrail_shipping' ) . '">' . __( 'Settings', 'logitrail-woocommerce-shipping' ) . '</a>',
        );

        return array_merge( $plugin_links, $links );
    }

    /**
     * logitrail_shipping_init function.
     *
     * @access public
     * @return void
     */
    function logitrail_shipping_init() {
        include_once( 'includes/class-logitrail-shipping.php' );
    }

    /**
     * logitrail_add_method function.
     *
     * @access public
     * @param mixed $methods
     * @return void
     */
    function logitrail_add_method( $methods ) {
        $methods[] = 'Logitrail_Shipping';

        return $methods;
    }

    function logitrail_plugin_path() {
        // gets the absolute path to this plugin directory
        return untrailingslashit( plugin_dir_path( __FILE__ ) );
    }

    function logitrail_woocommerce_locate_template($template, $template_name, $template_path) {
        global $woocommerce;

        $_template = $template;
        if (!$template_path) {
            $template_path = $woocommerce->template_url;
        }

        $plugin_path = $this->logitrail_plugin_path() . '/woocommerce/';

        // Look within passed path within the theme - this is priority
        $template = locate_template(
            array(
                $template_path . $template_name,
                $template_name
            )
        );

        // Modification: Get the template from this plugin, if it exists
        if (!$template && file_exists( $plugin_path . $template_name )) {
            $template = $plugin_path . $template_name;
        }

        // Use default template
        if (!$template) {
            $template = $_template;
        }

        // Return what we found
        return $template;
    }

    public static function logitrail_get_template() {
        $settings = get_option('woocommerce_logitrail_shipping_settings');
        $args = array('useTestServer' => ($settings['test_server'] === 'yes' ? true : false));
        wc_get_template( 'checkout/form-logitrail.php', $args);
    }

    public function logitrail_get_form() {
        global $woocommerce, $post;

        $address = $woocommerce->customer->get_shipping_address();
        $city = $woocommerce->customer->get_shipping_city();
        $postcode = $woocommerce->customer->get_shipping_postcode();
        $country = $woocommerce->customer->get_shipping_country();

        $settings = get_option('woocommerce_logitrail_shipping_settings');

        $apic = new Logitrail\Lib\ApiClient();
        $test_server = ($settings['test_server'] === 'yes' ? true : false);
        $apic->useTest($test_server);

        $apic->setMerchantId($settings['merchant_id']);
        $apic->setSecretKey($settings['secret_key']);

        // FIXME: If there is any way of getting the shipment customer info here
        // use it (firstname, lastname)
        $apic->setCustomerInfo('', '', '', '', $address, $postcode, $city, '', $country);
        $unique_id = $woocommerce->session->get_customer_id();
        $apic->setOrderId($unique_id);

        if($this->debug_mode) {
            $this->logitrail_debug_log('Form, creating with data: ' . '""' . ', ' . '""' . ', ' . '""' . ', ' . '""' . ', ' .  $address . ', ' . $postcode . ', ' . $city);
        }

        $cartContent = $woocommerce->cart->get_cart();

        $shipping_count = 0;
        $total_sum = $woocommerce->cart->subtotal;

        foreach($cartContent as $cartItem) {
            /** @var WC_Product $product */
            $product = $cartItem['data'];

            $taxes = WC_Tax::find_rates(array(
                'city' => $city,
                'postcode' => $postcode,
                'country' => $country,
                'tax_class' => $product->get_tax_class()
            ));

            if(count($taxes) > 0) {
                $tax = array_shift($taxes)['rate'];
            }
            else {
                // TODO: Should merchant be informed of products without marked tax?
                $tax = 0;
            }
            if (function_exists('wc_get_price_including_tax')) {
                $price_including_tax = wc_get_price_including_tax($product);
            } else {
                $price_including_tax = $product->get_price_including_tax(); // Woocommerce < 2.7
            }
            if (!$this->logitrail_is_virtual($product) && $this->logitrail_shipping_enabled($product->get_id())) {
                $apic->addProduct(
                    $product->get_sku(),
                    $product->get_title(),
                    $cartItem['quantity'],
                    $product->get_weight() * 1000,
                    $price_including_tax,
                    $tax
                );
                $shipping_count++;
            }
            if ($this->logitrail_is_virtual($product, true) && $this->logitrail_shipping_enabled($product->get_id())) {
                $total_sum -= $price_including_tax;
            }

            if($this->debug_mode) {
                $this->logitrail_debug_log('Form, added product with data: ' . '""' . ', ' . '""' . ', ' . '""' . ', ' . '""' . ', ' .  $address . ', ' . $postcode . ', ' . $city);
            }
        }
        $lang = explode('_', get_locale())[0];
        if (!$lang) {
            $lang = 'fi'; // Defaults to finnish
        }
        $fields = array(
            'total_sum' => $total_sum
        );
        $form = $apic->getForm($lang, $fields);

        $unique_id = $woocommerce->session->get_customer_id();
        if ($shipping_count > 0) {
            set_transient('logitrail_' . $unique_id . '_shipping', true);
            echo $form;
        } else {
            set_transient('logitrail_' . $unique_id . '_shipping', false);
        }

        if($this->debug_mode) {
            $this->logitrail_debug_log('Form, returned via ajax');
        }

        wp_die();
    }

    /*
     * Set price via AJAX call
     */
    public function logitrail_set_price() {
        global $woocommerce;

        $unique_id = $woocommerce->session->get_customer_id();
        set_transient('logitrail_' . $unique_id . '_price', $_POST['postage']);
        set_transient('logitrail_' . $unique_id . '_order_id', $_POST['order_id']);
        set_transient('logitrail_' . $unique_id . '_type', $_POST['delivery_type']);

        if($this->debug_mode) {
            $this->logitrail_debug_log('Setting postage to ' . $_POST['postage']);

            $postage = get_transient('logitrail_' . $unique_id . '_price');

            $this->logitrail_debug_log('Confirming postage value as ' . $postage);
        }
    }

    public function logitrail_payment_complete($this_id) {
        global $woocommerce, $post;

        $order = new WC_Order($this_id);
        if ($order->get_status() == 'failed') {
            return;
        }

        $settings = get_option('woocommerce_logitrail_shipping_settings');

        $apic = new Logitrail\Lib\ApiClient();
        $test_server = ($settings['test_server'] === 'yes' ? true : false);
        $apic->useTest($test_server);

        $apic->setMerchantId($settings['merchant_id']);
        $apic->setSecretKey($settings['secret_key']);

        $unique_id = $woocommerce->session->get_customer_id();

        $order_id = get_transient('logitrail_' . $unique_id . '_order_id');
        $shippable = get_transient('logitrail_' . $unique_id . '_shipping');
        if (method_exists($order, 'get_shipping_first_name')) {
            $shipping_first_name = $order->get_shipping_first_name();
            $shipping_last_name = $order->get_shipping_last_name();
            $billing_phone = $order->get_billing_phone();
            $billing_email = $order->get_billing_email();
            $shipping_address_1 = $order->get_shipping_address_1();
            $shipping_address_2 = $order->get_shipping_address_2();
            $shipping_postcode = $order->get_shipping_postcode();
            $shipping_city = $order->get_shipping_city();
            $shipping_company = $order->get_shipping_company();
            $shipping_country = $order->get_shipping_country();
        } else {
            // Woocommerce < 2.7
            $shipping_first_name = $order->shipping_first_name;
            $shipping_last_name = $order->shipping_last_name;
            $billing_phone = $order->billing_phone;
            $billing_email = $order->billing_email;
            $shipping_address_1 = $order->shipping_address_1;
            $shipping_address_2 = $order->shipping_address_2;
            $shipping_postcode = $order->shipping_postcode;
            $shipping_city = $order->shipping_city;
            $shipping_company = $order->shipping_company;
            $shipping_country = $order->shipping_country;
        }
        if (!$shippable) {
            // Product is not shippable, do nothing
        } else if (!$order_id) {
            if ($this->debug_mode) {
                $this->logitrail_debug_log('Order confirmation failed with details: ' .
                    $shipping_first_name . ', ' .
                    $shipping_last_name . ', ' .
                    $billing_phone . ', ' .
                    $billing_email . ', ' .
                    $shipping_address_1 . ' ' .
                    $shipping_address_2 . ', ' .
                    $shipping_postcode . ', ' .
                    $shipping_city
                );
            }
            echo "<br><b style='color: red'>Tilauksen vahvistaminen epäonnistui, ota yhteyttä myyjään</b><br>";
        } else {
            // Order confirmation
            $apic->setOrderId($this_id);
            $apic->setCustomerInfo(
                $shipping_first_name,
                $shipping_last_name,
                $billing_phone,
                $billing_email,
                $shipping_address_1 . ' ' . $shipping_address_2,
                $shipping_postcode,
                $shipping_city,
                $shipping_company,
                $shipping_country
            );
            $apic->updateOrder($order_id);

            // TODO: handle failed confirmation (if possible?)
            $result = $apic->confirmOrder($order_id);
            // TODO: translate
            echo "<br>Voit seurata toimitustasi osoitteessa: <a href='" . $result['tracking_url'] . "' target='_BLANK'>" . $result['tracking_url'] . "</a><br>";

            if($this->debug_mode) {
                $this->logitrail_debug_log(
                    'Confirmed order ' . $order_id . 'with details: ' .
                    $shipping_first_name . ', ' .
                    $shipping_last_name . ', ' .
                    $billing_phone . ', ' .
                    $billing_email . ', ' .
                    $shipping_address_1 . ' ' .
                    $shipping_address_2 . ', ' .
                    $shipping_postcode . ', ' .
                    $shipping_city
                );
            }
        }
        delete_transient('logitrail_' . $unique_id . '_price');
        delete_transient('logitrail_' . $unique_id . '_order_id');
        delete_transient('logitrail_' . $unique_id . '_type');
        delete_transient('logitrail_' . $unique_id . '_shipping');
        unset(WC()->session->shipping_for_package);
    }

    /**
     * Update shipping price when order review is updated
     *
     * @global type $woocommerce
     */
    public static function logitrail_woocommerce_review_order_before_shipping() {
        global $woocommerce;

        // get the packages
        $packages = $woocommerce->shipping->get_packages();

        // go through each
        foreach ( $packages as $key => $package ) {
            // if the shipping is set, remove it
            if ( isset( $package['rates']['logitrail_shipping_postage'] ) ) {
                $unique_id = $woocommerce->session->get_customer_id();
                $package['rates']['logitrail_shipping_postage']->cost = get_transient('logitrail_' . $unique_id . '_price');
            }
        }

        // update the packages in the object
        $woocommerce->shipping->packages = $packages;
    }

    function logitrail_create_product($post_id) {
        if ( get_post_status ( $post_id ) == 'publish' ) {
            global $woocommerce;

            $settings = get_option( 'woocommerce_logitrail_shipping_settings' );

            $product = wc_get_product( $post_id );

            if ( ! $product ) {
                return;
            }
            // Add variable product children only when adding the parent
            if (property_exists($product, 'variation_id')) {
                return;
            }

            $apic        = new Logitrail\Lib\ApiClient();
            $test_server = ( $settings['test_server'] === 'yes' ? true : false );
            $apic->useTest( $test_server );

            $apic->setMerchantId( $settings['merchant_id'] );
            $apic->setSecretKey( $settings['secret_key'] );

            if ( ! $product->get_sku() && !$product->get_type() == 'variable' ) {
                $this->logitrail_set_error('SKU puuttuu tuotteesta "' . $product->get_title() . '". Tuotetta ei voitu viedä Logitrailin järjestelmään.');
            } else {
                if ($product->get_type() == 'variable') {
                    // Add variation products here
                    $parent = new WC_Product_Variable($product);
                    $children = $parent->get_children();
                    $sku_array = array();
                    foreach ($children as $child_id) {
                        /** @var WC_Product_Variable $child */
                        $child = wc_get_product($child_id);
                        $attributes = implode(' - ', $child->get_variation_attributes());
                        $child_title = $child->get_title() . ' - ' . $attributes;

                        if ( ! $child->get_sku() ) {
                            $this->logitrail_set_error('SKU puuttuu tuotteesta "' . $child_title . '". Tuotetta ei voitu viedä Logitrailin järjestelmään.');
                            continue;
                        }
                        if (in_array($child->get_sku(), $sku_array)) {
                            $this->logitrail_set_error(
                                'Tuotteen "' . $child_title . '" SKU "'. $child->get_sku() .'" on jo lisätty Logitrailiin, 
                                Tuotetta ei voitu viedä Logitrailin järjestelmään'
                            );
                            continue;
                        } else {
                            $sku_array[] = $child->get_sku();
                        }

                        if (function_exists('wc_get_price_including_tax')) {
                            $price_including_tax = wc_get_price_including_tax($child);
                        } else {
                            $price_including_tax = $child->get_price_including_tax(); // Woocommerce < 2.7
                        }
                        if (!$this->logitrail_is_virtual($child) && $this->logitrail_shipping_enabled($child->get_id())) {
                            $apic->addProduct(
                                $child->get_sku(),
                                $child_title,
                                1,
                                $child->get_weight() * 1000,
                                $price_including_tax,
                                0,
                                get_post_meta( $child->get_id(), 'barcode', true ),
                                $child->get_width() * 10,
                                $child->get_height() * 10,
                                $child->get_length() * 10
                            );
                        }
                    }
                } else {
                    if (function_exists('wc_get_price_including_tax')) {
                        $price_including_tax = wc_get_price_including_tax($product);
                    } else {
                        $price_including_tax = $product->get_price_including_tax(); // Woocommerce < 2.7
                    }
                    // weight for Logitrail goes in grams, dimensions in millimeter
                    if (!$this->logitrail_is_virtual($product) && $this->logitrail_shipping_enabled($product->get_id())) {
                        $apic->addProduct(
                            $product->get_sku(),
                            $product->get_title(),
                            1,
                            $product->get_weight() * 1000,
                            $price_including_tax,
                            0,
                            get_post_meta( $post_id, 'barcode', true ),
                            $product->get_width() * 10,
                            $product->get_height() * 10,
                            $product->get_length() * 10
                        );
                    }
                }

                $responses = $apic->createProducts();
                $errors    = 0;

                foreach ( $responses as $response ) {
                    if ( ! $response['success'] ) {
                        // if we have more than one product, don't report each
                        // separately, but just as a count
                        if ( count( $responses == 1 ) ) {
                            //wc_add_notice("Virhe siirrettäessä tuotetta Logitrailille", "notice");
                            $this->logitrail_set_error('Virhe siirrettäessä tuotetta Logitrailille.');
                        } else {
                            $errors ++;
                        }
                    }
                }

                if ( count( $responses > 1 ) && $errors > 0 ) {
                    //wc_add_notice("Virhe " . $errors . " tuotteen kohdalla siirrettäessä " . count('$responses') . " tuotetta Logitrailille");
                }

                if ( $this->debug_mode ) {
                    if (function_exists('wc_get_price_including_tax')) {
                        $price_including_tax = wc_get_price_including_tax($product);
                    } else {
                        $price_including_tax = $product->get_price_including_tax(); // Woocommerce < 2.7
                    }
                    $this->logitrail_debug_log( 'Added product with info: ' . $product->get_sku() . ', ' . $product->get_title() . ', ' . 1 . ', ' . $product->get_weight() * 1000 . ', ' . $price_including_tax . ', ' . 0 . ', ' . get_post_meta( $post_id, 'barcode', true ) . ', ' . $product->get_width() * 10 . ', ' . $product->get_height() * 10 . ', ' . $product->get_length() * 10 );
                }
            }
        }
    }

    /**
     * Set error for the user
     *
     * @param string $message
     */
    function logitrail_set_error($message) {
        $notifications = get_transient('logitrail_' . wp_get_current_user()->ID . '_notifications');
        $notifications[] =
            array(
                'class' => 'notice notice-error',
                'message' => $message
            );

        set_transient('logitrail_' . wp_get_current_user()->ID . '_notifications', $notifications);
    }

    /**
     * If not in cart, inform user shipping price can only be calculated in cart page.
     *
     * @param type $label
     * @param type $method
     * @return string
     */
    function logitrail_remove_label($label, $method) {
        if(is_cart()) {
            return 'Laskenta tarvitsee osoitetiedot.';
        }

        return $label;
    }

    /**
     * Export all current products to Logitrail.
     * Called via AJAX
     */
    public function export_products() {
        $settings = get_option('woocommerce_logitrail_shipping_settings');

        $apic = new Logitrail\Lib\ApiClient();
        $test_server = ($settings['test_server'] === 'yes' ? true : false);
        $apic->useTest($test_server);

        $apic->setMerchantId($settings['merchant_id']);
        $apic->setSecretKey($settings['secret_key']);

        $productsAdded = 0;
        $productsAddedTotal = 0;
        $loop = new WP_Query( array( 'post_type' => array('product'), 'posts_per_page' => -1 ) );
        $errors = 0;
        $sku_array = array();
        while($loop->have_posts()) {
            $loop->the_post();

            $post_id = get_the_ID();
            $product = wc_get_product($post_id);

            if ($product->get_type() == 'variable') {
                // Add variation products here
                $parent = new WC_Product_Variable($product);
                $children = $parent->get_children();
                foreach ($children as $child_id) {
                    /** @var WC_Product_Variable $child */
                    $child = wc_get_product($child_id);
                    $attributes = implode(' - ', $child->get_variation_attributes());
                    $child_title = $child->get_title() . ' - ' . $attributes;

                    if ( ! $child->get_sku() ) {
                        $this->logitrail_set_error('SKU puuttuu tuotteesta "' . $child_title . '". Tuotetta ei voitu viedä Logitrail-järjestelmään.');
                        $errors++;
                        continue;
                    }
                    if (in_array($child->get_sku(), $sku_array)) {
                        $this->logitrail_set_error('Tuotteen "' . $child_title . '" SKU "'. $child->get_sku() .'" on jo lisätty Logitrailiin. Tuotetta ei voitu viedä Logitrail-järjestelmään.');
                        $errors++;
                        continue;
                    } else {
                        $sku_array[] = $child->get_sku();
                    }

                    if (function_exists('wc_get_price_including_tax')) {
                        $price_including_tax = wc_get_price_including_tax($child);
                    } else {
                        $price_including_tax = $child->get_price_including_tax(); // Woocommerce < 2.7
                    }
                    if (!$this->logitrail_is_virtual($child) && $this->logitrail_shipping_enabled($child->get_id())) {
                        $apic->addProduct(
                            $child->get_sku(),
                            $child_title,
                            1,
                            $child->get_weight() * 1000,
                            $price_including_tax,
                            0,
                            get_post_meta( $child->get_id(), 'barcode', true ),
                            $child->get_width() * 10,
                            $child->get_height() * 10,
                            $child->get_length() * 10
                        );
                        $productsAdded++;
                        $productsAddedTotal++;
                    }
                }
            } else {
                if (!$this->logitrail_is_virtual($product) && $this->logitrail_shipping_enabled($product->get_id())) {
                    if (in_array($product->get_sku(), $sku_array)) {
                        $this->logitrail_set_error('Tuotteen "' . $product->title . '" SKU "'. $product->get_sku() .'" on jo lisätty. Tuotetta ei voitu viedä Logitrail-järjestelmään.');
                        $errors++;
                        continue;
                    }
                    if (function_exists('wc_get_price_including_tax')) {
                        $price_including_tax = wc_get_price_including_tax($product);
                    } else {
                        $price_including_tax = $product->get_price_including_tax(); // Woocommerce < 2.7
                    }
                    // weight for Logitrail goes in grams, dimensions in millimeter
                    $apic->addProduct(
                        $product->get_sku(),
                        $product->get_title(),
                        1,
                        $product->get_weight() * 1000,
                        $price_including_tax,
                        0,
                        null,
                        $product->get_width() * 10,
                        $product->get_height() * 10,
                        $product->get_length() * 10
                    );
                    $productsAdded++;
                    $productsAddedTotal++;
                }
            }

            if($this->debug_mode) {
                if (function_exists('wc_get_price_including_tax')) {
                    $price_including_tax = wc_get_price_including_tax($product);
                } else {
                    $price_including_tax = $product->get_price_including_tax(); // Woocommerce < 2.7
                }
                $this->logitrail_debug_log('Added product with info: ' . $product->get_sku() . ', ' . $product->get_title() . ', ' . 1 . ', ' . $product->get_weight() * 1000 . ', ' . $price_including_tax . ', ' . 0 . ', ' . get_post_meta($post_id, 'barcode', true) . ', ' . $product->get_width() * 10 . ', ' . $product->get_height() * 10 . ', ' . $product->get_length() * 10);
            }

            // create products in batches of (about) 5, so in big shops we don't get
            // huge amount of products taking memory in ApiClient
            // Remaining products will be added after the loop
            if($productsAdded >= 5) {
                // TODO: Add error handling/reposting when Logitrail's errors are sorted out, ie. they don't send HTML instead of JSON on error
                $response = $apic->createProducts();
                $apic->clearProducts();
                $productsAdded = 0;

                if($this->debug_mode) {
                    $this->logitrail_debug_log('Created or updated added products and emptied products list,');
                }
            }
        }

        $response = $apic->createProducts();
        $apic->clearProducts();

        if($this->debug_mode) {
            $this->logitrail_debug_log('Created or updated added products and emptied products list,');
        }

        wp_reset_query();

        if (!$errors) {
            $notifications = get_transient('logitrail_' . wp_get_current_user()->ID . '_notifications');
            $notifications[] =
                array(
                    'class' => 'updated notice notice-success is-dismissible',
                    'message' => 'Tuotteet viety Logitrail-järjestelmään.'
                );

            set_transient('logitrail_' . wp_get_current_user()->ID . '_notifications', $notifications);
        }
    }

    public static function logitrail_add_barcode() {
        global $post;
        woocommerce_wp_text_input(
            array(
                'id' => 'barcode['.$post->ID.']',
                'label' => __( 'Barcode', 'woocommerce' ),
                'placeholder' => 'barcode here',
                'desc_tip' => 'true',
                'description' => __( 'Product barcode.', 'woocommerce' ),
                'value' => get_post_meta( $post->ID, 'barcode', true )
            )
        );
    }

    public static function logitrail_add_enable_shipping() {
        global $post;
        $value = Logitrail_WooCommerce::logitrail_get_shipping($post->ID);
        woocommerce_wp_checkbox(
            array(
                'id' => 'logitrail_enable_shipping',
                'label' => __( 'Enable shipping via Logitrail', 'woocommerce' ),
                'cbvalue' => true,
                'value' => $value,
                'desc_tip' => 'true',
                'description' => __( 'Use logitrail to handle shipping', 'woocommerce' )
            )
        );
    }

    function logitrail_variation_settings_fields( $loop, $variation_data, $variation ) {
        $parent_barcode = get_post_meta( $variation->post_parent, 'barcode', true);
        woocommerce_wp_text_input(
            array(
                'id'          => 'barcode[' . $variation->ID . ']',
                'label'       => __( 'Barcode', 'woocommerce' ),
                'placeholder' => $parent_barcode,
                'desc_tip'    => 'true',
                'description' => __( 'Enter the product barcode or leave blank to use the parent product barcode', 'woocommerce' ),
                'value'       => get_post_meta( $variation->ID, 'barcode', true )
            )
        );
    }

    /**
     * Get logitrail_enable_shipping option, defaults as true
     * @param $product_id
     * @return bool
     */
    public static function logitrail_get_shipping($product_id) {
        $shipping = get_post_meta($product_id, 'logitrail_enable_shipping', true);
        if ( $shipping || $shipping === "" ) {
            return true;
        } else {
            return false;
        }
    }

    function logitrail_barcode_save($post_id){
        // Saving Barcode
        $barcode = $_POST['barcode'][$post_id];
        if( !empty($barcode) ) {
            update_post_meta( $post_id, 'barcode', esc_attr( $barcode ) );
        }
        else {
            update_post_meta( $post_id, 'barcode', esc_attr( $barcode ) );
        }
    }

    function logitrail_enable_shipping_save($post_id) {
        if( !empty($_POST['logitrail_enable_shipping']) ) {
            update_post_meta( $post_id, 'logitrail_enable_shipping', esc_attr( $_POST['logitrail_enable_shipping'] ) );
        } else {
            update_post_meta( $post_id, 'logitrail_enable_shipping', "0" );
        }
    }

    function logitrail_save_variation_settings_fields( $post_id ) {
        $text_field = $_POST['barcode'][ $post_id ];
        if( !empty( $text_field ) ) {
            update_post_meta( $post_id, 'barcode', esc_attr( $text_field ) );
        }
    }

    function clear_wc_shipping_rates_cache(){
        // unset to force recalculation of cart total price when
        // shipping price is changed

        if($this->debug_mode) {
            $rates26 = array();
        }

        // WooCommerce 2.6 cache
        $packages = WC()->cart->get_shipping_packages();
        foreach ($packages as $key => $value) {
            $shipping_session = "shipping_for_package_$key";

            unset(WC()->session->$shipping_session);

            if($this->debug_mode) {
                $rates26[] = print_r(WC()->session->$shipping_session, true);
            }

        }

        // WooCommerce 2.5 cache
        unset(WC()->session->shipping_for_package);

        if($this->debug_mode) {
            $this->logitrail_debug_log('Emptied caches: 2.5 (' . print_r(WC()->session->shipping_for_package, true) . '), 2.6 (' . implode(', ', $rates26) . ') ' . $_POST['postage']);
        }
    }

    function logitrail_notifications() {
        $notifications = get_transient('logitrail_' . wp_get_current_user()->ID . '_notifications');
        if (!$notifications) {
            return;
        }

        foreach($notifications as $notification) {
            printf( '<div class="%1$s"><p>%2$s</p></div>', $notification['class'], $notification['message'] );
        }

        set_transient('logitrail_' . wp_get_current_user()->ID . '_notifications', array());
    }



    // Functions related to debug logs

    public static function get_debug_log() {
        global $wpdb;

        $sql = "SELECT * FROM `" . self::$tables['debug'] . "` ORDER BY created_at DESC LIMIT 100";
        $results = $wpdb->get_results($sql, ARRAY_A);

        $lines = array();
        foreach($results as $id => $row) {
            $lines[] = '<b>' . Date('d.m.Y H:i:s', $row['created_at']) . "</b> {$row['operation']}";
        }

        wp_send_json($lines);
    }

    public static function clear_debug_log() {
        global $wpdb;

        $delete = $wpdb->query("TRUNCATE TABLE `" . self::$tables['debug'] . "`");

        wp_send_json(array('status' => 'success'));
    }

    public static function logitrail_debug_log($text) {
        global $wpdb;

        $wpsess = 'aa'; //WP_Session_Tokens::get();
        $wpdb->insert( self::$tables['debug'], array('session' => $wpsess, 'operation' => $text, 'created_at' => time()) );
    }

    public static function logitrail_install() {
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        $tables = self::$tables;
        $settings = get_option('woocommerce_logitrail_shipping_settings');

        dbDelta( "CREATE TABLE {$tables['debug']} (
                  id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  session varchar(255) NOT NULL,
                  operation varchar(255) NOT NULL,
                  created_at int NOT NULL
                ) ENGINE='InnoDB'" );

        dbDelta( "CREATE TABLE {$tables['log']} (
                  id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  event_id varchar(255) NOT NULL,
                  event_type varchar(255) NOT NULL,
                  webhook_id varchar(255) NOT NULL,
                  timestamp int NOT NULL,
                  retry_count int NOT NULL,
                  payload text NOT NULL
                ) ENGINE='InnoDB'" );

        $username = wp_generate_password(8, false);
        $password = wp_generate_password(12, false);

        if (!( isset($settings['webhook_username']) && !$settings['webhook_username'] == "" ) ||
        !( isset($settings['webhook_password']) && !$settings['webhook_password'] == "" )) {
            if (!isset($settings['webhook_username']) || $settings['webhook_password'] == "") {
                $settings['webhook_username'] = $username;
            }
            if (!isset($settings['webhook_username']) || $settings['webhook_password'] == "") {
                $settings['webhook_password'] = $password;
            }
            update_option('woocommerce_logitrail_shipping_settings', $settings);
        }

        update_option( 'logitrail_db_version', self::$db_version );
    }

    function logitrail_update_db_check() {
        if ( get_option( 'logitrail_db_version' ) != self::$db_version ) {
            $this->logitrail_install();
        }
    }

    public static function logitrail_uninstall() {
        global $wpdb;
        $wpdb->query("DROP TABLE `" . self::$tables['debug'] . "`");
        $wpdb->query("DROP TABLE `" . self::$tables['log'] . "`");
    }

    function register_api_hooks() {
        register_rest_route( 'logitrail', '/update/', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_product'),
        ) );
    }

    function update_product() {
        global $wpdb;
        $apic = new Logitrail\Lib\ApiClient();
        $hash = explode(' ', getallheaders()['Authorization'])[1];
        $auth = explode(':', base64_decode($hash));
        $settings = get_option('woocommerce_logitrail_shipping_settings');

        if ($auth[0] == $settings['webhook_username'] && $auth[1] == $settings['webhook_password']) {
            $received_data = $apic->processWebhookData(file_get_contents('php://input'));

            if ($received_data) {
                $wpdb->insert(self::$tables['log'], array(
                    'event_id' => $received_data['event_id'],
                    'event_type' => $received_data['event_type'],
                    'webhook_id' => $received_data['webhook_id'],
                    'timestamp' => $received_data['ts'],
                    'retry_count' => $received_data['retry_count'],
                    'payload' => json_encode($received_data['payload'])
                ));

                $payload = $received_data['payload'];

                switch($received_data['event_type']) {
                    case "product.inventory.change":
                        $product = $payload['product'];
                        $available = (int)$payload['inventory']['available'];

                        $wc_product = wc_get_product_id_by_sku($product['merchants_id']);

                        // Product Bundles support
                        if (function_exists('wc_pb_get_bundled_product_map')) {
                            $parents = wc_pb_get_bundled_product_map($wc_product);
                            foreach ($parents as $parent_id) {
                                $args = array(
                                    'bundle_id' => $parent_id,
                                    'return'     => 'id=>product_id'
                                );

                                $bundle_quantity = wc_get_product($parent_id)->get_stock_quantity();
                                if (is_null($bundle_quantity)) {
                                    continue;
                                }
                                $children = WC_PB_DB::query_bundled_items( $args );
                                $quantities = array();
                                foreach ($children as $child_id) {
                                    $child = wc_get_product($child_id);
                                    if (!is_null($child->get_stock_quantity())) {
                                        $quantities[] = $child->get_stock_quantity();
                                    }
                                }
                                if (count($quantities) > 0 && min($quantities) < $bundle_quantity) {
                                    wc_update_product_stock($parent_id, min($quantities));
                                }
                            }
                        }

                        wc_update_product_stock($wc_product, $available);
                        break;
                    case "order.shipped":
                        $order_id = $payload['order']['merchants_order']['id'];
                        $order = WC_Order_Factory::get_order($order_id);
                        if (!$order) {
                            if($this->debug_mode) {
                                $this->logitrail_debug_log('Webhook order.shipped failed, order id ' . $order_id . ' not found');
                            }
                            break;
                        }
                        $order->update_status('completed');
                        break;
                }
            }
        }
    }

    /**
     * @param WC_Product $product
     * @param bool $ignore_bundled
     * @return bool
     */
    public function logitrail_is_virtual($product, $ignore_bundled = false) {
        // We don't want to ship virtual products, but bundled products are virtual so exclude them
        if ( $product->is_virtual() && (!property_exists($product, 'bundled_value') || $ignore_bundled) ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Check if product is shippable
     *
     * @param int $product_id
     * @return bool
     */
    public static function logitrail_shipping_enabled($product_id) {
        $product = wc_get_product( $product_id );
        // Disable shipping on bundle products
        if ( in_array($product->get_type(), array("bundle")) ) {
            return false;
        }
        return self::logitrail_get_shipping($product_id);
    }
}

new Logitrail_WooCommerce();
