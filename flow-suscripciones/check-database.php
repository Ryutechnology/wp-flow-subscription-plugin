<?php
/**
 * Check database table structure for issues
 */

if (!defined('ABSPATH')) exit;

add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;

    global $wpdb;

    echo '<div class="notice notice-warning">';
    echo '<h3>🔍 Database Table Check</h3>';

    // Check wc_customer_lookup table structure
    $table_name = $wpdb->prefix . 'wc_customer_lookup';

    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

    if (!$table_exists) {
        echo '<p>❌ <strong>Critical:</strong> wc_customer_lookup table does not exist!</p>';
        echo '<p>WooCommerce needs this table for customer analytics.</p>';
        echo '</div>';
        return;
    }

    echo '<p>✅ wc_customer_lookup table exists</p>';

    // Get table structure
    $columns = $wpdb->get_results("DESCRIBE $table_name");

    echo '<p><strong>Table Columns:</strong></p>';
    echo '<ul>';

    $flow_columns = [];
    $core_columns = [];

    foreach ($columns as $column) {
        if (strpos($column->Field, 'flow_') !== false) {
            $flow_columns[] = $column->Field . ' (' . $column->Type . ')';
        } else {
            $core_columns[] = $column->Field . ' (' . $column->Type . ')';
        }
    }

    echo '<li><strong>Core WooCommerce columns:</strong> ' . implode(', ', $core_columns) . '</li>';
    if (!empty($flow_columns)) {
        echo '<li><strong>Flow custom columns:</strong> ' . implode(', ', $flow_columns) . '</li>';
    }
    echo '</ul>';

    // Check for table errors
    $check_result = $wpdb->get_results("CHECK TABLE $table_name");
    foreach ($check_result as $result) {
        if ($result->Msg_text !== 'OK') {
            echo '<p>⚠️ <strong>Table issue:</strong> ' . esc_html($result->Msg_text) . '</p>';
        }
    }

    // Try a simple query to see if table works
    $test_query = $wpdb->get_var("SELECT COUNT(*) FROM $table_name LIMIT 1");
    if ($test_query === null && $wpdb->last_error) {
        echo '<p>❌ <strong>Query Error:</strong> ' . esc_html($wpdb->last_error) . '</p>';
    } else {
        echo '<p>✅ Table queries working (found ' . $test_query . ' customers)</p>';
    }

    echo '</div>';
});