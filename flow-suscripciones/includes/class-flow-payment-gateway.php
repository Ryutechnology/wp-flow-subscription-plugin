<?php
if (!defined('ABSPATH')) exit;

// Only define the class if WooCommerce is available
if (class_exists('WC_Payment_Gateway')) {

class Flow_Payment_Gateway extends WC_Payment_Gateway {

    /**
     * Gateway API credentials and settings
     */
    public $api_key;
    public $secret_key;
    public $sandbox;

    public function __construct() {
        $this->id = 'flow';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'Flow Suscripciones';
        $this->method_description = 'Procesa pagos de suscripciones recurrentes usando Flow.';

        // Declare support for various features including checkout blocks
        $this->supports = array(
            'products',
            'refunds'
        );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title', 'Convierte tu carrito en una suscripción');
        $this->description = $this->get_option('description', 'Paga con Flow - Suscripciones recurrentes seguras.');
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->api_key = $this->get_option('api_key');
        $this->secret_key = $this->get_option('secret_key');
        $this->sandbox = $this->get_option('sandbox', 'yes') === 'yes';

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_flow_webhook', array($this, 'webhook_handler'));
        add_action('woocommerce_api_flow_return', array($this, 'return_handler'));
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => 'Habilitar/Deshabilitar',
                'type'        => 'checkbox',
                'label'       => 'Habilitar Flow Suscripciones',
                'default'     => 'yes'
            ),
            'title' => array(
                'title'       => 'Título',
                'type'        => 'text',
                'description' => 'Título que verán los usuarios durante el checkout.',
                'default'     => 'Flow Suscripciones',
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => 'Descripción',
                'type'        => 'textarea',
                'description' => 'Descripción que verán los usuarios durante el checkout.',
                'default'     => 'Paga con Flow - Suscripciones recurrentes seguras.',
                'desc_tip'    => true,
            ),
            'api_key' => array(
                'title'       => 'API Key',
                'type'        => 'text',
                'description' => 'Tu API Key de Flow.',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'secret_key' => array(
                'title'       => 'Secret Key',
                'type'        => 'password',
                'description' => 'Tu Secret Key de Flow.',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'sandbox' => array(
                'title'       => 'Modo Sandbox',
                'type'        => 'checkbox',
                'label'       => 'Habilitar modo sandbox (pruebas)',
                'default'     => 'yes',
                'description' => 'Usar el entorno de pruebas de Flow.',
                'desc_tip'    => true,
            )
        );
    }

    /**
     * Process the payment and return the result
     */
    public function process_payment($order_id) {
        error_log('Flow Subscription: Starting process_payment for order ID: ' . $order_id);

        // Validate subscription products first
        if (!$this->validate_fields()) {
            error_log('Flow Subscription: Validation failed for order ID: ' . $order_id);
            return array('result' => 'fail');
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            error_log('Flow Subscription: Order not found for ID: ' . $order_id);
            wc_add_notice('Error: No se pudo procesar el pedido.', 'error');
            return array('result' => 'fail');
        }

        // Initialize Flow API
        $flow_api = new Flow_API();
        error_log('Flow Subscription: Flow API initialized');

        // Get order details
        $amount = intval($order->get_total());
        $order_number = $order->get_order_number();
        $customer_email = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $customer_address = $order->get_billing_address_1();
        $customer_city = $order->get_billing_city();

        error_log('Flow Subscription: Order details - Amount: ' . $amount . ', Email: ' . $customer_email . ', Name: ' . $customer_name);

        // Step 1: Create or get subscription plan
        $plan_name = 'Subscription ' . get_bloginfo('name') . ' - $' . $amount;
        error_log('Flow Subscription: Creating plan - ' . $plan_name);

        $plan_response = $flow_api->create_plan($plan_name, $amount);
        error_log('Flow Subscription: Plan response - ' . json_encode($plan_response));

        if (isset($plan_response['error'])) {
            $error_msg = 'Error creating Flow plan: ' . $plan_response['error'];
            error_log('Flow Subscription: ' . $error_msg);
            $order->add_order_note($error_msg);
            wc_add_notice('Error creando plan de suscripción: ' . $plan_response['error'], 'error');
            return array('result' => 'fail');
        }

        if (!isset($plan_response['planId'])) {
            $error_msg = 'Invalid Flow plan response: missing planId';
            error_log('Flow Subscription: ' . $error_msg);
            $order->add_order_note($error_msg);
            wc_add_notice('Error: Respuesta inválida al crear plan de suscripción.', 'error');
            return array('result' => 'fail');
        }

        $plan_id = $plan_response['planId'];
        error_log('Flow Subscription: Plan created successfully - Plan ID: ' . $plan_id);

        // Step 2: Create customer first
        error_log('Flow Subscription: Creating customer - Email: ' . $customer_email . ', Name: ' . $customer_name);
        $customer_response = $flow_api->create_customer($customer_email, $customer_name, $customer_address, $customer_city);
        error_log('Flow Subscription: Customer response - ' . json_encode($customer_response));

        if (isset($customer_response['code'])) {
            $error_msg = 'Error creating Flow customer: ' . $customer_response['message'];
            error_log('Flow Subscription: ' . $error_msg);
            $order->add_order_note($error_msg);
            wc_add_notice('Error creando cliente: ' . $customer_response['message'], 'error');
            return array('result' => 'fail');
        }

        if (!isset($customer_response['customerId'])) {
            $error_msg = 'Invalid Flow customer response: missing customerId';
            error_log('Flow Subscription: ' . $error_msg);
            $order->add_order_note($error_msg);
            wc_add_notice('Error: Respuesta inválida al crear cliente.', 'error');
            return array('result' => 'fail');
        }

        $customer_id = $customer_response['customerId'];
        error_log('Flow Subscription: Customer created successfully - Customer ID: ' . $customer_id);

        // Step 3: Create subscription
        error_log('Flow Subscription: Creating subscription for plan: ' . $plan_id . ', customer ID: ' . $customer_id);
        $subscription_response = $flow_api->create_subscription($plan_id, $customer_id);
        error_log('Flow Subscription: Subscription response - ' . json_encode($subscription_response));

        if (isset($subscription_response['code'])) {
            $error_msg = 'Error creating Flow subscription: ' . $subscription_response['message'];
            error_log('Flow Subscription: ' . $error_msg);
            $order->add_order_note($error_msg);
            wc_add_notice('Error creando suscripción: ' . $subscription_response['message'], 'error');
            return array('result' => 'fail');
        }

        if (!isset($subscription_response['subscriptionId'])) {
            $error_msg = 'Invalid Flow subscription response: missing subscriptionId';
            error_log('Flow Subscription: ' . $error_msg);
            $order->add_order_note($error_msg);
            wc_add_notice('Error: Respuesta inválida al crear suscripción.', 'error');
            return array('result' => 'fail');
        }

        $subscription_id = $subscription_response['subscriptionId'];
        error_log('Flow Subscription: Subscription created successfully - Subscription ID: ' . $subscription_id);

        // Step 4: Register credit card for the customer
        $url_return = WC()->api_request_url('flow_return') . '?order_id=' . $order_id;
        error_log('Flow Subscription: Registering credit card for customer ID: ' . $customer_id . ', return URL: ' . $url_return);

        $card_registration_response = $flow_api->register_credit_card($customer_id, $url_return);
        error_log('Flow Subscription: Card registration response - ' . json_encode($card_registration_response));

        if (isset($card_registration_response['code'])) {
            $error_msg = 'Error registering credit card: ' . $card_registration_response['message'];
            error_log('Flow Subscription: ' . $error_msg);
            $order->add_order_note($error_msg);
            wc_add_notice('Error registrando tarjeta de crédito: ' . $card_registration_response['message'], 'error');
            return array('result' => 'fail');
        }

        if (!isset($card_registration_response['url']) || !isset($card_registration_response['token'])) {
            $error_msg = 'Invalid Flow card registration response: missing URL or token';
            error_log('Flow Subscription: ' . $error_msg);
            $order->add_order_note($error_msg);
            wc_add_notice('Error: Respuesta inválida del registro de tarjeta.', 'error');
            return array('result' => 'fail');
        }

        $token = $card_registration_response['token'];
        $card_url = $card_registration_response['url'];
        error_log('Flow Subscription: Card registration initiated - Token: ' . $token . ', URL: ' . $card_url);

        // Store Flow subscription data in order meta
        $order->update_meta_data('_flow_token', $token);
        $order->update_meta_data('_flow_plan_id', $plan_id);
        $order->update_meta_data('_flow_subscription_id', $subscription_id);
        $order->update_meta_data('_flow_customer_id', $customer_id);
        $order->update_meta_data('_flow_customer_email', $customer_email);
        $order->update_meta_data('_flow_customer_data', $customer_response);
        $order->update_meta_data('_flow_card_registration_data', $card_registration_response);
        $order->update_meta_data('_flow_subscription_amount', $amount);

        error_log('Flow Subscription: Order metadata stored');

        // Mark order as pending credit card registration
        $order->update_status('pending', 'Esperando registro de tarjeta de crédito vía Flow. Token: ' . $token);
        $order->save();

        error_log('Flow Subscription: Order status updated to pending, redirecting to: ' . $card_url);

        // Clear cart
        WC()->cart->empty_cart();

        // Redirect to Flow credit card registration page
        return array(
            'result' => 'success',
            'redirect' => $card_url . '?token=' . $token
        );
    }

    /**
     * Webhook handler
     */
    public function webhook_handler() {
        error_log('Flow Subscription: Webhook received - POST data: ' . json_encode($_POST));

        // Get Flow webhook data
        $token = sanitize_text_field($_POST['token'] ?? '');

        if (empty($token)) {
            error_log('Flow Subscription: Webhook missing token');
            status_header(400);
            exit('Invalid token');
        }

        error_log('Flow Subscription: Processing webhook for token: ' . $token);

        // Find order by token
        $orders = wc_get_orders(array(
            'meta_query' => array(
                array(
                    'key' => '_flow_token',
                    'value' => $token,
                    'compare' => '='
                )
            ),
            'limit' => 1
        ));

        if (empty($orders)) {
            error_log('Flow Subscription: Webhook - Order not found for token: ' . $token);
            status_header(404);
            exit('Order not found');
        }

        $order = $orders[0];
        $order_id = $order->get_id();
        error_log('Flow Subscription: Webhook - Found order ID: ' . $order_id . ' for token: ' . $token);

        // Initialize Flow API to get credit card registration status
        $flow_api = new Flow_API();
        error_log('Flow Subscription: Webhook - Getting registration status for token: ' . $token);

        $registration_status = $flow_api->get_register_results($token);
        error_log('Flow Subscription: Webhook - Registration status response: ' . json_encode($registration_status));

        if (isset($registration_status['error'])) {
            $error_msg = 'Flow webhook error: ' . $registration_status['error'];
            error_log('Flow Subscription: ' . $error_msg);
            $order->add_order_note('Error getting card registration status: ' . $registration_status['error']);
            status_header(500);
            exit('Error getting card registration status');
        }

        // Process card registration based on status
        if (isset($registration_status['status'])) {
            $status = $registration_status['status'];
            error_log('Flow Subscription: Webhook - Processing status: ' . $status . ' for order: ' . $order_id);

            switch ($status) {
                case 1: // Pending
                    error_log('Flow Subscription: Webhook - Card registration pending for order: ' . $order_id);
                    $order->update_status('pending', 'Registro de tarjeta pendiente en Flow');
                    break;
                case 2: // Completed - card registered successfully
                    error_log('Flow Subscription: Webhook - Card registration completed for order: ' . $order_id);
                    $this->handle_card_registration_completion($order, $registration_status);
                    break;
                case 3: // Rejected
                    error_log('Flow Subscription: Webhook - Card registration rejected for order: ' . $order_id);
                    $order->update_status('failed', 'Registro de tarjeta rechazado en Flow');
                    break;
                case 4: // Cancelled
                    error_log('Flow Subscription: Webhook - Card registration cancelled for order: ' . $order_id);
                    $order->update_status('cancelled', 'Registro de tarjeta cancelado en Flow');
                    break;
                default:
                    error_log('Flow Subscription: Webhook - Unknown status: ' . $status . ' for order: ' . $order_id);
                    $order->add_order_note('Estado de registro de tarjeta desconocido en Flow: ' . $status);
            }
        } else {
            error_log('Flow Subscription: Webhook - No status field in registration response for order: ' . $order_id);
        }

        error_log('Flow Subscription: Webhook processing completed for order: ' . $order_id);
        status_header(200);
        exit('OK');
    }

    /**
     * Handle credit card registration completion
     */
    private function handle_card_registration_completion($order, $registration_status) {
        $order_id = $order->get_id();
        error_log('Flow Subscription: Starting card registration completion for order: ' . $order_id);

        // Get stored subscription data
        $subscription_id = $order->get_meta('_flow_subscription_id');
        $customer_id = $order->get_meta('_flow_customer_id');
        $customer_email = $order->get_meta('_flow_customer_email');

        error_log('Flow Subscription: Retrieved metadata - Subscription ID: ' . $subscription_id . ', Customer ID: ' . $customer_id . ', Customer Email: ' . $customer_email);

        if (!$subscription_id || !$customer_id) {
            $error_msg = 'Error: Missing subscription ID or customer ID';
            error_log('Flow Subscription: ' . $error_msg . ' for order: ' . $order_id);
            $order->add_order_note($error_msg);
            return;
        }

        // Store credit card registration data
        $card_id = $registration_status['cardId'] ?? '';
        error_log('Flow Subscription: Storing card registration data - Card ID: ' . $card_id . ' for order: ' . $order_id);

        $order->update_meta_data('_flow_card_id', $card_id);
        $order->update_meta_data('_flow_registration_status', $registration_status);

        // Store Flow subscription data in customer profile
        $customer_user_id = $order->get_customer_id();
        if ($customer_user_id) {
            error_log('Flow Subscription: Storing Flow data for customer user ID: ' . $customer_user_id);

            Flow_Customer_Columns::set_customer_flow_subscription_id($customer_user_id, $subscription_id);
            Flow_Customer_Columns::set_customer_flow_customer_id($customer_user_id, $customer_id);

            error_log('Flow Subscription: Customer Flow data stored - Subscription ID: ' . $subscription_id . ', Customer ID: ' . $customer_id);
        }

        // Mark order as completed - subscription is now active with registered card
        error_log('Flow Subscription: Completing payment for order: ' . $order_id . ' with subscription ID: ' . $subscription_id);

        $order->payment_complete($subscription_id);
        $success_note = 'Suscripción activada exitosamente. Subscription ID: ' . $subscription_id . ', Customer ID: ' . $customer_id . ', Card ID: ' . ($card_id ?: 'N/A');
        $order->add_order_note($success_note);

        $order->save();
        error_log('Flow Subscription: Card registration completion finished for order: ' . $order_id . ' - ' . $success_note);
    }

    /**
     * Return handler
     */
    public function return_handler() {
        error_log('Flow Subscription: Return handler called - GET data: ' . json_encode($_GET));

        $order_id = intval($_GET['order_id'] ?? 0);
        $token = sanitize_text_field($_GET['token'] ?? '');

        // If no token in GET, try to get it from the stored order data
        if (empty($token) && $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $token = $order->get_meta('_flow_token');
                error_log('Flow Subscription: Retrieved token from order metadata: ' . $token);
            }
        }

        error_log('Flow Subscription: Return handler - Order ID: ' . $order_id . ', Token: ' . $token);

        if (!$order_id) {
            error_log('Flow Subscription: Return handler - No order ID provided, redirecting to checkout');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('Flow Subscription: Return handler - Order not found for ID: ' . $order_id);
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        error_log('Flow Subscription: Return handler - Processing return for order: ' . $order_id);

        // If we have a token, verify the credit card registration status
        if ($token) {
            error_log('Flow Subscription: Return handler - Checking registration status for token: ' . $token);

            $flow_api = new Flow_API();
            $registration_status = $flow_api->get_register_results($token);
            error_log('Flow Subscription: Return handler - Registration status: ' . json_encode($registration_status));

            // Check for Flow API errors first
            if (isset($registration_status['code']) && isset($registration_status['message'])) {
                $error_msg = 'Flow API Error checking registration status: ' . $registration_status['message'] . ' (Code: ' . $registration_status['code'] . ')';
                error_log('Flow Subscription: Return handler - ' . $error_msg);
                $order->add_order_note($error_msg);
                wc_add_notice('Error verificando el estado del registro de tarjeta. Por favor contacta soporte.', 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }

            if (isset($registration_status['status'])) {
                $status = $registration_status['status'];
                error_log('Flow Subscription: Return handler - Processing status: ' . $status . ' for order: ' . $order_id);

                switch ($status) {
                    case 2: // Completed - card registered successfully
                        error_log('Flow Subscription: Return handler - Card registration completed, handling completion');

                        // Verify we have the required registration data
                        if (!isset($registration_status['customerId'])) {
                            error_log('Flow Subscription: Return handler - Missing customerId in registration status');
                            $order->add_order_note('Error: Missing customerId in registration response');
                            wc_add_notice('Error en la respuesta del registro de tarjeta. Por favor contacta soporte.', 'error');
                            wp_redirect(wc_get_checkout_url());
                            exit;
                        }

                        // Handle card registration completion
                        $this->handle_card_registration_completion($order, $registration_status);

                        // Add success message
                        wc_add_notice('¡Suscripción activada exitosamente! Tu tarjeta ha sido registrada y la suscripción está activa.', 'success');
                        $return_url = $this->get_return_url($order);
                        error_log('Flow Subscription: Return handler - Redirecting to success page: ' . $return_url);
                        wp_redirect($return_url);
                        exit;

                    case 3: // Rejected
                        error_log('Flow Subscription: Return handler - Card registration rejected for order: ' . $order_id);
                        $order->update_status('failed', 'Registro de tarjeta rechazado en Flow');
                        $order->add_order_note('Card registration rejected. Reason: ' . ($registration_status['message'] ?? 'Unknown'));
                        wc_add_notice('El registro de la tarjeta fue rechazado. Por favor intenta con otra tarjeta.', 'error');
                        wp_redirect(wc_get_checkout_url());
                        exit;

                    case 4: // Cancelled
                        error_log('Flow Subscription: Return handler - Card registration cancelled for order: ' . $order_id);
                        $order->update_status('cancelled', 'Registro de tarjeta cancelado en Flow');
                        $order->add_order_note('Card registration cancelled by user');
                        wc_add_notice('El registro de la tarjeta fue cancelado. Puedes intentarlo nuevamente.', 'notice');
                        wp_redirect(wc_get_checkout_url());
                        exit;

                    case 1: // Still pending
                        error_log('Flow Subscription: Return handler - Card registration still pending for order: ' . $order_id);
                        $order->add_order_note('Cliente regresó con registro de tarjeta aún pendiente');
                        wc_add_notice('El registro de tu tarjeta está en proceso. Te notificaremos cuando esté listo.', 'notice');
                        $return_url = $this->get_return_url($order);
                        error_log('Flow Subscription: Return handler - Redirecting to pending page: ' . $return_url);
                        wp_redirect($return_url);
                        exit;

                    default:
                        error_log('Flow Subscription: Return handler - Unknown status: ' . $status . ' for order: ' . $order_id);
                        $order->add_order_note('Unknown registration status: ' . $status);
                        wc_add_notice('Estado de registro desconocido. Por favor contacta soporte.', 'error');
                        wp_redirect(wc_get_checkout_url());
                        exit;
                }
            } else {
                // No status field in response
                $error_msg = 'Missing status field in registration response';
                error_log('Flow Subscription: Return handler - ' . $error_msg);
                $order->add_order_note($error_msg . ': ' . json_encode($registration_status));
                wc_add_notice('Respuesta inválida del servicio de registro. Por favor contacta soporte.', 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }
        } else {
            error_log('Flow Subscription: Return handler - No token available for order: ' . $order_id);
            $order->add_order_note('Return handler called without token');
            wc_add_notice('No se pudo verificar el estado del registro de tarjeta. Por favor contacta soporte.', 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        // Default redirect to thank you page if order exists
        $return_url = $this->get_return_url($order);
        error_log('Flow Subscription: Return handler - Default redirect to: ' . $return_url);
        wp_redirect($return_url);
        exit;
    }

    /**
     * Get Flow payment status
     */
    private function get_flow_payment_status($token) {
        // Get API credentials
        $api_key = $this->api_key ?: get_option('flow_api_key');
        $secret_key = $this->secret_key ?: get_option('flow_secret_key');

        if (empty($api_key) || empty($secret_key)) {
            return array('error' => 'Credenciales Flow no configuradas');
        }

        // Prepare parameters for Flow API
        $params = array(
            'apiKey' => $api_key,
            'token' => $token
        );

        // Sort parameters for signature
        ksort($params);

        // Create signature
        $params['s'] = hash_hmac('sha256', urldecode(http_build_query($params)), $secret_key);

        // Determine API URL based on global Flow environment setting
        $environment = get_option('flow_environment', 'sandbox');
        $api_url = ($environment === 'production') ? 'https://www.flow.cl/api/payment/getStatus' : 'https://sandbox.flow.cl/api/payment/getStatus';

        // Make API request
        $response = wp_remote_get($api_url . '?' . http_build_query($params), array(
            'timeout' => 30
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

    /**
     * Check if this gateway is available
     */
    public function is_available() {
        // Basic availability check
        if ($this->enabled === 'no') {
            return false;
        }

        // Check if WooCommerce is available
        if (!function_exists('WC')) {
            return false;
        }

        // Check if cart exists
        if (!WC()->cart || WC()->cart->is_empty()) {
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

            // Check if product has "Suscripciones Flow" category
            $has_subscription_category = has_term('Suscripciones Flow', 'product_cat', $product->get_id());

            error_log('Flow Subscription: Product ID ' . $product->get_id() . ' (' . $product->get_name() . ') has Suscripciones Flow category: ' . ($has_subscription_category ? 'Yes' : 'No'));

            // If product doesn't have the "Suscripciones Flow" category
            if (!$has_subscription_category) {
                $product_categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
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

    /**
     * Admin Panel Options
     */
    public function admin_options() {
        ?>
        <h3><?php echo $this->method_title; ?></h3>
        <p><?php echo $this->method_description; ?></p>

        <?php
        // Show sync status
        $flow_api_key = get_option('flow_api_key');
        $flow_secret_key = get_option('flow_secret_key');

        if ($flow_api_key && $flow_secret_key) {
            echo '<div class="notice notice-success inline"><p><strong>✓ Credenciales Flow sincronizadas automáticamente</strong></p></div>';

            // Auto-sync credentials if they're different
            if ($this->api_key !== $flow_api_key || $this->secret_key !== $flow_secret_key) {
                $this->update_option('api_key', $flow_api_key);
                $this->update_option('secret_key', $flow_secret_key);
                echo '<div class="notice notice-info inline"><p>Credenciales actualizadas automáticamente desde la configuración principal de Flow.</p></div>';
            }
        } else {
            echo '<div class="notice notice-warning inline"><p><strong>⚠ Configura las credenciales Flow en Flow Suscripciones > Configuración</strong></p></div>';
        }
        ?>

        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>

        <h4>URLs para configurar en Flow</h4>
        <table class="form-table">
            <tr>
                <th>URL de Webhook:</th>
                <td><code><?php echo home_url('/wc-api/flow_webhook/'); ?></code></td>
            </tr>
            <tr>
                <th>URL de Retorno:</th>
                <td><code><?php echo home_url('/wc-api/flow_return/'); ?></code></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Process admin options and sync with Flow settings
     */
    public function process_admin_options() {
        $saved = parent::process_admin_options();

        // Auto-sync with Flow plugin settings if they exist
        $flow_api_key = get_option('flow_api_key');
        $flow_secret_key = get_option('flow_secret_key');

        if (empty($this->get_option('api_key')) && $flow_api_key) {
            $this->update_option('api_key', $flow_api_key);
        }

        if (empty($this->get_option('secret_key')) && $flow_secret_key) {
            $this->update_option('secret_key', $flow_secret_key);
        }

        return $saved;
    }
}

} // End class_exists check
?>