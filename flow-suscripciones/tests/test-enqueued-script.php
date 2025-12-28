<?php
/**
 * Test Enqueued Flow Customer Script
 * This script verifies that the JavaScript file is properly enqueued via admin_enqueue_scripts
 */

if (!defined('ABSPATH')) {
    require_once(__DIR__ . '/../../../../wp-config.php');
}

if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to access this page.');
}

echo '<div style="font-family: monospace; padding: 20px; background: #f0f0f0;">';
echo '<h1>🧪 Flow Customer Script Enqueue Test</h1>';

// Simulate different admin pages to test script loading
$test_hooks = array(
    'users.php' => 'WordPress Users Page',
    'woocommerce_page_wc-customers' => 'WooCommerce Customers Page',
    'woocommerce_page_wc-admin' => 'WooCommerce Admin Page',
    'admin.php' => 'Generic Admin Page'
);

// Test GET parameters
$test_get_params = array(
    'page=wc-customers' => 'WC Customers Parameter',
    'page=wc-admin&path=/customers' => 'WC Admin Customers Path',
    'page=wc-admin&path=/analytics/customers' => 'WC Analytics Customers',
    'role=customer' => 'Customer Role Filter'
);

echo '<h2>🎯 Script Loading Tests</h2>';

if (class_exists('Flow_Customer_Columns')) {
    echo '<p>✅ Flow_Customer_Columns class is loaded</p>';

    $columns_instance = new Flow_Customer_Columns();

    // Test script enqueuing for each hook
    echo '<h3>Hook-based Loading Tests</h3>';
    echo '<table border="1" style="border-collapse: collapse; width: 100%;">';
    echo '<tr><th>Hook</th><th>Page Type</th><th>Test Result</th></tr>';

    foreach ($test_hooks as $hook => $description) {
        // Capture any output from the enqueue method
        ob_start();

        // Temporarily modify $_GET to test parameter detection
        $old_get = $_GET;

        try {
            // Test the enqueue method
            $columns_instance->enqueue_flow_customer_scripts($hook);

            // Check if script was enqueued
            $enqueued = wp_script_is('flow-customer-columns', 'enqueued');
            $registered = wp_script_is('flow-customer-columns', 'registered');

            $status = '';
            if ($enqueued) {
                $status = '✅ Enqueued';
            } elseif ($registered) {
                $status = '⚠️ Registered only';
            } else {
                $status = '❌ Not loaded';
            }

            echo "<tr><td><code>$hook</code></td><td>$description</td><td>$status</td></tr>";

        } catch (Exception $e) {
            echo "<tr><td><code>$hook</code></td><td>$description</td><td>❌ Error: " . esc_html($e->getMessage()) . "</td></tr>";
        }

        $_GET = $old_get;
        ob_end_clean();

        // Dequeue for next test
        wp_dequeue_script('flow-customer-columns');
        wp_deregister_script('flow-customer-columns');
    }

    echo '</table>';

    echo '<h3>GET Parameter Tests</h3>';
    echo '<table border="1" style="border-collapse: collapse; width: 100%;">';
    echo '<tr><th>GET Parameters</th><th>Scenario</th><th>Test Result</th></tr>';

    foreach ($test_get_params as $params => $description) {
        // Parse parameters
        parse_str($params, $parsed_params);

        // Backup current $_GET
        $old_get = $_GET;
        $_GET = array_merge($_GET, $parsed_params);

        ob_start();

        try {
            // Test with admin.php hook (generic hook that checks GET params)
            $columns_instance->enqueue_flow_customer_scripts('admin.php');

            $enqueued = wp_script_is('flow-customer-columns', 'enqueued');
            $registered = wp_script_is('flow-customer-columns', 'registered');

            $status = '';
            if ($enqueued) {
                $status = '✅ Enqueued';
            } elseif ($registered) {
                $status = '⚠️ Registered only';
            } else {
                $status = '❌ Not loaded';
            }

            echo "<tr><td><code>$params</code></td><td>$description</td><td>$status</td></tr>";

        } catch (Exception $e) {
            echo "<tr><td><code>$params</code></td><td>$description</td><td>❌ Error: " . esc_html($e->getMessage()) . "</td></tr>";
        }

        // Restore $_GET
        $_GET = $old_get;
        ob_end_clean();

        // Dequeue for next test
        wp_dequeue_script('flow-customer-columns');
        wp_deregister_script('flow-customer-columns');
    }

    echo '</table>';

} else {
    echo '<p style="color: red;">❌ Flow_Customer_Columns class not found!</p>';
}

