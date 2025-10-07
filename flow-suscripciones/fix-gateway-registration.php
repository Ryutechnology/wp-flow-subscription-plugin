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
                $plan_name = 'Suscripción ' . get_bloginfo('name') . ' - $' . $amount;
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

                // Step 2: Create subscription and customer
                $subscription_response = $flow_api->create_subscription($plan_id, $customer_email);

                if (isset($subscription_response['error'])) {
                    $order->add_order_note('Error creating Flow subscription: ' . $subscription_response['error']);
                    wc_add_notice('Error creando suscripción: ' . $subscription_response['error'], 'error');
                    return array('result' => 'fail');
                }

                if (!isset($subscription_response['subscriptionId'])) {
                    $order->add_order_note('Invalid Flow subscription response: missing subscriptionId');
                    wc_add_notice('Error: Respuesta inválida al crear suscripción.', 'error');
                    return array('result' => 'fail');
                }

                $subscription_id = $subscription_response['subscriptionId'];

                // Step 3: Register credit card for the customer
                $url_return = WC()->api_request_url('flow_return') . '?order_id=' . $order_id;
                $card_registration_response = $flow_api->register_credit_card($customer_email, $url_return);

                if (isset($card_registration_response['error'])) {
                    $order->add_order_note('Error registering credit card: ' . $card_registration_response['error']);
                    wc_add_notice('Error registrando tarjeta de crédito: ' . $card_registration_response['error'], 'error');
                    return array('result' => 'fail');
                }

                if (!isset($card_registration_response['url']) || !isset($card_registration_response['token'])) {
                    $order->add_order_note('Invalid Flow card registration response: missing URL or token');
                    wc_add_notice('Error: Respuesta inválida del registro de tarjeta.', 'error');
                    return array('result' => 'fail');
                }

                // Store Flow subscription data in order meta
                $order->update_meta_data('_flow_token', $card_registration_response['token']);
                $order->update_meta_data('_flow_plan_id', $plan_id);
                $order->update_meta_data('_flow_subscription_id', $subscription_id);
                $order->update_meta_data('_flow_customer_email', $customer_email);
                $order->update_meta_data('_flow_card_registration_data', $card_registration_response);
                $order->update_meta_data('_flow_subscription_amount', $amount);

                // Mark order as pending credit card registration
                $order->update_status('pending', 'Esperando registro de tarjeta de crédito vía Flow. Token: ' . $card_registration_response['token']);
                $order->save();

                // Clear cart
                WC()->cart->empty_cart();

                // Redirect to Flow credit card registration page
                return array(
                    'result' => 'success',
                    'redirect' => $card_registration_response['url']
                );
            }

            /**
             * Create Flow payment
             */
            private function create_flow_payment($payment_data) {
                // Get API credentials
                $api_key = $this->api_key ?: get_option('flow_api_key');
                $secret_key = $this->secret_key ?: get_option('flow_secret_key');

                if (empty($api_key) || empty($secret_key)) {
                    return array('error' => 'Credenciales Flow no configuradas');
                }

                // Prepare parameters for Flow API
                $params = array(
                    'apiKey' => $api_key,
                    'commerceOrder' => $payment_data['commerceOrder'],
                    'subject' => $payment_data['subject'],
                    'amount' => $payment_data['amount'],
                    'currency' => $payment_data['currency'],
                    'email' => $payment_data['email'],
                    'urlConfirmation' => $payment_data['urlConfirmation'],
                    'urlReturn' => $payment_data['urlReturn']
                );

                // Sort parameters for signature
                ksort($params);

                // Create signature
                $params['s'] = hash_hmac('sha256', urldecode(http_build_query($params)), $secret_key);

                // Determine API URL based on sandbox mode
                $sandbox = $this->get_option('sandbox', 'yes') === 'yes';
                $api_url = $sandbox ? 'https://sandbox.flow.cl/api/payment/create' : 'https://www.flow.cl/api/payment/create';

                // Make API request
                $response = wp_remote_post($api_url, array(
                    'body' => $params,
                    'timeout' => 30,
                    'headers' => array(
                        'Content-Type' => 'application/x-www-form-urlencoded'
                    )
                ));

                if (is_wp_error($response)) {
                    return array('error' => 'Error de conexión: ' . $response->get_error_message());
                }

                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    return array('error' => 'Respuesta JSON inválida de Flow');
                }

                return $data;
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