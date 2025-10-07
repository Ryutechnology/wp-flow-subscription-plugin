<?php
/**
 * Gateway Registration Fix
 * This file ensures the Flow payment gateway is properly registered
 */

if (!defined('ABSPATH')) exit;

// Use the safest, most reliable method to register payment gateway
add_action('plugins_loaded', 'flow_register_payment_gateway', 0);

function flow_register_payment_gateway() {
    // Don't do anything if WooCommerce is not active
    if (!class_exists('WooCommerce') || !class_exists('WC_Payment_Gateway')) {
        return;
    }

    // Load our payment gateway class
    if (!class_exists('Flow_Payment_Gateway_Fixed')) {

        class Flow_Payment_Gateway_Fixed extends WC_Payment_Gateway {

            /**
             * Gateway API credentials and settings
             * Explicitly declared to avoid PHP 8.2+ dynamic property warnings
             */
            public $api_key;
            public $secret_key;

            public function __construct() {
                $this->id = 'flow';
                $this->icon = '';
                $this->has_fields = false;
                $this->method_title = __('Flow Suscripciones');
                $this->method_description = __('Procesa pagos de suscripciones recurrentes usando Flow.');

                // Declare support for checkout blocks
                $this->supports = array(
                    'products',
                    'refunds'
                );

                // Load the settings.
                $this->init_form_fields();
                $this->init_settings();

                // Define user set variables
                $this->title = $this->get_option('title', 'Flow Suscripciones');
                $this->description = $this->get_option('description', 'Paga de forma segura con Flow');
                $this->enabled = $this->get_option('enabled', 'yes');

                // Ensure gateway is enabled by default on first load
                if (empty($this->get_option('enabled'))) {
                    $this->update_option('enabled', 'yes');
                    $this->enabled = 'yes';
                }

                // Auto-load credentials from Flow plugin if available
                $this->api_key = $this->get_option('api_key', get_option('flow_api_key', ''));
                $this->secret_key = $this->get_option('secret_key', get_option('flow_secret_key', ''));

                // Actions
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            }

            public function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array(
                        'title'   => __('Enable/Disable'),
                        'type'    => 'checkbox',
                        'label'   => __('Enable Flow Suscripciones'),
                        'default' => 'yes'
                    ),
                    'title' => array(
                        'title'       => __('Title'),
                        'type'        => 'text',
                        'description' => __('This controls the title for the payment method the customer sees during checkout.'),
                        'default'     => __('Flow Suscripciones'),
                        'desc_tip'    => true,
                    ),
                    'description' => array(
                        'title'       => __('Description'),
                        'type'        => 'textarea',
                        'description' => __('Payment method description that the customer will see on your checkout.'),
                        'default'     => __('Pay securely with Flow'),
                        'desc_tip'    => true,
                    ),
                    'api_key' => array(
                        'title'       => __('API Key'),
                        'type'        => 'text',
                        'description' => __('Your Flow API Key'),
                        'default'     => get_option('flow_api_key', ''),
                        'desc_tip'    => true,
                    ),
                    'secret_key' => array(
                        'title'       => __('Secret Key'),
                        'type'        => 'password',
                        'description' => __('Your Flow Secret Key'),
                        'default'     => get_option('flow_secret_key', ''),
                        'desc_tip'    => true,
                    ),
                );
            }

            public function process_payment($order_id) {
                $order = wc_get_order($order_id);

                // Mark as on-hold (we're awaiting the payment)
                $order->update_status('on-hold', __('Awaiting Flow payment', 'woocommerce'));

                // Reduce stock levels
                wc_reduce_stock_levels($order_id);

                // Remove cart
                WC()->cart->empty_cart();

                // Return thankyou redirect
                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            }

            public function is_available() {
                // Basic availability check
                if ($this->enabled !== 'yes') {
                    return false;
                }

                // Check if WooCommerce is available
                if (!function_exists('WC')) {
                    return false;
                }

                // Check if cart exists and needs payment
                if (WC()->cart && WC()->cart->is_empty()) {
                    return false;
                }

                if (WC()->cart && !WC()->cart->needs_payment()) {
                    return false;
                }

                // All checks passed
                return true;
            }
        }
    }

    // Register the gateway with WooCommerce
    add_filter('woocommerce_payment_gateways', function($gateways) {
        $gateways[] = 'Flow_Payment_Gateway_Fixed';
        return $gateways;
    });
}

// Add admin notice to confirm registration
add_action('admin_notices', function() {
    if (current_user_can('manage_options') && class_exists('WooCommerce')) {

        static $notice_shown = false;
        if ($notice_shown) return;
        $notice_shown = true;

        $status_message = '';

        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();

            if (isset($gateways['flow'])) {
                $status_message = '✅ Flow payment gateway successfully registered! Check WooCommerce > Settings > Payments';
            } else {
                $status_message = '❌ Flow payment gateway registration failed';
            }
        } else {
            $status_message = '⚠️ WooCommerce payment gateways not ready yet';
        }

        if ($status_message) {
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p><strong>Flow Suscripciones:</strong> ' . $status_message . '</p>';
            echo '</div>';
        }
    }
});
?>