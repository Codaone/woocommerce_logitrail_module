<?php
/**
 * Functions used by plugins
 */
if ( ! class_exists( 'Logitrain_Dependencies' ) )
	require_once 'class-logitrail-dependencies.php';

/**
 * WC Detection
 */
if ( ! function_exists( 'logitrail_is_woocommerce_active' ) ) {
	function logitrail_is_woocommerce_active() {
		return Logitrail_Dependencies::woocommerce_active_check();
	}
}