<?php
/**
 * Flow Data Synchronization Tool
 * Run this to sync Flow subscription data from user meta to WooCommerce customer table
 */

// Load WordPress
if (!defined('ABSPATH')) {
    require_once('../../../wp-config.php');
}

// Security check
if (!current_user_can('manage_options')) {
    die('Access denied. Admin privileges required.');
}

echo "<h1>Flow Data Synchronization</h1>\n";

// Load required classes
require_once(dirname(__FILE__) . '/includes/class-flow-woocommerce.php');
require_once(dirname(__FILE__) . '/includes/class-flow-customer-columns.php');

$flow_wc = new Flow_WooCommerce();

if (!$flow_wc->is_woocommerce_available()) {
    echo "<p style='color: red;'>❌ WooCommerce is not available</p>\n";
    exit;
}

echo "<h2>Step 1: Check existing data</h2>\n";

global $wpdb;

// Check user meta for Flow data
$user_meta_count = $wpdb->get_var("
    SELECT COUNT(DISTINCT user_id)
    FROM {$wpdb->usermeta}
    WHERE meta_key IN ('_flow_subscription_id', '_flow_customer_id')
");

echo "<p>Users with Flow data in user meta: <strong>$user_meta_count</strong></p>\n";

// Check WC customer table data
$wc_table = $wpdb->prefix . 'wc_customer_lookup';
$wc_flow_count = $wpdb->get_var("
    SELECT COUNT(*)
    FROM $wc_table
    WHERE flow_subscription_id IS NOT NULL AND flow_subscription_id != ''
");

echo "<p>WooCommerce customers with Flow data: <strong>$wc_flow_count</strong></p>\n";

// If no user meta data exists, create some test data
if ($user_meta_count == 0) {
    echo "<h2>Step 2: Creating test data</h2>\n";

    // Get some customers to add test data to
    $test_customers = $wpdb->get_results("
        SELECT customer_id, email
        FROM $wc_table
        ORDER BY customer_id DESC
        LIMIT 3
    ");

    if ($test_customers) {
        foreach ($test_customers as $customer) {
            // Find corresponding user ID
            $user_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE user_email = %s",
                $customer->email
            ));

            if ($user_id) {
                $test_flow_customer_id = 'flow_test_' . $customer->customer_id;
                $test_subscription_id = 'sub_test_' . uniqid();
                $statuses = ['active', 'inactive', 'pending'];
                $test_status = $statuses[array_rand($statuses)];

                update_user_meta($user_id, '_flow_customer_id', $test_flow_customer_id);
                update_user_meta($user_id, '_flow_subscription_id', $test_subscription_id);
                update_user_meta($user_id, '_flow_subscription_status', $test_status);

                echo "<p style='color: green;'>✅ Added test data for user $user_id ({$customer->email})</p>\n";
                echo "<p>&nbsp;&nbsp;&nbsp;Flow Customer ID: $test_flow_customer_id</p>\n";
                echo "<p>&nbsp;&nbsp;&nbsp;Subscription ID: $test_subscription_id</p>\n";
                echo "<p>&nbsp;&nbsp;&nbsp;Status: $test_status</p>\n";
            }
        }

        // Update count
        $user_meta_count = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id)
            FROM {$wpdb->usermeta}
            WHERE meta_key IN ('_flow_subscription_id', '_flow_customer_id')
        ");

        echo "<p><strong>Total users with Flow data after test: $user_meta_count</strong></p>\n";
    }
}

echo "<h2>Step 3: Synchronizing data</h2>\n";

// Run the synchronization
$synced_count = $flow_wc->sync_flow_data_to_customer_table();

if ($synced_count !== false) {
    echo "<p style='color: green;'>✅ Successfully synchronized <strong>$synced_count</strong> customers</p>\n";
} else {
    echo "<p style='color: red;'>❌ Synchronization failed</p>\n";
}

echo "<h2>Step 4: Verification</h2>\n";

// Check results
$wc_flow_count_after = $wpdb->get_var("
    SELECT COUNT(*)
    FROM $wc_table
    WHERE flow_subscription_id IS NOT NULL AND flow_subscription_id != ''
");

echo "<p>WooCommerce customers with Flow data after sync: <strong>$wc_flow_count_after</strong></p>\n";

// Show sample data
$sample_data = $wpdb->get_results("
    SELECT customer_id, email, flow_customer_id, flow_subscription_id, flow_subscription_status
    FROM $wc_table
    WHERE flow_subscription_id IS NOT NULL AND flow_subscription_id != ''
    ORDER BY customer_id DESC
    LIMIT 10
");

if ($sample_data) {
    echo "<h3>Sample synchronized data:</h3>\n";
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>\n";
    echo "<tr style='background: #f0f0f0;'><th>Customer ID</th><th>Email</th><th>Flow Customer ID</th><th>Subscription ID</th><th>Status</th></tr>\n";

    foreach ($sample_data as $row) {
        $status_color = $row->flow_subscription_status === 'active' ? 'green' :
                       ($row->flow_subscription_status === 'inactive' ? 'red' : 'orange');

        echo "<tr>";
        echo "<td>{$row->customer_id}</td>";
        echo "<td>{$row->email}</td>";
        echo "<td>{$row->flow_customer_id}</td>";
        echo "<td>{$row->flow_subscription_id}</td>";
        echo "<td style='color: $status_color; font-weight: bold;'>{$row->flow_subscription_status}</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
} else {
    echo "<p style='color: red;'>❌ No synchronized data found</p>\n";
}

echo "<h2>Step 5: Set up automatic sync</h2>\n";

// Add a function to automatically sync when user meta is updated
if (!has_action('updated_user_meta', 'flow_auto_sync_customer_data')) {
    add_action('updated_user_meta', function($meta_id, $user_id, $meta_key, $meta_value) {
        if (in_array($meta_key, ['_flow_subscription_id', '_flow_customer_id', '_flow_subscription_status'])) {
            error_log('Flow: Auto-syncing customer data for user ' . $user_id);

            $flow_wc = new Flow_WooCommerce();
            if ($flow_wc->is_woocommerce_available()) {
                // Get user email
                $user = get_user_by('ID', $user_id);
                if ($user) {
                    $flow_customer_id = get_user_meta($user_id, '_flow_customer_id', true);
                    $flow_subscription_id = get_user_meta($user_id, '_flow_subscription_id', true);
                    $flow_status = get_user_meta($user_id, '_flow_subscription_status', true) ?: 'inactive';

                    $flow_wc->update_customer_flow_data_by_email(
                        $user->user_email,
                        $flow_customer_id,
                        $flow_subscription_id,
                        $flow_status
                    );
                }
            }
        }
    }, 10, 4);

    echo "<p style='color: green;'>✅ Set up automatic synchronization hook</p>\n";
} else {
    echo "<p style='color: blue;'>ℹ️ Automatic synchronization hook already exists</p>\n";
}

echo "<h2>Synchronization Complete!</h2>\n";
echo "<p><strong>Next steps:</strong></p>\n";
echo "<ul>\n";
echo "<li>Go to WooCommerce → Customers to see the Flow columns</li>\n";
echo "<li>Go to WordPress → Users to see the Flow columns</li>\n";
echo "<li>Check browser console for JavaScript loading messages</li>\n";
echo "<li>The data should now populate automatically via AJAX</li>\n";
echo "</ul>\n";
?>