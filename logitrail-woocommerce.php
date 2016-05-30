<?php

/*
    Plugin Name: Logitrail
    Description: Integrate checkout shipping with Logitrail
    Version: 0.0.1
    Author: Petri Kanerva petri@codaone.fi
*/

if(!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Require the Logitrail ApiClient
require_once( 'includes/ApiClient.php' );

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

        private $merchant_id;
        private $secret_key;

    /**
     * Constructor
     */
    public function __construct() {
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'wf_plugin_action_links' ) );
        add_action( 'woocommerce_shipping_init', array( $this, 'logitrail_shipping_init') );
        add_filter( 'woocommerce_shipping_methods', array( $this, 'logitrail_add_method') );

        add_action( 'woocommerce_checkout_after_customer_details', array( $this, 'logitrail_get_template') );
        add_action( 'woocommerce_payment_complete', array( 'Logitrail_WooCommerce', 'logitrail_payment_complete'), 10, 1 );

        add_action( 'woocommerce_order_status_completed', array( 'Logitrail_WooCommerce', 'logitrail_payment_complete'), 10, 1 );

        add_action( 'wc_ajax_logitrail', array( 'Logitrail_WooCommerce', 'logitrail_get_form' ) );
        add_action( 'wc_ajax_logitrail_setprice', array( 'Logitrail_WooCommerce', 'logitrail_set_price' ) );

        add_filter( 'woocommerce_locate_template', array($this, 'logitrail_woocommerce_locate_template'), 10, 3 );

	add_action( 'woocommerce_review_order_before_shipping', array($this, 'logitrail_woocommerce_review_order_before_shipping'), 5, 1 );

	add_action( 'post_updated', array($this, 'logitrail_create_product'));
    }

    /**
     * Plugin page links
     */
    public function wf_plugin_action_links( $links ) {
        $plugin_links = array(
            '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=shipping&section=logitrail_shipping' ) . '">' . __( 'Settings', 'ups-woocommerce-shipping' ) . '</a>',
        );

        return array_merge( $plugin_links, $links );
    }

    /**
     * wc_ups_init function.
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

	$plugin_path  = $this->logitrail_plugin_path() . '/woocommerce/';

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

    public static function logitrail_get_form() {
        global $woocommerce, $post;

        $address = $woocommerce->customer->get_shipping_address();
        $city = $woocommerce->customer->get_shipping_city();
        $postcode = $woocommerce->customer->get_shipping_postcode();

        $settings = get_option('woocommerce_logitrail_shipping_settings');

        $apic = new Logitrail\Lib\ApiClient();
	$test_server = ($settings['test_server'] === 'yes' ? true : false);
	$apic->useTest($test_server);

        $apic->setMerchantId($settings['merchant_id']);
        $apic->setSecretKey($settings['secret_key']);
	// FIXME: If there is any way of getting the shipment customer info here
	// use it (firstname, lastname)
        $apic->setCustomerInfo('', '', '', '', $address, $postcode, $city);

        $apic->setOrderId($woocommerce->session->get_session_cookie()[3]);

        $cartContent = $woocommerce->cart->get_cart();

        foreach($cartContent as $cartItem) {
	    // FIXME: add tax percentage
            $apic->addProduct($cartItem['data']->get_sku(), $cartItem['data']->get_title(), $cartItem['quantity'], $cartItem['data']->get_weight() * 1000, $cartItem['data']->get_price(), 0);
        }

        $form = $apic->getForm();
        echo $form;
        wp_die();
    }

    public static function logitrail_set_price() {
        global $woocommerce;

        set_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_price', $_POST['postage']);
        set_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_order_id', $_POST['order_id']);
    }

    public static function logitrail_payment_complete($this_id) {
	global $woocommerce, $post;

        $settings = get_option('woocommerce_logitrail_shipping_settings');

	$order = new WC_Order($this_id);

	$apic = new Logitrail\Lib\ApiClient();
	$test_server = ($settings['test_server'] === 'yes' ? true : false);
	$apic->useTest($test_server);

        $apic->setMerchantId($settings['merchant_id']);
        $apic->setSecretKey($settings['secret_key']);

        $order_id = get_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_order_id');

	$apic->setOrderId($this_id);
	$apic->setCustomerInfo($order->shipping_first_name, $order->shipping_last_name, $order->billing_phone, $order->billing_email, $order->shipping_address_1 . ' ' . $order->shipping_address_2, $order->shipping_postcode, $order->shipping_city);
	$apic->updateOrder($order_id);

	// TODO: handle failed confirmation (if possible?)
        $result = $apic->confirmOrder($order_id);

	echo "<br />Voit seurata toimitustasi osoitteessa: <a href='" . $result->tracking_url . "' target='_BLANK'>" . $result->tracking_url . "</a><br />";

        delete_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_order_id');
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
		$package['rates']['logitrail_shipping_postage']->cost = get_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_price');
	    }
	}

	// update the packages in the object
	$woocommerce->shipping->packages = $packages;
    }

    function logitrail_create_product($post_id) {
	$settings = get_option('woocommerce_logitrail_shipping_settings');

	$product = wc_get_product($post_id);

	$apic = new Logitrail\Lib\ApiClient();
	$test_server = ($settings['test_server'] === 'yes' ? true : false);
	$apic->useTest($test_server);

        $apic->setMerchantId($settings['merchant_id']);
        $apic->setSecretKey($settings['secret_key']);

	// weight for Logitrail goes in grams, dimensions in millimeter
	// FIXME: get tax percentage
	$apic->addProduct($product->get_sku(), $product->get_title(), 1, $product->get_weight() * 1000, $product->get_price(), 0, null, $product->get_width() * 10, $product->get_height() * 10, $product->get_length() * 10);

	$response = $apic->createProducts();
    }
}

new Logitrail_WooCommerce();
