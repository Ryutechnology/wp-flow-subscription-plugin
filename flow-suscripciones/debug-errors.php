<?php
/**
 * Quick error diagnostic for Flow plugin
 */

if (!defined('ABSPATH')) exit;

add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;

    echo '<div class="notice notice-info">';
    echo '<h3>🔍 Flow Plugin Debug Status</h3>';

    // Check if classes exist
    $classes = [
        'Flow_WooCommerce' => class_exists('Flow_WooCommerce'),
        'Flow_Payment_Gateway' => class_exists('Flow_Payment_Gateway'),
        'Flow_Customer_Columns' => class_exists('Flow_Customer_Columns'),
        'WooCommerce' => class_exists('WooCommerce'),
        'WC_Payment_Gateway' => class_exists('WC_Payment_Gateway')
    ];

    echo '<p><strong>Class Status:</strong></p><ul>';
    foreach ($classes as $class => $exists) {
        $status = $exists ? '✅' : '❌';
        echo "<li>$status $class</li>";
    }
    echo '</ul>';

    // Check WooCommerce payment gateways
    if (function_exists('WC') && WC()->payment_gateways()) {
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        echo '<p><strong>Available Payment Gateways:</strong> ';
        if (empty($gateways)) {
            echo '❌ None';
        } else {
            echo implode(', ', array_keys($gateways));
        }
        echo '</p>';
    }

    // Check for recent PHP errors
    if (function_exists('error_get_last')) {
        $last_error = error_get_last();
        if ($last_error && strpos($last_error['message'], 'Flow') !== false) {
            echo '<p><strong>⚠️ Recent PHP Error:</strong> ' . esc_html($last_error['message']) . '</p>';
        }
    }

    echo '</div>';
});

// Log initialization attempt
error_log('Flow Debug: Debug script loaded at ' . current_time('Y-m-d H:i:s'));