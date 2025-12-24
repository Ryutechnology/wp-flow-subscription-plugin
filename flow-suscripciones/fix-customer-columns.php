<?php
/**
 * Fix Flow customer columns - Add missing columns to wc_customer_lookup table
 *
 * To run this script:
 * 1. Copy this file to your WordPress admin area
 * 2. Access it via: /wp-admin/fix-customer-columns.php
 * 3. Or add it as an admin page (see alternative below)
 */

// Try to load WordPress
if (!defined('ABSPATH')) {
    // Try different paths to find WordPress
    $possible_paths = [
        __DIR__ . '/../../../../wp-config.php',
        __DIR__ . '/../../../wp-config.php',
        __DIR__ . '/../../wp-config.php',
        __DIR__ . '/../wp-config.php'
    ];

    $wp_loaded = false;
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            require_once($path);
            $wp_loaded = true;
            break;
        }
    }

    if (!$wp_loaded) {
        die('Could not load WordPress. Please run this script from WordPress admin or copy it to wp-admin directory.');
    }
}

// Check authorization
if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to run this script. Please log in as an administrator.');
}

global $wpdb;

echo "<h2>🔧 Flow Customer Columns Repair</h2>\n";

// Get the WooCommerce customer lookup table name
$wc_customer_lookup_table = $wpdb->prefix . 'wc_customer_lookup';

// Check if table exists
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$wc_customer_lookup_table'") === $wc_customer_lookup_table;

if (!$table_exists) {
    echo "<p>❌ <strong>Error:</strong> WooCommerce customer lookup table does not exist!</p>\n";
    exit;
}

echo "<p>✅ WooCommerce customer lookup table found: $wc_customer_lookup_table</p>\n";

// Get current table structure
$current_columns = $wpdb->get_results("DESCRIBE $wc_customer_lookup_table");
$existing_columns = [];
foreach ($current_columns as $column) {
    $existing_columns[] = $column->Field;
}

echo "<p><strong>Current columns:</strong> " . implode(', ', $existing_columns) . "</p>\n";

// Define Flow columns to add
$flow_columns = [
    'flow_customer_id' => 'VARCHAR(100) DEFAULT NULL',
    'flow_subscription_id' => 'VARCHAR(100) DEFAULT NULL',
    'flow_subscription_status' => 'VARCHAR(20) DEFAULT NULL'
];

$added_columns = [];

// Add missing columns
foreach ($flow_columns as $column_name => $column_definition) {
    if (!in_array($column_name, $existing_columns)) {
        echo "<p>➕ Adding column: $column_name</p>\n";

        $sql = "ALTER TABLE `$wc_customer_lookup_table` ADD COLUMN `$column_name` $column_definition";
        $result = $wpdb->query($sql);

        if ($result === false) {
            echo "<p>❌ <strong>Failed to add $column_name:</strong> " . $wpdb->last_error . "</p>\n";
        } else {
            echo "<p>✅ Successfully added $column_name</p>\n";
            $added_columns[] = $column_name;
        }
    } else {
        echo "<p>ℹ️ Column $column_name already exists</p>\n";
    }
}

// Verify the columns were added
if (!empty($added_columns)) {
    echo "<p><strong>Verifying added columns...</strong></p>\n";
    $updated_columns = $wpdb->get_results("DESCRIBE $wc_customer_lookup_table");
    $new_column_list = [];
    foreach ($updated_columns as $column) {
        $new_column_list[] = $column->Field;
    }

    foreach ($added_columns as $column_name) {
        if (in_array($column_name, $new_column_list)) {
            echo "<p>✅ Verified: $column_name exists in table</p>\n";
        } else {
            echo "<p>❌ Verification failed: $column_name not found in table</p>\n";
        }
    }
}

// Sync existing Flow data from user meta to customer lookup table
echo "<h3>📊 Syncing Flow Data</h3>\n";

