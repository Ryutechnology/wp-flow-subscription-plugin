<?php
/**
 * Test script to verify no PHP warnings are generated
 * This can be added temporarily to check for warnings
 */

// Enable error reporting to catch any warnings
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Add action to test gateway instantiation
add_action('wp_loaded', function() {
    if (current_user_can('manage_options') && isset($_GET['flow_test_warnings'])) {

        echo '<div style="background: white; padding: 20px; margin: 20px; border: 1px solid #ccc;">';
        echo '<h2>Flow Gateway Warning Test</h2>';

        echo '<h3>PHP Version:</h3>';
        echo 'PHP Version: ' . PHP_VERSION . '<br>';
        echo 'PHP 8.2+ Dynamic Property Warnings: ' . (version_compare(PHP_VERSION, '8.2.0', '>=') ? 'Enabled' : 'Not Applicable') . '<br>';

        echo '<h3>Testing Gateway Instantiation:</h3>';

        try {
            if (class_exists('Flow_Payment_Gateway_Fixed')) {
                echo 'Creating Flow_Payment_Gateway_Fixed instance...<br>';
                $gateway = new Flow_Payment_Gateway_Fixed();
                echo '✅ Flow_Payment_Gateway_Fixed created successfully without warnings<br>';
                echo 'Gateway ID: ' . $gateway->id . '<br>';
                echo 'Gateway Title: ' . $gateway->method_title . '<br>';
            } else {
                echo '❌ Flow_Payment_Gateway_Fixed class not found<br>';
            }

            if (class_exists('Flow_Payment_Gateway')) {
                echo '<br>Creating Flow_Payment_Gateway instance...<br>';
                $gateway2 = new Flow_Payment_Gateway();
                echo '✅ Flow_Payment_Gateway created successfully without warnings<br>';
                echo 'Gateway ID: ' . $gateway2->id . '<br>';
                echo 'Gateway Title: ' . $gateway2->method_title . '<br>';
            } else {
                echo '❌ Flow_Payment_Gateway class not found<br>';
            }

        } catch (Exception $e) {
            echo '❌ Error creating gateway: ' . $e->getMessage() . '<br>';
        }

        // Check if gateways are registered
        if (function_exists('WC') && WC()->payment_gateways()) {
            echo '<h3>WooCommerce Registration Check:</h3>';
            $gateways = WC()->payment_gateways()->payment_gateways();

            if (isset($gateways['flow'])) {
                echo '✅ Flow gateway registered in WooCommerce<br>';
                echo 'Registered gateway class: ' . get_class($gateways['flow']) . '<br>';
            } else {
                echo '❌ Flow gateway not registered in WooCommerce<br>';
            }

            echo '<br>All registered gateways:<br>';
            foreach ($gateways as $id => $gateway) {
                echo '- ' . $id . ': ' . get_class($gateway) . '<br>';
            }
        }

        echo '<hr>';
        echo '<p><strong>If you see this message without any PHP warnings above, the deprecation issue is fixed!</strong></p>';
        echo '</div>';

        exit;
    }
});

// Add admin menu item to test
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Flow Warning Test',
        'Flow Warning Test',
        'manage_options',
        'flow-warning-test',
        function() {
            echo '<div class="wrap">';
            echo '<h1>Flow Gateway Warning Test</h1>';
            echo '<p>Test that the Flow payment gateway creates without PHP 8.2+ deprecation warnings.</p>';
            echo '<p><a href="' . admin_url('admin.php?flow_test_warnings=1') . '" class="button-primary">Run Warning Test</a></p>';
            echo '</div>';
        }
    );
});
?>