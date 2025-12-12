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
                add_action('admin_notices', function() use ($missing_classes) {
                    echo '<div class="notice notice-error"><p>';
                    echo 'Flow Suscripciones: Missing classes: ' . implode(', ', $missing_classes);
                    echo '</p></div>';
                });
                return false;
            }
        } catch (Exception $e) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>';
                echo 'Flow Suscripciones Error: ' . esc_html($e->getMessage());
                echo '</p></div>';
            });
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
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>';
                echo 'Flow Suscripciones Component Error: ' . esc_html($e->getMessage());
                echo '</p></div>';
            });
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
}

// Initialize the plugin early to ensure WooCommerce integration works
add_action('plugins_loaded', function() {
    Flow_Suscripciones::get_instance();
}, 20);