// Get all customers with Flow subscription data in user meta
$customers_with_flow_data = $wpdb->get_results("
    SELECT DISTINCT user_id
    FROM {$wpdb->usermeta}
    WHERE meta_key IN ('_flow_subscription_id', '_flow_customer_id')
");

if (empty($customers_with_flow_data)) {
    echo "<p>ℹ️ No customers found with Flow data in user meta</p>\n";
} else {
    echo "<p>Found " . count($customers_with_flow_data) . " customers with Flow data</p>\n";

    $synced_count = 0;
    foreach ($customers_with_flow_data as $customer_data) {
        $user_id = $customer_data->user_id;

        // Get Flow data from user meta
        $flow_subscription_id = get_user_meta($user_id, '_flow_subscription_id', true);
        $flow_customer_id = get_user_meta($user_id, '_flow_customer_id', true);

        // Skip if no data
        if (empty($flow_subscription_id) && empty($flow_customer_id)) {
            continue;
        }

        // Check if customer exists in lookup table
        $customer_lookup_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT customer_id FROM $wc_customer_lookup_table WHERE customer_id = %d",
            $user_id
        ));

        if (!$customer_lookup_exists) {
            echo "<p>⚠️ Customer ID $user_id not found in WooCommerce customer lookup table</p>\n";
            continue;
        }

        // Determine subscription status
        $subscription_status = 'inactive';
        if (!empty($flow_subscription_id)) {
            // Check for recent orders
            $recent_orders = wc_get_orders([
                'customer_id' => $user_id,
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC',
                'status' => ['completed', 'processing']
            ]);

            if (!empty($recent_orders)) {
                $latest_order = $recent_orders[0];
                $days_since_order = (time() - $latest_order->get_date_created()->getTimestamp()) / (24 * 60 * 60);

                if ($days_since_order <= 35) {
                    $subscription_status = 'active';
                }
            }
        }

        // Update customer lookup table
        $update_data = [];
        $update_format = [];

        if (!empty($flow_customer_id)) {
            $update_data['flow_customer_id'] = $flow_customer_id;
            $update_format[] = '%s';
        }

        if (!empty($flow_subscription_id)) {
            $update_data['flow_subscription_id'] = $flow_subscription_id;
            $update_format[] = '%s';
        }

        $update_data['flow_subscription_status'] = $subscription_status;
        $update_format[] = '%s';

        $result = $wpdb->update(
            $wc_customer_lookup_table,
            $update_data,
            ['customer_id' => $user_id],
            $update_format,
            ['%d']
        );

        if ($result !== false) {
            $synced_count++;
            echo "<p>✅ Synced customer $user_id: Flow ID: $flow_customer_id, Subscription ID: $flow_subscription_id, Status: $subscription_status</p>\n";
        } else {
            echo "<p>❌ Failed to sync customer $user_id: " . $wpdb->last_error . "</p>\n";
        }
    }

    echo "<p><strong>✅ Sync complete: $synced_count customers updated</strong></p>\n";
}

// Clear WooCommerce caches
if (function_exists('wc_delete_shop_order_transients')) {
    wc_delete_shop_order_transients();
    echo "<p>🧹 Cleared WooCommerce transients</p>\n";
}

// Clear any WooCommerce admin cache
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_admin%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wc_admin%'");
echo "<p>🧹 Cleared WooCommerce admin cache</p>\n";

echo "<h3>🎉 Repair Complete!</h3>\n";
echo "<p>The Flow customer columns should now be visible in your WooCommerce customers view.</p>\n";
echo "<p><strong>Next steps:</strong></p>\n";
echo "<ul>\n";
echo "<li>Go to WooCommerce → Analytics → Customers to see the new columns</li>\n";
echo "<li>The columns should show Flow Status, Flow Subscription ID, and Flow Dashboard links</li>\n";
echo "<li>If you still don't see them, try refreshing the page or clearing your browser cache</li>\n";
echo "</ul>\n";