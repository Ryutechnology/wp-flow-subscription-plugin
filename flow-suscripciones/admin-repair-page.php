<?php
/**
 * Temporary admin page for repairing Flow customer columns
 * Add this code to your functions.php or activate as a plugin temporarily
 */

if (!defined('ABSPATH')) exit;

// Add admin menu item
add_action('admin_menu', 'flow_add_repair_admin_menu');

function flow_add_repair_admin_menu() {
    add_management_page(
        'Fix Flow Customer Columns',
        'Fix Flow Columns',
        'manage_options',
        'fix-flow-columns',
        'flow_repair_columns_admin_page'
    );
}

function flow_repair_columns_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }

    // Handle form submission
    if (isset($_POST['run_repair']) && wp_verify_nonce($_POST['flow_repair_nonce'], 'flow_repair_action')) {
        flow_run_column_repair();
        return;
    }

    // Display the admin page
    ?>
    <div class="wrap">
        <h1>🔧 Fix Flow Customer Columns</h1>

        <div class="notice notice-info">
            <p><strong>What this does:</strong></p>
            <ul>
                <li>Adds missing Flow columns to the WooCommerce customer lookup table</li>
                <li>Syncs existing Flow subscription data</li>
                <li>Clears WooCommerce caches</li>
                <li>Makes the Flow columns visible in WooCommerce → Analytics → Customers</li>
            </ul>
        </div>

        <div class="notice notice-warning">
            <p><strong>⚠️ Important:</strong> This will modify your database. Make sure you have a backup before proceeding.</p>
        </div>

        <form method="post" style="margin-top: 20px;">
            <?php wp_nonce_field('flow_repair_action', 'flow_repair_nonce'); ?>
            <p>
                <input type="submit" name="run_repair" class="button button-primary button-large"
                       value="🚀 Fix Flow Customer Columns"
                       onclick="return confirm('Are you sure you want to run the repair? This will modify your database.');">
            </p>
        </form>

        <h3>Current Status</h3>
        <?php flow_show_current_status(); ?>
    </div>
    <?php
}

function flow_show_current_status() {
    global $wpdb;

    $wc_customer_lookup_table = $wpdb->prefix . 'wc_customer_lookup';

    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$wc_customer_lookup_table'") === $wc_customer_lookup_table;

    echo '<div class="notice notice-info inline">';

    if (!$table_exists) {
        echo '<p>❌ <strong>Critical:</strong> WooCommerce customer lookup table does not exist!</p>';
    } else {
        echo '<p>✅ WooCommerce customer lookup table exists</p>';

        // Check for Flow columns
        $columns = $wpdb->get_results("DESCRIBE $wc_customer_lookup_table");
        $existing_columns = [];
        foreach ($columns as $column) {
            $existing_columns[] = $column->Field;
        }

        $flow_columns = ['flow_customer_id', 'flow_subscription_id', 'flow_subscription_status'];

        foreach ($flow_columns as $column) {
            if (in_array($column, $existing_columns)) {
                echo "<p>✅ Column <code>$column</code> exists</p>";
            } else {
                echo "<p>❌ Column <code>$column</code> is missing</p>";
            }
        }

        // Count customers with Flow data
        $customers_with_flow = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id)
            FROM {$wpdb->usermeta}
            WHERE meta_key IN ('_flow_subscription_id', '_flow_customer_id')
        ");

        echo "<p>📊 Found <strong>$customers_with_flow</strong> customers with Flow data in user meta</p>";
    }

    echo '</div>';
}

