<?php
/**
 * Check Flow gateway registration status
 */

if (!defined('ABSPATH')) exit;

add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;

    echo '<div class="notice notice-info">';
    echo '<h3>🔍 Flow Gateway Status Check</h3>';

    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        echo '<p>❌ WooCommerce not active</p></div>';
        return;
    }

    // Check if Flow_Payment_Gateway class exists
    $gateway_class_exists = class_exists('Flow_Payment_Gateway');
    echo '<p><strong>Flow_Payment_Gateway class:</strong> ' . ($gateway_class_exists ? '✅ Loaded' : '❌ Not loaded') . '</p>';

    if (!$gateway_class_exists) {
        echo '<p>🔧 <strong>Issue:</strong> Gateway class not loaded. Checking file...</p>';
        $gateway_file = plugin_dir_path(__FILE__) . 'includes/class-flow-payment-gateway.php';
        if (file_exists($gateway_file)) {
            echo '<p>✅ Gateway file exists at: ' . $gateway_file . '</p>';
            echo '<p>⚠️ File exists but class not loaded - check for PHP errors</p>';
        } else {
            echo '<p>❌ Gateway file missing at: ' . $gateway_file . '</p>';
        }
    }

    // Check WooCommerce payment gateways
    if (function_exists('WC') && WC()->payment_gateways()) {
        $all_gateways = WC()->payment_gateways()->payment_gateways();
        $available_gateways = WC()->payment_gateways()->get_available_payment_gateways();

        echo '<p><strong>All registered gateways:</strong> ' . implode(', ', array_keys($all_gateways)) . '</p>';
        echo '<p><strong>Available gateways:</strong> ' . (empty($available_gateways) ? 'None' : implode(', ', array_keys($available_gateways))) . '</p>';

        if (isset($all_gateways['flow'])) {
            $flow_gateway = $all_gateways['flow'];
            echo '<p>✅ <strong>Flow gateway registered</strong></p>';
            echo '<ul>';
            echo '<li><strong>ID:</strong> ' . $flow_gateway->id . '</li>';
            echo '<li><strong>Title:</strong> ' . $flow_gateway->method_title . '</li>';
            echo '<li><strong>Enabled:</strong> ' . ($flow_gateway->enabled === 'yes' ? '✅ Yes' : '❌ No - ' . $flow_gateway->enabled) . '</li>';
            echo '<li><strong>Available:</strong> ' . ($flow_gateway->is_available() ? '✅ Yes' : '❌ No') . '</li>';
            if (!$flow_gateway->is_available()) {
                echo '<li><strong>⚠️ Why not available:</strong> Check is_available() method in gateway class</li>';
            }
            echo '</ul>';

            // Check gateway settings
            $settings = get_option('woocommerce_flow_settings', []);
            echo '<p><strong>Gateway settings:</strong></p>';
            echo '<ul>';
            echo '<li><strong>API Key set:</strong> ' . (!empty($settings['api_key']) ? '✅ Yes' : '❌ No') . '</li>';
            echo '<li><strong>Secret Key set:</strong> ' . (!empty($settings['secret_key']) ? '✅ Yes' : '❌ No') . '</li>';
            echo '<li><strong>Enabled in settings:</strong> ' . (($settings['enabled'] ?? 'no') === 'yes' ? '✅ Yes' : '❌ No') . '</li>';
            echo '</ul>';
        } else {
            echo '<p>❌ <strong>Flow gateway NOT registered</strong></p>';
            echo '<p>🔧 <strong>Action needed:</strong> Gateway registration failed</p>';
        }
    } else {
        echo '<p>❌ WooCommerce payment gateways not available</p>';
    }

    // Check for recent errors
    if (function_exists('error_get_last')) {
        $last_error = error_get_last();
        if ($last_error && (strpos($last_error['message'], 'Flow') !== false || strpos($last_error['message'], 'gateway') !== false)) {
            echo '<p>⚠️ <strong>Recent PHP Error:</strong> ' . esc_html($last_error['message']) . '</p>';
            echo '<p><strong>File:</strong> ' . esc_html($last_error['file']) . ' <strong>Line:</strong> ' . $last_error['line'] . '</p>';
        }
    }

    echo '</div>';
});