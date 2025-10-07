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
    private $cron;

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

        // Load text domain for translations
        add_action('plugins_loaded', [$this, 'load_textdomain']);
    }

    /**
     * Initialize plugin components
     */
    private function init_components() {
        try {
            // Initialize admin interface
            if (is_admin()) {
                $this->admin = new Flow_Admin();
            }

            // Initialize public shortcode
            $this->shortcode = new Flow_Shortcode();

            // Initialize flow return handler
            new Flow_Activator();

            // Initialize cron jobs
            $this->cron = new Flow_Cron();
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
        // Clear scheduled cron jobs
        if ($this->cron) {
            $this->cron->clear_cron();
        }
        
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
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'flow-suscripciones',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
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

// Initialize the plugin
Flow_Suscripciones::get_instance();