function flow_run_column_repair() {
    global $wpdb;

    echo '<div class="wrap">';
    echo '<h1>🔧 Flow Customer Columns Repair Results</h1>';

    // Get the WooCommerce customer lookup table name
    $wc_customer_lookup_table = $wpdb->prefix . 'wc_customer_lookup';

    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$wc_customer_lookup_table'") === $wc_customer_lookup_table;

    if (!$table_exists) {
        echo '<div class="notice notice-error"><p>❌ <strong>Error:</strong> WooCommerce customer lookup table does not exist!</p></div>';
        echo '</div>';
        return;
    }

    echo '<div class="notice notice-success"><p>✅ WooCommerce customer lookup table found: ' . $wc_customer_lookup_table . '</p></div>';

    // Get current table structure
    $current_columns = $wpdb->get_results("DESCRIBE $wc_customer_lookup_table");
    $existing_columns = [];
    foreach ($current_columns as $column) {
        $existing_columns[] = $column->Field;
    }

    echo '<p><strong>Current columns:</strong> ' . implode(', ', $existing_columns) . '</p>';

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
            echo '<p>➕ Adding column: ' . $column_name . '</p>';

            $sql = "ALTER TABLE `$wc_customer_lookup_table` ADD COLUMN `$column_name` $column_definition";
            $result = $wpdb->query($sql);

            if ($result === false) {
                echo '<div class="notice notice-error inline"><p>❌ <strong>Failed to add ' . $column_name . ':</strong> ' . $wpdb->last_error . '</p></div>';
            } else {
                echo '<div class="notice notice-success inline"><p>✅ Successfully added ' . $column_name . '</p></div>';
                $added_columns[] = $column_name;
            }
        } else {
            echo '<p>ℹ️ Column ' . $column_name . ' already exists</p>';
        }
    }

    // Verify the columns were added
    if (!empty($added_columns)) {
        echo '<h3>📋 Verifying Added Columns</h3>';
        $updated_columns = $wpdb->get_results("DESCRIBE $wc_customer_lookup_table");
        $new_column_list = [];
        foreach ($updated_columns as $column) {
            $new_column_list[] = $column->Field;
        }

        foreach ($added_columns as $column_name) {
            if (in_array($column_name, $new_column_list)) {
                echo '<div class="notice notice-success inline"><p>✅ Verified: ' . $column_name . ' exists in table</p></div>';
            } else {
                echo '<div class="notice notice-error inline"><p>❌ Verification failed: ' . $column_name . ' not found in table</p></div>';
            }
        }
    }

    // Sync existing Flow data from user meta to customer lookup table
    echo '<h3>📊 Syncing Flow Data</h3>';

    // Get all customers with Flow subscription data in user meta
    $customers_with_flow_data = $wpdb->get_results("
        SELECT DISTINCT user_id
        FROM {$wpdb->usermeta}
        WHERE meta_key IN ('_flow_subscription_id', '_flow_customer_id')
    ");

    if (empty($customers_with_flow_data)) {
        echo '<p>ℹ️ No customers found with Flow data in user meta</p>';
    } else {
        echo '<p>Found ' . count($customers_with_flow_data) . ' customers with Flow data</p>';

        $synced_count = 0;
        $errors_count = 0;

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
                echo '<p>⚠️ Customer ID ' . $user_id . ' not found in WooCommerce customer lookup table</p>';
                $errors_count++;
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
                echo '<p>✅ Synced customer ' . $user_id . ': Flow ID: ' . $flow_customer_id . ', Subscription ID: ' . $flow_subscription_id . ', Status: ' . $subscription_status . '</p>';
            } else {
                echo '<p>❌ Failed to sync customer ' . $user_id . ': ' . $wpdb->last_error . '</p>';
                $errors_count++;
            }
        }

        echo '<div class="notice notice-success"><p><strong>✅ Sync complete: ' . $synced_count . ' customers updated</strong></p></div>';

        if ($errors_count > 0) {
            echo '<div class="notice notice-warning"><p><strong>⚠️ ' . $errors_count . ' errors encountered during sync</strong></p></div>';
        }
    }

    // Clear WooCommerce caches
    if (function_exists('wc_delete_shop_order_transients')) {
        wc_delete_shop_order_transients();
        echo '<p>🧹 Cleared WooCommerce transients</p>';
    }

    // Clear any WooCommerce admin cache
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_admin%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wc_admin%'");
    echo '<p>🧹 Cleared WooCommerce admin cache</p>';

    echo '<div class="notice notice-success">';
    echo '<h3>🎉 Repair Complete!</h3>';
    echo '<p>The Flow customer columns should now be visible in your WooCommerce customers view.</p>';
    echo '<p><strong>Next steps:</strong></p>';
    echo '<ul>';
    echo '<li>Go to <strong>WooCommerce → Analytics → Customers</strong> to see the new columns</li>';
    echo '<li>The columns should show: <strong>Flow Status</strong>, <strong>Flow Subscription ID</strong>, and <strong>Flow Dashboard</strong> links</li>';
    echo '<li>You can also see them in <strong>Users → All Users</strong> when filtering by Customer role</li>';
    echo '<li>If you still don\'t see them, try refreshing the page or clearing your browser cache</li>';
    echo '</ul>';
    echo '</div>';

    echo '</div>';
}

// Add notice to remind about temporary page
add_action('admin_notices', 'flow_repair_admin_notice');

function flow_repair_admin_notice() {
    $screen = get_current_screen();
    if ($screen && $screen->id !== 'tools_page_fix-flow-columns') {
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>Flow Customer Columns:</strong> ';
        echo 'If you can\'t see the new Flow columns, <a href="' . admin_url('tools.php?page=fix-flow-columns') . '">run the repair tool</a> to fix the database.';
        echo '</p>';
        echo '</div>';
    }
}