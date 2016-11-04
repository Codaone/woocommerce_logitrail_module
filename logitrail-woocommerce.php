<?php

/*
    Plugin Name: Logitrail
    Description: Integrate checkout shipping with Logitrail
    Version: 0.0.14
    Author: <a href="mailto:petri@codaone.fi">Petri Kanerva</a> | <a href="http://www.codaone.fi/">Codaone Oy</a>
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

    protected static $db_version = '0.1';
	protected static $tables = array(
        'debug' => 'logitrail_debug'
    );

	private $merchant_id;
	private $secret_key;

    private $debug_mode;

    /**
     * Constructor
     */
    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'logitrail_install'));
        register_uninstall_hook(__FILE__, array($this, 'logitrail_uninstall'));

        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'wf_plugin_action_links' ) );
        add_action( 'woocommerce_shipping_init', array( $this, 'logitrail_shipping_init') );
        add_filter( 'woocommerce_shipping_methods', array( $this, 'logitrail_add_method') );

        add_action( 'woocommerce_checkout_shipping', array($this, 'logitrail_get_template') );
        add_action( 'woocommerce_payment_complete', array($this, 'logitrail_payment_complete'), 10, 1 );

        add_action( 'woocommerce_order_status_completed', array($this, 'logitrail_payment_complete'), 10, 1 );

        add_action( 'wc_ajax_logitrail', array($this, 'logitrail_get_form' ) );
        add_action( 'wc_ajax_logitrail_setprice', array($this, 'logitrail_set_price'));
        add_action( 'wc_ajax_logitrail_export_products', array( $this, 'export_products' ) );

        add_action( 'wc_ajax_logitrail_debug_log', array( $this, 'get_debug_log' ) );
        add_action( 'wc_ajax_logitrail_debug_log_clear', array( $this, 'clear_debug_log' ) );

		add_action( 'parse_request', array( $this, 'handle_product_import' ), 0 );

        add_filter( 'woocommerce_locate_template', array($this, 'logitrail_woocommerce_locate_template'), 10, 3 );

		add_action( 'woocommerce_review_order_before_shipping', array($this, 'logitrail_woocommerce_review_order_before_shipping'), 5, 1 );

		add_action( 'save_post', array($this, 'logitrail_create_product'), 10, 2);

		add_filter( 'woocommerce_cart_shipping_method_full_label', array($this, 'logitrail_remove_label'), 10, 2 );

		// essentially disable WooCommerce's shipping rates cache
		add_filter( 'woocommerce_checkout_update_order_review', array($this, 'clear_wc_shipping_rates_cache'), 10, 2);

		// extra barcode field stuff
		add_action( 'woocommerce_product_options_general_product_data', array($this, 'logitrail_add_barcode'));
		add_action( 'woocommerce_process_product_meta', array($this, 'logitrail_barcode_save'));

		add_action( 'admin_notices', array($this, 'logitrail_notifications'));

        add_action('woocommerce_after_checkout_validation', array(&$this, 'validate_shipping_method'));

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
        $shipping_method = get_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_type');
        if (!$shipping_method) {
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
        $apic->setCustomerInfo('', '', '', '', $address, $postcode, $city);
        $apic->setOrderId($woocommerce->session->get_session_cookie()[3]);

        if($this->debug_mode) {
            $this->logitrail_debug_log('Form, creating with data: ' . '""' . ', ' . '""' . ', ' . '""' . ', ' . '""' . ', ' .  $address . ', ' . $postcode . ', ' . $city);
        }

        $cartContent = $woocommerce->cart->get_cart();

        foreach($cartContent as $cartItem) {
			$taxes = WC_Tax::find_rates(array(
				'city' => $city,
				'postcode' => $postcode,
				'country' => $country,
				'tax_class' => $cartItem['data']->get_tax_class()
			));

			if(count($taxes) > 0) {
				$tax = array_shift($taxes)['rate'];
			}
			else {
				// TODO: Should merchant be informed of products without marked tax?
				$tax = 0;
			}

			$apic->addProduct($cartItem['data']->get_sku(), $cartItem['data']->get_title(), $cartItem['quantity'], $cartItem['data']->get_weight() * 1000, $cartItem['data']->get_price_including_tax(), $tax);

            if($this->debug_mode) {
                $this->logitrail_debug_log('Form, added product with data: ' . '""' . ', ' . '""' . ', ' . '""' . ', ' . '""' . ', ' .  $address . ', ' . $postcode . ', ' . $city);
            }

        }

        $form = $apic->getForm();
        echo $form;

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

        set_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_price', $_POST['postage']);
        set_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_order_id', $_POST['order_id']);
        set_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_type', $_POST['delivery_type']);

        if($this->debug_mode) {
            $this->logitrail_debug_log('Setting postage to ' . $_POST['postage']);

            $postage = get_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_price');

            $this->logitrail_debug_log('Confirming postage value as ' . $postage);
        }
    }

    public function logitrail_payment_complete($this_id) {
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
		// TODO: translate
		echo "<br />Voit seurata toimitustasi osoitteessa: <a href='" . $result['tracking_url'] . "' target='_BLANK'>" . $result['tracking_url'] . "</a><br />";

        if($this->debug_mode) {
            $this->logitrail_debug_log('Confirmed order ' . $order_id . 'with details: ' . $order->shipping_first_name . ', ' . $order->shipping_last_name . ', ' . $order->billing_phone . ', ' . $order->billing_email . ', ' . $order->shipping_address_1 . ' ' . $order->shipping_address_2 . ', ' . $order->shipping_postcode . ', ' . $order->shipping_city);
        }

		delete_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_price');
		delete_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_order_id');
		delete_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_type');
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
				$package['rates']['logitrail_shipping_postage']->cost = get_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_price');
			}
		}

		// update the packages in the object
		$woocommerce->shipping->packages = $packages;
    }

	public static function handle_product_import() {
		$received_data = '{"event_id":"57761f66cd2f27b12d8b45cf","webhook_id":"5763d8c33e250d3a548b4568","event_type":"product.inventory.change","ts":"2016-07-01T07:44:38+00:00","retry_count":0,"payload":'
							. '{'
								. '"product":{"id":"573ed8d63e250d0b5f8b4567","merchants_id":"7","name":"Puteli","weight":20,"dimensions":[33,55,22],"gtin":"545"},'
								. '"product2":{"id":"573ed8d63e250d0b5f8b4567","merchants_id":"7","name":"Auteli","weight":20,"dimensions":[33,55,22],"gtin":"545"}'
							. '}'
						. '}';
		$received_data = json_decode($received_data);

		switch($received_data->event_type) {
			case "product.inventory.change":
				foreach ($received_data->payload as $product) {
					// FIXME: Parts of webhooks implementation is missing from
					// Logitrail. Finish this side of them after finalization.

					//wc_update_product_stock($product->merchants_id/*, NEW STOCK AMOUNT*/);
				}
				break;
		}
	}

	function logitrail_create_product($post_id) {
		global $woocommerce;

		$settings = get_option('woocommerce_logitrail_shipping_settings');

		$product = wc_get_product($post_id);

		if(!$product) {
			return;
		}

		$apic = new Logitrail\Lib\ApiClient();
		$test_server = ($settings['test_server'] === 'yes' ? true : false);
		$apic->useTest($test_server);

		$apic->setMerchantId($settings['merchant_id']);
		$apic->setSecretKey($settings['secret_key']);

		if(!$product->get_sku()) {
			$notifications = get_transient('logitrail_' . wp_get_current_user()->ID . '_notifications');
			$notifications[] =
				array(
					'class' => 'notice notice-error',
					'message' => 'SKU puuttuu tuotteesta "' . $product->get_title() . '". Tuotetta ei voitu viedä Logitrailin järjestelmään.'
				);

	        set_transient('logitrail_' . wp_get_current_user()->ID . '_notifications', $notifications);
		}
		else {
			// weight for Logitrail goes in grams, dimensions in millimeter
			$apic->addProduct($product->get_sku(), $product->get_title(), 1, $product->get_weight() * 1000, $product->get_price_including_tax(), 0, get_post_meta($post_id, 'barcode', true), $product->get_width() * 10, $product->get_height() * 10, $product->get_length() * 10);

			$responses = $apic->createProducts();
			$errors = 0;

			foreach($responses as $response) {
				if(!$response['success']) {
					// if we have more than one product, don't report each
					// separately, but just as a count
					if(count($responses == 1)) {
						//wc_add_notice("Virhe siirrettäessä tuotetta Logitrailille", "notice");
						$notifications = get_transient('logitrail_' . wp_get_current_user()->ID . '_notifications');
						$notifications[] =
							array(
								'class' => 'notice notice-error',
								'message' => 'Virhe siirrettäessä tuotetta Logitrailille.'
							);

						set_transient('logitrail_' . wp_get_current_user()->ID . '_notifications', $notifications);
					}
					else {
						$errors++;
					}
				}
			}

			if(count($responses > 1) && $errors > 0) {
				//wc_add_notice("Virhe " . $errors . " tuotteen kohdalla siirrettäessä " . count('$responses') . " tuotetta Logitrailille");
			}

            if($this->debug_mode) {
                $this->logitrail_debug_log('Added product with info: ' . $product->get_sku() . ', ' . $product->get_title() . ', ' . 1 . ', ' . $product->get_weight() * 1000 . ', ' . $product->get_price_including_tax() . ', ' . 0 . ', ' . get_post_meta($post_id, 'barcode', true) . ', ' . $product->get_width() * 10 . ', ' . $product->get_height() * 10 . ', ' . $product->get_length() * 10);
            }
		}
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
	 * Export all vurrent products to Logitrail.
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
		while($loop->have_posts()) {
			$loop->the_post();

			$post_id = get_the_ID();
			$product = wc_get_product($post_id);

			// weight for Logitrail goes in grams, dimensions in millimeter
			$apic->addProduct($product->get_sku(), $product->get_title(), 1, $product->get_weight() * 1000, $product->get_price_including_tax(), 0, null, $product->get_width() * 10, $product->get_height() * 10, $product->get_length() * 10);

            if($this->debug_mode) {
                $this->logitrail_debug_log('Added product with info: ' . $product->get_sku() . ', ' . $product->get_title() . ', ' . 1 . ', ' . $product->get_weight() * 1000 . ', ' . $product->get_price_including_tax() . ', ' . 0 . ', ' . get_post_meta($post_id, 'barcode', true) . ', ' . $product->get_width() * 10 . ', ' . $product->get_height() * 10 . ', ' . $product->get_length() * 10);
            }

			// create products in batches of 5, so in big shops we don't get
			// huge amount of products taking memory in ApiClient
			$productsAdded++;
			$productsAddedTotal++;
			if($productsAdded > 5) {
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
	}

	public static function logitrail_add_barcode() {
		woocommerce_wp_text_input(
			array(
				'id' => 'barcode',
				'label' => __( 'Barcode', 'woocommerce' ),
				'placeholder' => 'barcode here',
				'desc_tip' => 'true',
				'description' => __( 'Product barcode.', 'woocommerce' )
			)
		);
	}

	function logitrail_barcode_save($post_id){

		// Saving Barcode
		$barcode = $_POST['barcode'];
		if( !empty($barcode) ) {
			update_post_meta( $post_id, 'barcode', esc_attr( $barcode ) );
		}
		else {
			update_post_meta( $post_id, 'barcode', esc_attr( $barcode ) );
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

        dbDelta( "CREATE TABLE IF NOT EXISTS `{$tables['debug']}` (
                  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  `session` varchar(255) NOT NULL,
                  `operation` varchar(255) NOT NULL,
                  `created_at` int NOT NULL
                ) ENGINE='InnoDB'" );

        add_option( 'logitrail_db_version', self::$db_version );
    }

    public static function logitrail_uninstall() {
        $delete = $wpdb->query("DROP TABLE `" . self::$tables['debug'] . "`");
    }
}

new Logitrail_WooCommerce();
