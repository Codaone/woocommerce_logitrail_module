<?php
/**
 * Logitrail_Shipping class.
 *
 * @extends WC_Shipping_Method
 */

// Require the Logitrail ApiClient
require_once( 'ApiClient.php' );

class Logitrail_Shipping extends WC_Shipping_Method {

    /**
     * __construct function.
     *
     * @access public
     * @return void
     */
    public function __construct() {
	$this->id                 = LOGITRAIL_ID;
	$this->method_title       = __( 'Logitrail', 'logitrail-woocommerce' );
	$this->method_description = __( 'The Logitrail extension informs Logitrail of the order and retrieves shipping fee during checkout.', 'logitrail-woocommerce' );

	$lt_settings 		= get_option( 'woocommerce_'.LOGITRAIL_ID.'_settings', null );

	$this->init();
    }

    /**
     * init function.
     *
     * @access public
     * @return void
     */
    private function init() {
		global $woocommerce;
		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->enabled			= isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : $this->enabled;
		$this->title			= isset( $this->settings['title'] ) ? $this->settings['title'] : $this->method_title;

		$this->merchant_id         	= isset( $this->settings['merchant_id'] ) ? $this->settings['merchant_id'] : '';
		$this->secret_key        	= isset( $this->settings['secret_key'] ) ? $this->settings['secret_key'] : '';
		$this->negotiated      		= isset( $this->settings['negotiated'] ) && $this->settings['negotiated'] == 'yes' ? true : false;

		$this->fallback		   	= !empty( $this->settings['fallback'] ) ? $this->settings['fallback'] : '';
		$this->test_server		= !empty( $this->settings['test_server'] ) ? $this->settings['test_server'] : '';
		$this->debug_mode		= !empty( $this->settings['debug_mode'] ) ? $this->settings['debug_mode'] : '';

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );

		wp_register_script( 'logitrail-script', plugins_url('/script.js', __FILE__) );
    }

    /**
     * environment_check function.
     *
     * @access public
     * @return void
     */
    private function environment_check() {
		global $woocommerce;

		$error_message = '';

		// Check for Logitrail Merchant ID
		if(!$this->merchant_id && $this->enabled == 'yes' ) {
			$error_message .= '<p>' . __( 'Logitrail is enabled, but the Logitrail Merchant ID has not been set.', 'logitrail-woocommerce' ) . '</p>';
		}

		// Check for Logitrail Secret Key
		if(!$this->secret_key && $this->enabled == 'yes' ) {
			$error_message .= '<p>' . __( 'Logitrail is enabled, but the Logitrail Secret Key has not been set.', 'logitrail-woocommerce' ) . '</p>';
		}

		if(!$error_message == '' ) {
			echo '<div class="error">';
			echo $error_message;
			echo '</div>';
		}
    }

    /**
     * admin_options function.
     *
     * @access public
     * @return void
     */
    public function admin_options() {
		// Check users environment supports this method
		$this->environment_check();

		// Show settings
		parent::admin_options();
    }

    /**
     * init_form_fields function.
     *
     * @access public
     * @return void
     */
    public function init_form_fields() {
	global $woocommerce;

    	$this->form_fields  = array(
	    'enabled'	=> array(
			'title'		=> __( 'Enabled', 'logitrail-woocommerce-shipping' ),
			'type'		=> 'checkbox',
			'label'		=> __( 'Enable', 'logitrail-woocommerce-shipping' ),
			'default'	=> 'no',
			'description'	=> __( 'Enable Logitrail on Cart/Checkout page.', 'logitrail-woocommerce-shipping' ),
			'desc_tip'	=> true
	    ),
	    'title'	=> array(
			'title'		=> __( 'Method Title', 'logitrail-woocommerce' ),
			'type'		=> 'text',
			'description'	=> __( 'This controls the title which the user sees during checkout.', 'logitrail-woocommerce' ),
			'default'	=> __( 'Logitrail', 'logitrail-woocommerce' ),
			'desc_tip'	=> true
	    ),
	    'merchant_id'=> array(
			'title'		=> __( 'Logitrail Merchant ID', 'logitrail-woocommerce' ),
			'type'		=> 'text',
			'description'	=> __( 'Obtained from Logitrail customer service.', 'logitrail-woocommerce' ),
			'default'	=> '',
			'desc_tip'	=> true
	    ),
	    'secret_key'=> array(
			'title'		=> __( 'Logitrail Secret Key', 'logitrail-woocommerce' ),
			'type'		=> 'text',
			'description'	=> __( 'Obtained from Logitrail customer service.', 'logitrail-woocommerce' ),
			'default'	=> '',
			'desc_tip'	=> true
	    ),
	    'fallback'	=> array(
			'title'		=> __( 'Fallback', 'logitrail-woocommerce' ),
			'type'		=> 'text',
			'description'	=> __( 'If Logitrail returns no shipping rates, offer this amount for shipping so that the user can still checkout. Leave blank to disable.', 'logitrail-woocommerce' ),
			'default'	=> '',
			'desc_tip'	=> true
	    ),
	    'test_server'=> array(
			'title'		=> __( 'Test server', 'logitrail-woocommerce' ),
			'label'		=> __( 'Use Logitrail test server', 'logitrail-woocommerce' ),
			'type'		=> 'checkbox',
			'default'	=> false,
			'desc_tip'	=> true
	    ),
	    'debug_mode'=> array(
			'title'		=> __( 'Debug mode', 'logitrail-woocommerce' ),
			'label'		=> __( 'Log debug data which tells more about what\'s happening.<br /><a href="#" class="debug-log">Show Debug log</a>', 'logitrail-woocommerce' ),
			'type'		=> 'checkbox',
			'default'	=> false,
			'desc_tip'	=> true
	    ),
	    'export_products'=> array(
			'title'		=> __( 'Export products', 'logitrail-woocommerce' ),
			'label'		=> __( "Export all current products to Logitrail's system<br />(will happen once after option is selected, then option is reset to not selected)", 'logitrail-woocommerce' ),
			'type'		=> 'button',
			'default'	=> 'Export now',
			'class'		=> 'button-secondary export-now'
	    ),
        );

		wp_enqueue_script( 'logitrail-script' );
    }

    /**
     * calculate_shipping function.
     *
     * @access public
     * @param mixed $package
     * @return void
     */
    public function calculate_shipping( $package = Array() ) {
    	global $woocommerce;

		$shipping_methods = array('pickup' => 'Nouto', 'letter' => 'Kirje', 'home' => 'Ovelle');
		$title = $this->settings['title'];
		if($title == '') {
			$type = get_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_type');
			$title = ($type && array_key_exists($type, $shipping_methods) ? $shipping_methods[$type] : 'Toimitustapaa ei ole valittu');
		}

        $postage = get_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_price');
        $this->add_rate( array(
                'id' 	=> $this->id . '_postage',
                'label' => $title,
				'cost' 	=> $postage,
                'sort'  => 0
        ) );

        $debug_mode = ($this->settings['debug_mode'] === 'yes' ? true : false);
        if($debug_mode) {
            Logitrail_WooCommerce::logitrail_debug_log('Informing WooCommerce postage as ' . $postage);
        }
    }
}
