<?php
/**
 * Test Both WordPress Users and WooCommerce Customers Interfaces
 * This script checks if Flow columns appear in both interfaces
 */

if (!defined('ABSPATH')) {
    require_once(__DIR__ . '/../../../../wp-config.php');
}

if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to access this page.');
}

global $wpdb;

echo '<div style="font-family: monospace; padding: 20px; background: #f0f0f0;">';
echo '<h1>🔍 Flow Columns - Both Interfaces Test</h1>';

// Test WordPress Users Integration
echo '<h2>🎯 WordPress Users Page Test</h2>';

// Check if Flow Customer Columns class exists
if (class_exists('Flow_Customer_Columns')) {
    echo '<p>✅ Flow_Customer_Columns class is loaded</p>';

    $columns_instance = new Flow_Customer_Columns();

    // Test the add_customer_column method
    $test_columns = array(
        'cb' => '<input type="checkbox" />',
        'username' => 'Username',
        'name' => 'Name',
        'email' => 'Email',
        'role' => 'Role',
        'posts' => 'Posts'
    );

    $enhanced_columns = $columns_instance->add_customer_column($test_columns);

    echo '<p><strong>WordPress Users Columns Test:</strong></p>';
    echo '<table border="1" style="border-collapse: collapse; width: 100%;">';
    echo '<tr><th>Column Key</th><th>Column Title</th><th>Status</th></tr>';

    foreach ($enhanced_columns as $key => $title) {
        $is_flow_column = strpos($key, 'flow_') === 0 ? '✅ Flow Column' : 'Standard Column';
        $row_style = strpos($key, 'flow_') === 0 ? 'background-color: #e7f3ff;' : '';
        echo "<tr style='$row_style'><td><code>$key</code></td><td>$title</td><td>$is_flow_column</td></tr>";
    }
    echo '</table>';

    // Check if Flow columns were added
    $flow_columns_found = 0;
    if (array_key_exists('flow_subscription_status', $enhanced_columns)) $flow_columns_found++;
    if (array_key_exists('flow_subscription_id', $enhanced_columns)) $flow_columns_found++;
    if (array_key_exists('flow_subscription_url', $enhanced_columns)) $flow_columns_found++;

    if ($flow_columns_found === 3) {
        echo '<p style="color: green; font-weight: bold;">✅ All 3 Flow columns successfully added to WordPress Users!</p>';
    } else {
        echo "<p style='color: red; font-weight: bold;'>❌ Only $flow_columns_found/3 Flow columns found in WordPress Users</p>";
    }

} else {
    echo '<p style="color: red;">❌ Flow_Customer_Columns class not found!</p>';
}

echo '<hr>';

// Test WooCommerce Customers Integration
echo '<h2>🛒 WooCommerce Customers Test</h2>';

if (class_exists('WooCommerce') && class_exists('Flow_Customer_Columns')) {
    echo '<p>✅ WooCommerce is active and Flow_Customer_Columns is loaded</p>';

    $columns_instance = new Flow_Customer_Columns();

    // Test WooCommerce customer columns
    $wc_test_columns = array(
        'cb' => '<input type="checkbox" />',
        'customer_name' => 'Name',
        'username' => 'Username',
        'location' => 'Location',
        'orders' => 'Orders',
        'money_spent' => 'Money Spent',
        'last_order' => 'Last Order'
    );

    $wc_enhanced_columns = $columns_instance->add_woocommerce_customer_columns($wc_test_columns);

    echo '<p><strong>WooCommerce Customers Columns Test:</strong></p>';
    echo '<table border="1" style="border-collapse: collapse; width: 100%;">';
    echo '<tr><th>Column Key</th><th>Column Title</th><th>Status</th></tr>';

    foreach ($wc_enhanced_columns as $key => $title) {
        $is_flow_column = strpos($key, 'flow_') === 0 ? '✅ Flow Column' : 'Standard Column';
        $row_style = strpos($key, 'flow_') === 0 ? 'background-color: #e7f3ff;' : '';
        echo "<tr style='$row_style'><td><code>$key</code></td><td>$title</td><td>$is_flow_column</td></tr>";
    }
    echo '</table>';

    // Check if Flow columns were added to WooCommerce
    $wc_flow_columns_found = 0;
    if (array_key_exists('flow_subscription_status', $wc_enhanced_columns)) $wc_flow_columns_found++;
    if (array_key_exists('flow_subscription_id', $wc_enhanced_columns)) $wc_flow_columns_found++;
    if (array_key_exists('flow_subscription_url', $wc_enhanced_columns)) $wc_flow_columns_found++;

    if ($wc_flow_columns_found === 3) {
        echo '<p style="color: green; font-weight: bold;">✅ All 3 Flow columns successfully added to WooCommerce Customers!</p>';
    } else {
        echo "<p style='color: red; font-weight: bold;'>❌ Only $wc_flow_columns_found/3 Flow columns found in WooCommerce Customers</p>";
    }

} else {
    if (!class_exists('WooCommerce')) {
        echo '<p style="color: red;">❌ WooCommerce is not active!</p>';
    }
    if (!class_exists('Flow_Customer_Columns')) {
        echo '<p style="color: red;">❌ Flow_Customer_Columns class not found!</p>';
    }
}

