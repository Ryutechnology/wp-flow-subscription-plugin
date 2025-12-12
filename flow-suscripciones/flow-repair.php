<?php
/**
 * Flow Plugin Repair Tool
 * Attempt to fix common installation issues
 */

if (!defined('ABSPATH')) exit;

// Add repair tool to admin menu
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Flow Plugin Repair',
        'Flow Repair',
        'manage_options',
        'flow-repair',
        'flow_repair_page'
    );
});

function flow_repair_page() {
    if (isset($_POST['run_repair'])) {
        run_flow_repair();
        return;
    }

    if (isset($_POST['force_reload'])) {
        force_reload_flow_components();
        return;
    }

    echo '<div class="wrap">';
    echo '<h1>🔧 Flow Plugin Repair Tool</h1>';
    echo '<p>This tool will attempt to fix common issues preventing the Flow payment gateway and columns from working.</p>';

    echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0;">';
    echo '<h3>⚠️ Important</h3>';
    echo '<p>Before using this tool:</p>';
    echo '<ul>';
    echo '<li>Make sure WooCommerce is active and working</li>';
    echo '<li>Backup your website (this tool will modify database tables)</li>';
    echo '<li>Deactivate any caching plugins temporarily</li>';
    echo '</ul>';
    echo '</div>';

    echo '<form method="post">';
    wp_nonce_field('flow_repair_action', 'flow_repair_nonce');
    echo '<p><input type="submit" name="run_repair" value="🔧 Run Auto Repair" class="button-primary" onclick="return confirm(\'Are you sure? This will modify database tables and force re-register components.\')"></p>';
    echo '</form>';

    echo '<form method="post">';
    wp_nonce_field('flow_force_reload', 'flow_reload_nonce');
    echo '<p><input type="submit" name="force_reload" value="🔄 Force Reload Components" class="button-secondary" onclick="return confirm(\'This will force reload all Flow components.\')"></p>';
    echo '</form>';

    echo '<p><a href="?page=flow-diagnostic" class="button">📋 Run Diagnostic First</a></p>';
    echo '</div>';
}

