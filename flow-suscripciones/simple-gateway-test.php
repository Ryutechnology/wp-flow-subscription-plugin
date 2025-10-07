<?php
/**
 * Simple Gateway Test - Add this temporarily to test direct registration
 * Add this to your wp-config.php or functions.php temporarily
 */

// Direct registration approach - bypassing all complex initialization
add_action('plugins_loaded', function() {
    if (class_exists('WC_Payment_Gateway')) {

        // Define a simple test gateway class inline
        if (!class_exists('Flow_Test_Gateway')) {
            class Flow_Test_Gateway extends WC_Payment_Gateway {

                public function __construct() {
                    $this->id = 'flow_test';
                    $this->method_title = 'Flow Test Gateway';
                    $this->method_description = 'Test gateway to verify WooCommerce integration';
                    $this->title = 'Flow Test';
                    $this->description = 'This is a test gateway';
                    $this->enabled = 'yes';
                    $this->has_fields = false;
                }

                public function process_payment($order_id) {
                    $order = wc_get_order($order_id);
                    $order->payment_complete();
                    return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url($order)
                    );
                }
            }
        }

        // Register the test gateway
        add_filter('woocommerce_payment_gateways', function($gateways) {
            $gateways[] = 'Flow_Test_Gateway';
            return $gateways;
        });
    }
}, 11);

// Add admin notice to show status
add_action('admin_notices', function() {
    if (current_user_can('manage_options')) {
        $message = '';

        if (!class_exists('WooCommerce')) {
            $message = '❌ WooCommerce not active';
        } elseif (!class_exists('WC_Payment_Gateway')) {
            $message = '❌ WC_Payment_Gateway not available';
        } elseif (class_exists('Flow_Test_Gateway')) {
            $message = '✅ Flow_Test_Gateway loaded successfully';

            // Check if registered
            if (function_exists('WC') && WC()->payment_gateways()) {
                $gateways = WC()->payment_gateways()->payment_gateways();
                if (isset($gateways['flow_test'])) {
                    $message .= ' and registered in WooCommerce';
                } else {
                    $message .= ' but NOT registered in WooCommerce';
                }
            }
        } else {
            $message = '❌ Flow_Test_Gateway not loaded';
        }

        echo '<div class="notice notice-info"><p><strong>Flow Test:</strong> ' . $message . '</p></div>';
    }
});
?>