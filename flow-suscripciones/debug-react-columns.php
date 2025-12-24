<?php
/**
 * Debug script for React-based WooCommerce Admin columns
 */

if (!defined('ABSPATH')) {
    require_once(__DIR__ . '/../../../../wp-config.php');
}

if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to access this page.');
}

global $wpdb;

echo '<div style="font-family: monospace; padding: 20px; background: #f0f0f0;">';
echo '<h1>🔍 Debug Flow Columns for React Interface</h1>';

// Check database
echo '<h2>📊 Database Check</h2>';
$wc_customer_lookup_table = $wpdb->prefix . 'wc_customer_lookup';

$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$wc_customer_lookup_table'") === $wc_customer_lookup_table;

if (!$table_exists) {
    echo '<p style="color: red;">❌ WooCommerce customer lookup table does not exist!</p>';
    echo '</div>';
    exit;
}

echo '<p style="color: green;">✅ WooCommerce customer lookup table exists</p>';

// Check columns
$columns = $wpdb->get_results("DESCRIBE $wc_customer_lookup_table");
$existing_columns = [];
foreach ($columns as $column) {
    $existing_columns[] = $column->Field;
}

$flow_columns = ['flow_customer_id', 'flow_subscription_id', 'flow_subscription_status'];
$missing_columns = [];

foreach ($flow_columns as $column) {
    if (in_array($column, $existing_columns)) {
        echo "<p style='color: green;'>✅ Column <code>$column</code> exists</p>";
    } else {
        echo "<p style='color: red;'>❌ Column <code>$column</code> is missing</p>";
        $missing_columns[] = $column;
    }
}

if (!empty($missing_columns)) {
    echo '<p style="color: red; font-weight: bold;">⚠️ Missing columns detected! Run the repair tool first.</p>';
}

