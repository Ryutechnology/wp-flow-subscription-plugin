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
                // Validate subscription products first
                if (!$this->validate_fields()) {
                    return array('result' => 'fail');
                }

                $order = wc_get_order($order_id);

                if (!$order) {
                    wc_add_notice('Error: No se pudo procesar el pedido.', 'error');
                    return array('result' => 'fail');
                }

                // Initialize Flow API
                $flow_api = new Flow_API();

                // Get order details
                $amount = intval($order->get_total());
                $order_number = $order->get_order_number();
                $customer_email = $order->get_billing_email();
                $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                $customer_address = $order->get_billing_address_1();
                $customer_city = $order->get_billing_city();

                // Step 1: Create or get subscription plan
                $plan_name = 'subs_cart' . $order_number . '_$' . $amount;
                $plan_response = $flow_api->create_plan($plan_name, $amount);

                if (isset($plan_response['error'])) {
                    $order->add_order_note('Error creating Flow plan: ' . $plan_response['error']);
                    wc_add_notice('Error creando plan de suscripción: ' . $plan_response['error'], 'error');
                    return array('result' => 'fail');
                }

                if (!isset($plan_response['planId'])) {
                    $order->add_order_note('Invalid Flow plan response: missing planId');
                    wc_add_notice('Error: Respuesta inválida al crear plan de suscripción.', 'error');
                    return array('result' => 'fail');
                }

                $plan_id = $plan_response['planId'];

                // Step 2: Create customer first
                $customer_response = $flow_api->create_customer($customer_email, $customer_name, $customer_address, $customer_city);

                if (isset($customer_response['code'])) {
                    $order->add_order_note('Error creating Flow customer: ' . $customer_response['message']);
                    wc_add_notice('Error creando cliente: ' . $customer_response['message'], 'error');
                    return array('result' => 'fail');
                }

                if (!isset($customer_response['customerId'])) {
                    $order->add_order_note('Invalid Flow customer response: missing customerId');
                    wc_add_notice('Error: Respuesta inválida al crear cliente.', 'error');
                    return array('result' => 'fail');
                }

                $customer_id = $customer_response['customerId'];

                // Step 3: Register credit card for the customer (subscription will be created after successful registration)
                $url_return = rest_url('flow/v1/return') . '?plan_id=' . $plan_id;
                $card_registration_response = $flow_api->register_credit_card($customer_id, $url_return);

                if (isset($card_registration_response['code'])) {
                    $order->add_order_note('Error registering credit card: ' . $card_registration_response['message']);
                    wc_add_notice('Error registrando tarjeta de crédito: ' . $card_registration_response['message'], 'error');
                    return array('result' => 'fail');
                }

                if (!isset($card_registration_response['url']) || !isset($card_registration_response['token'])) {
                    $order->add_order_note('Invalid Flow card registration response: missing URL or token');
                    wc_add_notice('Error: Respuesta inválida del registro de tarjeta.', 'error');
                    return array('result' => 'fail');
                }

                // Store Flow data in order meta (subscription will be created after card registration)
                $order->update_meta_data('_flow_token', $card_registration_response['token']);
                $order->update_meta_data('_flow_plan_id', $plan_id);
                $order->update_meta_data('_flow_customer_id', $customer_id);
                $order->update_meta_data('_flow_customer_email', $customer_email);
                $order->update_meta_data('_flow_customer_data', $customer_response);
                $order->update_meta_data('_flow_card_registration_data', $card_registration_response);
                $order->update_meta_data('_flow_subscription_amount', $amount);
                $order->update_meta_data('_flow_subscription_pending', 'yes'); // Flag to indicate subscription needs to be created

                // Store Flow customer data (subscription will be created after card registration)
                $wc_customer_id = $order->get_customer_id();
                if ($wc_customer_id > 0) {
                    Flow_Customer_Columns::set_customer_flow_customer_id($wc_customer_id, $customer_id);

                    // Update WooCommerce customer lookup table with customer ID only
                    $wc_integration = new Flow_WooCommerce();
                    if ($wc_integration->is_woocommerce_available()) {
                        $wc_integration->update_customer_flow_data(
                            $wc_customer_id,
                            $customer_id,
                            '', // No subscription ID yet
                            'pending_card' // Pending card registration
                        );
                    }

                    error_log("Flow Gateway: Stored customer data for customer {$wc_customer_id} - Customer ID: {$customer_id} (subscription will be created after card registration)");
                }

                // Mark order as pending credit card registration
                $order->update_status('pending', 'Esperando registro de tarjeta de crédito vía Flow. Token: ' . $card_registration_response['token']);
                $order->save();

                // Clear cart
                WC()->cart->empty_cart();

                // Redirect to Flow credit card registration page
                return array(
                    'result' => 'success',
                    'redirect' => $card_registration_response['url'] . '?token=' . $card_registration_response['token']
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
                if (!WC()->cart || WC()->cart->is_empty()) {
                    return false;
                }

                if (!WC()->cart->needs_payment()) {
                    return false;
                }

                // Always show the gateway - we'll handle validation in process_payment
                return true;
            }

            /**
             * Check if cart has any non-subscription products
             */
            private function get_non_subscription_products() {
                $non_subscription_products = array();

                if (!WC()->cart || WC()->cart->is_empty()) {
                    return $non_subscription_products;
                }

                $cart_items = WC()->cart->get_cart();

                foreach ($cart_items as $cart_item) {
                    $product = $cart_item['data'];

                    if (!$product) {
                        continue;
                    }

                    if ($product->is_type('variation')) {
                        $parent_id = $product->get_parent_id();
                    } else {
                        $parent_id = $product->get_id();
                    }

                    // Check if product has "Suscripciones Flow" category
                    $has_subscription_category = has_term('flow-subscriptions', 'product_cat', $parent_id);

                    $terms = get_the_terms($parent_id, 'product_cat');
                    echo '<pre>';
                    $terms_list = array();
                    if ($terms && !is_wp_error($terms)) {
                        foreach ($terms as $term) {
                            $terms_list[] = $term->name;
                        }
                    }
                    echo 'Product ID: ' . $parent_id . ' - ' . $product->get_name() . PHP_EOL;
                    echo 'Categories: ' . (!empty($terms_list) ? implode(', ', $terms_list) : 'None') . PHP_EOL;
                    echo 'Has Suscripciones Flow category: ' . ($has_subscription_category ? 'Yes' : 'No') . PHP_EOL;
                    echo '</pre>';

                    error_log('Flow Subscription Fixed: Product ID ' . $product->get_id() . ' (' . $product->get_name() . ') has Suscripciones Flow category: ' . ($has_subscription_category ? 'Yes' : 'No'));

                    // If product doesn't have the "Suscripciones Flow" category
                    if (!$has_subscription_category) {
                        $product_categories = wp_get_post_terms($product->get_id(), '', array('fields' => 'names'));
                        $category_list = !empty($product_categories) ? implode(', ', $product_categories) : 'Sin categorías';

                        $non_subscription_products[] = array(
                            'id' => $product->get_id(),
                            'name' => $product->get_name(),
                            'categories' => $category_list
                        );
                    }
                }

                return $non_subscription_products;
            }

            /**
             * Validate payment before processing
             */
            public function validate_fields() {
                $non_subscription_products = $this->get_non_subscription_products();

                if (!empty($non_subscription_products)) {
                    $product_list = array();
                    foreach ($non_subscription_products as $product) {
                        $product_list[] = '• ' . $product['name'];
                    }

                    $message = 'Flow Suscripciones solo puede procesar productos de la categoría "Suscripciones Flow". Los siguientes productos no pertenecen a esta categoría:' . PHP_EOL . PHP_EOL . implode(PHP_EOL, $product_list) . PHP_EOL . PHP_EOL . 'Por favor elige otros productos o selecciona otro método de pago.';

                    wc_add_notice($message, 'error');
                    return false;
                }

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