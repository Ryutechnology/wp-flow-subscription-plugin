<?php
/*
Plugin Name: Flow Suscripciones Completo
Description: Suscripciones recurrentes vía Flow con formulario público y panel admin. Versión modularizada.
Version: 2.0
Author: Rudyard Fuster
*/

if (!defined('ABSPATH')) exit;

// Define plugin constants
define('FLOW_SUSCRIPCIONES_VERSION', '1.1.0');
define('FLOW_SUSCRIPCIONES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FLOW_SUSCRIPCIONES_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load the autoloader
require_once FLOW_SUSCRIPCIONES_PLUGIN_DIR . 'includes/class-flow-loader.php';

class Flow_Suscripciones {

    private static $instance = null;
    private $admin;
    private $shortcode;

    /**
     * Singleton pattern - get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - initialize the plugin
     */
    private function __construct() {
        if (!$this->load_dependencies()) {
            return; // Exit if dependencies failed to load
        }
        $this->init_hooks();
        $this->init_components();
        $this->add_repair_menu();
    }

    /**
     * Load all required dependencies
     */
    private function load_dependencies() {
        try {
            // Register autoloader
            Flow_Loader::register();

            // Fallback manual loading
            Flow_Loader::load_classes();

            // Verify all classes loaded successfully
            $missing_classes = Flow_Loader::verify_classes();
            if (!empty($missing_classes)) {
                // Silently log missing classes without showing admin notice
                error_log('Flow Suscripciones: Missing classes: ' . implode(', ', $missing_classes));
                return false;
            }
        } catch (Exception $e) {
            // Silently log exception without showing admin notice
            error_log('Flow Suscripciones Error: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Activation hook
        register_activation_hook(__FILE__, [$this, 'activate_plugin']);

        // Deactivation hook
        register_deactivation_hook(__FILE__, [$this, 'deactivate_plugin']);

        // Initialize plugin on WordPress init
        add_action('init', [$this, 'init']);
    }

    /**
     * Initialize plugin components
     */
    public function init_components() {
        try {
            // GRADUAL RE-ENABLING - Testing components one by one
            error_log('Flow: Starting gradual re-enabling mode');

            // Load diagnostic tools
            require_once FLOW_SUSCRIPCIONES_PLUGIN_DIR . 'debug-errors.php';
            require_once FLOW_SUSCRIPCIONES_PLUGIN_DIR . 'check-database.php';

            // Step 1: Re-enable admin interface
            if (is_admin()) {
                $this->admin = new Flow_Admin();
                error_log('Flow: Flow_Admin loaded');
            }

            // Step 2: Re-enable shortcode
            $this->shortcode = new Flow_Shortcode();
            error_log('Flow: Flow_Shortcode loaded');

            // Step 3: Direct gateway registration
            $this->register_payment_gateway();
            error_log('Flow: Direct gateway registration loaded');

            // Step 4: Test Flow_Activator (handles database setup)
            new Flow_Activator();
            error_log('Flow: Flow_Activator loaded');

            // Step 5: Add essential diagnostic tools
            require_once FLOW_SUSCRIPCIONES_PLUGIN_DIR . 'flow-diagnostic.php';
            require_once FLOW_SUSCRIPCIONES_PLUGIN_DIR . 'flow-repair.php';
            require_once FLOW_SUSCRIPCIONES_PLUGIN_DIR . 'check-gateway-status.php';
            error_log('Flow: Essential diagnostic tools loaded');

            // Note: These components were DISABLED because they interfere with WooCommerce:
            // - Flow_WooCommerce (too many aggressive hooks)
            // - Flow_Customer_Columns (clears WC transients, modifies database)
            // - Various debug/test tools (conflicting hooks)

            error_log('Flow: Safe initialization completed - Core functionality working');
        } catch (Exception $e) {
            // Silently log component error without showing admin notice
            error_log('Flow Suscripciones Component Error: ' . $e->getMessage());
        }
    }

    /**
     * Plugin activation
     */
    public function activate_plugin() {
        Flow_Activator::activate();
        Flow_Activator::activate_new_page();
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate_plugin() {
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Initialize plugin functionality
     */
    public function init() {
        // Plugin is fully loaded and ready
        do_action('flow_suscripciones_loaded');
    }

    /**
     * Register Flow payment gateway with WooCommerce
     */
    public function register_payment_gateway() {
        // Only register if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            error_log('Flow Gateway: WooCommerce not available for registration');
            return;
        }

        // Hook into WooCommerce payment gateways filter
        add_filter('woocommerce_payment_gateways', array($this, 'add_flow_gateway_to_woocommerce'));

        // Also hook into plugins_loaded to ensure it gets registered
        add_action('plugins_loaded', array($this, 'load_gateway_class'), 11);

        error_log('Flow Gateway: Registration hooks added');
    }

    /**
     * Load the Flow payment gateway class
     */
    public function load_gateway_class() {
        if (!class_exists('WC_Payment_Gateway')) {
            error_log('Flow Gateway: WC_Payment_Gateway base class not available');
            return;
        }

        // Load the gateway class
        $gateway_file = FLOW_SUSCRIPCIONES_PLUGIN_DIR . 'includes/class-flow-payment-gateway.php';
        if (file_exists($gateway_file)) {
            require_once $gateway_file;
            error_log('Flow Gateway: Gateway class file loaded from ' . $gateway_file);
        } else {
            error_log('Flow Gateway: Gateway class file not found at ' . $gateway_file);
        }
    }

    /**
     * Add Flow gateway to WooCommerce payment gateways
     */
    public function add_flow_gateway_to_woocommerce($gateways) {
        // Ensure the gateway class is loaded
        $this->load_gateway_class();

        if (class_exists('Flow_Payment_Gateway')) {
            $gateways[] = 'Flow_Payment_Gateway';
            error_log('Flow Gateway: Successfully added to WooCommerce gateways array');
        } else {
            error_log('Flow Gateway: Flow_Payment_Gateway class not found when trying to register');
        }

        return $gateways;
    }

    /**
     * Get plugin version
     */
    public static function get_version() {
        return FLOW_SUSCRIPCIONES_VERSION;
    }

    /**
     * Get plugin directory path
     */
    public static function get_plugin_dir() {
        return FLOW_SUSCRIPCIONES_PLUGIN_DIR;
    }

    /**
     * Get plugin URL
     */
    public static function get_plugin_url() {
        return FLOW_SUSCRIPCIONES_PLUGIN_URL;
    }

    /**
     * Add repair menu for Flow customer columns (temporary)
     */
    private function add_repair_menu() {
        add_action('admin_menu', array($this, 'flow_repair_admin_menu'));
        // Removed admin notice to reduce notice spam
    }

    public function flow_repair_admin_menu() {
        add_submenu_page(
            'tools.php',
            'Fix Flow Customer Columns',
            'Fix Flow Columns',
            'manage_options',
            'fix-flow-columns',
            array($this, 'flow_repair_admin_page')
        );
    }

    // Removed flow_repair_admin_notice to eliminate admin notice spam

    public function flow_repair_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        // Handle form submission
        if (isset($_POST['run_repair']) && wp_verify_nonce($_POST['flow_repair_nonce'], 'flow_repair_action')) {
            $this->run_column_repair();
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
            <?php $this->show_current_status(); ?>
        </div>
        <?php
    }

    private function show_current_status() {
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

    private function run_column_repair() {
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

        echo '<div class="notice notice-success"><p>✅ WooCommerce customer lookup table found</p></div>';

        // Get current table structure
        $current_columns = $wpdb->get_results("DESCRIBE $wc_customer_lookup_table");
        $existing_columns = [];
        foreach ($current_columns as $column) {
            $existing_columns[] = $column->Field;
        }

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

        // Sync Flow data
        $this->sync_flow_data($wc_customer_lookup_table);

        // Clear caches
        $this->clear_wc_caches();

        echo '<div class="notice notice-success">';
        echo '<h3>🎉 Repair Complete!</h3>';
        echo '<p><strong>Next steps:</strong></p>';
        echo '<ul>';
        echo '<li>Go to <strong>WooCommerce → Analytics → Customers</strong> to see the new columns</li>';
        echo '<li>You should see: <strong>Flow Status</strong>, <strong>Flow Subscription ID</strong>, and <strong>Flow Dashboard</strong> links</li>';
        echo '<li>You can also see them in <strong>Users → All Users</strong> when filtering by Customer role</li>';
        echo '</ul>';
        echo '</div>';

        echo '</div>';
    }

    private function sync_flow_data($wc_customer_lookup_table) {
        global $wpdb;

        echo '<h3>📊 Syncing Flow Data</h3>';

        $customers_with_flow_data = $wpdb->get_results("
            SELECT DISTINCT user_id
            FROM {$wpdb->usermeta}
            WHERE meta_key IN ('_flow_subscription_id', '_flow_customer_id')
        ");

        if (empty($customers_with_flow_data)) {
            echo '<p>ℹ️ No customers found with Flow data in user meta</p>';
            return;
        }

        echo '<p>Found ' . count($customers_with_flow_data) . ' customers with Flow data</p>';

        $synced_count = 0;

        foreach ($customers_with_flow_data as $customer_data) {
            $user_id = $customer_data->user_id;

            $flow_subscription_id = get_user_meta($user_id, '_flow_subscription_id', true);
            $flow_customer_id = get_user_meta($user_id, '_flow_customer_id', true);

            if (empty($flow_subscription_id) && empty($flow_customer_id)) {
                continue;
            }

            $customer_lookup_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT customer_id FROM $wc_customer_lookup_table WHERE customer_id = %d",
                $user_id
            ));

            if (!$customer_lookup_exists) {
                continue;
            }

            // Determine subscription status
            $subscription_status = 'inactive';
            if (!empty($flow_subscription_id)) {
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

            $update_data = [];
            if (!empty($flow_customer_id)) {
                $update_data['flow_customer_id'] = $flow_customer_id;
            }
            if (!empty($flow_subscription_id)) {
                $update_data['flow_subscription_id'] = $flow_subscription_id;
            }
            $update_data['flow_subscription_status'] = $subscription_status;

            $result = $wpdb->update(
                $wc_customer_lookup_table,
                $update_data,
                ['customer_id' => $user_id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            if ($result !== false) {
                $synced_count++;
            }
        }

        echo '<div class="notice notice-success"><p><strong>✅ Sync complete: ' . $synced_count . ' customers updated</strong></p></div>';
    }

    private function clear_wc_caches() {
        if (function_exists('wc_delete_shop_order_transients')) {
            wc_delete_shop_order_transients();
        }

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_admin%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wc_admin%'");

        echo '<p>🧹 Cleared WooCommerce caches</p>';
    }
}

// Initialize the plugin early to ensure WooCommerce integration works
add_action('plugins_loaded', function() {
    Flow_Suscripciones::get_instance();
}, 20);