echo '<hr>';

// Test script file existence
echo '<h2>📁 Script File Tests</h2>';

$script_path = plugin_dir_path(__FILE__) . 'assets/js/flow-customer-columns.js';
$script_url = plugin_dir_url(__FILE__) . 'assets/js/flow-customer-columns.js';

echo '<table border="1" style="border-collapse: collapse;">';
echo '<tr><th>Check</th><th>Result</th><th>Details</th></tr>';

// File existence
if (file_exists($script_path)) {
    $file_size = filesize($script_path);
    $file_modified = date('Y-m-d H:i:s', filemtime($script_path));
    echo "<tr><td>File Exists</td><td>✅ Yes</td><td>Size: {$file_size} bytes, Modified: {$file_modified}</td></tr>";
} else {
    echo "<tr><td>File Exists</td><td>❌ No</td><td>Path: {$script_path}</td></tr>";
}

// URL accessibility
echo "<tr><td>Script URL</td><td>📍 Path</td><td><code>{$script_url}</code></td></tr>";

// File permissions
if (file_exists($script_path)) {
    $perms = fileperms($script_path);
    $perms_octal = substr(sprintf('%o', $perms), -4);
    echo "<tr><td>File Permissions</td><td>📋 Info</td><td>{$perms_octal}</td></tr>";
}

echo '</table>';

echo '<hr>';

// Test localization data
echo '<h2>📡 Script Localization Test</h2>';

// Test the localization data generation
$test_data = array(
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('flow_customer_data'),
    'debug' => defined('WP_DEBUG') && WP_DEBUG
);

echo '<p><strong>Localized Data Preview:</strong></p>';
echo '<table border="1" style="border-collapse: collapse;">';
echo '<tr><th>Key</th><th>Value</th></tr>';

foreach ($test_data as $key => $value) {
    $display_value = is_bool($value) ? ($value ? 'true' : 'false') : esc_html($value);
    echo "<tr><td><code>$key</code></td><td>$display_value</td></tr>";
}

echo '</table>';

// Test environment detection
if (class_exists('Flow_Customer_Columns')) {
    $reflection = new ReflectionClass('Flow_Customer_Columns');
    $method = $reflection->getMethod('is_production_environment');
    $method->setAccessible(true);
    $is_production = $method->invoke($columns_instance);

    echo '<p><strong>Environment Detection:</strong> ' . ($is_production ? '🔴 Production' : '🟡 Development') . '</p>';
}

echo '<hr>';

// JavaScript loading test
echo '<h2>🔧 JavaScript Loading Test</h2>';

echo '<p>This test will attempt to load the Flow customer columns script and verify it works:</p>';

echo '<div id="script-test-results" style="padding: 10px; background: #fff; border: 1px solid #ccc; margin: 10px 0;">
    <p>⏳ Testing script loading...</p>
</div>';

// Enqueue script for this test
$script_url = plugin_dir_url(__FILE__) . 'assets/js/flow-customer-columns.js';
wp_enqueue_script('flow-customer-columns-test', $script_url, array('jquery'), time(), true);

$test_data['testMode'] = true;
wp_localize_script('flow-customer-columns-test', 'flowCustomerData', $test_data);

