<?php
/**
 * Check Flow Data in Database
 * This script checks what Flow data exists and forces a sync
 */

if (!defined('ABSPATH')) {
    require_once(__DIR__ . '/../../../../wp-config.php');
}

if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to access this page.');
}

global $wpdb;

echo '<div style="font-family: monospace; padding: 20px; background: #f0f0f0;">';
echo '<h1>🔍 Flow Data Check & Sync</h1>';

// Check if wc_customer_lookup table has Flow columns
$wc_customer_lookup_table = $wpdb->prefix . 'wc_customer_lookup';
$columns = $wpdb->get_results("DESCRIBE $wc_customer_lookup_table");
$has_flow_columns = false;

foreach ($columns as $column) {
    if (strpos($column->Field, 'flow_') !== false) {
        $has_flow_columns = true;
        echo "<p>✅ Found Flow column: <code>{$column->Field}</code> ({$column->Type})</p>";
    }
}

if (!$has_flow_columns) {
    echo '<p style="color: red;">❌ <strong>No Flow columns found!</strong> Run the repair tool first.</p>';
    echo '</div>';
    exit;
}

// Check existing data in customer lookup table
$flow_data_count = $wpdb->get_var("
    SELECT COUNT(*)
    FROM $wc_customer_lookup_table
    WHERE flow_subscription_id IS NOT NULL
    AND flow_subscription_id != ''
");

echo "<p><strong>📊 Current data in wc_customer_lookup table:</strong></p>";
echo "<p>Customers with Flow subscription ID: <strong>$flow_data_count</strong></p>";

if ($flow_data_count > 0) {
    // Show sample data
    $sample_data = $wpdb->get_results("
        SELECT customer_id, flow_customer_id, flow_subscription_id, flow_subscription_status
        FROM $wc_customer_lookup_table
        WHERE flow_subscription_id IS NOT NULL
        AND flow_subscription_id != ''
        LIMIT 5
    ");

    echo '<p><strong>Sample data:</strong></p>';
    echo '<table border="1" style="border-collapse: collapse;">';
    echo '<tr><th>Customer ID</th><th>Flow Customer ID</th><th>Flow Subscription ID</th><th>Status</th></tr>';
    foreach ($sample_data as $row) {
        echo "<tr>";
        echo "<td>{$row->customer_id}</td>";
        echo "<td>{$row->flow_customer_id}</td>";
        echo "<td>{$row->flow_subscription_id}</td>";
        echo "<td>{$row->flow_subscription_status}</td>";
        echo "</tr>";
    }
    echo '</table>';
}

// Check user meta data
$user_meta_count = $wpdb->get_var("
    SELECT COUNT(DISTINCT user_id)
    FROM {$wpdb->usermeta}
    WHERE meta_key IN ('_flow_subscription_id', '_flow_customer_id')
");

echo "<p>Customers with Flow data in user meta: <strong>$user_meta_count</strong></p>";

if ($user_meta_count > 0) {
    // Show sample user meta data
    $sample_users = $wpdb->get_results("
        SELECT DISTINCT user_id
        FROM {$wpdb->usermeta}
        WHERE meta_key IN ('_flow_subscription_id', '_flow_customer_id')
        LIMIT 5
    ");

    echo '<p><strong>Sample user meta data:</strong></p>';
    echo '<table border="1" style="border-collapse: collapse;">';
    echo '<tr><th>User ID</th><th>Email</th><th>Flow Subscription ID</th><th>Flow Customer ID</th></tr>';

    foreach ($sample_users as $user_row) {
        $user = get_user_by('ID', $user_row->user_id);
        $flow_sub_id = get_user_meta($user_row->user_id, '_flow_subscription_id', true);
        $flow_cust_id = get_user_meta($user_row->user_id, '_flow_customer_id', true);

        echo "<tr>";
        echo "<td>{$user_row->user_id}</td>";
        echo "<td>" . ($user ? $user->user_email : 'Unknown') . "</td>";
        echo "<td>$flow_sub_id</td>";
        echo "<td>$flow_cust_id</td>";
        echo "</tr>";
    }
    echo '</table>';
}

// If we have user meta data but no customer lookup data, offer to sync
if ($user_meta_count > 0 && $flow_data_count == 0) {
    echo '<h2 style="color: orange;">⚠️ Data Sync Needed</h2>';
    echo '<p>You have Flow data in user meta but it hasn\'t been synced to the customer lookup table.</p>';

    if (isset($_POST['sync_data'])) {
        echo '<h3>🔄 Syncing Data...</h3>';

        // Load the WooCommerce integration class
        if (class_exists('Flow_WooCommerce')) {
            $wc_integration = new Flow_WooCommerce();

            // Get all users with Flow data
            $users_with_flow = $wpdb->get_results("
                SELECT DISTINCT user_id
                FROM {$wpdb->usermeta}
                WHERE meta_key IN ('_flow_subscription_id', '_flow_customer_id')
            ");

            $synced = 0;
            foreach ($users_with_flow as $user_data) {
                $user_id = $user_data->user_id;
                $flow_subscription_id = get_user_meta($user_id, '_flow_subscription_id', true);
                $flow_customer_id = get_user_meta($user_id, '_flow_customer_id', true);

                if ($flow_subscription_id || $flow_customer_id) {
                    // Determine status
                    $status = 'inactive';
                    if ($flow_subscription_id) {
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
                                $status = 'active';
                            }
                        }
                    }

                    // Update customer lookup table
                    $success = $wc_integration->update_customer_flow_data(
                        $user_id,
                        $flow_customer_id,
                        $flow_subscription_id,
                        $status
                    );

                    if ($success) {
                        $synced++;
                        echo "<p>✅ Synced user $user_id</p>";
                    } else {
                        echo "<p>❌ Failed to sync user $user_id</p>";
                    }
                }
            }

            echo "<p><strong>🎉 Sync complete! Updated $synced customers.</strong></p>";
            echo '<p><a href="' . $_SERVER['PHP_SELF'] . '">Refresh to see updated data</a></p>';

        } else {
            echo '<p style="color: red;">❌ Flow_WooCommerce class not found!</p>';
        }

    } else {
        echo '<form method="post">';
        echo '<p><input type="submit" name="sync_data" value="🚀 Sync Flow Data to Customer Table" class="button button-primary"></p>';
        echo '</form>';
    }
}

// Test the AJAX endpoint
echo '<h2>🧪 Test AJAX Endpoint</h2>';

if (isset($_POST['test_ajax']) && $_POST['test_user_id']) {
    $test_user_id = intval($_POST['test_user_id']);

    echo "<p>Testing AJAX for user ID: $test_user_id</p>";

    // Simulate the AJAX call
    $_POST['action'] = 'flow_get_customer_data';
    $_POST['customer_id'] = $test_user_id;
    $_POST['nonce'] = wp_create_nonce('flow_customer_data');

    // Load the customer columns class
    if (class_exists('Flow_Customer_Columns')) {
        $columns_class = new Flow_Customer_Columns();

        // Capture the output
        ob_start();
        $columns_class->ajax_get_customer_data();
        $ajax_output = ob_get_clean();

        echo '<p><strong>AJAX Response:</strong></p>';
        echo '<pre style="background: #f1f1f1; padding: 10px; border-radius: 4px;">';
        echo htmlspecialchars($ajax_output);
        echo '</pre>';
    } else {
        echo '<p style="color: red;">❌ Flow_Customer_Columns class not found!</p>';
    }
}

// Show sample customers for testing
$sample_customers = $wpdb->get_results("
    SELECT customer_id, user_login, user_email
    FROM {$wpdb->prefix}wc_customer_lookup
    LEFT JOIN {$wpdb->users} ON {$wpdb->prefix}wc_customer_lookup.customer_id = {$wpdb->users}.ID
    LIMIT 5
");

if (!empty($sample_customers)) {
    echo '<p><strong>Test AJAX endpoint with a sample customer:</strong></p>';
    echo '<form method="post">';
    echo '<select name="test_user_id">';
    foreach ($sample_customers as $customer) {
        echo "<option value='{$customer->customer_id}'>";
        echo "ID: {$customer->customer_id} - {$customer->user_email}";
        echo "</option>";
    }
    echo '</select>';
    echo ' <input type="submit" name="test_ajax" value="🧪 Test AJAX" class="button">';
    echo '</form>';
}

// Check if the JavaScript is working
echo '<h2>🔧 JavaScript Test</h2>';
echo '<p>Check your browser console for Flow debug messages. You should see:</p>';
echo '<ul>';
echo '<li>🔧 Flow: Admin Footer Script Starting</li>';
echo '<li>🎯 Flow DOM: Starting direct table manipulation (inline)</li>';
echo '<li>🔍 Flow DOM: Scanning for customer tables...</li>';
echo '</ul>';

echo '<script>';
echo 'console.log("✅ Flow Data Check: JavaScript is working");';
echo 'console.log("📊 Flow Data Status:", {';
echo '  flowDataInLookupTable: ' . $flow_data_count . ',';
echo '  flowDataInUserMeta: ' . $user_meta_count . ',';
echo '  hasFlowColumns: ' . ($has_flow_columns ? 'true' : 'false');
echo '});';
echo '</script>';

echo '</div>';
?>