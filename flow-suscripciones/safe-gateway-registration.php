<?php
/**
 * Safe Flow payment gateway registration
 */

if (!defined('ABSPATH')) exit;

// Only register gateway if WooCommerce is fully loaded and classes exist
add_action('woocommerce_loaded', function() {
    if (!class_exists('WC_Payment_Gateway')) {
        error_log('Flow Gateway: WC_Payment_Gateway not available');
        return;
    }

    // Load gateway class
    require_once plugin_dir_path(__FILE__) . 'includes/class-flow-payment-gateway.php';

    if (!class_exists('Flow_Payment_Gateway')) {
        error_log('Flow Gateway: Flow_Payment_Gateway class failed to load');
        return;
    }

    // Register the gateway
    add_filter('woocommerce_payment_gateways', function($gateways) {
        if (!in_array('Flow_Payment_Gateway', $gateways)) {
            $gateways[] = 'Flow_Payment_Gateway';
            error_log('Flow Gateway: Successfully registered');
        }
        return $gateways;
    }, 99);

}, 20); // High priority to ensure WooCommerce is ready

// Sync credentials only when needed
add_action('admin_init', function() {
    if (!is_admin()) return;

    $flow_api_key = get_option('flow_api_key');
    $flow_secret_key = get_option('flow_secret_key');

    if ($flow_api_key && $flow_secret_key) {
        $gateway_settings = get_option('woocommerce_flow_settings', []);

        // Only update if different
        if ($gateway_settings['api_key'] !== $flow_api_key || $gateway_settings['secret_key'] !== $flow_secret_key) {
            $gateway_settings['api_key'] = $flow_api_key;
            $gateway_settings['secret_key'] = $flow_secret_key;
            $gateway_settings['enabled'] = 'yes';

            update_option('woocommerce_flow_settings', $gateway_settings);
            error_log('Flow Gateway: Credentials synced');
        }
    }
});

error_log('Flow Gateway: Safe registration script loaded');