<?php
/**
 * Debug script to verify Flow payment gateway is properly registered
 * Add this to your functions.php temporarily or run via admin
 */

// Hook to check gateway registration status
add_action('admin_init', function() {
    if (current_user_can('manage_options') && isset($_GET['flow_debug'])) {
        echo '<div style="background: white; padding: 20px; margin: 20px;">';
        echo '<h2>Flow Payment Gateway Debug</h2>';

        // Check if WooCommerce is active
        echo '<h3>WooCommerce Status</h3>';
        echo 'WooCommerce class exists: ' . (class_exists('WooCommerce') ? '✅ YES' : '❌ NO') . '<br>';
        echo 'WC_Payment_Gateway exists: ' . (class_exists('WC_Payment_Gateway') ? '✅ YES' : '❌ NO') . '<br>';
        echo 'WC() function available: ' . (function_exists('WC') ? '✅ YES' : '❌ NO') . '<br>';

        // Check Flow classes
        echo '<h3>Flow Classes Status</h3>';
        echo 'Flow_WooCommerce exists: ' . (class_exists('Flow_WooCommerce') ? '✅ YES' : '❌ NO') . '<br>';
        echo 'Flow_Payment_Gateway exists: ' . (class_exists('Flow_Payment_Gateway') ? '✅ YES' : '❌ NO') . '<br>';

        // Check WooCommerce payment gateways
        if (function_exists('WC') && WC()->payment_gateways()) {
            echo '<h3>WooCommerce Payment Gateways</h3>';
            $gateways = WC()->payment_gateways()->payment_gateways();
            echo 'Total gateways: ' . count($gateways) . '<br>';
            echo 'Gateway IDs: ' . implode(', ', array_keys($gateways)) . '<br>';

            if (isset($gateways['flow'])) {
                echo '✅ Flow gateway IS registered!<br>';
                $flow_gateway = $gateways['flow'];
                echo 'Gateway title: ' . $flow_gateway->title . '<br>';
                echo 'Gateway enabled: ' . ($flow_gateway->enabled === 'yes' ? 'YES' : 'NO') . '<br>';
            } else {
                echo '❌ Flow gateway NOT registered<br>';
            }

            // Check available gateways (ones that show in checkout)
            $available = WC()->payment_gateways()->get_available_payment_gateways();
            echo '<br>Available gateways (checkout): ' . implode(', ', array_keys($available)) . '<br>';

            if (isset($available['flow'])) {
                echo '✅ Flow gateway is AVAILABLE for checkout<br>';
            } else {
                echo '❌ Flow gateway NOT available for checkout<br>';
            }
        } else {
            echo '<h3>❌ WooCommerce payment gateways not available</h3>';
        }

        // Check credentials
        echo '<h3>Flow Credentials</h3>';
        $api_key = get_option('flow_api_key');
        $secret_key = get_option('flow_secret_key');
        echo 'Flow API Key configured: ' . (!empty($api_key) ? '✅ YES' : '❌ NO') . '<br>';
        echo 'Flow Secret Key configured: ' . (!empty($secret_key) ? '✅ YES' : '❌ NO') . '<br>';

        echo '</div>';
        exit;
    }
});

// Add a link to run the debug in admin
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Flow Debug',
        'Flow Debug',
        'manage_options',
        'flow-debug',
        function() {
            echo '<div class="wrap">';
            echo '<h1>Flow Payment Gateway Debug</h1>';
            echo '<p><a href="' . admin_url('admin.php?flow_debug=1') . '" class="button-primary">Run Debug Check</a></p>';
            echo '</div>';
        }
    );
});
?>