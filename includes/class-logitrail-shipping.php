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

	// WF: Load UPS Settings.
	$ups_settings 		= get_option( 'woocommerce_'.LOGITRAIL_ID.'_settings', null );

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

	add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'export_products' ) );
	// Reset export option, so we don't export every time settings are saved, only when it is actually selected
	$this->settings['export_products'] = 'no';
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

    public function export_products() {
	// FIXME: This is run four times, any solution to mark it as running
	// so it would be only once..?
	if($this->settings['export_products'] == 'yes') {
	    $apic = new Logitrail\Lib\ApiClient();
	    $test_server = ($this->settings['test_server'] === 'yes' ? true : false);
	    $apic->useTest($test_server);

	    $apic->setMerchantId($this->settings['merchant_id']);
	    $apic->setSecretKey($this->settings['secret_key']);

	    $productsAdded = 0;
	    $productsAddedTotal = 0;
	    $loop = new WP_Query( array( 'post_type' => array('product'), 'posts_per_page' => -1 ) );
	    while($loop->have_posts()) {
		$loop->the_post();

		$post_id = get_the_ID();
		$product = wc_get_product($post_id);

		// weight for Logitrail goes in grams, dimensions in millimeter
		$apic->addProduct($product->get_sku(), $product->get_title(), 1, $product->get_weight() * 1000, $product->get_price(), 0, null, $product->get_width() * 10, $product->get_height() * 10, $product->get_length() * 10);

		// create products in batches of 5, so in big shops we don't get
		// huge amount of products taking memory in ApiClient
		$productsAdded++;
		$productsAddedTotal++;
		if($productsAdded > 5) {
		    // TODO: Add error handling/reposting when Logitrail's errors are sorted out, ie. they don't send HTML instead of JSON on error
		    $response = $apic->createProducts();
		    $apic->clearProducts();
		    $productsAdded = 0;
		}
	    }

	    $response = $apic->createProducts();
	    $apic->clearProducts();

	    wp_reset_query();

	    ?>
	    <div class="updated">
		<p><?php esc_html_e('Exported ' . $productsAddedTotal . ' products to Logitrail.', 'text-domain' ); ?></p>
	    </div>
	    <?php
	}
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
		'title'		=> __( 'Realtime Rates', 'ups-woocommerce-shipping' ),
		'type'		=> 'checkbox',
		'label'		=> __( 'Enable', 'ups-woocommerce-shipping' ),
		'default'	=> 'no',
		'description'	=> __( 'Enable Logitrail on Cart/Checkout page.', 'ups-woocommerce-shipping' ),
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
	    'export_products'=> array(
		'title'		=> __( 'Export products', 'logitrail-woocommerce' ),
		'label'		=> __( "Export all current products to Logitrail's system<br />(will happen once after option is selected, then option is reset to not selected)", 'logitrail-woocommerce' ),
		'type'		=> 'checkbox',
		'default'	=> false,
	    ),
        );
    }

    /**
     * calculate_shipping function.
     *
     * @access public
     * @param mixed $package
     * @return void
     */
    public function calculate_shipping( $package ) {
    	global $woocommerce;

        $this->add_rate( array(
                'id' 	=> $this->id . '_postage',
                'label' => ' ',
                'cost' 	=> get_transient('logitrail_' . $woocommerce->session->get_session_cookie()[3] . '_price'),
                'sort'  => 0
        ) );
    }
}
