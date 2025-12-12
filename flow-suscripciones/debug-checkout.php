<?php

// Add a notice in admin if no payment methods are available
add_action('admin_notices', function() {
    if (current_user_can('manage_options') && function_exists('WC')) {
        $available_gateways = WC()->payment_gateways()->get_available_payment_gateways();

        if (empty($available_gateways)) {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>Warning:</strong> No payment methods are currently available in WooCommerce checkout. ';
            echo 'This will cause the "No payment methods available" error.</p>';
            echo '<p><a href="' . admin_url('admin.php?page=wc-settings&tab=checkout') . '">Check Payment Settings</a></p>';
            echo '</div>';
        } else {
            $gateway_names = array();
            foreach ($available_gateways as $gateway) {
                $gateway_names[] = $gateway->title;
            }
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Available payment methods:</strong> ' . implode(', ', $gateway_names) . '</p>';
            echo '</div>';
        }
    }
});

// Debug hook for payment method selection
add_action('woocommerce_review_order_before_payment', function() {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $available = WC()->payment_gateways()->get_available_payment_gateways();
        error_log('=== Flow Debug: Review Order Before Payment ===');
        error_log('Available payment gateways during checkout: ' . implode(', ', array_keys($available)));

        // Check if cart needs payment
        if (WC()->cart) {
            error_log('Cart total: ' . WC()->cart->get_total());
            error_log('Cart needs payment: ' . (WC()->cart->needs_payment() ? 'YES' : 'NO'));
        }
    }
});

// Add manual gateway enable function
add_action('wp_loaded', function() {
    if (current_user_can('manage_options') && isset($_GET['flow_enable_gateway'])) {
        // Force enable the Flow gateway
        $gateway_settings = get_option('woocommerce_flow_settings', array());
        $gateway_settings['enabled'] = 'yes';
        update_option('woocommerce_flow_settings', $gateway_settings);

        wp_redirect(admin_url('admin.php?page=wc-settings&tab=checkout'));
        exit;
    }
});

// Add admin menu to enable gateway
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Flow Enable Gateway',
        'Flow Enable Gateway',
        'manage_options',
        'flow-enable-gateway',
        function() {
            echo '<div class="wrap">';
            echo '<h1>Flow Gateway Quick Enable</h1>';
            echo '<p>If the Flow gateway is not appearing in checkout, use this to force enable it.</p>';
            echo '<p><a href="' . admin_url('admin.php?flow_enable_gateway=1') . '" class="button-primary">Force Enable Flow Gateway</a></p>';
            echo '<p><a href="' . admin_url('admin.php?page=wc-settings&tab=checkout') . '" class="button">Go to Payment Settings</a></p>';
            echo '</div>';
        }
    );
});
?>