<?php
/**
 * Block Checkout Support for Flow Payment Gateway
 */

if (!defined('ABSPATH')) exit;

// Add action to declare block support
add_action('woocommerce_blocks_loaded', 'flow_register_payment_method_type');

function flow_register_payment_method_type() {
    // Check if the required class exists
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    // Include the custom blocks class
    require_once plugin_dir_path(__FILE__) . 'class-flow-blocks-support.php';

    // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function(\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
            $payment_method_registry->register(new Flow_Blocks_Support());
        }
    );
}
?>