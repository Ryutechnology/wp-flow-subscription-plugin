<?php
/**
 * Flow Plugin Installation Diagnostic
 * Check why the gateway and columns aren't installing properly
 */

if (!defined('ABSPATH')) exit;

// Add diagnostic page to admin menu
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Flow Plugin Diagnostic',
        'Flow Diagnostic',
        'manage_options',
        'flow-diagnostic',
        'flow_diagnostic_page'
    );
});

function flow_diagnostic_page() {
    if (isset($_GET['run_tests'])) {
        run_flow_diagnostic_tests();
        return;
    }

    echo '<div class="wrap">';
    echo '<h1>🔍 Flow Plugin Installation Diagnostic</h1>';
    echo '<p>This tool will help identify why the Flow payment gateway and columns are not installing properly.</p>';
    echo '<p><a href="?page=flow-diagnostic&run_tests=1" class="button-primary">Run Diagnostic Tests</a></p>';
    echo '</div>';
}

function run_flow_diagnostic_tests() {
    echo '<div class="wrap">';
    echo '<h1>🔍 Flow Plugin Diagnostic Results</h1>';
    echo '<div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccc;">';

    $results = [];

    // Test 1: WordPress Requirements
    echo '<h2>1. WordPress Environment</h2>';
    $wp_version = get_bloginfo('version');
    $php_version = phpversion();

    echo "<p><strong>WordPress Version:</strong> {$wp_version}</p>";
    echo "<p><strong>PHP Version:</strong> {$php_version}</p>";
    echo "<p><strong>Plugin Directory:</strong> " . plugin_dir_path(__FILE__) . "</p>";

    $results['wp_version_ok'] = version_compare($wp_version, '5.0', '>=');
    $results['php_version_ok'] = version_compare($php_version, '7.4', '>=');

    // Test 2: Plugin Files
    echo '<h2>2. Plugin Files Check</h2>';
    $required_files = [
        'flow-suscripciones.php' => plugin_dir_path(__FILE__) . 'flow-suscripciones.php',
        'class-flow-woocommerce.php' => plugin_dir_path(__FILE__) . 'includes/class-flow-woocommerce.php',
        'class-flow-payment-gateway.php' => plugin_dir_path(__FILE__) . 'includes/class-flow-payment-gateway.php',
        'class-flow-customer-columns.php' => plugin_dir_path(__FILE__) . 'includes/class-flow-customer-columns.php',
        'class-flow-loader.php' => plugin_dir_path(__FILE__) . 'includes/class-flow-loader.php'
    ];

    foreach ($required_files as $name => $path) {
        $exists = file_exists($path);
        $readable = $exists ? is_readable($path) : false;

        echo "<p><strong>{$name}:</strong> ";
        if ($exists && $readable) {
            echo "✅ OK";
        } elseif ($exists) {
            echo "⚠️ EXISTS but not readable";
        } else {
            echo "❌ MISSING";
        }
        echo " ({$path})</p>";

        $results["file_{$name}"] = $exists && $readable;
    }

    // Test 3: WooCommerce Availability
    echo '<h2>3. WooCommerce Integration</h2>';
    $wc_active = class_exists('WooCommerce');
    $wc_version = $wc_active ? WC()->version : 'Not installed';

    echo "<p><strong>WooCommerce Active:</strong> " . ($wc_active ? "✅ YES" : "❌ NO") . "</p>";
    echo "<p><strong>WooCommerce Version:</strong> {$wc_version}</p>";

    if ($wc_active) {
        $wc_payment_gateway_class = class_exists('WC_Payment_Gateway');
        echo "<p><strong>WC_Payment_Gateway Available:</strong> " . ($wc_payment_gateway_class ? "✅ YES" : "❌ NO") . "</p>";
        $results['wc_payment_gateway'] = $wc_payment_gateway_class;
    }

    $results['woocommerce_active'] = $wc_active;

    // Test 4: Flow Classes Loading
    echo '<h2>4. Flow Classes Status</h2>';
    $flow_classes = [
        'Flow_Loader' => class_exists('Flow_Loader'),
        'Flow_WooCommerce' => class_exists('Flow_WooCommerce'),
        'Flow_Payment_Gateway' => class_exists('Flow_Payment_Gateway'),
        'Flow_Customer_Columns' => class_exists('Flow_Customer_Columns'),
        'Flow_Suscripciones' => class_exists('Flow_Suscripciones')
    ];

    foreach ($flow_classes as $class_name => $loaded) {
        echo "<p><strong>{$class_name}:</strong> " . ($loaded ? "✅ LOADED" : "❌ NOT LOADED") . "</p>";
        $results["class_{$class_name}"] = $loaded;
    }

    // Test 5: Plugin Activation Status
    echo '<h2>5. Plugin Activation Status</h2>';
    $active_plugins = get_option('active_plugins', []);
    $flow_plugin_active = false;

    foreach ($active_plugins as $plugin) {
        if (strpos($plugin, 'flow-suscripciones') !== false) {
            $flow_plugin_active = true;
            echo "<p><strong>Flow Plugin Active:</strong> ✅ YES ({$plugin})</p>";
            break;
        }
    }

    if (!$flow_plugin_active) {
        echo "<p><strong>Flow Plugin Active:</strong> ❌ NO</p>";
    }

    $results['plugin_active'] = $flow_plugin_active;

    // Test 6: WordPress Hooks Status
    echo '<h2>6. WordPress Hooks Status</h2>';
    global $wp_filter;

    $important_hooks = [
        'plugins_loaded' => isset($wp_filter['plugins_loaded']),
        'init' => isset($wp_filter['init']),
        'woocommerce_payment_gateways' => isset($wp_filter['woocommerce_payment_gateways']),
        'admin_enqueue_scripts' => isset($wp_filter['admin_enqueue_scripts'])
    ];

    foreach ($important_hooks as $hook => $has_callbacks) {
        echo "<p><strong>{$hook} hook:</strong> " . ($has_callbacks ? "✅ HAS CALLBACKS" : "❌ NO CALLBACKS") . "</p>";
    }

    // Test 7: Database Tables
    echo '<h2>7. Database Tables</h2>';
    global $wpdb;

    $tables_to_check = [
        'wc_customer_lookup' => $wpdb->prefix . 'wc_customer_lookup',
        'flow_subscriptions' => $wpdb->prefix . 'flow_subscriptions'
    ];

    foreach ($tables_to_check as $table_name => $full_table_name) {
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table_name'") === $full_table_name;
        echo "<p><strong>{$table_name}:</strong> " . ($table_exists ? "✅ EXISTS" : "❌ MISSING") . "</p>";

        if ($table_exists && $table_name === 'wc_customer_lookup') {
            // Check for custom columns
            $columns = $wpdb->get_col("DESCRIBE $full_table_name");
            $has_flow_columns = in_array('flow_subscription_status', $columns) && in_array('flow_subscription_id', $columns);
            echo "<p><strong>Flow columns in wc_customer_lookup:</strong> " . ($has_flow_columns ? "✅ PRESENT" : "❌ MISSING") . "</p>";
        }
    }

    // Test 8: Payment Gateway Registration
    echo '<h2>8. Payment Gateway Registration</h2>';
    if ($wc_active && function_exists('WC') && WC()->payment_gateways()) {
        $payment_gateways = WC()->payment_gateways()->payment_gateways();
        $flow_gateway_registered = isset($payment_gateways['flow']);

        echo "<p><strong>Flow Gateway Registered:</strong> " . ($flow_gateway_registered ? "✅ YES" : "❌ NO") . "</p>";

        if ($flow_gateway_registered) {
            $gateway = $payment_gateways['flow'];
            echo "<p><strong>Gateway ID:</strong> {$gateway->id}</p>";
            echo "<p><strong>Gateway Title:</strong> {$gateway->method_title}</p>";
            echo "<p><strong>Gateway Enabled:</strong> " . ($gateway->enabled === 'yes' ? "✅ YES" : "❌ NO") . "</p>";
        }

        echo "<p><strong>Total Registered Gateways:</strong> " . count($payment_gateways) . "</p>";
        echo "<p><strong>Available Gateways:</strong> " . implode(', ', array_keys($payment_gateways)) . "</p>";

        $results['gateway_registered'] = $flow_gateway_registered;
    }

    // Test 9: Error Log Check
    echo '<h2>9. Recent Error Log Entries</h2>';
    $log_file = WP_CONTENT_DIR . '/debug.log';
    if (file_exists($log_file) && is_readable($log_file)) {
        $log_content = file_get_contents($log_file);
        $flow_errors = [];
        $lines = explode("\n", $log_content);

        foreach (array_reverse($lines) as $line) {
            if (stripos($line, 'flow') !== false && (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false)) {
                $flow_errors[] = $line;
                if (count($flow_errors) >= 5) break;
            }
        }

        if (!empty($flow_errors)) {
            echo "<p><strong>Recent Flow-related errors:</strong></p>";
            echo "<pre style='background: #f8f8f8; padding: 10px; overflow-x: auto;'>";
            foreach ($flow_errors as $error) {
                echo esc_html($error) . "\n";
            }
            echo "</pre>";
        } else {
            echo "<p>✅ No recent Flow-related errors found in debug.log</p>";
        }
    } else {
        echo "<p>⚠️ Debug log not accessible or debug logging not enabled</p>";
    }

    // Summary
    echo '<h2>10. Diagnostic Summary</h2>';
    $critical_issues = [];
    $warnings = [];

    if (!$results['plugin_active']) $critical_issues[] = "Plugin not active";
    if (!$results['woocommerce_active']) $critical_issues[] = "WooCommerce not active";
    if (isset($results['wc_payment_gateway']) && !$results['wc_payment_gateway']) $critical_issues[] = "WC_Payment_Gateway class not available";
    if (isset($results['class_Flow_Payment_Gateway']) && !$results['class_Flow_Payment_Gateway']) $critical_issues[] = "Flow_Payment_Gateway class not loaded";
    if (isset($results['gateway_registered']) && !$results['gateway_registered']) $critical_issues[] = "Flow gateway not registered";

    if (!empty($critical_issues)) {
        echo '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 10px 0;">';
        echo '<h3>🚨 Critical Issues Found:</h3>';
        echo '<ul>';
        foreach ($critical_issues as $issue) {
            echo "<li>{$issue}</li>";
        }
        echo '</ul>';
        echo '</div>';
    } else {
        echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 10px 0;">';
        echo '<h3>✅ No Critical Issues Found</h3>';
        echo '<p>The plugin appears to be configured correctly. If you\'re still having issues, check the specific WordPress site for theme conflicts or other plugin conflicts.</p>';
        echo '</div>';
    }

    // Recommendations
    echo '<h2>11. Recommendations</h2>';
    echo '<div style="background: #cce7ff; border: 1px solid #99ccff; padding: 15px; margin: 10px 0;">';
    echo '<h3>💡 Troubleshooting Steps:</h3>';
    echo '<ol>';
    echo '<li>Deactivate and reactivate the Flow plugin</li>';
    echo '<li>Clear any caching (if using cache plugins)</li>';
    echo '<li>Check for plugin conflicts by deactivating other plugins temporarily</li>';
    echo '<li>Switch to a default theme temporarily to check for theme conflicts</li>';
    echo '<li>Enable WordPress debug logging (WP_DEBUG = true in wp-config.php)</li>';
    echo '<li>Check if the site has any custom code that might interfere with plugin loading</li>';
    echo '</ol>';
    echo '</div>';

    echo '</div>';
    echo '<p><a href="?page=flow-diagnostic" class="button">← Back to Diagnostic Tool</a></p>';
    echo '</div>';
}

// Force diagnostic loading
add_action('admin_init', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'flow-diagnostic' && isset($_GET['force_load'])) {
        // Force load all Flow classes
        $plugin_dir = plugin_dir_path(__FILE__);

        if (file_exists($plugin_dir . 'includes/class-flow-loader.php')) {
            require_once $plugin_dir . 'includes/class-flow-loader.php';
            if (class_exists('Flow_Loader')) {
                Flow_Loader::register();
                Flow_Loader::load_classes();
            }
        }

        // Force instantiate main classes
        if (class_exists('Flow_WooCommerce')) {
            new Flow_WooCommerce();
        }
        if (class_exists('Flow_Customer_Columns')) {
            new Flow_Customer_Columns();
        }
    }
});
?>