echo '<hr>';

// Test sample data display
echo '<h2>📊 Sample Data Display Test</h2>';

$sample_users = get_users(array(
    'meta_query' => array(
        'relation' => 'OR',
        array(
            'key' => '_flow_subscription_id',
            'compare' => 'EXISTS'
        ),
        array(
            'key' => '_flow_customer_id',
            'compare' => 'EXISTS'
        )
    ),
    'number' => 3
));

if (!empty($sample_users)) {
    echo '<p><strong>Testing column content with sample users:</strong></p>';
    echo '<table border="1" style="border-collapse: collapse; width: 100%;">';
    echo '<tr><th>User ID</th><th>Email</th><th>Flow Status</th><th>Flow Subscription ID</th><th>Flow Dashboard</th></tr>';

    foreach ($sample_users as $user) {
        $status_content = $columns_instance->show_customer_column_content('', 'flow_subscription_status', $user->ID);
        $id_content = $columns_instance->show_customer_column_content('', 'flow_subscription_id', $user->ID);
        $url_content = $columns_instance->show_customer_column_content('', 'flow_subscription_url', $user->ID);

        echo "<tr>";
        echo "<td>{$user->ID}</td>";
        echo "<td>{$user->user_email}</td>";
        echo "<td>$status_content</td>";
        echo "<td>$id_content</td>";
        echo "<td>$url_content</td>";
        echo "</tr>";
    }
    echo '</table>';

    echo '<p style="color: green;">✅ Column content rendering test completed with ' . count($sample_users) . ' users</p>';
} else {
    echo '<p style="color: orange;">⚠️ No users with Flow data found for content testing</p>';
}

echo '<hr>';

// Hook Status Check
echo '<h2>🔗 WordPress Hooks Status</h2>';

echo '<table border="1" style="border-collapse: collapse;">';
echo '<tr><th>Hook Name</th><th>Status</th><th>Priority</th></tr>';

// Check if hooks are registered
$hooks_to_check = [
    'manage_users_columns' => 'add_customer_column',
    'manage_users_custom_column' => 'show_customer_column_content',
    'admin_head-users.php' => 'inject_users_page_styles',
    'admin_footer-users.php' => 'inject_users_page_javascript',
    'manage_woocommerce_page_wc-customers_columns' => 'add_woocommerce_customer_columns',
    'manage_woocommerce_page_wc-customers_custom_column' => 'show_woocommerce_admin_column_content'
];

foreach ($hooks_to_check as $hook_name => $method_name) {
    if (has_filter($hook_name)) {
        $priority = has_filter($hook_name);
        echo "<tr style='background-color: #e7f7e7;'><td><code>$hook_name</code></td><td>✅ Registered</td><td>$priority</td></tr>";
    } else {
        echo "<tr style='background-color: #ffe7e7;'><td><code>$hook_name</code></td><td>❌ Not found</td><td>-</td></tr>";
    }
}

echo '</table>';

echo '<hr>';

// JavaScript and AJAX Test
echo '<h2>📡 JavaScript & AJAX Test</h2>';

// Check if AJAX endpoints are registered
if (has_action('wp_ajax_flow_get_customer_data')) {
    echo '<p>✅ AJAX endpoint wp_ajax_flow_get_customer_data is registered</p>';
} else {
    echo '<p style="color: red;">❌ AJAX endpoint wp_ajax_flow_get_customer_data is NOT registered</p>';
}

