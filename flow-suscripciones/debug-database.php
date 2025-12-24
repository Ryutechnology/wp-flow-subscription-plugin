<?php
/**
 * Flow Subscription Database Debug Tool
 * Run this file to check and populate database columns
 */

// Load WordPress
require_once('../../../wp-config.php');

if (!is_admin() && !defined('WP_CLI')) {
    die('Access denied. Run this from WordPress admin or CLI.');
}

global $wpdb;

echo "<h1>Flow Subscription Database Debug</h1>\n";

// Check if WooCommerce customer lookup table exists
$wc_table = $wpdb->prefix . 'wc_customer_lookup';
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$wc_table'");

if (!$table_exists) {
    echo "<p style='color: red;'>❌ WooCommerce customer lookup table does not exist: $wc_table</p>\n";
    echo "<p>Please ensure WooCommerce is properly installed.</p>\n";
    exit;
}

echo "<p style='color: green;'>✅ WooCommerce customer lookup table exists: $wc_table</p>\n";

// Check if Flow columns exist
$flow_columns = ['flow_customer_id', 'flow_subscription_id', 'flow_subscription_status'];
$existing_columns = [];

foreach ($flow_columns as $column) {
    $column_exists = $wpdb->get_results($wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = %s AND COLUMN_NAME = %s AND TABLE_SCHEMA = %s",
        $wc_table,
        $column,
        DB_NAME
    ));

    if ($column_exists) {
        $existing_columns[] = $column;
        echo "<p style='color: green;'>✅ Column exists: $column</p>\n";
    } else {
        echo "<p style='color: red;'>❌ Column missing: $column</p>\n";
    }
}

// Create missing columns
foreach ($flow_columns as $column) {
    if (!in_array($column, $existing_columns)) {
        echo "<p>🔧 Creating column: $column</p>\n";

        $column_type = 'VARCHAR(100) DEFAULT NULL';
        if ($column === 'flow_subscription_status') {
            $column_type = 'VARCHAR(20) DEFAULT NULL';
        }

        $result = $wpdb->query("ALTER TABLE `$wc_table` ADD COLUMN `$column` $column_type");

        if ($result !== false) {
            echo "<p style='color: green;'>✅ Successfully created column: $column</p>\n";
        } else {
            echo "<p style='color: red;'>❌ Failed to create column: $column - Error: " . $wpdb->last_error . "</p>\n";
        }
    }
}

// Check current data in WooCommerce customer table
echo "<h2>Current Customer Data</h2>\n";

$customers = $wpdb->get_results("SELECT customer_id, username, email, flow_customer_id, flow_subscription_id, flow_subscription_status FROM $wc_table ORDER BY customer_id DESC LIMIT 10");

if ($customers) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>\n";
    echo "<tr><th>Customer ID</th><th>Username</th><th>Email</th><th>Flow Customer ID</th><th>Flow Subscription ID</th><th>Flow Status</th></tr>\n";

    foreach ($customers as $customer) {
        $flow_customer_id = $customer->flow_customer_id ?? 'NULL';
        $flow_subscription_id = $customer->flow_subscription_id ?? 'NULL';
        $flow_status = $customer->flow_subscription_status ?? 'NULL';

        echo "<tr>";
        echo "<td>{$customer->customer_id}</td>";
        echo "<td>" . ($customer->username ?: 'N/A') . "</td>";
        echo "<td>{$customer->email}</td>";
        echo "<td>$flow_customer_id</td>";
        echo "<td>$flow_subscription_id</td>";
        echo "<td>$flow_status</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
} else {
    echo "<p>No customers found in WooCommerce customer lookup table.</p>\n";
}

// Check Flow subscription table
echo "<h2>Flow Subscription Table Data</h2>\n";

$flow_table = $wpdb->prefix . 'flow_subscriptions';
$flow_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$flow_table'");

if ($flow_table_exists) {
    echo "<p style='color: green;'>✅ Flow subscriptions table exists: $flow_table</p>\n";

    $flow_subs = $wpdb->get_results("SELECT * FROM $flow_table ORDER BY id DESC LIMIT 10");

    if ($flow_subs) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>\n";
        echo "<tr><th>ID</th><th>Email</th><th>Name</th><th>Plan ID</th><th>Flow Customer ID</th><th>Status</th></tr>\n";

        foreach ($flow_subs as $sub) {
            echo "<tr>";
            echo "<td>{$sub->id}</td>";
            echo "<td>{$sub->email}</td>";
            echo "<td>{$sub->name}</td>";
            echo "<td>" . ($sub->plan_id ?? 'N/A') . "</td>";
            echo "<td>" . ($sub->flow_customer_id ?? 'N/A') . "</td>";
            echo "<td>" . ($sub->status ?? 'N/A') . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    } else {
        echo "<p>No data found in Flow subscriptions table.</p>\n";
    }
} else {
    echo "<p style='color: red;'>❌ Flow subscriptions table does not exist: $flow_table</p>\n";
}