// Check data
$flow_data_count = $wpdb->get_var("
    SELECT COUNT(*)
    FROM $wc_customer_lookup_table
    WHERE flow_subscription_id IS NOT NULL
    AND flow_subscription_id != ''
");

echo "<p>📊 Found <strong>$flow_data_count</strong> customers with Flow subscription data in lookup table</p>";

// Check user meta data
$user_meta_count = $wpdb->get_var("
    SELECT COUNT(DISTINCT user_id)
    FROM {$wpdb->usermeta}
    WHERE meta_key IN ('_flow_subscription_id', '_flow_customer_id')
");

echo "<p>📊 Found <strong>$user_meta_count</strong> customers with Flow data in user meta</p>";

// Check hooks
echo '<h2>🔧 WordPress Hooks Check</h2>';

$hooks_to_check = [
    'woocommerce_admin_report_columns',
    'woocommerce_admin_report_column_data',
    'woocommerce_admin_customers_report_columns',
    'woocommerce_admin_customers_report_column_data',
    'woocommerce_analytics_customers_data',
    'rest_api_init'
];

foreach ($hooks_to_check as $hook) {
    $callbacks = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wp_hook_$hook'");
    if ($callbacks || has_filter($hook)) {
        echo "<p style='color: green;'>✅ Hook <code>$hook</code> has callbacks</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Hook <code>$hook</code> may not have callbacks</p>";
    }
}

// Check if Flow class is loaded
echo '<h2>🔌 Class Loading Check</h2>';

if (class_exists('Flow_Customer_Columns')) {
    echo '<p style="color: green;">✅ Flow_Customer_Columns class is loaded</p>';

    $reflection = new ReflectionClass('Flow_Customer_Columns');
    $methods = $reflection->getMethods();

    $important_methods = [
        'add_flow_report_columns',
        'add_customers_report_columns',
        'register_flow_customer_api_fields',
        'modify_analytics_customers_data'
    ];

    foreach ($important_methods as $method_name) {
        if ($reflection->hasMethod($method_name)) {
            echo "<p style='color: green;'>✅ Method <code>$method_name</code> exists</p>";
        } else {
            echo "<p style='color: red;'>❌ Method <code>$method_name</code> missing</p>";
        }
    }
} else {
    echo '<p style="color: red;">❌ Flow_Customer_Columns class not loaded</p>';
}

// Check WooCommerce version and admin
echo '<h2>📦 WooCommerce Check</h2>';

if (function_exists('WC')) {
    $wc_version = WC()->version;
    echo "<p style='color: green;'>✅ WooCommerce version: $wc_version</p>";

    if (class_exists('Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore')) {
        echo '<p style="color: green;">✅ WooCommerce Admin API is available</p>';
    } else {
        echo '<p style="color: orange;">⚠️ WooCommerce Admin API may not be available</p>';
    }
} else {
    echo '<p style="color: red;">❌ WooCommerce not active</p>';
}

// Check current screen
echo '<h2>🖥️ Current Screen Check</h2>';
$screen = get_current_screen();
if ($screen) {
    echo "<p>Current Screen ID: <code>{$screen->id}</code></p>";
    echo "<p>Current Screen Base: <code>{$screen->base}</code></p>";
} else {
    echo '<p>No current screen available</p>';
}

echo "<p>Current URL: <code>{$_SERVER['REQUEST_URI']}</code></p>";

// Test REST API endpoint
echo '<h2>🌐 REST API Test</h2>';

$rest_url = rest_url('wc/v3/customers');
echo "<p>REST URL: <a href='$rest_url' target='_blank'>$rest_url</a></p>";

// Test one customer
$sample_customer = $wpdb->get_row("
    SELECT customer_id, flow_subscription_id, flow_subscription_status
    FROM $wc_customer_lookup_table
    WHERE flow_subscription_id IS NOT NULL
    LIMIT 1
");

if ($sample_customer) {
    echo "<h3>📋 Sample Customer Data</h3>";
    echo "<p>Customer ID: <code>{$sample_customer->customer_id}</code></p>";
    echo "<p>Flow Subscription ID: <code>{$sample_customer->flow_subscription_id}</code></p>";
    echo "<p>Flow Status: <code>{$sample_customer->flow_subscription_status}</code></p>";

    // Test URL generation
    $is_production = defined('FLOW_ENVIRONMENT') && FLOW_ENVIRONMENT === 'production';
    $base_url = $is_production ? 'https://dashboard.flow.cl' : 'https://dashboard.sandbox.flow.cl';
    $dashboard_url = $base_url . '/private/suscripciones/subscriptions/details/?sus_id=' . urlencode($sample_customer->flow_subscription_id);

    echo "<p>Generated URL: <a href='$dashboard_url' target='_blank'>$dashboard_url</a></p>";
}

// JavaScript test
echo '<h2>🔧 JavaScript Debug</h2>';
?>
<script>
console.log('🔍 Flow Debug: Testing JavaScript environment');

// Check wp object
if (typeof wp !== 'undefined') {
    console.log('✅ wp object available:', wp);

    if (wp.hooks) {
        console.log('✅ wp.hooks available:', wp.hooks);

        // Test adding a filter
        try {
            wp.hooks.addFilter(
                'woocommerce_admin_customers_report_columns',
                'flow-debug/test',
                function(columns) {
                    console.log('🎯 Filter called with columns:', columns);
                    return columns;
                }
            );
            console.log('✅ Successfully added test filter');
        } catch (error) {
            console.error('❌ Failed to add test filter:', error);
        }
    } else {
        console.error('❌ wp.hooks not available');
    }
} else {
    console.error('❌ wp object not available');
}

// Check current URL
console.log('🌍 Current URL:', window.location.href);
console.log('🌍 URL Search:', window.location.search);
console.log('🌍 URL Hash:', window.location.hash);

// Check for WooCommerce Admin
if (window.wcSettings) {
    console.log('✅ WooCommerce settings available:', window.wcSettings);
} else {
    console.log('❌ WooCommerce settings not available');
}

// Check for React
if (typeof React !== 'undefined') {
    console.log('✅ React available:', React);
} else {
    console.log('❌ React not available');
}

// Check flowCustomerData
if (typeof flowCustomerData !== 'undefined') {
    console.log('✅ flowCustomerData available:', flowCustomerData);
} else {
    console.log('❌ flowCustomerData not available');
}
</script>

<h2>🎯 Recommendations</h2>
<?php
if (!empty($missing_columns)) {
    echo '<p style="color: red; font-weight: bold;">1. Run the repair tool first: Go to Tools → Fix Flow Columns</p>';
}

if ($flow_data_count == 0 && $user_meta_count > 0) {
    echo '<p style="color: orange; font-weight: bold;">2. Data sync needed: Run the repair tool to sync data from user meta to customer table</p>';
}

echo '<p>3. Check browser console (F12) for JavaScript debug messages starting with "🔍 Flow Debug"</p>';
echo '<p>4. If columns still don\'t appear, the React interface may need specific WooCommerce Admin hooks that vary by version</p>';

echo '</div>';
?>