function run_flow_repair() {
    if (!wp_verify_nonce($_POST['flow_repair_nonce'], 'flow_repair_action')) {
        wp_die('Security check failed');
    }

    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }

    echo '<div class="wrap">';
    echo '<h1>🔧 Flow Plugin Repair Results</h1>';
    echo '<div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccc;">';

    $repairs = 0;
    $errors = 0;

    // Repair 1: Force reload all classes
    echo '<h2>1. Reloading Flow Classes</h2>';
    try {
        $plugin_dir = plugin_dir_path(__FILE__);

        // Load loader class
        if (file_exists($plugin_dir . 'includes/class-flow-loader.php')) {
            require_once $plugin_dir . 'includes/class-flow-loader.php';
            if (class_exists('Flow_Loader')) {
                Flow_Loader::register();
                Flow_Loader::load_classes();
                echo '<p>✅ Flow classes reloaded successfully</p>';
                $repairs++;
            }
        }
    } catch (Exception $e) {
        echo '<p>❌ Error reloading classes: ' . esc_html($e->getMessage()) . '</p>';
        $errors++;
    }

    // Repair 2: Force WooCommerce integration
    echo '<h2>2. Forcing WooCommerce Integration</h2>';
    try {
        if (class_exists('WooCommerce') && class_exists('Flow_WooCommerce')) {
            $wc_integration = new Flow_WooCommerce();

            // Force early initialization
            $wc_integration->init_early();
            $wc_integration->force_gateway_registration();

            echo '<p>✅ WooCommerce integration forced</p>';
            $repairs++;
        } else {
            echo '<p>⚠️ WooCommerce or Flow_WooCommerce not available</p>';
        }
    } catch (Exception $e) {
        echo '<p>❌ Error with WooCommerce integration: ' . esc_html($e->getMessage()) . '</p>';
        $errors++;
    }

    // Repair 3: Add missing database columns
    echo '<h2>3. Checking/Adding Database Columns</h2>';
    try {
        global $wpdb;

        // Check if WooCommerce customer lookup table exists
        $table_name = $wpdb->prefix . 'wc_customer_lookup';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        if ($table_exists) {
            $columns = $wpdb->get_col("DESCRIBE $table_name");
            $columns_added = 0;

            // Add flow_subscription_status column if missing
            if (!in_array('flow_subscription_status', $columns)) {
                $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN flow_subscription_status VARCHAR(50) DEFAULT NULL");
                if ($result !== false) {
                    echo '<p>✅ Added flow_subscription_status column</p>';
                    $columns_added++;
                } else {
                    echo '<p>❌ Failed to add flow_subscription_status column</p>';
                }
            }

            // Add flow_subscription_id column if missing
            if (!in_array('flow_subscription_id', $columns)) {
                $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN flow_subscription_id VARCHAR(100) DEFAULT NULL");
                if ($result !== false) {
                    echo '<p>✅ Added flow_subscription_id column</p>';
                    $columns_added++;
                } else {
                    echo '<p>❌ Failed to add flow_subscription_id column</p>';
                }
            }

            // Add flow_customer_id column if missing
            if (!in_array('flow_customer_id', $columns)) {
                $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN flow_customer_id VARCHAR(100) DEFAULT NULL");
                if ($result !== false) {
                    echo '<p>✅ Added flow_customer_id column</p>';
                    $columns_added++;
                } else {
                    echo '<p>❌ Failed to add flow_customer_id column</p>';
                }
            }

            if ($columns_added === 0) {
                echo '<p>✅ All required columns already exist</p>';
            }

            $repairs += $columns_added;
        } else {
            echo '<p>⚠️ WooCommerce customer lookup table not found</p>';
        }
    } catch (Exception $e) {
        echo '<p>❌ Database error: ' . esc_html($e->getMessage()) . '</p>';
        $errors++;
    }

    // Repair 4: Force re-register payment gateway
    echo '<h2>4. Re-registering Payment Gateway</h2>';
    try {
        if (function_exists('WC') && WC()->payment_gateways()) {
            // Clear payment gateways cache
            WC()->payment_gateways()->payment_gateways = null;
            WC()->payment_gateways()->init();

            // Force add Flow gateway
            add_filter('woocommerce_payment_gateways', function($gateways) {
                if (!in_array('Flow_Payment_Gateway', $gateways)) {
                    $gateways[] = 'Flow_Payment_Gateway';
                }
                return $gateways;
            }, 999);

            echo '<p>✅ Payment gateway re-registration forced</p>';
            $repairs++;
        }
    } catch (Exception $e) {
        echo '<p>❌ Error re-registering payment gateway: ' . esc_html($e->getMessage()) . '</p>';
        $errors++;
    }

    // Repair 5: Clear relevant caches
    echo '<h2>5. Clearing Caches</h2>';
    try {
        // Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Clear WooCommerce transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wc_%'");

        // Clear Flow-specific transients
        delete_transient('flow_cleaned_wc_transients');

        echo '<p>✅ Caches cleared</p>';
        $repairs++;
    } catch (Exception $e) {
        echo '<p>❌ Error clearing caches: ' . esc_html($e->getMessage()) . '</p>';
        $errors++;
    }

    // Repair 6: Force plugin re-activation
    echo '<h2>6. Force Plugin Re-activation</h2>';
    try {
        // Run activation hooks
        if (class_exists('Flow_Activator')) {
            Flow_Activator::activate();
            echo '<p>✅ Activation hooks re-run</p>';
            $repairs++;
        }
    } catch (Exception $e) {
        echo '<p>❌ Error running activation: ' . esc_html($e->getMessage()) . '</p>';
        $errors++;
    }

    // Summary
    echo '<h2>7. Repair Summary</h2>';
    if ($repairs > 0) {
        echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 10px 0;">';
        echo "<h3>✅ Repair Completed</h3>";
        echo "<p>Successfully completed {$repairs} repair operation(s).</p>";
        if ($errors > 0) {
            echo "<p>⚠️ {$errors} operation(s) had errors - check above for details.</p>";
        }
        echo '<p><strong>Next Steps:</strong></p>';
        echo '<ul>';
        echo '<li>Go to WooCommerce → Settings → Payments to verify Flow gateway appears</li>';
        echo '<li>Check WooCommerce → Customers to see if Flow columns are visible</li>';
        echo '<li>If issues persist, try deactivating and reactivating the plugin</li>';
        echo '</ul>';
        echo '</div>';
    } else {
        echo '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 10px 0;">';
        echo '<h3>❌ No Repairs Completed</h3>';
        echo '<p>The repair tool could not complete any operations. This may indicate:</p>';
        echo '<ul>';
        echo '<li>The plugin is already working correctly</li>';
        echo '<li>There are deeper compatibility issues</li>';
        echo '<li>File permissions or server configuration problems</li>';
        echo '</ul>';
        echo '</div>';
    }

    echo '</div>';
    echo '<p><a href="?page=flow-repair" class="button">← Back to Repair Tool</a></p>';
    echo '<p><a href="?page=flow-diagnostic&run_tests=1" class="button">🔍 Run Diagnostic Again</a></p>';
    echo '</div>';
}

function force_reload_flow_components() {
    if (!wp_verify_nonce($_POST['flow_reload_nonce'], 'flow_force_reload')) {
        wp_die('Security check failed');
    }

    echo '<div class="wrap">';
    echo '<h1>🔄 Force Reload Results</h1>';
    echo '<div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccc;">';

    try {
        // Force instantiate all Flow components
        echo '<h2>Force Loading Components</h2>';

        if (class_exists('Flow_WooCommerce')) {
            new Flow_WooCommerce();
            echo '<p>✅ Flow_WooCommerce instantiated</p>';
        }

        if (class_exists('Flow_Customer_Columns')) {
            new Flow_Customer_Columns();
            echo '<p>✅ Flow_Customer_Columns instantiated</p>';
        }

        if (class_exists('Flow_Shortcode')) {
            new Flow_Shortcode();
            echo '<p>✅ Flow_Shortcode instantiated</p>';
        }

        echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 10px 0;">';
        echo '<h3>✅ Components Reloaded</h3>';
        echo '<p>All available Flow components have been force-loaded.</p>';
        echo '</div>';

    } catch (Exception $e) {
        echo '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 10px 0;">';
        echo '<h3>❌ Error During Reload</h3>';
        echo '<p>' . esc_html($e->getMessage()) . '</p>';
        echo '</div>';
    }

    echo '</div>';
    echo '<p><a href="?page=flow-repair" class="button">← Back to Repair Tool</a></p>';
    echo '</div>';
}
?>