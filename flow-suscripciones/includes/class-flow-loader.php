<?php
if (!defined('ABSPATH')) exit;

class Flow_Loader {

    /**
     * Auto-load classes
     */
    public static function autoload($class_name) {
        // Only load Flow classes
        if (strpos($class_name, 'Flow_') !== 0) {
            return;
        }

        // Convert class name to file name
        $file_name = 'class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';
        $file_path = plugin_dir_path(__FILE__) . $file_name;

        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }

    /**
     * Register autoloader
     */
    public static function register() {
        spl_autoload_register([__CLASS__, 'autoload']);
    }

    /**
     * Load all required classes manually (fallback)
     */
    public static function load_classes() {
        // Load in proper dependency order
        $classes = [
            'class-flow-activator.php',     // No dependencies
            'class-flow-api.php',           // No dependencies
            'class-flow-database.php',      // No dependencies
            'class-flow-subscription.php',  // Depends on API, DB
            'class-flow-woocommerce.php',   // No dependencies
            'class-flow-payment-gateway.php', // Depends on WooCommerce
            'class-flow-admin.php',         // Depends on database
            'class-flow-shortcode.php',     // Depends on API, DB, WC
            'class-flow-cron.php'           // Depends on API, DB, WC
        ];

        foreach ($classes as $class_file) {
            $file_path = plugin_dir_path(__FILE__) . $class_file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }

    /**
     * Check if all required classes are loaded
     */
    public static function verify_classes() {
        $required_classes = [
            'Flow_Activator',
            'Flow_API',
            'Flow_Database',
            'Flow_Subscription',
            'Flow_WooCommerce',
            'Flow_Admin',
            'Flow_Shortcode',
            'Flow_Cron'
        ];

        // Flow_Payment_Gateway is optional (only loaded if WooCommerce is available)
        // So we don't include it in the required classes check

        $missing_classes = [];
        foreach ($required_classes as $class) {
            if (!class_exists($class)) {
                $missing_classes[] = $class;
            }
        }

        return $missing_classes;
    }
}