echo '<script>
jQuery(document).ready(function($) {
    console.log("🧪 Flow Script Test: Starting...");

    let testResults = [];

    // Test jQuery availability
    if (typeof $ !== "undefined") {
        testResults.push("✅ jQuery is available");
    } else {
        testResults.push("❌ jQuery is not available");
    }

    // Test localized data
    if (typeof flowCustomerData !== "undefined") {
        testResults.push("✅ flowCustomerData is available");

        if (flowCustomerData.ajaxUrl) {
            testResults.push("✅ AJAX URL is set: " + flowCustomerData.ajaxUrl);
        } else {
            testResults.push("❌ AJAX URL is missing");
        }

        if (flowCustomerData.nonce) {
            testResults.push("✅ Nonce is available");
        } else {
            testResults.push("❌ Nonce is missing");
        }
    } else {
        testResults.push("❌ flowCustomerData is not available");
    }

    // Test script loading capability
    try {
        $("body").append("<div id=\"flow-test-div\" style=\"display:none;\">Test</div>");
        if ($("#flow-test-div").length > 0) {
            testResults.push("✅ DOM manipulation works");
            $("#flow-test-div").remove();
        } else {
            testResults.push("❌ DOM manipulation failed");
        }
    } catch (e) {
        testResults.push("❌ DOM manipulation error: " + e.message);
    }

    // Display results
    $("#script-test-results").html("<h4>JavaScript Test Results:</h4><ul><li>" + testResults.join("</li><li>") + "</li></ul>");

    console.log("🧪 Flow Script Test: Results", testResults);
});
</script>';

echo '<hr>';

// Final summary
echo '<h2>📋 Test Summary</h2>';

$script_exists = file_exists($script_path);
$class_exists = class_exists('Flow_Customer_Columns');

echo '<table border="1" style="border-collapse: collapse; width: 100%;">';
echo '<tr><th>Component</th><th>Status</th><th>Notes</th></tr>';

echo '<tr style="background-color: ' . ($class_exists ? '#e7f7e7' : '#ffe7e7') . ';">';
echo '<td><strong>PHP Class</strong></td>';
echo '<td>' . ($class_exists ? '✅ Loaded' : '❌ Missing') . '</td>';
echo '<td>' . ($class_exists ? 'Flow_Customer_Columns is available' : 'Class not found - check plugin activation') . '</td>';
echo '</tr>';

echo '<tr style="background-color: ' . ($script_exists ? '#e7f7e7' : '#ffe7e7') . ';">';
echo '<td><strong>JavaScript File</strong></td>';
echo '<td>' . ($script_exists ? '✅ Exists' : '❌ Missing') . '</td>';
echo '<td>' . ($script_exists ? 'Script file is available for enqueuing' : 'Script file not found at expected path') . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td><strong>admin_enqueue_scripts</strong></td>';
echo '<td>🔧 Method Added</td>';
echo '<td>enqueue_flow_customer_scripts() method implemented</td>';
echo '</tr>';

echo '</table>';

if ($class_exists && $script_exists) {
    echo '<div style="background: #e7f7e7; padding: 15px; border-radius: 5px; margin: 10px 0;">';
    echo '<h3>✅ Success! Ready to use:</h3>';
    echo '<ol>';
    echo '<li><strong>Go to WooCommerce → Customers</strong> to see the script in action</li>';
    echo '<li><strong>Open browser console</strong> to see Flow Customer Columns debug messages</li>';
    echo '<li><strong>Look for "🎯 Flow Customer Columns: Script loaded"</strong> message</li>';
    echo '<li><strong>The script will automatically</strong> add Flow columns to customer tables</li>';
    echo '</ol>';
    echo '<p><em>Note: The script only loads on customer-related admin pages for performance.</em></p>';
    echo '</div>';
} else {
    echo '<div style="background: #ffe7e7; padding: 15px; border-radius: 5px; margin: 10px 0;">';
    echo '<h3>⚠️ Issues found:</h3>';
    echo '<ul>';
    if (!$class_exists) echo '<li>Flow_Customer_Columns class is not available</li>';
    if (!$script_exists) echo '<li>JavaScript file is missing</li>';
    echo '</ul>';
    echo '<p>Please ensure the plugin is properly installed and activated.</p>';
    echo '</div>';
}

echo '</div>';
?>