// Add JavaScript test
echo '<div id="js-test-results"></div>';
echo '<script>
console.log("🧪 Flow Interface Test: JavaScript loaded");

// Test if we can detect Flow columns in current page
jQuery(document).ready(function($) {
    console.log("🧪 Flow Interface Test: jQuery ready");

    let testResults = [];

    // Check if Flow columns exist in page
    const flowColumns = $(\'th:contains("Flow")\');
    testResults.push("Flow columns in DOM: " + flowColumns.length);

    // Check if our CSS classes exist
    const hasFlowStyles = $(\'<style>.flow-test {}</style>\').appendTo("head");
    testResults.push("CSS injection capability: ✅");

    // Check AJAX capability
    if (window.ajaxurl || (window.flowCustomerData && window.flowCustomerData.ajaxUrl)) {
        testResults.push("AJAX URL available: ✅");
    } else {
        testResults.push("AJAX URL available: ❌");
    }

    // Display results
    $("#js-test-results").html("<p><strong>JavaScript Test Results:</strong></p><ul><li>" + testResults.join("</li><li>") + "</li></ul>");

    console.log("🧪 Flow Interface Test: Results", testResults);
});
</script>';

echo '<hr>';

// Final Summary
echo '<h2>📋 Summary</h2>';

$wordpress_users_ok = $flow_columns_found === 3;
$woocommerce_customers_ok = isset($wc_flow_columns_found) ? $wc_flow_columns_found === 3 : false;

echo '<table border="1" style="border-collapse: collapse; width: 100%;">';
echo '<tr><th>Interface</th><th>Status</th><th>Notes</th></tr>';

echo '<tr style="background-color: ' . ($wordpress_users_ok ? '#e7f7e7' : '#ffe7e7') . ';">';
echo '<td><strong>WordPress Users</strong></td>';
echo '<td>' . ($wordpress_users_ok ? '✅ Working' : '❌ Issues') . '</td>';
echo '<td>' . ($wordpress_users_ok ? 'All Flow columns detected' : 'Flow columns missing or incomplete') . '</td>';
echo '</tr>';

echo '<tr style="background-color: ' . ($woocommerce_customers_ok ? '#e7f7e7' : '#ffe7e7') . ';">';
echo '<td><strong>WooCommerce Customers</strong></td>';
echo '<td>' . ($woocommerce_customers_ok ? '✅ Working' : '❌ Issues') . '</td>';
echo '<td>' . ($woocommerce_customers_ok ? 'All Flow columns detected' : 'Flow columns missing, incomplete, or WooCommerce not active') . '</td>';
echo '</tr>';

echo '</table>';

if ($wordpress_users_ok && $woocommerce_customers_ok) {
    echo '<p style="color: green; font-weight: bold; font-size: 18px;">🎉 SUCCESS: Flow columns are working in BOTH interfaces!</p>';
    echo '<div style="background: #e7f7e7; padding: 15px; border-radius: 5px; margin: 10px 0;">';
    echo '<h3>✅ What to do next:</h3>';
    echo '<ol>';
    echo '<li><strong>WordPress Users:</strong> Go to <code>Users → All Users</code> to see Flow columns</li>';
    echo '<li><strong>WooCommerce Customers:</strong> Go to <code>WooCommerce → Customers</code> to see Flow columns</li>';
    echo '<li>If columns still don\'t appear, check browser console for JavaScript errors</li>';
    echo '</ol>';
    echo '</div>';
} else {
    echo '<p style="color: red; font-weight: bold; font-size: 18px;">⚠️ ISSUES DETECTED: Some interfaces have problems</p>';
    echo '<div style="background: #ffe7e7; padding: 15px; border-radius: 5px; margin: 10px 0;">';
    echo '<h3>🔧 Troubleshooting steps:</h3>';
    echo '<ol>';
    echo '<li>Check that the Flow plugin is fully activated</li>';
    echo '<li>Verify WordPress and WooCommerce are up to date</li>';
    echo '<li>Look for PHP errors in your error log</li>';
    echo '<li>Check browser console for JavaScript errors</li>';
    echo '</ol>';
    echo '</div>';
}

echo '</div>';
?>