// Check user meta for Flow data
echo "<h2>User Meta Flow Data</h2>\n";

$user_meta_flow = $wpdb->get_results("
    SELECT u.ID, u.user_email,
           MAX(CASE WHEN um.meta_key = '_flow_subscription_id' THEN um.meta_value END) as flow_subscription_id,
           MAX(CASE WHEN um.meta_key = '_flow_customer_id' THEN um.meta_value END) as flow_customer_id,
           MAX(CASE WHEN um.meta_key = '_flow_subscription_status' THEN um.meta_value END) as flow_status
    FROM {$wpdb->users} u
    INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
    WHERE um.meta_key IN ('_flow_subscription_id', '_flow_customer_id', '_flow_subscription_status')
    GROUP BY u.ID
    ORDER BY u.ID DESC
    LIMIT 10
");

if ($user_meta_flow) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>\n";
    echo "<tr><th>User ID</th><th>Email</th><th>Flow Subscription ID</th><th>Flow Customer ID</th><th>Flow Status</th></tr>\n";

    foreach ($user_meta_flow as $user) {
        echo "<tr>";
        echo "<td>{$user->ID}</td>";
        echo "<td>{$user->user_email}</td>";
        echo "<td>" . ($user->flow_subscription_id ?: 'NULL') . "</td>";
        echo "<td>" . ($user->flow_customer_id ?: 'NULL') . "</td>";
        echo "<td>" . ($user->flow_status ?: 'NULL') . "</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
} else {
    echo "<p>No Flow subscription data found in user meta.</p>\n";
}

// Add some test data if none exists
if (empty($user_meta_flow) && empty($flow_subs)) {
    echo "<h2>Adding Test Data</h2>\n";

    // Get a customer to add test data to
    $test_customer = $wpdb->get_row("SELECT customer_id, email FROM $wc_table ORDER BY customer_id DESC LIMIT 1");

    if ($test_customer) {
        echo "<p>🔧 Adding test Flow data for customer: {$test_customer->email}</p>\n";

        $test_flow_id = 'test-' . uniqid();
        $test_sub_id = 'sub-' . uniqid();

        // Update WooCommerce customer lookup table
        $update_result = $wpdb->update(
            $wc_table,
            array(
                'flow_customer_id' => $test_flow_id,
                'flow_subscription_id' => $test_sub_id,
                'flow_subscription_status' => 'active'
            ),
            array('customer_id' => $test_customer->customer_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        if ($update_result !== false) {
            echo "<p style='color: green;'>✅ Added test data to WooCommerce customer lookup table</p>\n";
            echo "<p>Customer ID: {$test_customer->customer_id}</p>\n";
            echo "<p>Flow Customer ID: $test_flow_id</p>\n";
            echo "<p>Flow Subscription ID: $test_sub_id</p>\n";
            echo "<p>Status: active</p>\n";
        } else {
            echo "<p style='color: red;'>❌ Failed to add test data: " . $wpdb->last_error . "</p>\n";
        }

        // Also add to user meta
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} um
             INNER JOIN {$wpdb->users} u ON u.ID = um.user_id
             WHERE um.meta_key = 'billing_email' AND um.meta_value = %s
             LIMIT 1",
            $test_customer->email
        ));

        if (!$user_id) {
            // Try to find user by email directly
            $user_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE user_email = %s LIMIT 1",
                $test_customer->email
            ));
        }

        if ($user_id) {
            update_user_meta($user_id, '_flow_customer_id', $test_flow_id);
            update_user_meta($user_id, '_flow_subscription_id', $test_sub_id);
            update_user_meta($user_id, '_flow_subscription_status', 'active');
            echo "<p style='color: green;'>✅ Added test data to user meta for user ID: $user_id</p>\n";
        }
    }
}

echo "<h2>Database Debug Complete</h2>\n";
echo "<p>You can now test the Flow customer columns functionality.</p>\n";
?>